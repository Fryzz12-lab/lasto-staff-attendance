<?php
/**
 * Phase 3C: Administrator Break Correction Approval, Rejection and Reconciliation Test Suite
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db();
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
    echo " Running Phase 3C: Admin Break Correction Review Tests   \n";
    echo "=========================================================\n\n";

    // Setup isolated test users
    $pw = password_hash('Pass123!', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day, must_change_password)
                   VALUES ('P3C_STF_01', 'p3c_staff_1', ?, 'Phase3C Staff One', 'p3cstaff1@test.local', 'staff', 1, 0, 0)")
        ->execute([$pw]);
    $u1 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day, must_change_password)
                   VALUES ('P3C_STF_02', 'p3c_staff_2', ?, 'Phase3C Staff Two', 'p3cstaff2@test.local', 'staff', 1, 0, 0)")
        ->execute([$pw]);
    $u2 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day, must_change_password)
                   VALUES ('P3C_ADM_01', 'p3c_admin_1', ?, 'Phase3C Admin One', 'p3cadmin1@test.local', 'admin', 1, 0, 0)")
        ->execute([$pw]);
    $admin1Id = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day, must_change_password)
                   VALUES ('P3C_ADM_02', 'p3c_admin_2', ?, 'Phase3C Admin Two', 'p3cadmin2@test.local', 'admin', 1, 0, 0)")
        ->execute([$pw]);
    $admin2Id = (int) $pdo->lastInsertId();

    $pdo->exec("INSERT INTO schedule_assignments (user_id, schedule_id, effective_from) VALUES ($u1, 1, '2026-01-01')");
    $pdo->exec("INSERT INTO schedule_assignments (user_id, schedule_id, effective_from) VALUES ($u2, 1, '2026-01-01')");

    $workDate = (new DateTimeImmutable('yesterday'))->format('Y-m-d');

    // -------------------------------------------------------------
    // Scenario 1: Auto-Closed Break Approval (Type E)
    // -------------------------------------------------------------
    echo "Scenario 1: Auto-Closed Break Approval (Provenance Preserved, Discrepancy Resolved)...\n";
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, status, break_review_status, total_break_minutes, shift_minutes, work_minutes, provisional_work_minutes, version)
                   VALUES (?, ?, ?, ?, 'completed', 'under_review', 300, 570, 270, 510, 2)")
        ->execute([$u1, $workDate, "{$workDate} 08:30:00", "{$workDate} 18:00:00"]);
    $att1Id = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, allowed_minutes, exceeded, is_auto_closed, closure_reason, system_closed_at)
                   VALUES (?, ?, 'lunch', 1, ?, ?, 300, 60, 1, 1, 'clock_out', ?)")
        ->execute([$att1Id, $u1, "{$workDate} 13:00:00", "{$workDate} 18:00:00", "{$workDate} 18:00:00"]);
    $autoBreakId = (int) $pdo->lastInsertId();

    $reqSub = submit_break_correction_request($u1, $workDate, 'auto_closed', $autoBreakId, 'lunch', null, "{$workDate} 13:45:00", 'I returned at 1:45 PM');
    $req1Id = (int) $reqSub['id'];

    $revResult = review_attendance_correction_request($req1Id, 'approved', $admin1Id, 'Verified return logs');
    assert_test($revResult['decision'] === 'approved', 'Review result returned approved');

    $reqCheck = $pdo->query("SELECT * FROM attendance_correction_requests WHERE id = {$req1Id}")->fetch();
    assert_test($reqCheck['status'] === 'approved', 'Request record marked approved');
    assert_test((int)$reqCheck['reviewed_by'] === $admin1Id, 'Reviewed by correctly saved');
    assert_test($reqCheck['admin_note'] === 'Verified return logs', 'Admin note saved');

    $breakCheck = $pdo->query("SELECT * FROM break_records WHERE id = {$autoBreakId}")->fetch();
    assert_test((int)$breakCheck['is_auto_closed'] === 1, 'Break record is_auto_closed flag preserved as 1');
    assert_test($breakCheck['closure_reason'] === 'clock_out', 'Break closure_reason preserved');
    assert_test($breakCheck['system_closed_at'] === "{$workDate} 18:00:00", 'system_closed_at preserved');
    assert_test($breakCheck['start_time'] === "{$workDate} 13:00:00", 'Break start time preserved');
    assert_test($breakCheck['end_time'] === "{$workDate} 13:45:00", 'Break end time updated to approved requested time');
    assert_test((int)$breakCheck['duration_minutes'] === 45, 'Break duration recalculated to 45 mins');
    assert_test((int)$breakCheck['exceeded'] === 0, 'Exceeded flag reset to 0');

    $attCheck = $pdo->query("SELECT * FROM attendance WHERE id = {$att1Id}")->fetch();
    assert_test((int)$attCheck['version'] === 3, 'Attendance version atomically incremented to 3');
    assert_test((int)$attCheck['total_break_minutes'] === 45, 'Total break minutes recalculated to 45');
    assert_test((int)$attCheck['work_minutes'] === 525, 'Work minutes recalculated to 525 mins (570 - 45)');
    assert_test($attCheck['break_review_status'] === 'resolved', 'break_review_status transitioned to resolved');
    assert_test($attCheck['provisional_work_minutes'] === null, 'provisional_work_minutes cleared to NULL');

    $auditCheck = $pdo->query("SELECT * FROM attendance_audit_logs WHERE attendance_id = {$att1Id} AND action = 'break_corrected'")->fetch();
    assert_test(!empty($auditCheck), 'Audit log entry created for break_corrected');
    assert_test($auditCheck['field_name'] === 'break_end', 'Audit log field is break_end');
    assert_test($auditCheck['old_value'] === "{$workDate} 18:00:00", 'Audit log recorded old system-closed time');
    assert_test($auditCheck['new_value'] === "{$workDate} 13:45:00", 'Audit log recorded approved time');

    $notifCheck = $pdo->query("SELECT * FROM notifications WHERE user_id = {$u1} AND event_key = 'corr-reviewed-{$req1Id}'")->fetch();
    assert_test(!empty($notifCheck), 'Staff notification created for approved break');
    assert_test(str_contains($notifCheck['message'], 'approved'), 'Notification confirms approval');

    // -------------------------------------------------------------
    // Scenario 2: Missing Break Approval (Type D)
    // -------------------------------------------------------------
    echo "Scenario 2: Missing Break Approval (New break record inserted and totals updated)...\n";
    $missingSub = submit_break_correction_request($u1, $workDate, 'missing', null, 'short', "{$workDate} 10:30:00", "{$workDate} 10:40:00", 'Forgot to record morning tea break');
    $req2Id = (int) $missingSub['id'];

    review_attendance_correction_request($req2Id, 'approved', $admin1Id, 'Tea break confirmed');

    $req2Check = $pdo->query("SELECT * FROM attendance_correction_requests WHERE id = {$req2Id}")->fetch();
    assert_test($req2Check['status'] === 'approved', 'Missing break request approved');
    assert_test(!empty($req2Check['break_id']), 'break_id backfilled onto request row');
    $newBreakId = (int) $req2Check['break_id'];

    $newBreak = $pdo->query("SELECT * FROM break_records WHERE id = {$newBreakId}")->fetch();
    assert_test(!empty($newBreak), 'New break record inserted into break_records');
    assert_test($newBreak['break_type'] === 'short', 'New break type is short');
    assert_test($newBreak['start_time'] === "{$workDate} 10:30:00", 'New break start time is correct');
    assert_test($newBreak['end_time'] === "{$workDate} 10:40:00", 'New break end time is correct');
    assert_test((int)$newBreak['duration_minutes'] === 10, 'New break duration is 10 minutes');
    assert_test((int)$newBreak['allowed_minutes'] === 10, 'New break allowed minutes matches policy suggest_session (10)');
    assert_test((int)$newBreak['exceeded'] === 0, 'New break exceeded is 0');
    assert_test((int)$newBreak['is_auto_closed'] === 0, 'New break is not auto-closed');

    $attCheck2 = $pdo->query("SELECT * FROM attendance WHERE id = {$att1Id}")->fetch();
    assert_test((int)$attCheck2['version'] === 4, 'Attendance version incremented to 4');
    assert_test((int)$attCheck2['total_break_minutes'] === 55, 'Total break minutes updated to 55 (45 + 10)');
    assert_test((int)$attCheck2['work_minutes'] === 515, 'Work minutes recalculated to 515 mins (570 - 55)');

    $auditAdd = $pdo->query("SELECT * FROM attendance_audit_logs WHERE attendance_id = {$att1Id} AND action = 'break_added'")->fetch();
    assert_test(!empty($auditAdd), 'Audit log entry created for break_added');
    assert_test(str_contains($auditAdd['new_value'], 'short:'), 'Audit log records new break session details');

    // -------------------------------------------------------------
    // Scenario 3: Break Start-Only Approval (Type A)
    // -------------------------------------------------------------
    echo "Scenario 3: Break Start-Only Approval (Type A)...\n";
    $reqStartSub = submit_break_correction_request($u1, $workDate, 'start', $autoBreakId, 'lunch', "{$workDate} 12:45:00", null, 'Left 15 mins earlier for lunch');
    $reqStartId = (int) $reqStartSub['id'];

    review_attendance_correction_request($reqStartId, 'approved', $admin2Id, 'Approved start change');
    $breakA = $pdo->query("SELECT * FROM break_records WHERE id = {$autoBreakId}")->fetch();
    assert_test($breakA['start_time'] === "{$workDate} 12:45:00", 'Start time updated to 12:45:00');
    assert_test($breakA['end_time'] === "{$workDate} 13:45:00", 'End time untouched at 13:45:00');
    assert_test((int)$breakA['duration_minutes'] === 60, 'Duration updated to 60 mins');
    assert_test((int)$breakA['exceeded'] === 0, 'Exceeded flag remains 0');

    // -------------------------------------------------------------
    // Scenario 4: Break End-Only Approval (Type B)
    // -------------------------------------------------------------
    echo "Scenario 4: Break End-Only Approval (Type B)...\n";
    $reqEndSub = submit_break_correction_request($u1, $workDate, 'end', $autoBreakId, 'lunch', null, "{$workDate} 13:50:00", 'Returned 5 minutes later');
    $reqEndId = (int) $reqEndSub['id'];

    review_attendance_correction_request($reqEndId, 'approved', $admin2Id, 'Approved end change');
    $breakB = $pdo->query("SELECT * FROM break_records WHERE id = {$autoBreakId}")->fetch();
    assert_test($breakB['start_time'] === "{$workDate} 12:45:00", 'Start time untouched at 12:45:00');
    assert_test($breakB['end_time'] === "{$workDate} 13:50:00", 'End time updated to 13:50:00');
    assert_test((int)$breakB['duration_minutes'] === 65, 'Duration updated to 65 mins');
    assert_test((int)$breakB['exceeded'] === 1, 'Exceeded flag set to 1 (> 60 allowed)');

    // -------------------------------------------------------------
    // Scenario 5: Both-Times Approval (Type C)
    // -------------------------------------------------------------
    echo "Scenario 5: Both-Times Approval (Type C)...\n";
    $reqBothSub = submit_break_correction_request($u1, $workDate, 'both', $autoBreakId, 'lunch', "{$workDate} 12:30:00", "{$workDate} 13:30:00", 'Adjusted both times to exact lunch slot');
    $reqBothId = (int) $reqBothSub['id'];

    review_attendance_correction_request($reqBothId, 'approved', $admin1Id, 'Both times approved');
    $breakC = $pdo->query("SELECT * FROM break_records WHERE id = {$autoBreakId}")->fetch();
    assert_test($breakC['start_time'] === "{$workDate} 12:30:00", 'Start time updated to 12:30:00');
    assert_test($breakC['end_time'] === "{$workDate} 13:30:00", 'End time updated to 13:30:00');
    assert_test((int)$breakC['duration_minutes'] === 60, 'Duration updated to 60 mins');
    assert_test((int)$breakC['exceeded'] === 0, 'Exceeded flag back to 0');

    // -------------------------------------------------------------
    // Scenario 6: Rejection of Auto-Closed Break -> rejected_unresolved
    // -------------------------------------------------------------
    echo "Scenario 6: Rejection of Auto-Closed Break (Transitions to rejected_unresolved)...\n";
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, status, break_review_status, total_break_minutes, shift_minutes, work_minutes, provisional_work_minutes, version)
                   VALUES (?, ?, ?, ?, 'completed', 'under_review', 300, 540, 240, 480, 1)")
        ->execute([$u2, $workDate, "{$workDate} 09:00:00", "{$workDate} 18:00:00"]);
    $att2Id = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, allowed_minutes, exceeded, is_auto_closed, closure_reason, system_closed_at)
                   VALUES (?, ?, 'lunch', 1, ?, ?, 300, 60, 1, 1, 'clock_out', ?)")
        ->execute([$att2Id, $u2, "{$workDate} 13:00:00", "{$workDate} 18:00:00", "{$workDate} 18:00:00"]);
    $autoBreak2Id = (int) $pdo->lastInsertId();

    $rejSub = submit_break_correction_request($u2, $workDate, 'auto_closed', $autoBreak2Id, 'lunch', null, "{$workDate} 13:30:00", 'I returned at 1:30 PM');
    $rejReqId = (int) $rejSub['id'];

    review_attendance_correction_request($rejReqId, 'rejected', $admin1Id, 'Door access logs do not corroborate 1:30 PM return');

    $rejReqCheck = $pdo->query("SELECT * FROM attendance_correction_requests WHERE id = {$rejReqId}")->fetch();
    assert_test($rejReqCheck['status'] === 'rejected', 'Request status is rejected');
    assert_test($rejReqCheck['admin_note'] === 'Door access logs do not corroborate 1:30 PM return', 'Rejection explanation recorded');

    $breakRej = $pdo->query("SELECT * FROM break_records WHERE id = {$autoBreak2Id}")->fetch();
    assert_test($breakRej['end_time'] === "{$workDate} 18:00:00", 'Break timestamps UNTOUCHED on rejection');
    assert_test((int)$breakRej['duration_minutes'] === 300, 'Break duration UNTOUCHED on rejection');

    $attRej = $pdo->query("SELECT * FROM attendance WHERE id = {$att2Id}")->fetch();
    assert_test((int)$attRej['version'] === 1, 'Attendance version UNTOUCHED on rejection');
    assert_test($attRej['break_review_status'] === 'rejected_unresolved', 'Attendance transitioned to rejected_unresolved');
    assert_test($attRej['provisional_work_minutes'] !== null, 'provisional_work_minutes remains active for discrepancy tracking');

    $rejAudit = $pdo->query("SELECT * FROM attendance_audit_logs WHERE attendance_id = {$att2Id} AND action = 'correction_rejected'")->fetch();
    assert_test(!empty($rejAudit), 'Audit log entry created for rejection');

    // -------------------------------------------------------------
    // Scenario 7: Optimistic Version Conflict Protection
    // -------------------------------------------------------------
    echo "Scenario 7: Optimistic Version Conflict Protection...\n";
    $confSub = submit_break_correction_request($u2, $workDate, 'auto_closed', $autoBreak2Id, 'lunch', null, "{$workDate} 14:00:00", 'Second attempt with gate ticket');
    $confReqId = (int) $confSub['id'];

    // Mutate attendance version externally
    $pdo->exec("UPDATE attendance SET version = version + 1 WHERE id = {$att2Id}");

    $versionConflictBlocked = false;
    try {
        review_attendance_correction_request($confReqId, 'approved', $admin1Id, 'Attempting approval on mutated record');
    } catch (InvalidArgumentException $e) {
        $versionConflictBlocked = str_contains($e->getMessage(), 'version conflict');
    }
    assert_test($versionConflictBlocked, 'Approval blocked with version conflict exception when record was mutated');

    $confReqPost = $pdo->query("SELECT * FROM attendance_correction_requests WHERE id = {$confReqId}")->fetch();
    assert_test($confReqPost['status'] === 'pending', 'Request remains pending after blocked approval');

    // -------------------------------------------------------------
    // Scenario 8: Administrator Self-Approval Block
    // -------------------------------------------------------------
    echo "Scenario 8: Administrator Self-Approval Prohibition...\n";
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, status, version)
                   VALUES (?, ?, ?, ?, 'completed', 1)")
        ->execute([$admin1Id, $workDate, "{$workDate} 09:00:00", "{$workDate} 18:00:00"]);
    $attAdminId = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, allowed_minutes, exceeded)
                   VALUES (?, ?, 'lunch', 1, ?, ?, 60, 60, 0)")
        ->execute([$attAdminId, $admin1Id, "{$workDate} 12:30:00", "{$workDate} 13:30:00"]);
    $adminBreakId = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO attendance_correction_requests (user_id, attendance_id, target_attendance_version, work_date, field_name, break_id, break_type, break_correction_type, requested_break_start, requested_break_end, reason, status)
                   VALUES (?, ?, 1, ?, 'break', ?, 'lunch', 'both', ?, ?, 'Admin request', 'pending')")
        ->execute([$admin1Id, $attAdminId, $workDate, $adminBreakId, "{$workDate} 12:00:00", "{$workDate} 13:00:00"]);
    $adminSelfReqId = (int) $pdo->lastInsertId();

    $selfApprovalBlocked = false;
    try {
        review_attendance_correction_request($adminSelfReqId, 'approved', $admin1Id, 'Self approving');
    } catch (InvalidArgumentException $e) {
        $selfApprovalBlocked = str_contains($e->getMessage(), 'cannot approve or reject their own');
    }
    assert_test($selfApprovalBlocked, 'Admin self-approval strictly blocked');

    // Other admin can review it:
    review_attendance_correction_request($adminSelfReqId, 'approved', $admin2Id, 'Independent admin approval');
    $adminSelfPost = $pdo->query("SELECT * FROM attendance_correction_requests WHERE id = {$adminSelfReqId}")->fetch();
    assert_test($adminSelfPost['status'] === 'approved', 'Different admin can approve administrator correction request');

    // -------------------------------------------------------------
    // Scenario 9: Duplicate Review Prevention
    // -------------------------------------------------------------
    echo "Scenario 9: Duplicate Review Prevention...\n";
    $duplicateBlocked = false;
    try {
        review_attendance_correction_request($adminSelfReqId, 'rejected', $admin2Id, 'Attempting second decision');
    } catch (InvalidArgumentException $e) {
        $duplicateBlocked = true;
    }
    assert_test($duplicateBlocked, 'Second review attempt on already reviewed request blocked');

    // -------------------------------------------------------------
    // Scenario 10: Shift Containment & Boundary Validation
    // -------------------------------------------------------------
    echo "Scenario 10: Shift Containment & Boundary Validation...\n";
    // Date: two days ago
    $twoDaysAgo = (new DateTimeImmutable('2 days ago'))->format('Y-m-d');
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, status, version)
                   VALUES (?, ?, ?, ?, 'completed', 1)")
        ->execute([$u1, $twoDaysAgo, "{$twoDaysAgo} 09:00:00", "{$twoDaysAgo} 17:00:00"]);
    $att3Id = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, allowed_minutes, exceeded)
                   VALUES (?, ?, 'lunch', 1, ?, ?, 60, 60, 0)")
        ->execute([$att3Id, $u1, "{$twoDaysAgo} 12:00:00", "{$twoDaysAgo} 13:00:00"]);
    $break3Id = (int) $pdo->lastInsertId();

    // A: Break before clock-in
    $pdo->prepare("INSERT INTO attendance_correction_requests (user_id, attendance_id, target_attendance_version, work_date, field_name, break_id, break_type, break_correction_type, requested_break_start, requested_break_end, reason, status)
                   VALUES (?, ?, 1, ?, 'break', ?, 'lunch', 'both', ?, ?, 'Before clock-in test', 'pending')")
        ->execute([$u1, $att3Id, $twoDaysAgo, $break3Id, "{$twoDaysAgo} 08:30:00", "{$twoDaysAgo} 09:30:00"]);
    $badReq1 = (int) $pdo->lastInsertId();

    $beforeClockInBlocked = false;
    try {
        review_attendance_correction_request($badReq1, 'approved', $admin1Id);
    } catch (InvalidArgumentException $e) {
        $beforeClockInBlocked = str_contains($e->getMessage(), 'cannot be before clock-in time');
    }
    assert_test($beforeClockInBlocked, 'Approval blocked if break start is before clock-in');

    // B: Break after clock-out
    $pdo->prepare("INSERT INTO attendance_correction_requests (user_id, attendance_id, target_attendance_version, work_date, field_name, break_id, break_type, break_correction_type, requested_break_start, requested_break_end, reason, status)
                   VALUES (?, ?, 1, ?, 'break', ?, 'lunch', 'both', ?, ?, 'After clock-out test', 'pending')")
        ->execute([$u1, $att3Id, $twoDaysAgo, $break3Id, "{$twoDaysAgo} 16:30:00", "{$twoDaysAgo} 17:30:00"]);
    $badReq2 = (int) $pdo->lastInsertId();

    $afterClockOutBlocked = false;
    try {
        review_attendance_correction_request($badReq2, 'approved', $admin1Id);
    } catch (InvalidArgumentException $e) {
        $afterClockOutBlocked = str_contains($e->getMessage(), 'cannot be after clock-out time');
    }
    assert_test($afterClockOutBlocked, 'Approval blocked if break end is after clock-out');

    // -------------------------------------------------------------
    // Scenario 11: Break Overlap Prevention on Approval
    // -------------------------------------------------------------
    echo "Scenario 11: Break Overlap Prevention on Approval...\n";
    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, allowed_minutes, exceeded)
                   VALUES (?, ?, 'solat', 1, ?, ?, 20, 20, 0)")
        ->execute([$att3Id, $u1, "{$twoDaysAgo} 14:00:00", "{$twoDaysAgo} 14:20:00"]);
    $solatBreakId = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO attendance_correction_requests (user_id, attendance_id, target_attendance_version, work_date, field_name, break_id, break_type, break_correction_type, requested_break_start, requested_break_end, reason, status)
                   VALUES (?, ?, 1, ?, 'break', ?, 'lunch', 'both', ?, ?, 'Overlap test', 'pending')")
        ->execute([$u1, $att3Id, $twoDaysAgo, $break3Id, "{$twoDaysAgo} 13:30:00", "{$twoDaysAgo} 14:10:00"]);
    $overlapReq = (int) $pdo->lastInsertId();

    $overlapBlocked = false;
    try {
        review_attendance_correction_request($overlapReq, 'approved', $admin1Id);
    } catch (InvalidArgumentException $e) {
        $overlapBlocked = str_contains($e->getMessage(), 'overlap');
    }
    assert_test($overlapBlocked, 'Approval strictly blocked if requested break overlaps an existing break');

    // -------------------------------------------------------------
    // Scenario 12: Category Limit Protection on Missing Break
    // -------------------------------------------------------------
    echo "Scenario 12: Category Limit Protection on Missing Break Approval...\n";
    $pdo->prepare("INSERT INTO attendance_correction_requests (user_id, attendance_id, target_attendance_version, work_date, field_name, break_id, break_type, break_correction_type, requested_break_start, requested_break_end, reason, status)
                   VALUES (?, ?, 1, ?, 'break', NULL, 'lunch', 'missing', ?, ?, 'Second lunch test', 'pending')")
        ->execute([$u1, $att3Id, $twoDaysAgo, "{$twoDaysAgo} 15:00:00", "{$twoDaysAgo} 15:30:00"]);
    $limitReq = (int) $pdo->lastInsertId();

    $limitBlocked = false;
    try {
        review_attendance_correction_request($limitReq, 'approved', $admin1Id);
    } catch (InvalidArgumentException $e) {
        $limitBlocked = str_contains($e->getMessage(), 'maximum of 1');
    }
    assert_test($limitBlocked, 'Approval of missing break blocked when category max sessions limit reached');

    // -------------------------------------------------------------
    // Scenario 13: Multiple Auto-Closed Breaks on Single Shift
    // -------------------------------------------------------------
    echo "Scenario 13: Multiple Auto-Closed Breaks (Partial vs Full Resolution)...\n";
    $threeDaysAgo = (new DateTimeImmutable('3 days ago'))->format('Y-m-d');
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, status, break_review_status, total_break_minutes, shift_minutes, work_minutes, provisional_work_minutes, version)
                   VALUES (?, ?, ?, ?, 'completed', 'under_review', 320, 540, 220, 460, 1)")
        ->execute([$u2, $threeDaysAgo, "{$threeDaysAgo} 09:00:00", "{$threeDaysAgo} 18:00:00"]);
    $attMultiId = (int) $pdo->lastInsertId();

    // Auto-closed Break 1 (lunch)
    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, allowed_minutes, exceeded, is_auto_closed, closure_reason, system_closed_at)
                   VALUES (?, ?, 'lunch', 1, ?, ?, 300, 60, 1, 1, 'clock_out', ?)")
        ->execute([$attMultiId, $u2, "{$threeDaysAgo} 13:00:00", "{$threeDaysAgo} 18:00:00", "{$threeDaysAgo} 18:00:00"]);
    $multiB1 = (int) $pdo->lastInsertId();

    // Auto-closed Break 2 (solat)
    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, allowed_minutes, exceeded, is_auto_closed, closure_reason, system_closed_at)
                   VALUES (?, ?, 'solat', 1, ?, ?, 20, 20, 0, 1, 'clock_out', ?)")
        ->execute([$attMultiId, $u2, "{$threeDaysAgo} 15:00:00", "{$threeDaysAgo} 15:20:00", "{$threeDaysAgo} 18:00:00"]);
    $multiB2 = (int) $pdo->lastInsertId();

    // Submit correction for Break 1 only
    $reqM1Sub = submit_break_correction_request($u2, $threeDaysAgo, 'auto_closed', $multiB1, 'lunch', null, "{$threeDaysAgo} 13:45:00", 'Lunch actual return');
    $reqM1Id = (int) $reqM1Sub['id'];

    review_attendance_correction_request($reqM1Id, 'approved', $admin1Id, 'Approved first break');

    $attMultiMid = $pdo->query("SELECT * FROM attendance WHERE id = {$attMultiId}")->fetch();
    assert_test($attMultiMid['break_review_status'] === 'under_review', 'Attendance remains under_review because Break 2 is still unverified');
    assert_test($attMultiMid['provisional_work_minutes'] !== null, 'provisional_work_minutes continues to track Break 2');

    // Submit and approve correction for Break 2
    $reqM2Sub = submit_break_correction_request($u2, $threeDaysAgo, 'auto_closed', $multiB2, 'solat', null, "{$threeDaysAgo} 15:15:00", 'Solat actual return');
    $reqM2Id = (int) $reqM2Sub['id'];

    review_attendance_correction_request($reqM2Id, 'approved', $admin1Id, 'Approved second break');

    $attMultiFinal = $pdo->query("SELECT * FROM attendance WHERE id = {$attMultiId}")->fetch();
    assert_test($attMultiFinal['break_review_status'] === 'resolved', 'Attendance transitioned to resolved once all auto-closed breaks approved');
    assert_test($attMultiFinal['provisional_work_minutes'] === null, 'provisional_work_minutes cleared to NULL');

    // -------------------------------------------------------------
    // Scenario 14: Phase 2 Punch Approval Still Works via review_attendance_correction_request
    // -------------------------------------------------------------
    echo "Scenario 14: Phase 2 Punch Approval Compatibility...\n";
    $fourDaysAgo = (new DateTimeImmutable('4 days ago'))->format('Y-m-d');
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, status, version)
                   VALUES (?, ?, ?, ?, 'completed', 1)")
        ->execute([$u1, $fourDaysAgo, "{$fourDaysAgo} 09:30:00", "{$fourDaysAgo} 18:00:00"]);
    $attPunchId = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO attendance_correction_requests (user_id, attendance_id, target_attendance_version, work_date, field_name, requested_clock_in, reason, status)
                   VALUES (?, ?, 1, ?, 'clock_in', ?, 'Traffic delay corrected', 'pending')")
        ->execute([$u1, $attPunchId, $fourDaysAgo, "{$fourDaysAgo} 09:00:00"]);
    $reqPunchId = (int) $pdo->lastInsertId();

    review_attendance_correction_request($reqPunchId, 'approved', $admin1Id, 'Punch correction approved');
    $attPunchPost = $pdo->query("SELECT * FROM attendance WHERE id = {$attPunchId}")->fetch();
    assert_test($attPunchPost['clock_in'] === "{$fourDaysAgo} 09:00:00", 'Clock-in updated by unified review function');
    assert_test((int)$attPunchPost['version'] === 2, 'Attendance version incremented by punch correction');

} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failed++;
} finally {
    $pdo->rollBack();
    echo "\n[INFO] Sandbox transaction rolled back. Database returned to original state.\n";
}

echo "\n=========================================================\n";
echo " Phase 3C Test Suite Finished: {$passed} Passed, {$failed} Failed\n";
echo "=========================================================\n\n";

exit($failed > 0 ? 1 : 0);

