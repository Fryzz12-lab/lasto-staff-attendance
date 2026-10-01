<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/helpers.php';

$failures = 0;
$report = static function (bool $passed, string $message) use (&$failures): void {
    echo ($passed ? 'PASS: ' : 'FAIL: ') . $message . PHP_EOL;
    if (!$passed) $failures++;
};
$report(PHP_VERSION_ID >= 80000, 'PHP 8 or later');
foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'openssl'] as $extension) {
    $report(extension_loaded($extension), 'PHP extension: ' . $extension);
}
$report(APP_ENV === 'production', 'LASTO_APP_ENV=production');
$report(DB_USER !== 'root' && DB_PASS !== '', 'Dedicated database account with a non-empty password');
$origin = parse_url((string) getenv('LASTO_PUBLIC_ORIGIN'));
$report(is_array($origin) && ($origin['scheme'] ?? '') === 'https' && !empty($origin['host'])
    && !isset($origin['user']) && !isset($origin['pass']) && !isset($origin['query']) && !isset($origin['fragment'])
    && in_array($origin['path'] ?? '', ['', '/'], true), 'Public HTTPS origin configured');
foreach (['storage', 'uploads/profiles'] as $directory) {
    $report(is_dir(__DIR__ . '/../' . $directory) && is_writable(__DIR__ . '/../' . $directory), 'Writable directory: ' . $directory);
}
$report(!is_file(__DIR__ . '/../storage/uat-manifest.json') && !is_file(__DIR__ . '/../storage/uat-manifest.json.tmp'), 'Private UAT manifests removed after UAT cleanup');
try {
    $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET), DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $report(true, 'Database connection');
    foreach (['users' => ['auth_version', 'email_verified_at', 'employment_end_date', 'must_change_password'],
        'attendance' => ['is_wfh'], 'account_recovery_tokens' => ['token_hash'],
        'user_security_questions' => ['answer_hash']] as $table => $required) {
        $columns = array_column($pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(), 'Field');
        $report(!array_diff($required, $columns), 'Required schema: ' . $table);
    }
    $report((int) $pdo->query("SELECT COUNT(*) FROM users WHERE LEFT(username,4)='uat_'")->fetchColumn() === 0, 'No UAT accounts remain');
    $report((int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1 AND must_change_password=0")->fetchColumn() > 0, 'Active administrator with account setup complete');
    $defaultPasswords = 0;
    foreach ($pdo->query('SELECT password_hash FROM users WHERE is_active=1') as $account) {
        foreach (['Admin@123', 'Staff@123'] as $password) {
            if (password_verify($password, $account['password_hash'])) { $defaultPasswords++; break; }
        }
    }
    $report($defaultPasswords === 0, 'No active accounts using bundled demo passwords');
} catch (Throwable $error) {
    $report(false, 'Database/schema check failed: ' . $error->getMessage());
}
echo "MANUAL: Verify HTTPS cookies, login throttling at the server/proxy, restore a backup, schedule daily attendance, test recovery and complete browser UAT.\n";
echo "This read-only check is not deployment certification.\n";
exit($failures ? 1 : 0);
