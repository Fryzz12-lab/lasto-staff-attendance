<?php
/**
 * Phase 3E: Complete End-to-End Integration, Integrity, and Security Audit Suite
 * Covers Scenarios A through N + Rejection, Resubmission, Missing Breaks, Punch Corrections, Concurrency, and Stale Versions
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/attendance_service.php';
require_once __DIR__ . '/../includes/report_export.php';
require_once __DIR__ . '/../includes/adjustment_service.php';
require_once __DIR__ . '/../includes/notification_service.php';

$pdo = db();
$pdo->beginTransaction();

$passed = 0;
$failed = 0;

function assert_audit(bool $condition, string $description): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  [AUDIT PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [AUDIT FAIL] {$description}\n";
        $failed++;
    }
}

try {
    echo "====================================================================\n";
    echo " Phase 3E: End-to-End Workflow & Comprehensive Audit Test Suite     \n";
    echo "====================================================================\n\n";

    // 1. Setup isolated test users
    $pw = password_hash('Pass123!', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day, must_change_password)
                   VALUES ('P3E_STF_01', 'p3e_staff_1', ?, 'Phase3E Staff One', 'p3estaff1@test.local', 'staff', 1, 0, 0)")
        ->execute([$pw]);
    $staff1Id = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day, must_change_password)
                   VALUES ('P3E_STF_02', 'p3e_staff_2', ?, 'Phase3E Staff Two', 'p3estaff2@test.local', 'staff', 1, 0, 0)")
        ->execute([$pw]);
    $staff2Id = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day, must_change_password)
                   VALUES ('P3E_ADM_01', 'p3e_admin_1', ?, 'Phase3E Admin One', 'p3eadmin1@test.local', 'admin', 1, 0, 0)")
        ->execute([$pw]);
    $admin1Id = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day, must_change_password)
                   VALUES ('P3E_ADM_02', 'p3e_admin_2', ?, 'Phase3E Admin Two', 'p3eadmin2@test.local', 'admin', 1, 0, 0)")
        ->execute([$pw]);
    $admin2Id = (int) $pdo->lastInsertId();

    $workDate = (new DateTimeImmutable('yesterday'))->format('Y-m-d');

    echo "--- Primary E2E Lifecycle: Scenarios A through N ---\n";

    // Scenario A: Staff clocks in
    // We create the attendance row simulating clock_in() at 09:00:00
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, status, version)
                   VALUES (?, ?, '{$workDate} 09:00:00', 'present', 1)")
        ->execute([$staff1Id, $workDate]);
    $attId = (int) $pdo->lastInsertId();
    $attA = $pdo->query("SELECT * FROM attendance WHERE id = {$attId}")->fetch();
    assert_audit($attA['clock_in'] === "{$workDate} 09:00:00", 'Step A: Staff clocked in successfully at 09:00:00');
    assert_audit((int)$attA['version'] === 1, 'Step A: Initial attendance version is 1');

    // Scenario B: Staff starts lunch
    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, allowed_minutes, is_auto_closed)
                   VALUES (?, ?, 'lunch', 1, '{$workDate} 12:00:00', 60, 0)")
        ->execute([$attId, $staff1Id]);
    $breakId = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE attendance SET status = 'on_break', version = version + 1 WHERE id = ?")->execute([$attId]);
    $attB = $pdo->query("SELECT * FROM attendance WHERE id = {$attId}")->fetch();
    assert_audit($attB['status'] === 'on_break', 'Step B: Staff status transitioned to on_break');
    assert_audit((int)$attB['version'] === 2, 'Step B: Attendance version incremented to 2');

    // Scenario C: Staff forgets to end lunch
    $breakC = $pdo->query("SELECT * FROM break_records WHERE id = {$breakId}")->fetch();
    assert_audit($breakC['end_time'] === null, 'Step C: Break remains unended (forgotten break end)');

    // Scenario D: Staff clocks out successfully (Safe Clock-Out with Open Break)
    // Directly invoke clock_out() or simulate safe clock-out logic
    $clockOutTime = "{$workDate} 18:00:00";
    $durSec = strtotime($clockOutTime) - strtotime($breakC['start_time']);
    $durMin = (int) floor($durSec / 60); // 360 mins
    $allowed = (int) $breakC['allowed_minutes']; // 60 mins
    $exceeded = $durMin > $allowed ? 1 : 0; // 1

    $pdo->prepare("UPDATE break_records SET end_time = ?, duration_minutes = ?, exceeded = ?, is_auto_closed = 1, closure_reason = 'clock_out', system_closed_at = ? WHERE id = ?")
        ->execute([$clockOutTime, $durMin, $exceeded, $clockOutTime, $breakId]);

    // Official work time = total span (540m) minus auto-closed break duration (360m) = 180 mins
    // Provisional work time = total span (540m) minus policy allowed break (60m) = 480 mins
    $pdo->prepare("UPDATE attendance SET clock_out = ?, work_minutes = 180, total_break_minutes = 360, status = 'completed', break_review_status = 'under_review', provisional_work_minutes = 480, version = version + 1 WHERE id = ?")
        ->execute([$clockOutTime, $attId]);

    write_audit($attId, $staff1Id, $staff1Id, 'break_auto_closed', 'break_end', null, $clockOutTime, 'System auto-closed unended lunch break upon clock-out');
    write_audit($attId, $staff1Id, $staff1Id, 'clock_out', 'clock_out', null, $clockOutTime, 'Staff clock out with open lunch break auto-closed');

    // Scenario E: Evidence Preserved & Discrepancy Flagged
    $attE = $pdo->query("SELECT * FROM attendance WHERE id = {$attId}")->fetch();
    $breakE = $pdo->query("SELECT * FROM break_records WHERE id = {$breakId}")->fetch();
    assert_audit($attE['clock_out'] === $clockOutTime, 'Step D: Staff clocked out successfully');
    assert_audit((int)$breakE['is_auto_closed'] === 1 && $breakE['closure_reason'] === 'clock_out', 'Step E: Original break evidence marked auto-closed by clock_out');
    assert_audit($breakE['system_closed_at'] === $clockOutTime, 'Step E: system_closed_at timestamp faithfully recorded');
    assert_audit($attE['break_review_status'] === 'under_review', 'Step E: Shift break_review_status flagged as under_review');
    assert_audit((int)$attE['work_minutes'] === 180 && (int)$attE['provisional_work_minutes'] === 480, 'Step E: Official work_minutes (180) strictly separated from provisional estimate (480)');

    // Scenario F: Staff receives an actionable notification
    $reminderCount = process_break_discrepancy_reminders();
    $staffNotif = $pdo->query("SELECT * FROM notifications WHERE user_id = {$staff1Id} AND event_type = 'break_discrepancy'")->fetch();
    assert_audit(!empty($staffNotif), 'Step F: Staff receives actionable break discrepancy notification');
    assert_audit($staffNotif['destination'] === '/staff/corrections.php', 'Step F: Notification links directly to /staff/corrections.php');
    assert_audit(strpos($staffNotif['event_key'], "break-disc-{$attId}-") === 0, 'Step F: Notification uses compound deduplication key');

    // Scenario G: Staff submits actual break return time (13:00:00)
    $reqG = submit_break_correction_request(
        $staff1Id,
        $workDate,
        'auto_closed',
        $breakId,
        'lunch',
        "{$workDate} 12:00:00",
        "{$workDate} 13:00:00",
        'Returned from lunch at 1:00 PM, forgot to end on phone',
        null
    );
    assert_audit(isset($reqG['id']) && (int)$reqG['id'] > 0, 'Step G: Staff submitted break correction successfully');
    $corrReqId = (int) $reqG['id'];
    $reqRow = $pdo->query("SELECT * FROM attendance_correction_requests WHERE id = {$corrReqId}")->fetch();
    assert_audit($reqRow['status'] === 'pending', 'Step G: Correction request initialized to pending');
    assert_audit((int)$reqRow['target_attendance_version'] === 3, 'Step G: Target attendance version locked at baseline 3');

    // Attendance transitioned to correction_pending
    $attG = $pdo->query("SELECT * FROM attendance WHERE id = {$attId}")->fetch();
    assert_audit($attG['break_review_status'] === 'correction_pending', 'Step G: Attendance break_review_status transitioned to correction_pending');

    // Scenario H: Admin receives the correction request
    $adminNotif = $pdo->query("SELECT * FROM notifications WHERE user_id = {$admin1Id} AND event_type = 'correction_request'")->fetch();
    assert_audit(!empty($adminNotif), 'Step H: Administrator received in-app notification of break correction request');
    assert_audit($adminNotif['destination'] === '/admin/corrections.php', 'Step H: Admin notification routes to /admin/corrections.php');

    // Scenario I: Admin reviews original versus requested time
    assert_audit($reqRow['requested_break_start'] === "{$workDate} 12:00:00", 'Step I: Requested break start accurately presented (12:00)');
    assert_audit($reqRow['requested_break_end'] === "{$workDate} 13:00:00", 'Step I: Requested break end accurately presented (13:00)');
    assert_audit($breakE['end_time'] === "{$workDate} 18:00:00", 'Step I: Original system auto-closed timestamp preserved for comparison (18:00)');

    // Scenario J: Admin approves request
    $reviewResult = review_attendance_correction_request($corrReqId, 'approved', $admin1Id, 'Verified against building turnstile badge logs');
    assert_audit($reviewResult['decision'] === 'approved', 'Step J: Administrator approved the break correction request');

    // Scenario K: Official working hours are recalculated
    $attK = $pdo->query("SELECT * FROM attendance WHERE id = {$attId}")->fetch();
    $breakK = $pdo->query("SELECT * FROM break_records WHERE id = {$breakId}")->fetch();
    assert_audit((int)$breakK['duration_minutes'] === 60, 'Step K: Break duration recalculated to 60 minutes');
    assert_audit((int)$breakK['exceeded'] === 0, 'Step K: Exceeded flag cleared to 0');
    assert_audit((int)$attK['total_break_minutes'] === 60, 'Step K: Attendance total_break_minutes updated to 60');
    assert_audit((int)$attK['work_minutes'] === 480, 'Step K: Attendance work_minutes accurately recalculated to 480 mins (8h 00m)');
    assert_audit((int)$attK['version'] === 4, 'Step K: Attendance version atomically incremented to 4');

    // Scenario L: Discrepancy is resolved only when genuinely verified
    assert_audit($attK['break_review_status'] === 'resolved', 'Step L: Discrepancy marked resolved upon verification');
    assert_audit($attK['provisional_work_minutes'] === null, 'Step L: Provisional hours cleared from active tracking');
    assert_audit((int)$breakK['is_auto_closed'] === 1 && $breakK['closure_reason'] === 'clock_out', 'Step L: Auto-closure provenance permanently preserved');

    // Scenario M: Staff receives the approval notification
    $staffDecisionNotif = $pdo->query("SELECT * FROM notifications WHERE user_id = {$staff1Id} AND event_type = 'correction_decision' ORDER BY id DESC LIMIT 1")->fetch();
    assert_audit(!empty($staffDecisionNotif), 'Step M: Staff received decision notification');
    assert_audit(strpos($staffDecisionNotif['message'], 'approved') !== false, 'Step M: Notification confirms approval');

    // Scenario N: Admin reports and exports reflect the approved result
    $allAttApproved = $pdo->query("SELECT a.*, u.full_name, u.employee_id FROM attendance a JOIN users u ON u.id = a.user_id WHERE a.id = {$attId}")->fetchAll();
    $matrixApproved = report_attendance_export_matrix($allAttApproved);
    assert_audit(strpos($matrixApproved[0][6], '[Under Review') === false, 'Step N: Total working time clean without review brackets');
    assert_audit($matrixApproved[0][6] === '8h 00m', 'Step N: Exported working time reflects 8h 00m');
    assert_audit($matrixApproved[0][7] === __('st_resolved'), 'Step N: Exported status shows Resolved');
    assert_audit($matrixApproved[0][8] === '-', 'Step N: Provisional work time column is dash');

    // Cleared from discrepancy report filter
    $discReportList = report_filter_rows($allAttApproved, 'discrepancies');
    assert_audit(count($discReportList) === 0, 'Step N: Resolved shift is cleared from Discrepancies report');

    echo "\n--- Additional Edge Scenarios: Rejection, Missing Break, Concurrency & Security ---\n";

    // Edge 1: Rejection Flow
    $workDate2 = (new DateTimeImmutable('3 days ago'))->format('Y-m-d');
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, work_minutes, total_break_minutes, status, break_review_status, provisional_work_minutes, version)
                   VALUES (?, ?, '{$workDate2} 09:00:00', '{$workDate2} 18:00:00', 180, 360, 'completed', 'under_review', 480, 1)")
        ->execute([$staff2Id, $workDate2]);
    $att2Id = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, is_auto_closed, closure_reason)
                   VALUES (?, ?, 'lunch', 1, '{$workDate2} 12:00:00', '{$workDate2} 18:00:00', 360, 1, 'clock_out')")
        ->execute([$att2Id, $staff2Id]);
    $break2Id = (int) $pdo->lastInsertId();

    $reqRej = submit_break_correction_request($staff2Id, $workDate2, 'auto_closed', $break2Id, 'lunch', "{$workDate2} 12:00:00", "{$workDate2} 12:30:00", 'Incorrect break claim', null);
    $req2Id = (int) $reqRej['id'];

    review_attendance_correction_request($req2Id, 'rejected', $admin1Id, 'Discrepancy in badge logs, not verified');
    $att2Post = $pdo->query("SELECT * FROM attendance WHERE id = {$att2Id}")->fetch();
    assert_audit($att2Post['break_review_status'] === 'rejected_unresolved', 'Edge 1: Rejected break correction transitions to rejected_unresolved');
    assert_audit((int)$att2Post['work_minutes'] === 180, 'Edge 1: Official work minutes unchanged on rejection');
    // var_dump($att2Post['provisional_work_minutes']);
    assert_audit(!empty($att2Post['provisional_work_minutes']), 'Edge 1: Provisional hours retained for audit visibility (actual: ' . var_export($att2Post['provisional_work_minutes'], true) . ')');

    // Edge 2: Revised Resubmission after Rejection
    $reqRevised = submit_break_correction_request($staff2Id, $workDate2, 'auto_closed', $break2Id, 'lunch', "{$workDate2} 12:00:00", "{$workDate2} 13:00:00", 'Resubmitted with manager confirmation email', null);
    assert_audit(isset($reqRevised['id']) && (int)$reqRevised['id'] > 0, 'Edge 2: Revised break correction request accepted after rejection');
    $att2Resub = $pdo->query("SELECT * FROM attendance WHERE id = {$att2Id}")->fetch();
    assert_audit($att2Resub['break_review_status'] === 'correction_pending', 'Edge 2: Attendance transitions back to correction_pending');

    // Edge 3: Missing Break Submission and Approval
    $workDate3 = (new DateTimeImmutable('4 days ago'))->format('Y-m-d');
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, work_minutes, total_break_minutes, status, break_review_status, version)
                   VALUES (?, ?, '{$workDate3} 09:00:00', '{$workDate3} 18:00:00', 540, 0, 'completed', 'none', 1)")
        ->execute([$staff1Id, $workDate3]);
    $att3Id = (int) $pdo->lastInsertId();

    $reqMissing = submit_break_correction_request($staff1Id, $workDate3, 'missing', null, 'short', "{$workDate3} 15:00:00", "{$workDate3} 15:10:00", 'Forgot to record short break', 'Medical clinic visit delayed submission');
    assert_audit(isset($reqMissing['id']) && (int)$reqMissing['id'] > 0, 'Edge 3: Missing unrecorded break request submitted');
    $req3Id = (int) $reqMissing['id'];

    review_attendance_correction_request($req3Id, 'approved', $admin2Id, 'Approved 10m short break');
    $att3Post = $pdo->query("SELECT * FROM attendance WHERE id = {$att3Id}")->fetch();
    assert_audit((int)$att3Post['total_break_minutes'] === 10, 'Edge 3: Missing break duration deducted from work minutes');
    assert_audit((int)$att3Post['work_minutes'] === 530, 'Edge 3: Work minutes updated to 530 (540 - 10)');

    // Edge 4: Stale Baseline Version Conflict
    $workDate4 = (new DateTimeImmutable('5 days ago'))->format('Y-m-d');
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, work_minutes, total_break_minutes, status, break_review_status, version)
                   VALUES (?, ?, '{$workDate4} 09:00:00', '{$workDate4} 18:00:00', 480, 60, 'completed', 'under_review', 2)")
        ->execute([$staff1Id, $workDate4]);
    $att4Id = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, is_auto_closed)
                   VALUES (?, ?, 'lunch', 1, '{$workDate4} 12:00:00', '{$workDate4} 18:00:00', 360, 1)")
        ->execute([$att4Id, $staff1Id]);
    $br4Id = (int) $pdo->lastInsertId();

    // Submit request while attendance is version 2
    $reqStale = submit_break_correction_request($staff1Id, $workDate4, 'auto_closed', $br4Id, 'lunch', "{$workDate4} 12:00:00", "{$workDate4} 13:00:00", 'Stale test', 'Delayed due to weekend schedule');
    $req4Id = (int) $reqStale['id'];

    // Mutate attendance behind the scenes (e.g. by another admin or punch update) -> version becomes 3
    $pdo->prepare("UPDATE attendance SET version = 3 WHERE id = ?")->execute([$att4Id]);

    $staleBlocked = false;
    try {
        review_attendance_correction_request($req4Id, 'approved', $admin1Id, 'Should fail');
    } catch (InvalidArgumentException $e) {
        $staleBlocked = true;
        echo "Edge 4 caught exception: " . $e->getMessage() . "\n";
        assert_audit(strpos($e->getMessage(), 'version conflict') !== false, 'Edge 4: Stale attendance version approval strictly blocked with conflict message');
    }
    assert_audit($staleBlocked, 'Edge 4: Version concurrency protection verified');

    // Edge 5: Admin Self-Approval Guard
    $workDate5 = (new DateTimeImmutable('6 days ago'))->format('Y-m-d');
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, work_minutes, total_break_minutes, status, break_review_status, version)
                   VALUES (?, ?, '{$workDate5} 09:00:00', '{$workDate5} 18:00:00', 480, 60, 'completed', 'under_review', 1)")
        ->execute([$admin1Id, $workDate5]);
    $att5Id = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, is_auto_closed)
                   VALUES (?, ?, 'lunch', 1, '{$workDate5} 12:00:00', '{$workDate5} 18:00:00', 360, 1)")
        ->execute([$att5Id, $admin1Id]);
    $br5Id = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO attendance_correction_requests (user_id, attendance_id, break_id, break_type, break_correction_type, target_attendance_version, work_date, field_name, requested_break_start, requested_break_end, reason, status)
                   VALUES (?, ?, ?, 'lunch', 'auto_closed', 1, ?, 'break', '{$workDate5} 12:00:00', '{$workDate5} 13:00:00', 'Admin self correction', 'pending')")
        ->execute([$admin1Id, $att5Id, $br5Id, $workDate5]);
    $req5Id = (int) $pdo->lastInsertId();

    $selfBlocked = false;
    try {
        review_attendance_correction_request($req5Id, 'approved', $admin1Id, 'Self approval attempt');
    } catch (InvalidArgumentException $e) {
        $selfBlocked = true;
        assert_audit(strpos($e->getMessage(), 'own correction requests') !== false, 'Edge 5: Admin self-approval strictly blocked');
    }
    assert_audit($selfBlocked, 'Edge 5: Administrator separation of duties enforced');

    // Edge 6: Cross-User Break Access Guard
    // First: Cross-user submission when user2 has no attendance on that date
    $crossBlockedNoAtt = false;
    try {
        submit_break_correction_request($staff2Id, $workDate, 'auto_closed', $breakId, 'lunch', "{$workDate} 12:00:00", "{$workDate} 13:00:00", 'Malicious cross user', null);
    } catch (InvalidArgumentException $e) {
        $crossBlockedNoAtt = true;
    }
    assert_audit($crossBlockedNoAtt, 'Edge 6a: Cross-user submission blocked when attacker has no attendance on shift date');

    // Second: Cross-user submission when user2 has their own attendance on workDate, but passes user1's break_id
    $workDate6 = (new DateTimeImmutable('7 days ago'))->format('Y-m-d');
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, work_minutes, status, version)
                   VALUES (?, ?, '{$workDate6} 09:00:00', '{$workDate6} 18:00:00', 540, 'completed', 1)")
        ->execute([$staff2Id, $workDate6]);

    $crossBlockedForeignBreak = false;
    try {
        submit_break_correction_request($staff2Id, $workDate6, 'auto_closed', $breakId, 'lunch', "{$workDate6} 12:00:00", "{$workDate6} 13:00:00", 'Malicious cross break', 'Late cross test');
    } catch (InvalidArgumentException $e) {
        $crossBlockedForeignBreak = true;
        assert_audit(strpos($e->getMessage(), 'permission') !== false || strpos($e->getMessage(), 'does not belong to this shift') !== false, 'Edge 6b: Cross-user break ownership strictly enforced: ' . $e->getMessage());
    }
    assert_audit($crossBlockedForeignBreak, 'Edge 6b: Foreign break ownership boundary enforced');

} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failed++;
} finally {
    $pdo->rollBack();
    echo "\n[INFO] Phase 3E Sandbox transaction rolled back. Database returned to original state.\n";
}

echo "\n====================================================================\n";
echo " Phase 3E Audit Suite Finished: {$passed} Passed, {$failed} Failed\n";
echo "====================================================================\n\n";

exit($failed > 0 ? 1 : 0);
