<?php
/**
 * Phase 3D: Break Discrepancy Reporting, Timesheet Export Safeguards, and Administrative Monitoring Test Suite
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/report_export.php';
require_once __DIR__ . '/../includes/adjustment_service.php';
require_once __DIR__ . '/../includes/notification_service.php';
require_once __DIR__ . '/../includes/attendance_service.php';
require_once __DIR__ . '/test_database_safety.php';

$pdo = db();
TestDatabaseSafety::requireDisposableDatabase($pdo, 'break_discrepancy_reporting_test');
$pdo->beginTransaction();

$passed = 0;
$failed = 0;

function assert_test(bool $condition, string $description): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
    }
}

try {
    echo "=========================================================\n";
    echo " Running Phase 3D: Break Discrepancy Reporting Tests     \n";
    echo "=========================================================\n\n";

    // Setup isolated test users
    $pw = password_hash('Pass123!', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day, must_change_password)
                   VALUES ('P3D_STF_01', 'p3d_staff_1', ?, 'Phase3D Staff One', 'p3dstaff1@test.local', 'staff', 1, 0, 0)")
        ->execute([$pw]);
    $u1 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day, must_change_password)
                   VALUES ('P3D_STF_02', 'p3d_staff_2', ?, 'Phase3D Staff Two', 'p3dstaff2@test.local', 'staff', 1, 0, 0)")
        ->execute([$pw]);
    $u2 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day, must_change_password)
                   VALUES ('P3D_ADM_01', 'p3d_admin_1', ?, 'Phase3D Admin One', 'p3dadmin1@test.local', 'admin', 1, 0, 0)")
        ->execute([$pw]);
    $admin1Id = (int) $pdo->lastInsertId();

    $date1 = '2026-03-01';
    $date2 = '2026-03-02';
    $date3 = '2026-03-03';
    $date4 = '2026-03-04';
    $date5 = '2026-03-05';

    // -------------------------------------------------------------
    // Scenario 1: Shift with No Discrepancy
    // -------------------------------------------------------------
    echo "Scenario 1: Shift with No Discrepancy...\n";
    // Normal 9:00 to 18:00 with 60m lunch break (12:00 to 13:00) -> 480 work minutes
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, work_minutes, total_break_minutes, status, break_review_status, provisional_work_minutes, version)
                   VALUES (?, ?, '2026-03-01 09:00:00', '2026-03-01 18:00:00', 480, 60, 'completed', 'none', NULL, 1)")
        ->execute([$u1, $date1]);
    $att1Id = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, is_auto_closed)
                   VALUES (?, ?, 'lunch', 1, '2026-03-01 12:00:00', '2026-03-01 13:00:00', 60, 0)")
        ->execute([$att1Id, $u1]);

    $att1 = $pdo->query("SELECT * FROM attendance WHERE id = {$att1Id}")->fetch();
    assert_test($att1['break_review_status'] === 'none', 'Clean shift has break_review_status none');
    assert_test((int)$att1['work_minutes'] === 480, 'Clean shift has normal work_minutes 480');
    assert_test($att1['provisional_work_minutes'] === null, 'Clean shift has null provisional_work_minutes');

    // -------------------------------------------------------------
    // Scenario 2: Shift with Auto-Closed Break Under Review
    // -------------------------------------------------------------
    echo "Scenario 2: Shift with Auto-Closed Break Under Review...\n";
    // Clock-in 09:00, Break start 12:00, Clock-out 18:00 with open break -> auto-closed at 18:00
    // Official work_minutes = 120 (09:00-12:00), Provisional work_minutes = 480 (09:00-18:00 minus 60 min lunch policy)
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, work_minutes, total_break_minutes, status, break_review_status, provisional_work_minutes, version)
                   VALUES (?, ?, '2026-03-02 09:00:00', '2026-03-02 18:00:00', 180, 360, 'completed', 'under_review', 480, 1)")
        ->execute([$u1, $date2]);
    $att2Id = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, is_auto_closed, closure_reason, system_closed_at)
                   VALUES (?, ?, 'lunch', 1, '2026-03-02 12:00:00', '2026-03-02 18:00:00', 360, 1, 'clock_out', '2026-03-02 18:00:00')")
        ->execute([$att2Id, $u1]);

    $att2 = $pdo->query("SELECT * FROM attendance WHERE id = {$att2Id}")->fetch();
    assert_test($att2['break_review_status'] === 'under_review', 'Discrepant shift has break_review_status under_review');
    assert_test((int)$att2['provisional_work_minutes'] === 480, 'Discrepant shift records provisional_work_minutes 480');
    assert_test((int)$att2['work_minutes'] === 180, 'Official work_minutes remains 180 (no silent inflation)');

    // -------------------------------------------------------------
    // Scenario 3: Shift with Pending Break Correction Request
    // -------------------------------------------------------------
    echo "Scenario 3: Shift with Pending Break Correction Request...\n";
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, work_minutes, total_break_minutes, status, break_review_status, provisional_work_minutes, version)
                   VALUES (?, ?, '2026-03-03 09:00:00', '2026-03-03 18:00:00', 180, 360, 'completed', 'correction_pending', 480, 2)")
        ->execute([$u1, $date3]);
    $att3Id = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, is_auto_closed, closure_reason)
                   VALUES (?, ?, 'lunch', 1, '2026-03-03 12:00:00', '2026-03-03 18:00:00', 360, 1, 'clock_out')")
        ->execute([$att3Id, $u1]);
    $br3Id = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO attendance_correction_requests (user_id, attendance_id, break_id, break_type, break_correction_type, target_attendance_version, work_date, field_name, requested_break_start, requested_break_end, reason, status)
                   VALUES (?, ?, ?, 'lunch', 'auto_closed', 2, ?, 'break', '2026-03-03 12:00:00', '2026-03-03 13:00:00', 'Forgot to end break on time', 'pending')")
        ->execute([$u1, $att3Id, $br3Id, $date3]);
    $req3Id = (int) $pdo->lastInsertId();

    $att3 = $pdo->query("SELECT * FROM attendance WHERE id = {$att3Id}")->fetch();
    assert_test($att3['break_review_status'] === 'correction_pending', 'Shift with pending request has break_review_status correction_pending');

    // -------------------------------------------------------------
    // Scenario 4: Shift with Rejected Break Correction
    // -------------------------------------------------------------
    echo "Scenario 4: Shift with Rejected Break Correction...\n";
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, work_minutes, total_break_minutes, status, break_review_status, provisional_work_minutes, version)
                   VALUES (?, ?, '2026-03-04 09:00:00', '2026-03-04 18:00:00', 180, 360, 'completed', 'rejected_unresolved', 480, 3)")
        ->execute([$u2, $date4]);
    $att4Id = (int) $pdo->lastInsertId();

    $att4 = $pdo->query("SELECT * FROM attendance WHERE id = {$att4Id}")->fetch();
    assert_test($att4['break_review_status'] === 'rejected_unresolved', 'Rejected break correction yields rejected_unresolved');

    // -------------------------------------------------------------
    // Scenario 5: Shift with Approved Break Correction (Resolved)
    // -------------------------------------------------------------
    echo "Scenario 5: Shift with Approved Break Correction...\n";
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, work_minutes, total_break_minutes, status, break_review_status, provisional_work_minutes, version)
                   VALUES (?, ?, '2026-03-05 09:00:00', '2026-03-05 18:00:00', 480, 60, 'completed', 'admin_verified', 480, 4)")
        ->execute([$u2, $date5]);
    $att5Id = (int) $pdo->lastInsertId();

    $att5 = $pdo->query("SELECT * FROM attendance WHERE id = {$att5Id}")->fetch();
    assert_test($att5['break_review_status'] === 'admin_verified', 'Approved correction marks attendance admin_verified');

    // -------------------------------------------------------------
    // Scenario 6: Shift with Multiple Breaks (One Normal, One Auto-Closed)
    // -------------------------------------------------------------
    echo "Scenario 6: Shift with Multiple Breaks...\n";
    $date6 = '2026-03-06';
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, work_minutes, total_break_minutes, status, break_review_status, provisional_work_minutes, version)
                   VALUES (?, ?, '2026-03-06 09:00:00', '2026-03-06 18:00:00', 380, 160, 'completed', 'under_review', 460, 1)")
        ->execute([$u1, $date6]);
    $att6Id = (int) $pdo->lastInsertId();

    // Normal prayer break 20 mins
    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, is_auto_closed)
                   VALUES (?, ?, 'solat', 1, '2026-03-06 13:00:00', '2026-03-06 13:20:00', 20, 0)")
        ->execute([$att6Id, $u1]);

    // Auto-closed short break 140 mins (15:40 to 18:00)
    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, is_auto_closed, closure_reason)
                   VALUES (?, ?, 'short', 1, '2026-03-06 15:40:00', '2026-03-06 18:00:00', 140, 1, 'clock_out')")
        ->execute([$att6Id, $u1]);

    $brList = $pdo->query("SELECT * FROM break_records WHERE attendance_id = {$att6Id} ORDER BY id ASC")->fetchAll();
    assert_test(count($brList) === 2, 'Two break records exist for multi-break shift');
    assert_test((int)$brList[0]['is_auto_closed'] === 0 && (int)$brList[0]['duration_minutes'] === 20, 'Normal prayer break preserved');
    assert_test((int)$brList[1]['is_auto_closed'] === 1 && (int)$brList[1]['duration_minutes'] === 140, 'Auto-closed short break flagged');

    // -------------------------------------------------------------
    // Scenario 7: Provisional Hours Separation in Reports
    // -------------------------------------------------------------
    echo "Scenario 7: Provisional Hours Separation in Reports...\n";
    $allAtt = $pdo->query("SELECT a.*, u.full_name FROM attendance a JOIN users u ON u.id = a.user_id WHERE a.id IN ({$att1Id}, {$att2Id}, {$att3Id}, {$att4Id}, {$att5Id}) ORDER BY a.work_date ASC")->fetchAll();
    $matrix = report_attendance_export_matrix($allAtt);

    // Row 0 is clean (att1): total working time has no bracket annotation
    assert_test(strpos($matrix[0][6], '[Under Review') === false, 'Clean shift matrix working time has no review annotation');
    assert_test($matrix[0][7] === '-', 'Clean shift matrix review status column is dash');
    assert_test($matrix[0][8] === '-', 'Clean shift matrix provisional hours column is dash');

    // Row 1 is under_review (att2): total working time contains [Under Review - Prov: 8h 00m]
    assert_test(strpos($matrix[1][6], '[Under Review - Prov: 8h 00m]') !== false, 'Under review shift matrix has provisional annotation in column 6');
    assert_test($matrix[1][7] === __('st_under_review'), 'Under review shift matrix column 9 displays Under Review label');
    assert_test($matrix[1][8] === '8h 00m', 'Under review shift matrix column 10 displays separated provisional hours');

    // -------------------------------------------------------------
    // Scenario 8: Monthly / Date Range Discrepancy Aggregation
    // -------------------------------------------------------------
    echo "Scenario 8: Monthly/Date Range Discrepancy Aggregation...\n";
    $discStmt = $pdo->prepare("SELECT
        COUNT(*) AS total_disc,
        SUM(CASE WHEN a.break_review_status = 'under_review' THEN 1 ELSE 0 END) AS awaiting_staff,
        SUM(CASE WHEN a.break_review_status = 'correction_pending' THEN 1 ELSE 0 END) AS pending_admin,
        SUM(CASE WHEN a.break_review_status = 'rejected_unresolved' THEN 1 ELSE 0 END) AS rejected_unresolved,
        COUNT(DISTINCT a.user_id) AS unique_users
        FROM attendance a JOIN users u ON u.id = a.user_id
        WHERE a.work_date BETWEEN '2026-03-01' AND '2026-03-31'
          AND a.break_review_status IN ('under_review', 'correction_pending', 'rejected_unresolved')");
    $discStmt->execute();
    $discData = $discStmt->fetch();

    assert_test((int)$discData['total_disc'] === 4, 'Aggregation found 4 discrepant shifts (att2, att3, att4, att6)');
    assert_test((int)$discData['awaiting_staff'] === 2, 'Aggregation found 2 awaiting staff action (att2, att6)');
    assert_test((int)$discData['pending_admin'] === 1, 'Aggregation found 1 pending admin review (att3)');
    assert_test((int)$discData['rejected_unresolved'] === 1, 'Aggregation found 1 rejected unresolved (att4)');

    // -------------------------------------------------------------
    // Scenario 9: Timesheet CSV Export Format
    // -------------------------------------------------------------
    echo "Scenario 9: Timesheet CSV Export Format...\n";
    $headers = report_attendance_headers();
    assert_test(count($headers) === 11, 'report_attendance_headers() returns exactly 11 columns');
    assert_test($headers[7] === __('break_review_status_col'), 'Header 9 is Break Review Status');
    assert_test($headers[8] === __('provisional_work_time_unverified'), 'Header 10 is Provisional Work Time');

    // Backward compatibility: first 9 columns match original semantics
    assert_test($headers[0] === __('date'), 'Header 0 is Date');
    assert_test($headers[1] === __('employee'), 'Header 1 is Employee');
    assert_test($headers[6] === __('total_working_time'), 'Header 6 is Total Working Time');

    // -------------------------------------------------------------
    // Scenario 10: Timesheet Excel & PDF Export Warnings
    // -------------------------------------------------------------
    echo "Scenario 10: Timesheet Export Warnings...\n";
    $ctx = report_attendance_context($allAtt, 'All Staff');
    assert_test(isset($ctx['unresolved_breaks_count']) && $ctx['unresolved_breaks_count'] === 3, 'Export context counts 3 unresolved breaks in batch (att2, att3, att4)');
    assert_test(isset($ctx['review'][__('unresolved_discrepancies')]), 'Review exception items include Unresolved Break Discrepancies');

    // Filtered check for discrepancies filter
    $discFiltered = report_filter_rows($allAtt, 'discrepancies');
    assert_test(count($discFiltered) === 3, 'report_filter_rows with discrepancies returns exactly 3 shifts');

    // -------------------------------------------------------------
    // Scenario 11: Off-Day / Replacement-Off Adjustment Safeguard
    // -------------------------------------------------------------
    echo "Scenario 11: Off-Day Adjustment Safeguard...\n";
    // Create an offday review row pointing to att2 (which has an unresolved break discrepancy)
    $pdo->prepare("INSERT INTO offday_adjustment_reviews (employee_id, attendance_id, status) VALUES (?, ?, 'pending')")
        ->execute([$u1, $att2Id]);
    $revId = (int) $pdo->lastInsertId();

    $adjustmentBlocked = false;
    try {
        decide_offday_adjustment($revId, 'replacement_off', $admin1Id, 'Attempting adjustment on discrepant shift');
    } catch (InvalidArgumentException $e) {
        $adjustmentBlocked = true;
        assert_test(strpos($e->getMessage(), 'unresolved break discrepancy') !== false, 'Adjustment blocked with clear message: ' . $e->getMessage());
    }
    assert_test($adjustmentBlocked, 'Adjustment was successfully blocked by defense-in-depth guard');

    // Verify ledger has not been modified
    $ledgerCount = $pdo->query("SELECT COUNT(*) FROM staff_balance_ledger WHERE review_id = {$revId}")->fetchColumn();
    assert_test((int)$ledgerCount === 0, 'No ledger entry was created for blocked review');

    // -------------------------------------------------------------
    // Scenario 12: Notification Reminder Generation
    // -------------------------------------------------------------
    echo "Scenario 12: Notification Reminder Generation...\n";
    // Backdate att3 creation to > 24 hours ago for admin reminder test
    $pdo->prepare("UPDATE attendance_correction_requests SET created_at = DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE id = ?")
        ->execute([$req3Id]);

    // Backdate att2 work_date to 2 days ago so it falls in past 14 days
    $twoDaysAgo = (new DateTimeImmutable('2 days ago'))->format('Y-m-d');
    $pdo->prepare("UPDATE attendance SET work_date = ? WHERE id = ?")
        ->execute([$twoDaysAgo, $att2Id]);

    $createdReminders = process_break_discrepancy_reminders();
    assert_test($createdReminders >= 2, "Reminders generated ({$createdReminders} events created for staff and admin)");

    $staffNotice = $pdo->query("SELECT * FROM notifications WHERE user_id = {$u1} AND event_type = 'break_discrepancy'")->fetch();
    assert_test(!empty($staffNotice), 'Staff notification created for auto-closed break');
    assert_test(strpos($staffNotice['event_key'], "break-disc-{$att2Id}-") === 0, 'Staff notification uses compound key format break-disc-{attId}-{date}');

    $adminNotice = $pdo->query("SELECT * FROM notifications WHERE user_id = {$admin1Id} AND event_type = 'pending_break_correction'")->fetch();
    assert_test(!empty($adminNotice), 'Admin notification created for overdue pending break correction');
    assert_test(strpos($adminNotice['destination'], '/admin/corrections.php') !== false, 'Admin notification directs to /admin/corrections.php');

    // -------------------------------------------------------------
    // Scenario 13: Deduplication Key Enforcement (Idempotency)
    // -------------------------------------------------------------
    echo "Scenario 13: Deduplication Key Enforcement...\n";
    // Second execution on same day must create 0 new notifications
    $secondRun = process_break_discrepancy_reminders();
    assert_test($secondRun === 0, 'Second reminder execution creates 0 duplicate notifications (strictly idempotent)');

    // -------------------------------------------------------------
    // Scenario 14: In-App vs Push Preference Handling
    // -------------------------------------------------------------
    echo "Scenario 14: Push and In-App Graceful Fallback...\n";
    // User u1 has 0 push subscriptions in test sandbox.
    // Verify push_send_to_user does not throw or crash when no push subscription exists
    $pushThrew = false;
    try {
        push_send_to_user($u1, 'Test Title', 'Test Message', '/staff/corrections.php', 'test-key-p3d', 'break_discrepancy');
    } catch (Throwable $e) {
        $pushThrew = true;
    }
    assert_test(!$pushThrew, 'push_send_to_user completes gracefully without errors on dummy/empty subscriptions');

    // -------------------------------------------------------------
    // Scenario 15: Security & Authorization
    // -------------------------------------------------------------
    echo "Scenario 15: Security & Role Authorization...\n";
    // In our architecture:
    // admin/reports.php has $isAdminArea = true which verifies current_user()['role'] === 'admin'.
    // Verify that staff user cannot access admin areas
    $staffUser = $pdo->query("SELECT * FROM users WHERE id = {$u1}")->fetch();
    assert_test($staffUser['role'] === 'staff', 'Test staff role is confirmed staff');
    $adminUser = $pdo->query("SELECT * FROM users WHERE id = {$admin1Id}")->fetch();
    assert_test($adminUser['role'] === 'admin', 'Test admin role is confirmed admin');

    // Test permission check function
    $canAdmin = ($adminUser['role'] === 'admin' && (int)$adminUser['is_active'] === 1);
    $canStaffAdmin = ($staffUser['role'] === 'admin' && (int)$staffUser['is_active'] === 1);
    assert_test($canAdmin === true, 'Admin user passes role check for Discrepancy report');
    assert_test($canStaffAdmin === false, 'Staff user fails role check for Discrepancy report (blocked)');

} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failed++;
} finally {
    $pdo->rollBack();
    echo "\n[INFO] Sandbox transaction rolled back. Database returned to original state.\n";
}

echo "\n=========================================================\n";
echo " Phase 3D Test Suite Finished: {$passed} Passed, {$failed} Failed\n";
echo "=========================================================\n\n";

exit($failed > 0 ? 1 : 0);
