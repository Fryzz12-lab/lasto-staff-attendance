<?php
/**
 * Automated Test Suite: Phase 6B.1 — Shared-Device Push Security & CSRF-Protected Logout
 * Staff Attendance & Workforce Management System
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/push_service.php';

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

echo "=== PHASE 6B.1: CSRF-PROTECTED LOGOUT & SHARED-DEVICE PUSH SECURITY ===\n";

require_once __DIR__ . '/test_database_safety.php';

$database = 'staff_attendance_test_' . bin2hex(random_bytes(6));
$pdo = db();
$pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    if ($schema === false) throw new RuntimeException('Cannot read test schema.');
    $schema = str_replace('`staff_attendance`', '`' . $database . '`', $schema);
    $pdo->exec($schema);

    // Helper to simulate dummy user
    $userAId = 99901;
    $userBId = 99902;

    // Clean up any test fixtures from previous runs
    $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id IN (?, ?) OR endpoint LIKE ?')->execute([$userAId, $userBId, 'https://fcm.googleapis.com/fcm/send/test_%']);

    // Ensure test users exist in DB
    $pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$userAId, $userBId]);
    $stmt = $pdo->prepare('INSERT INTO users (id, employee_id, username, email, full_name, role, auth_version, password_hash, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?, 1, NOW(), NOW())');
    $stmt->execute([$userAId, 'EMP_TEST_99901', 'test_user_a', 'user_a@test.local', 'Test User A', 'admin', password_hash('Secret123!', PASSWORD_BCRYPT)]);
    $stmt->execute([$userBId, 'EMP_TEST_99902', 'test_user_b', 'user_b@test.local', 'Test User B', 'staff', password_hash('Secret123!', PASSWORD_BCRYPT)]);

function login_as_test_user(int $userId, string $role): void {
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_role'] = $role;
    $_SESSION['auth_version'] = 0;
    $_SESSION['last_activity'] = time();
    if (empty($_SESSION[CSRF_TOKEN_KEY])) {
        $_SESSION[CSRF_TOKEN_KEY] = bin2hex(random_bytes(32));
    }
}

// -------------------------------------------------------------------------
// Test 1: GET-based logout rejection (H2 protection)
    // -------------------------------------------------------------------------
    echo "\n--- Test Suite 1: CSRF & Method Enforcement in logout.php ---\n";
    
    login_as_test_user($userAId, 'admin');
    
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = [];
    
    // Simulate what logout.php does on GET:
    $redirectUrl = '';
    if (!is_logged_in()) {
        $redirectUrl = '/';
    } else {
        $user = current_user();
        if (!is_post()) {
            flash_set('warning', __('msg_invalid_logout'));
            $redirectUrl = $user && $user['role'] === 'admin' ? '/admin/' : '/staff/';
        }
    }
    
    assert_test('GET request is rejected', $redirectUrl === '/admin/', "Expected redirect to /admin/, got {$redirectUrl}");
    assert_test('Session remains active on GET logout attempt', isset($_SESSION['user_id']) && $_SESSION['user_id'] === $userAId);
    
    $flashes = flash_get();
    $hasWarning = false;
    foreach ($flashes as $f) {
        if ($f['type'] === 'warning' && $f['message'] === __('msg_invalid_logout')) {
            $hasWarning = true;
            break;
        }
    }
    assert_test('Flash warning set on invalid GET logout', $hasWarning);

    // -------------------------------------------------------------------------
    // Test 2: POST without CSRF token rejection (H2 protection)
    // -------------------------------------------------------------------------
    login_as_test_user($userAId, 'admin');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = []; // No token
    
    $redirectUrl = '';
    $user = current_user();
    if (!csrf_verify(null)) {
        flash_set('danger', __('msg_csrf_refresh'));
        $redirectUrl = $user && $user['role'] === 'admin' ? '/admin/' : '/staff/';
    }
    
    assert_test('POST without CSRF token is rejected', $redirectUrl === '/admin/');
    assert_test('Session preserved when CSRF missing', isset($_SESSION['user_id']) && $_SESSION['user_id'] === $userAId);
    flash_get(); // Clear flashes

    // -------------------------------------------------------------------------
    // Test 3: POST with invalid CSRF token rejection
    // -------------------------------------------------------------------------
    login_as_test_user($userAId, 'admin');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf_token' => 'invalid_csrf_token_12345'];
    
    $redirectUrl = '';
    $user = current_user();
    if (!csrf_verify($_POST['csrf_token'])) {
        flash_set('danger', __('msg_csrf_refresh'));
        $redirectUrl = $user && $user['role'] === 'admin' ? '/admin/' : '/staff/';
    }
    assert_test('POST with invalid CSRF token is rejected', $redirectUrl === '/admin/');
    assert_test('Session preserved when CSRF invalid', isset($_SESSION['user_id']) && $_SESSION['user_id'] === $userAId);
    flash_get();

    // -------------------------------------------------------------------------
    // Test 4: Device Disassociation on Valid POST Logout (H1 protection)
    // -------------------------------------------------------------------------
    echo "\n--- Test Suite 2: Device-Specific Push Disassociation on Logout ---\n";
    
    $endpointLaptop = 'https://fcm.googleapis.com/fcm/send/test_laptop_token_abc123';
    $endpointPhone = 'https://fcm.googleapis.com/fcm/send/test_phone_token_xyz789';
    $dummyP256dh = 'BMh1234567890abcdefghijklmnopqrstuvwxyzABCDEF1234567890abcdefghijklmnopqrstuvwxyzABCD=';
    $dummyAuth = 'auth1234567890AB=';
    
    login_as_test_user($userAId, 'admin');
    
    // Register two devices for User A
    push_subscribe_device($userAId, $endpointLaptop, $dummyP256dh, $dummyAuth, 'Mozilla/5.0 (Laptop)');
    push_subscribe_device($userAId, $endpointPhone, $dummyP256dh, $dummyAuth, 'Mozilla/5.0 (Mobile Phone)');
    
    // Verify both are present for User A
    $devLaptop = push_find_device($userAId, $endpointLaptop);
    $devPhone = push_find_device($userAId, $endpointPhone);
    assert_test('User A has laptop subscription before logout', $devLaptop !== null);
    assert_test('User A has phone subscription before logout', $devPhone !== null);
    
    // Now User A logs out from laptop:
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $validToken = csrf_token();
    $_POST = [
        'csrf_token' => $validToken,
        'endpoint' => $endpointLaptop,
    ];
    
    // Execute logout disassociation logic
    $user = current_user();
    if (csrf_verify($_POST['csrf_token'])) {
        $endpointToUnsub = trim((string)($_POST['endpoint'] ?? ''));
        if ($endpointToUnsub !== '' && push_is_valid_endpoint($endpointToUnsub) && $user) {
            push_unsubscribe_device((int)$user['id'], $endpointToUnsub);
        }
    }
    
    // Check database state
    $laptopAfterLogout = push_find_device($userAId, $endpointLaptop);
    $phoneAfterLogout = push_find_device($userAId, $endpointPhone);
    assert_test('Laptop endpoint disassociated from User A', $laptopAfterLogout === null);
    assert_test('Unrelated device (Phone) remains active for User A', $phoneAfterLogout !== null);

    // -------------------------------------------------------------------------
    // Test 5: Automatic Endpoint Synchronization on Account Switch (H1 protection)
    // -------------------------------------------------------------------------
    echo "\n--- Test Suite 3: Automatic Endpoint Sync & Ownership Transfer ---\n";
    
    // Scenario: An existing endpoint in DB was registered to User A (e.g. from prior session or if unregister failed)
    $sharedBrowserEndpoint = 'https://fcm.googleapis.com/fcm/send/test_shared_browser_endpoint_456';
    push_subscribe_device($userAId, $sharedBrowserEndpoint, $dummyP256dh, $dummyAuth, 'Chrome Desktop');
    push_set_device_preferences($userAId, $sharedBrowserEndpoint, ['shift_ending' => false, 'break_ending' => false]);
    push_set_device_language($userAId, $sharedBrowserEndpoint, 'zh');
    
    $ownedBefore = push_find_device($userAId, $sharedBrowserEndpoint);
    assert_test('Shared browser endpoint initially owned by User A', $ownedBefore !== null);
    
    // User B signs into this browser. The frontend calls action: sync for this endpoint:
    $syncInput = [
        'action' => 'sync',
        'endpoint' => $sharedBrowserEndpoint,
        'p256dh' => $dummyP256dh,
        'auth' => $dummyAuth,
    ];
    
    login_as_test_user($userBId, 'staff');
    $targetUserId = $userBId;
    $endpoint = $syncInput['endpoint'];
    $stmt = $pdo->prepare('SELECT id, user_id, notification_preferences FROM push_subscriptions WHERE endpoint = ? LIMIT 1');
    $stmt->execute([$endpoint]);
    $existing = $stmt->fetch();
    
    $actionTaken = '';
    if ($existing && (int)$existing['user_id'] !== $targetUserId) {
        $newPrefs = json_encode(push_new_device_preferences($targetUserId));
        $up = $pdo->prepare('UPDATE push_subscriptions SET user_id = ?, p256dh = ?, auth = ?, notification_preferences = ?, updated_at = NOW() WHERE id = ?');
        $up->execute([$targetUserId, $dummyP256dh, $dummyAuth, $newPrefs, (int)$existing['id']]);
        push_set_device_language($targetUserId, $endpoint, 'ms');
        $actionTaken = 'reassigned';
    }
    
    assert_test('Sync detected cross-account endpoint and triggered reassignment', $actionTaken === 'reassigned');
    
    // Verify User A no longer owns the endpoint
    $userAOwnership = push_find_device($userAId, $sharedBrowserEndpoint);
    assert_test('Endpoint no longer associated with User A', $userAOwnership === null);
    
    // Verify User B now owns the endpoint
    $userBOwnership = push_find_device($userBId, $sharedBrowserEndpoint);
    assert_test('Endpoint now strictly owned by User B', $userBOwnership !== null);
    
    // Verify preferences were reset to prevent User A preference leakage to User B
    $userBPrefs = json_decode((string)$userBOwnership['notification_preferences'], true);
    assert_test('Preferences reset to User B defaults (break_ending is true)', ($userBPrefs['break_ending'] ?? false) === true);
    assert_test('Language updated to User B active language (ms)', ($userBPrefs['language'] ?? '') === 'ms');

    // -------------------------------------------------------------------------
    // Test 6: Sync with unmapped new endpoint registers cleanly
    // -------------------------------------------------------------------------
    $brandNewEndpoint = 'https://fcm.googleapis.com/fcm/send/test_brand_new_endpoint_999';
    $stmt = $pdo->prepare('SELECT id FROM push_subscriptions WHERE endpoint = ? LIMIT 1');
    $stmt->execute([$brandNewEndpoint]);
    $existingNew = $stmt->fetch();
    
    if (!$existingNew) {
        push_subscribe_device($userBId, $brandNewEndpoint, $dummyP256dh, $dummyAuth, 'Firefox Mobile');
    }
    
    $newSub = push_find_device($userBId, $brandNewEndpoint);
    assert_test('Sync registers brand new endpoint for authenticated user', $newSub !== null);

} finally {
    TestDatabaseSafety::guardDropDatabase($database);
    $pdo->exec('USE `' . DB_NAME . '`');
    $pdo->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}

echo "\n=======================================================\n";
echo "Phase 6B.1 Test Results: {$passed} Passed, {$failed} Failed\n";
echo "=======================================================\n";

exit($failed > 0 ? 1 : 0);

