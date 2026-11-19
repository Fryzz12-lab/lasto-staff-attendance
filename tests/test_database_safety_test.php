<?php
/**
 * Automated Test Suite for Test Database Safety & Isolation Guard
 */

declare(strict_types=1);

require_once __DIR__ . '/test_database_safety.php';
require_once __DIR__ . '/../config/database.php';

$checks = 0;
$passed = 0;

function assert_guard(bool $condition, string $description): void
{
    global $checks, $passed;
    $checks++;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$description}\n";
    } else {
        echo "  [FAIL] {$description}\n";
    }
}

echo "=== TEST DATABASE SAFETY & ISOLATION VERIFICATION ===\n\n";

// 1. isProtected database classification
assert_guard(TestDatabaseSafety::isProtected('staff_attendance') === true, 'staff_attendance identified as protected');
assert_guard(TestDatabaseSafety::isProtected('staff_attendance_uat') === true, 'staff_attendance_uat identified as protected');
assert_guard(TestDatabaseSafety::isProtected('mysql') === true, 'system DB mysql identified as protected');
assert_guard(TestDatabaseSafety::isProtected('information_schema') === true, 'system DB information_schema identified as protected');
assert_guard(TestDatabaseSafety::isProtected('my_app_production') === true, 'production-suffixed DB identified as protected');
assert_guard(TestDatabaseSafety::isProtected('staff_attendance_disposable_fresh_a1b2c3') === false, 'disposable database identified as NOT protected');
assert_guard(TestDatabaseSafety::isProtected('staff_attendance_test_123456') === false, 'test database identified as NOT protected');
assert_guard(TestDatabaseSafety::isDisposableDatabase('staff_attendance_export_disposable_a1b2c3d4'), 'unique disposable export database name accepted');
assert_guard(TestDatabaseSafety::isDisposableDatabase('staff_attendance') === false, 'normal application database is not disposable');
assert_guard(TestDatabaseSafety::isDisposableDatabase('staff_attendance_export_disposable_live') === false, 'non-unique disposable-like database name rejected');

// 2. guardDropDatabase rejection of protected databases
$dropBlockedDev = false;
try {
    TestDatabaseSafety::guardDropDatabase('staff_attendance');
} catch (RuntimeException $e) {
    $dropBlockedDev = true;
}
assert_guard($dropBlockedDev, 'guardDropDatabase strictly blocks DROP on staff_attendance');

$dropBlockedUat = false;
try {
    TestDatabaseSafety::guardDropDatabase('staff_attendance_uat');
} catch (RuntimeException $e) {
    $dropBlockedUat = true;
}
assert_guard($dropBlockedUat, 'guardDropDatabase strictly blocks DROP on staff_attendance_uat');

$dropAllowedDisposable = false;
try {
    TestDatabaseSafety::guardDropDatabase('staff_attendance_disposable_fresh_123');
    $dropAllowedDisposable = true;
} catch (RuntimeException $e) {
    $dropAllowedDisposable = false;
}
assert_guard($dropAllowedDisposable, 'guardDropDatabase permits DROP on disposable databases');

// 3. guardSchemaChanges against active protected DB connection
$pdo = db();
$schemaBlockedOnDev = false;
try {
    TestDatabaseSafety::guardSchemaChanges($pdo, 'mock_schema_test');
} catch (RuntimeException $e) {
    $schemaBlockedOnDev = true;
}
assert_guard($schemaBlockedOnDev, 'guardSchemaChanges strictly blocks DDL on protected database connection');

// 4. requireTable auto-migration rejection against protected DB
$missingTableRejected = false;
try {
    TestDatabaseSafety::requireTable($pdo, 'table_that_does_not_exist_' . mt_rand(), 'mock_test');
} catch (RuntimeException $e) {
    $missingTableRejected = true;
}
assert_guard($missingTableRejected, 'requireTable strictly forbids automatic migration on protected database');

// 5. requireTable passes when table legitimately exists
$existingTableAllowed = false;
try {
    TestDatabaseSafety::requireTable($pdo, 'users', 'mock_test');
    TestDatabaseSafety::requireTable($pdo, 'login_rate_limits', 'mock_test');
    $existingTableAllowed = true;
} catch (RuntimeException $e) {
    $existingTableAllowed = false;
}
assert_guard($existingTableAllowed, 'requireTable passes when required table legitimately exists');

echo "\nTest Database Safety Guard: {$passed}/{$checks} passed.\n";
exit($passed === $checks ? 0 : 1);

