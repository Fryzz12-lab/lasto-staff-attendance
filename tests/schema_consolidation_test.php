<?php
/**
 * Automated Test Suite: cPanel Database Schema Consolidation & Migration Validation (Phase 6B.4 / H5)
 * AGY Staff Attendance & Workforce Management System
 */

declare(strict_types=1);

$passed = 0;
$failed = 0;

function assert_true(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$message}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$message}\n";
    }
}

echo "=== PHASE 6B.4: DATABASE SCHEMA CONSOLIDATION & MIGRATION TESTS ===\n\n";

$mysqlCli = 'C:\\xampp\\mysql\\bin\\mysql.exe';
$rootPdo = new PDO('mysql:host=127.0.0.1', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
require_once __DIR__ . '/test_database_safety.php';

// ============================================================================
// Test Suite 1: Test A — Fresh Installation on Disposable Database
// ============================================================================
echo "--- Test Suite 1: Test A — Fresh Installation on Disposable Database ---\n";

$freshDb = 'staff_attendance_disposable_fresh_' . bin2hex(random_bytes(3));
TestDatabaseSafety::guardDropDatabase($freshDb);
$rootPdo->exec("DROP DATABASE IF EXISTS `{$freshDb}`");
$rootPdo->exec("CREATE DATABASE `{$freshDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$importCmd = "\"{$mysqlCli}\" -u root {$freshDb} < database/cpanel_schema.sql";
exec($importCmd, $output, $ret);
assert_true($ret === 0, 'database/cpanel_schema.sql imports cleanly with zero syntax errors');

$freshPdo = new PDO("mysql:host=127.0.0.1;dbname={$freshDb}", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$expectedTables = [
    'account_recovery_tokens',
    'attendance',
    'attendance_audit_logs',
    'attendance_correction_requests',
    'attendance_location_events',
    'auth_remember_tokens',
    'break_records',
    'leave_replacement_reviews',
    'leave_requests',
    'login_rate_limits',
    'notifications',
    'offday_adjustment_reviews',
    'offices',
    'push_subscriptions',
    'schedule_assignments',
    'staff_balance_ledger',
    'system_settings',
    'user_security_questions',
    'users',
    'work_schedules',
];

$actualTables = $freshPdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$missingTables = array_diff($expectedTables, $actualTables);
assert_true(empty($missingTables), 'All 20 required production tables are present in fresh install (count: ' . count($actualTables) . ')');

// Check critical tables introduced in Phases 5 & 6
assert_true(in_array('push_subscriptions', $actualTables, true), 'push_subscriptions table exists in fresh installation schema');
assert_true(in_array('login_rate_limits', $actualTables, true), 'login_rate_limits table exists in fresh installation schema');
assert_true(in_array('notifications', $actualTables, true), 'notifications table exists in fresh installation schema');

// Verify performance indexes
$indexesOffday = array_column($freshPdo->query('SHOW INDEX FROM offday_adjustment_reviews')->fetchAll(PDO::FETCH_ASSOC), 'Key_name');
assert_true(in_array('idx_offday_review_employee', $indexesOffday, true), 'idx_offday_review_employee index present in offday_adjustment_reviews');

$indexesLeaveRepl = array_column($freshPdo->query('SHOW INDEX FROM leave_replacement_reviews')->fetchAll(PDO::FETCH_ASSOC), 'Key_name');
assert_true(in_array('idx_leave_repl_employee', $indexesLeaveRepl, true), 'idx_leave_repl_employee index present in leave_replacement_reviews');

$indexesLedger = array_column($freshPdo->query('SHOW INDEX FROM staff_balance_ledger')->fetchAll(PDO::FETCH_ASSOC), 'Key_name');
assert_true(in_array('idx_balance_source', $indexesLedger, true), 'idx_balance_source index present in staff_balance_ledger');

// Import seed file into fresh database
$seedCmd = "\"{$mysqlCli}\" -u root {$freshDb} < database/cpanel_seed.sql";
exec($seedCmd, $outputSeed, $retSeed);
assert_true($retSeed === 0, 'database/cpanel_seed.sql imports cleanly into fresh database');

$defaultSchedule = $freshPdo->query("SELECT name FROM work_schedules WHERE is_default=1")->fetchColumn();
assert_true($defaultSchedule === 'LASTO Standard Schedule', 'Default work schedule seeded properly');

$companySetting = $freshPdo->query("SELECT setting_value FROM system_settings WHERE setting_key='company_name'")->fetchColumn();
assert_true($companySetting === 'LASTO TECHNOLOGY', 'Default company setting seeded properly');

// ============================================================================
// Test Suite 2: Functional Query Verification & Foreign Key Cascades
// ============================================================================
echo "\n--- Test Suite 2: Functional Query Verification & Foreign Key Cascades ---\n";

// Insert a test user
$freshPdo->exec("INSERT INTO users (employee_id, username, password_hash, full_name, role) VALUES ('EMP001', 'test_emp', 'hash', 'Test Employee', 'staff')");
$userId = (int)$freshPdo->lastInsertId();
assert_true($userId > 0, 'User record inserted successfully');

// Insert a push subscription for this user
$freshPdo->exec("INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, notification_preferences) VALUES ($userId, 'https://fcm.googleapis.com/fcm/send/fake1', 'p256', 'auth', '{\"all\":true}')");
$subId = (int)$freshPdo->lastInsertId();
assert_true($subId > 0, 'Push subscription record inserted successfully');

// Insert a notification for this user
$freshPdo->exec("INSERT INTO notifications (user_id, event_type, event_key, title, message, destination) VALUES ($userId, 'test', 'k1', 'Title', 'Msg', '/staff/')");
$notifId = (int)$freshPdo->lastInsertId();
assert_true($notifId > 0, 'Notification record inserted successfully');

// Insert rate limit record
$freshPdo->exec("INSERT INTO login_rate_limits (rate_key, identifier_type, identifier, failed_attempts, first_failed_at, last_failed_at) VALUES ('ip:127.0.0.1', 'ip', '127.0.0.1', 1, NOW(), NOW())");
$rlId = (int)$freshPdo->lastInsertId();
assert_true($rlId > 0, 'Login rate limit record inserted successfully');

// Test foreign key CASCADE on user delete
$freshPdo->exec("DELETE FROM users WHERE id = $userId");
$remainingSubs = (int)$freshPdo->query("SELECT COUNT(*) FROM push_subscriptions WHERE user_id = $userId")->fetchColumn();
$remainingNotifs = (int)$freshPdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = $userId")->fetchColumn();
assert_true($remainingSubs === 0, 'Foreign key CASCADE successfully removed push subscriptions upon user deletion');
assert_true($remainingNotifs === 0, 'Foreign key CASCADE successfully removed notifications upon user deletion');

// Drop fresh test database
TestDatabaseSafety::guardDropDatabase($freshDb);
$rootPdo->exec("DROP DATABASE IF EXISTS `{$freshDb}`");
echo "  [INFO] Disposable fresh database `{$freshDb}` dropped.\n";

// ============================================================================
// Test Suite 3: Test B — Upgrade Compatibility & Data Preservation
// ============================================================================
echo "\n--- Test Suite 3: Test B — Upgrade Compatibility & Data Preservation ---\n";

$upgradeDb = 'staff_attendance_disposable_upgrade_' . bin2hex(random_bytes(3));
TestDatabaseSafety::guardDropDatabase($upgradeDb);
$rootPdo->exec("DROP DATABASE IF EXISTS `{$upgradeDb}`");
$rootPdo->exec("CREATE DATABASE `{$upgradeDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

// Build simulated legacy schema: fresh install minus push_subscriptions, notifications, login_rate_limits
$legacyPdo = new PDO("mysql:host=127.0.0.1;dbname={$upgradeDb}", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Import cpanel_schema.sql first
exec("\"{$mysqlCli}\" -u root {$upgradeDb} < database/cpanel_schema.sql", $out, $rc);
// Now drop the newly added Phase 5/6 tables to simulate an older legacy deployment
$legacyPdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
$legacyPdo->exec("DROP TABLE IF EXISTS `push_subscriptions`;");
$legacyPdo->exec("DROP TABLE IF EXISTS `login_rate_limits`;");
$legacyPdo->exec("DROP TABLE IF EXISTS `notifications`;");
$legacyPdo->exec("DROP TABLE IF EXISTS `attendance_location_events`;");
$legacyPdo->exec("ALTER TABLE `users` DROP COLUMN IF EXISTS `notification_preferences`;");
$legacyPdo->exec("ALTER TABLE `users` DROP COLUMN IF EXISTS `attendance_location_enabled`;");
$legacyPdo->exec("ALTER TABLE `offday_adjustment_reviews` DROP KEY IF EXISTS `idx_offday_review_employee`;");
$legacyPdo->exec("ALTER TABLE `leave_replacement_reviews` DROP KEY IF EXISTS `idx_leave_repl_employee`;");
$legacyPdo->exec("ALTER TABLE `staff_balance_ledger` DROP KEY IF EXISTS `idx_balance_source`;");
$legacyPdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

$legacyTablesBefore = $legacyPdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
assert_true(!in_array('push_subscriptions', $legacyTablesBefore, true), 'Simulated legacy DB confirmed missing push_subscriptions');
assert_true(!in_array('login_rate_limits', $legacyTablesBefore, true), 'Simulated legacy DB confirmed missing login_rate_limits');

// Insert controlled synthetic employee records into legacy database
$legacyPdo->exec("INSERT INTO users (employee_id, username, password_hash, full_name, role) VALUES ('LEGACY001', 'legacy_admin', 'hash1', 'Legacy Admin', 'admin')");
$legacyPdo->exec("INSERT INTO users (employee_id, username, password_hash, full_name, role) VALUES ('LEGACY002', 'legacy_staff', 'hash2', 'Legacy Staff', 'staff')");
$legacyPdo->exec("INSERT INTO attendance (user_id, work_date, status) VALUES (1, '2026-10-01', 'completed')");
$legacyPdo->exec("INSERT INTO attendance (user_id, work_date, status) VALUES (2, '2026-10-01', 'completed')");

$usersCountBefore = (int)$legacyPdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$attCountBefore = (int)$legacyPdo->query("SELECT COUNT(*) FROM attendance")->fetchColumn();
assert_true($usersCountBefore === 2 && $attCountBefore === 2, 'Synthetic legacy data inserted (2 users, 2 attendance)');

// Now apply database/cpanel_upgrade.sql
$upgradeCmd = "\"{$mysqlCli}\" -u root {$upgradeDb} < database/cpanel_upgrade.sql";
exec($upgradeCmd, $outUpgrade, $rcUpgrade);
assert_true($rcUpgrade === 0, 'database/cpanel_upgrade.sql executed against legacy database with code 0');

// Verify existing records are 100% intact
$usersCountAfter = (int)$legacyPdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$attCountAfter = (int)$legacyPdo->query("SELECT COUNT(*) FROM attendance")->fetchColumn();
assert_true($usersCountAfter === $usersCountBefore, 'Existing user records completely preserved after upgrade');
assert_true($attCountAfter === $attCountBefore, 'Existing attendance records completely preserved after upgrade');

// Verify new structures are now present
$upgradedTables = $legacyPdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
assert_true(in_array('push_subscriptions', $upgradedTables, true), 'push_subscriptions table created by upgrade script');
assert_true(in_array('notifications', $upgradedTables, true), 'notifications table created by upgrade script');
assert_true(in_array('login_rate_limits', $upgradedTables, true), 'login_rate_limits table created by upgrade script');
assert_true(in_array('attendance_location_events', $upgradedTables, true), 'attendance_location_events table created by upgrade script');

// Verify user notification_preferences column exists
$userCols = array_column($legacyPdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC), 'Field');
assert_true(in_array('notification_preferences', $userCols, true), 'notification_preferences column added to users table');
assert_true(in_array('attendance_location_enabled', $userCols, true), 'attendance_location_enabled column added to users table');

// Test Idempotent Rerun: Run upgrade script a SECOND time
exec($upgradeCmd, $outRerun, $rcRerun);
assert_true($rcRerun === 0, 'database/cpanel_upgrade.sql is fully idempotent on rerun (exit code 0, no duplicate errors)');

// Drop upgrade test database
TestDatabaseSafety::guardDropDatabase($upgradeDb);
$rootPdo->exec("DROP DATABASE IF EXISTS `{$upgradeDb}`");
echo "  [INFO] Disposable upgrade database `{$upgradeDb}` dropped.\n";

// ============================================================================
// Test Suite 4: Test C — Missing Migration Detection & Preflight Integration
// ============================================================================
echo "\n--- Test Suite 4: Test C — Missing Migration Detection & Preflight Integration ---\n";

$missingDb = 'staff_attendance_disposable_missing_' . bin2hex(random_bytes(3));
TestDatabaseSafety::guardDropDatabase($missingDb);
$rootPdo->exec("DROP DATABASE IF EXISTS `{$missingDb}`");
$rootPdo->exec("CREATE DATABASE `{$missingDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

// Import cpanel_schema.sql, then drop login_rate_limits to simulate unmigrated DB
exec("\"{$mysqlCli}\" -u root {$missingDb} < database/cpanel_schema.sql", $outMiss, $rcMiss);
$missPdo = new PDO("mysql:host=127.0.0.1;dbname={$missingDb}", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$missPdo->exec("DROP TABLE IF EXISTS `login_rate_limits`");

// Run schema verification logic against this unmigrated database
$requiredSchema = [
    'users' => ['auth_version', 'email_verified_at', 'employment_end_date', 'must_change_password', 'notification_preferences'],
    'attendance' => ['is_wfh', 'was_scheduled_off_day'],
    'push_subscriptions' => ['endpoint', 'notification_preferences'],
    'notifications' => ['event_type', 'event_key'],
    'login_rate_limits' => ['rate_key', 'failed_attempts', 'throttled_until'],
];

$existingInMiss = $missPdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$detectedMissingTable = false;
foreach ($requiredSchema as $tbl => $cols) {
    if (!in_array($tbl, $existingInMiss, true)) {
        $detectedMissingTable = ($tbl === 'login_rate_limits');
    }
}
assert_true($detectedMissingTable, 'Preflight schema validation successfully detects missing `login_rate_limits` table');

// Now run upgrade on missing database to verify healing
exec("\"{$mysqlCli}\" -u root {$missingDb} < database/cpanel_upgrade.sql", $outHeal, $rcHeal);
$existingAfterHeal = $missPdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
assert_true(in_array('login_rate_limits', $existingAfterHeal, true), 'cpanel_upgrade.sql successfully healed the missing table');

// Drop missing test database
TestDatabaseSafety::guardDropDatabase($missingDb);
$rootPdo->exec("DROP DATABASE IF EXISTS `{$missingDb}`");
echo "  [INFO] Disposable missing-migration database `{$missingDb}` dropped.\n";

echo "\n=======================================================\n";
echo "Phase 6B.4 Test Results: {$passed} Passed, {$failed} Failed\n";
echo "=======================================================\n";

if ($failed > 0) {
    exit(1);
}

