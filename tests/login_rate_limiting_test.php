<?php
/**
 * Automated Security Test Suite: Phase 6B.2 — Persistent Login Rate Limiting & Brute-Force Protection
 * Staff Attendance & Workforce Management System
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_WARNING);

require_once __DIR__ . '/../includes/bootstrap.php';

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$name}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$name} - {$detail}\n";
    }
}

echo "=== PHASE 6B.2: PERSISTENT LOGIN RATE LIMITING & BRUTE-FORCE PROTECTION ===\n";

require_once __DIR__ . '/test_database_safety.php';

$database = 'staff_attendance_test_' . bin2hex(random_bytes(6));
$pdo = db();
$pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    if ($schema === false) throw new RuntimeException('Cannot read test schema.');
    $schema = str_replace('`staff_attendance`', '`' . $database . '`', $schema);
    $pdo->exec($schema);

    $testUser = 'test_rate_limiter_user_' . bin2hex(random_bytes(3));
    $testPass = 'StrongPass123!#';
    $testIp1 = '198.51.100.11';
    $testIp2 = '198.51.100.22';

    // Create test user in disposable test DB
    $insUser = $pdo->prepare('
        INSERT INTO users (employee_id, username, email, full_name, role, auth_version, password_hash, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 0, ?, 1, NOW(), NOW())
    ');
    $insUser->execute(['EMP_' . bin2hex(random_bytes(4)), $testUser, $testUser . '@test.local', 'Test Rate Limit User', 'staff', password_hash($testPass, PASSWORD_BCRYPT)]);

    // Clean any prior rate limits for test user/IPs
    auth_reset_rate_limits($testUser);
    auth_reset_rate_limits(null, $testIp1);
    auth_reset_rate_limits(null, $testIp2);

    // -------------------------------------------------------------------------
    // Test 1: Successful Login with Valid Credentials
    // -------------------------------------------------------------------------
    echo "\n--- Test Suite 1: Normal Authentication & Baseline ---\n";
    $_SERVER['REMOTE_ADDR'] = $testIp1;
    $res = attempt_login($testUser, $testPass, false);
    assert_test('Valid credentials authenticate successfully', $res['success'] === true);
    
    // Check rate limit table - should have 0 failed attempts
    $throttle = auth_is_throttled($testUser, $testIp1);
    assert_test('User is not throttled after successful login', $throttle['throttled'] === false);

    // -------------------------------------------------------------------------
    // Test 2: Failed Login Increments Failure Counter
    // -------------------------------------------------------------------------
    echo "\n--- Test Suite 2: Failed Login & Counter Accumulation ---\n";
    $resFail1 = attempt_login($testUser, 'WrongPassword1', false);
    assert_test('Invalid password rejected with standard credentials error', $resFail1['success'] === false && $resFail1['message'] === __('msg_invalid_credentials'));
    
    // Check database record
    $userHash = hash('sha256', strtolower($testUser));
    $keyUserIp = 'user_ip:' . $userHash . ':' . $testIp1;
    $row = $pdo->query("SELECT failed_attempts, throttled_until FROM login_rate_limits WHERE rate_key = '{$keyUserIp}'")->fetch();
    assert_test('Database record created with 1 failed attempt', $row && (int)$row['failed_attempts'] === 1);
    assert_test('Not throttled after 1 failed attempt', empty($row['throttled_until']));

    // -------------------------------------------------------------------------
    // Test 3: Username Enumeration Resistance
    // -------------------------------------------------------------------------
    echo "\n--- Test Suite 3: Username Enumeration Resistance ---\n";
    $nonExistentUser = 'ghost_user_' . bin2hex(random_bytes(4));
    $resNonExist = attempt_login($nonExistentUser, 'AnyPassword123!', false);
    assert_test('Nonexistent user returns identical error message to invalid password', $resNonExist['message'] === $resFail1['message']);
    
    $ghostHash = hash('sha256', strtolower($nonExistentUser));
    $ghostRow = $pdo->query("SELECT failed_attempts FROM login_rate_limits WHERE rate_key = 'user_ip:{$ghostHash}:{$testIp1}'")->fetch();
    assert_test('Nonexistent username failures are tracked in rate limit table identically', $ghostRow && (int)$ghostRow['failed_attempts'] === 1);

    // -------------------------------------------------------------------------
    // Test 4: Threshold Enforcement (5 Failed Attempts)
    // -------------------------------------------------------------------------
    echo "\n--- Test Suite 4: Threshold Enforcement (5 Failed Attempts) ---\n";
    // We already have 1 failed attempt for $testUser on $testIp1. Send 3 more (total 4)
    attempt_login($testUser, 'WrongPassword2', false);
    attempt_login($testUser, 'WrongPassword3', false);
    $resFail4 = attempt_login($testUser, 'WrongPassword4', false);
    assert_test('Attempt 4 rejected with standard credentials error (not yet throttled)', $resFail4['success'] === false && !isset($resFail4['throttled']));

    // Send 5th failed attempt: this triggers the threshold!
    $resFail5 = attempt_login($testUser, 'WrongPassword5', false);
    assert_test('Attempt 5 triggers throttling', $resFail5['success'] === false && !empty($resFail5['throttled']));
    assert_test('Throttled error message returned with cooldown minutes', str_contains($resFail5['message'], '15') || str_contains($resFail5['message'], (string)AUTH_COOLDOWN_MINUTES));

    // Attempt 6: should be rejected immediately before password hashing
    $resFail6 = attempt_login($testUser, $testPass, false); // Even with correct password, blocked during cooldown!
    assert_test('Subsequent attempt within cooldown is blocked immediately even with valid password', $resFail6['success'] === false && !empty($resFail6['throttled']));

    // -------------------------------------------------------------------------
    // Test 5: Session Independence (Bypass Resistance)
    // -------------------------------------------------------------------------
    echo "\n--- Test Suite 5: Session Independence & Server-Side Persistence ---\n";
    // Destroy PHP session completely and clear session variables
    $_SESSION = [];
    session_regenerate_id(true);
    
    // Attacker tries from fresh session:
    $resFreshSession = attempt_login($testUser, 'WrongPassword6', false);
    assert_test('New session is still throttled (cannot bypass via cookie/session reset)', $resFreshSession['success'] === false && !empty($resFreshSession['throttled']));

    // -------------------------------------------------------------------------
    // Test 6: Network & Account Rate-Limit Isolation (Legitimate User Protection)
    // -------------------------------------------------------------------------
    echo "\n--- Test Suite 6: Legitimate User Protection & IP Isolation ---\n";
    // Legitimate user connecting from a different network IP ($testIp2)
    $_SERVER['REMOTE_ADDR'] = $testIp2;
    $resLegit = attempt_login($testUser, $testPass, false);
    assert_test('Legitimate user from distinct IP can still log in (attacker cannot lock out employee)', $resLegit['success'] === true);

    // -------------------------------------------------------------------------
    // Test 7: Expiration & Automatic Recovery
    // -------------------------------------------------------------------------
    echo "\n--- Test Suite 7: Expiration & Automatic Recovery ---\n";
    // Simulate passage of time on $testIp1 by updating throttled_until to 1 second in the past
    $_SERVER['REMOTE_ADDR'] = $testIp1;
    $pdo->prepare("UPDATE login_rate_limits SET throttled_until = NOW() - INTERVAL 1 SECOND WHERE rate_key = ?")->execute([$keyUserIp]);

    $throttleAfterExpiry = auth_is_throttled($testUser, $testIp1);
    assert_test('Throttled state expires automatically after cooldown', $throttleAfterExpiry['throttled'] === false);

    // Now valid login succeeds on $testIp1
    $resRecovered = attempt_login($testUser, $testPass, false);
    assert_test('Login succeeds normally after cooldown expiration', $resRecovered['success'] === true);

    // Counter reset verified in DB
    $clearedRow = $pdo->query("SELECT * FROM login_rate_limits WHERE rate_key = '{$keyUserIp}'")->fetch();
    assert_test('Successful login clears failed attempts counter', $clearedRow === false);

    // -------------------------------------------------------------------------
    // Test 8: Proxy Header Protection
    // -------------------------------------------------------------------------
    echo "\n--- Test Suite 8: Proxy Forwarding & Anti-Spoofing ---\n";
    // Scenario 1: Untrusted REMOTE_ADDR sends spoofed X-Forwarded-For
    $_SERVER['REMOTE_ADDR'] = '203.0.113.50'; // Not in TRUSTED_PROXIES
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1, 192.168.1.1';
    $resolvedIp = auth_client_ip();
    assert_test('Untrusted proxy ignores spoofed X-Forwarded-For (uses REMOTE_ADDR)', $resolvedIp === '203.0.113.50');

    // Scenario 2: Explicitly trusted proxy sends forwarded IP
    // Define temporary test constant via putenv
    putenv('LASTO_TRUSTED_PROXIES=127.0.0.1,10.0.0.254');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99, 10.0.0.254';
    $trustedResolvedIp = auth_client_ip();
    assert_test('Trusted proxy extracts correct client IP from X-Forwarded-For', $trustedResolvedIp === '198.51.100.99');
    putenv('LASTO_TRUSTED_PROXIES'); // Clear env

    // -------------------------------------------------------------------------
    // Test 9: Concurrency & Atomic Database Locking
    // -------------------------------------------------------------------------
    echo "\n--- Test Suite 9: Atomic Updates & Concurrency ---\n";
    auth_reset_rate_limits($testUser, $testIp1);
    $_SERVER['REMOTE_ADDR'] = $testIp1;
    
    // Simulate 3 atomic increments
    auth_record_failed_attempt($testUser, $testIp1);
    auth_record_failed_attempt($testUser, $testIp1);
    auth_record_failed_attempt($testUser, $testIp1);

    $atomicRow = $pdo->query("SELECT failed_attempts FROM login_rate_limits WHERE rate_key = '{$keyUserIp}'")->fetch();
    assert_test('Sequential/concurrent updates correctly accumulate', (int)$atomicRow['failed_attempts'] === 3);

    // -------------------------------------------------------------------------
    // Test 10: Retention Pruning
    // -------------------------------------------------------------------------
    echo "\n--- Test Suite 10: Stale Record Pruning ---\n";
    // Create old record (> 10 days ago)
    $oldKey = 'ip:203.0.113.99';
    $pdo->prepare("
        INSERT INTO login_rate_limits (rate_key, identifier_type, identifier, failed_attempts, first_failed_at, last_failed_at, throttled_until)
        VALUES (?, 'ip', '203.0.113.99', 2, NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 10 DAY, NULL)
        ON DUPLICATE KEY UPDATE last_failed_at = NOW() - INTERVAL 10 DAY
    ")->execute([$oldKey]);

    $pruned = auth_prune_rate_limits(7);
    assert_test('Pruning removes stale rate limit records older than retention threshold', $pruned >= 1);
    
    $checkPruned = $pdo->query("SELECT * FROM login_rate_limits WHERE rate_key = '{$oldKey}'")->fetch();
    assert_test('Old record successfully purged from database', $checkPruned === false);

} finally {
    TestDatabaseSafety::guardDropDatabase($database);
    $pdo->exec('USE `' . DB_NAME . '`');
    $pdo->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}

echo "\n=======================================================\n";
echo "Phase 6B.2 Test Results: {$passed} Passed, {$failed} Failed\n";
echo "=======================================================\n";

exit($failed > 0 ? 1 : 0);
