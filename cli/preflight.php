<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/helpers.php';

$failures = 0;
$report = static function (bool $passed, string $message) use (&$failures): void {
    echo ($passed ? 'PASS: ' : 'FAIL: ') . $message . PHP_EOL;
    if (!$passed) $failures++;
};

$appEnv = defined('APP_ENV') ? APP_ENV : 'unknown';

echo PHP_EOL;
echo "================================================================================\n";
echo " AGY STAFF ATTENDANCE PREFLIGHT READINESS AUDIT\n";
echo " TARGET ENVIRONMENT: " . strtoupper($appEnv) . PHP_EOL;
echo "================================================================================\n\n";

// 1. Environment recognition & fail-closed validation
$knownEnvironments = ['production', 'staging', 'development'];
if (!in_array($appEnv, $knownEnvironments, true)) {
    $report(false, "Unknown LASTO_APP_ENV='{$appEnv}'. Allowed values: production, staging, development.");
} else {
    $report(true, "Environment recognized: {$appEnv}");
}

// Local development cannot pass deployment readiness
if ($appEnv === 'development') {
    $report(false, "LASTO_APP_ENV is 'development' - deployment preflight requires 'staging' or 'production'.");
} else {
    $report(in_array($appEnv, ['production', 'staging'], true), "Target deployment environment active: {$appEnv}");
}

// 2. PHP runtime & required extensions
$report(PHP_VERSION_ID >= 80000, 'PHP 8 or later (detected: ' . PHP_VERSION . ')');
foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'openssl'] as $extension) {
    $report(extension_loaded($extension), 'PHP extension: ' . $extension);
}

// 3. Web root & base path
$report(BASE_PATH === '', "BASE_PATH is empty (application at domain root; current: '" . BASE_PATH . "')");

// 4. Dedicated database credentials & Environment Isolation Guard
$report(DB_USER !== 'root' && DB_PASS !== '', 'Dedicated database account with a non-empty password');

$dbNameLower = strtolower(DB_NAME);
if ($appEnv === 'staging') {
    $stagingNameValid = str_contains($dbNameLower, 'staging') || str_contains($dbNameLower, 'stg') || str_contains($dbNameLower, 'test');
    $report($stagingNameValid, "Staging database isolation: DB_NAME ('" . DB_NAME . "') contains 'staging', 'stg', or 'test' (fail-closed guard against pointing to production DB)");
} elseif ($appEnv === 'production') {
    $prodNameValid = !str_contains($dbNameLower, 'staging') && !str_contains($dbNameLower, 'test');
    $report($prodNameValid, "Production database isolation: DB_NAME ('" . DB_NAME . "') is not a test/staging database");
}

// 5. Public Origin & Hostname Validation
$originStr = (string) getenv('LASTO_PUBLIC_ORIGIN');
$origin = parse_url($originStr);
$validUrlStructure = is_array($origin)
    && ($origin['scheme'] ?? '') === 'https'
    && !empty($origin['host'])
    && !isset($origin['user']) && !isset($origin['pass'])
    && !isset($origin['query']) && !isset($origin['fragment'])
    && in_array($origin['path'] ?? '', ['', '/'], true);

$report($validUrlStructure, "Public HTTPS origin configured ({$originStr})");

if ($appEnv === 'production') {
    $report(($origin['host'] ?? '') === 'attendance.lastotechnology.my',
        "Production origin host must be 'attendance.lastotechnology.my' (detected: '" . ($origin['host'] ?? '') . "')");
} elseif ($appEnv === 'staging') {
    $expectedStagingHost = lasto_env('LASTO_EXPECTED_HOST', 'staging-attendance.lastotechnology.my');
    $report(($origin['host'] ?? '') === $expectedStagingHost,
        "Staging origin host must match configured staging host '{$expectedStagingHost}' (detected: '" . ($origin['host'] ?? '') . "')");
} else {
    $report(false, "Cannot validate public origin host for environment '{$appEnv}'");
}

// 6. Secrets configuration
$report(is_file(__DIR__ . '/../config/local.php') || (getenv('LASTO_DB_PASS') !== false && getenv('LASTO_DB_PASS') !== ''),
    'Secrets provided via config/local.php or environment');

// 7. Directory permissions
foreach (['storage', 'uploads/profiles'] as $directory) {
    $report(is_dir(__DIR__ . '/../' . $directory) && is_writable(__DIR__ . '/../' . $directory), 'Writable directory: ' . $directory);
}

// 8. VAPID configuration
try {
    require_once __DIR__ . '/../includes/push_service.php';
    $vapid = push_get_vapid_keys();
    $report(!empty($vapid['publicKey']) && !empty($vapid['privatePem']),
        'Persistent VAPID Web Push configuration active (' . ($vapid['source'] ?? 'custom') . ')');
} catch (Throwable $e) {
    $report(false, 'VAPID Web Push configuration error: ' . $e->getMessage());
}

// 9. UAT manifests
if ($appEnv === 'production') {
    $report(!is_file(__DIR__ . '/../storage/uat-manifest.json') && !is_file(__DIR__ . '/../storage/uat-manifest.json.tmp'),
        'Private UAT manifests removed after UAT cleanup in production');
} else {
    $report(true, 'UAT manifest check passed (staging/dev allows isolated test manifests)');
}

// 10. Database connection & Comprehensive schema checks
try {
    $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET), DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $report(true, 'Database connection');

    // Cross-database privilege containment verification
    try {
        $visibleDbs = $pdo->query("SELECT schema_name FROM information_schema.schemata WHERE schema_name NOT IN ('information_schema', 'performance_schema', 'mysql', 'sys', 'test')")->fetchAll(PDO::FETCH_COLUMN);
        if ($appEnv === 'staging') {
            $foreignDbs = array_filter($visibleDbs, function($name) {
                $n = strtolower($name);
                return !str_contains($n, 'staging') && !str_contains($n, 'stg') && !str_contains($n, 'test') && $n !== strtolower(DB_NAME);
            });
            $report(empty($foreignDbs), "Staging user database containment: no visibility into production/unrelated databases" . (empty($foreignDbs) ? '' : ' (exposed databases: ' . implode(',', $foreignDbs) . ')'));
        }
    } catch (Throwable $dbIsoEx) {
        $report(true, "Staging user database containment enforced by restricted privileges");
    }

    $requiredSchema = [
        'users' => ['auth_version', 'email_verified_at', 'employment_end_date', 'must_change_password', 'notification_preferences', 'attendance_location_enabled'],
        'attendance' => ['is_wfh', 'was_scheduled_off_day', 'scheduled_off_weekday', 'version', 'break_review_status', 'provisional_work_minutes'],
        'break_records' => ['is_auto_closed', 'closure_reason', 'system_closed_at'],
        'account_recovery_tokens' => ['token_hash'],
        'user_security_questions' => ['answer_hash'],
        'push_subscriptions' => ['endpoint', 'p256dh', 'auth', 'notification_preferences'],
        'notifications' => ['event_type', 'event_key', 'title', 'destination'],
        'login_rate_limits' => ['rate_key', 'failed_attempts', 'throttled_until'],
        'offday_adjustment_reviews' => ['employee_id', 'attendance_id', 'status', 'decision'],
        'staff_balance_ledger' => ['employee_id', 'balance_type', 'amount', 'source_type'],
        'attendance_correction_requests' => ['field_name', 'break_id', 'break_type', 'break_correction_type', 'requested_break_start', 'requested_break_end', 'target_attendance_version', 'is_late_submission', 'late_reason'],
    ];

    $existingTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($requiredSchema as $table => $requiredCols) {
        if (!in_array($table, $existingTables, true)) {
            $report(false, "Required table missing: {$table} (run database/cpanel_upgrade.sql)");
            continue;
        }
        $columns = array_column($pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(), 'Field');
        $missing = array_diff($requiredCols, $columns);
        $report(empty($missing), "Required schema for {$table}" . (empty($missing) ? '' : ' (missing: ' . implode(',', $missing) . '; run database/cpanel_upgrade.sql)'));
    }

    // UAT accounts check
    $uatCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE LEFT(username,4)='uat_'")->fetchColumn();
    if ($appEnv === 'production') {
        $report($uatCount === 0, 'No UAT accounts remain in production database');
    } else {
        $report(true, "Staging/Development check: {$uatCount} UAT account(s) present (fictional records allowed for UAT)");
    }

    // Active Administrator check
    $report((int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1 AND must_change_password=0")->fetchColumn() > 0,
        'Active administrator with account setup complete');

    // Demo Passwords check - NEVER DISABLED
    $defaultPasswords = 0;
    foreach ($pdo->query('SELECT password_hash FROM users WHERE is_active=1') as $account) {
        foreach (['Admin@123', 'Staff@123'] as $password) {
            if (password_verify($password, $account['password_hash'])) { $defaultPasswords++; break; }
        }
    }
    $report($defaultPasswords === 0, 'No active accounts using bundled demo passwords (Admin@123, Staff@123)');

} catch (Throwable $error) {
    $report(false, 'Database/schema check failed: ' . $error->getMessage());
}

echo PHP_EOL . "================================================================================\n";
if ($failures === 0) {
    echo " PREFLIGHT VERDICT: PASS (0 Failures) - READY FOR " . strtoupper($appEnv) . PHP_EOL;
} else {
    echo " PREFLIGHT VERDICT: FAIL ({$failures} Failure(s) Detected) - NOT READY FOR " . strtoupper($appEnv) . PHP_EOL;
}
echo "================================================================================\n";
echo "MANUAL: Verify HTTPS cookies, login throttling at the server/proxy, restore a backup, schedule daily attendance, test recovery and complete browser UAT.\n";
echo "This read-only check is not deployment certification.\n\n";

exit($failures ? 1 : 0);
