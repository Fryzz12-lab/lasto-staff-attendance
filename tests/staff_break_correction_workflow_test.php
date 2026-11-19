<?php
/**
 * Phase 3B: Staff Break Correction Request Submission Test Suite
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
    echo " Running Phase 3B: Staff Break Correction Request Tests  \n";
    echo "=========================================================\n\n";

    // Setup isolated test users
    $pw = password_hash('Pass123!', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day, must_change_password)
                   VALUES ('P3B_STF_01', 'p3b_staff_1', ?, 'Phase3B Staff One', 'p3bstaff1@test.local', 'staff', 1, 0, 0)")
        ->execute([$pw]);
    $u1 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day, must_change_password)
                   VALUES ('P3B_STF_02', 'p3b_staff_2', ?, 'Phase3B Staff Two', 'p3bstaff2@test.local', 'staff', 1, 0, 0)")
        ->execute([$pw]);
    $u2 = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day, must_change_password)
                   VALUES ('P3B_ADM_01', 'p3b_admin_1', ?, 'Phase3B Admin One', 'p3badmin1@test.local', 'admin', 1, 0, 0)")
        ->execute([$pw]);
    $adminId = (int) $pdo->lastInsertId();

    $pdo->exec("INSERT INTO schedule_assignments (user_id, schedule_id, effective_from) VALUES ($u1, 1, '2026-01-01')");
    $pdo->exec("INSERT INTO schedule_assignments (user_id, schedule_id, effective_from) VALUES ($u2, 1, '2026-01-01')");

    $workDate = (new DateTimeImmutable('yesterday'))->format('Y-m-d');

    // Setup attendance for u1
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, status, version)
                   VALUES (?, ?, ?, ?, 'completed', 4)")
        ->execute([$u1, $workDate, "{$workDate} 08:30:00", "{$workDate} 18:00:00"]);
    $att1Id = (int) $pdo->lastInsertId();

    // Lunch break: 12:00 to 13:00 (60m)
    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, allowed_minutes, exceeded)
                   VALUES (?, ?, 'lunch', 1, ?, ?, 60, 60, 0)")
        ->execute([$att1Id, $u1, "{$workDate} 12:00:00", "{$workDate} 13:00:00"]);
    $lunchBreakId = (int) $pdo->lastInsertId();

    // Solat break: 16:00 to 16:20 (20m)
    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, allowed_minutes, exceeded)
                   VALUES (?, ?, 'solat', 1, ?, ?, 20, 20, 0)")
        ->execute([$att1Id, $u1, "{$workDate} 16:00:00", "{$workDate} 16:20:00"]);
    $solatBreakId = (int) $pdo->lastInsertId();

    // Setup attendance for u2 with auto-closed break
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, status, break_review_status, provisional_work_minutes, version)
                   VALUES (?, ?, ?, ?, 'completed', 'under_review', 510, 2)")
        ->execute([$u2, $workDate, "{$workDate} 09:00:00", "{$workDate} 18:00:00"]);
    $att2Id = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, allowed_minutes, exceeded, is_auto_closed, closure_reason, system_closed_at)
                   VALUES (?, ?, 'lunch', 1, ?, ?, 300, 60, 1, 1, 'clock_out', ?)")
        ->execute([$att2Id, $u2, "{$workDate} 13:00:00", "{$workDate} 18:00:00", "{$workDate} 18:00:00"]);
    $autoClosedBreakId = (int) $pdo->lastInsertId();

    // -------------------------------------------------------------
    // Scenario 1: Existing Break Correction (Type A - Start Only)
    // -------------------------------------------------------------
    echo "Scenario 1: Existing Break Correction (Type A - Start Only)...\n";
    $req1 = submit_break_correction_request(
        $u1,
        $workDate,
        'start',
        $lunchBreakId,
        'lunch',
        "{$workDate} 12:15:00",
        null,
        'Started lunch 15 minutes later'
    );
    assert_test(!empty($req1['id']), 'Correction request created successfully');

    $row1 = $pdo->query("SELECT * FROM attendance_correction_requests WHERE id = {$req1['id']}")->fetch();
    assert_test($row1['field_name'] === 'break', 'field_name is "break"');
    assert_test((int)$row1['break_id'] === $lunchBreakId, 'break_id references lunch break');
    assert_test($row1['break_type'] === 'lunch', 'break_type recorded as lunch');
    assert_test($row1['break_correction_type'] === 'start', 'break_correction_type is "start"');
    assert_test($row1['requested_break_start'] === "{$workDate} 12:15:00", 'requested_break_start matches input');
    assert_test($row1['requested_break_end'] === "{$workDate} 13:00:00", 'requested_break_end preserved from original record');
    assert_test((int)$row1['target_attendance_version'] === 4, 'target_attendance_version captured from baseline');
    assert_test($row1['status'] === 'pending', 'Status initialized to pending');

    // -------------------------------------------------------------
    // Scenario 2: Immutability of Official Records on Submission
    // -------------------------------------------------------------
    echo "Scenario 2: Immutability of Official Records on Submission...\n";
    $origBreak = $pdo->query("SELECT * FROM break_records WHERE id = {$lunchBreakId}")->fetch();
    assert_test($origBreak['start_time'] === "{$workDate} 12:00:00", 'Official break start_time remains untouched');
    assert_test((int)$origBreak['duration_minutes'] === 60, 'Official duration_minutes remains untouched');

    $origAtt = $pdo->query("SELECT * FROM attendance WHERE id = {$att1Id}")->fetch();
    assert_test((int)$origAtt['version'] === 4, 'attendance.version remains unchanged upon submission');

    // -------------------------------------------------------------
    // Scenario 3: Existing Break Correction (Type B - Return Only)
    // -------------------------------------------------------------
    echo "Scenario 3: Existing Break Correction (Type B - Return Only)...\n";
    $req3 = submit_break_correction_request(
        $u1,
        $workDate,
        'end',
        $solatBreakId,
        'solat',
        null,
        "{$workDate} 16:15:00",
        'Returned from prayer 5 minutes earlier'
    );
    $row3 = $pdo->query("SELECT * FROM attendance_correction_requests WHERE id = {$req3['id']}")->fetch();
    assert_test($row3['requested_break_start'] === "{$workDate} 16:00:00", 'requested_break_start preserved from original solat start');
    assert_test($row3['requested_break_end'] === "{$workDate} 16:15:00", 'requested_break_end updated to requested return time');

    // -------------------------------------------------------------
    // Scenario 4: Existing Break Correction (Type C - Both Start & Return)
    // -------------------------------------------------------------
    echo "Scenario 4: Existing Break Correction (Type C - Both Start & Return)...\n";
    // Setup third break: short break 10:00 to 10:10
    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, allowed_minutes, exceeded)
                   VALUES (?, ?, 'short', 1, ?, ?, 10, 10, 0)")
        ->execute([$att1Id, $u1, "{$workDate} 10:00:00", "{$workDate} 10:10:00"]);
    $shortBreakId = (int) $pdo->lastInsertId();

    $req4 = submit_break_correction_request(
        $u1,
        $workDate,
        'both',
        $shortBreakId,
        'short',
        "{$workDate} 10:05:00",
        "{$workDate} 10:15:00",
        'Both start and end were delayed by 5 minutes'
    );
    $row4 = $pdo->query("SELECT * FROM attendance_correction_requests WHERE id = {$req4['id']}")->fetch();
    assert_test($row4['requested_break_start'] === "{$workDate} 10:05:00", 'Both start requested time recorded');
    assert_test($row4['requested_break_end'] === "{$workDate} 10:15:00", 'Both return requested time recorded');

    // -------------------------------------------------------------
    // Scenario 5: System Auto-Closed Break Correction (Type E)
    // -------------------------------------------------------------
    echo "Scenario 5: System Auto-Closed Break Correction (Type E)...\n";
    $req5 = submit_break_correction_request(
        $u2,
        $workDate,
        'auto_closed',
        $autoClosedBreakId,
        'lunch',
        null,
        "{$workDate} 14:00:00",
        'Actually returned from lunch at 2:00 PM, not at clock-out'
    );
    $row5 = $pdo->query("SELECT * FROM attendance_correction_requests WHERE id = {$req5['id']}")->fetch();
    assert_test((int)$row5['break_id'] === $autoClosedBreakId, 'References auto-closed break');
    assert_test($row5['break_correction_type'] === 'auto_closed', 'break_correction_type is "auto_closed"');
    assert_test($row5['requested_break_start'] === "{$workDate} 13:00:00", 'Original start preserved');
    assert_test($row5['requested_break_end'] === "{$workDate} 14:00:00", 'Actual return time captured');

    // Check that auto_closed break itself remains is_auto_closed = 1 until admin review
    $checkBreak2 = $pdo->query("SELECT * FROM break_records WHERE id = {$autoClosedBreakId}")->fetch();
    assert_test((int)$checkBreak2['is_auto_closed'] === 1, 'break_records.is_auto_closed remains 1');

    // -------------------------------------------------------------
    // Scenario 6: Missing Break Submission (Type D)
    // -------------------------------------------------------------
    echo "Scenario 6: Missing Break Submission (Type D)...\n";
    $req6 = submit_break_correction_request(
        $u1,
        $workDate,
        'missing',
        null,
        'short',
        "{$workDate} 14:30:00",
        "{$workDate} 14:40:00",
        'Forgot to record afternoon tea short break'
    );
    $row6 = $pdo->query("SELECT * FROM attendance_correction_requests WHERE id = {$req6['id']}")->fetch();
    assert_test($row6['break_id'] === null, 'break_id is NULL for missing break');
    assert_test($row6['break_type'] === 'short', 'break_type recorded as short');
    assert_test($row6['break_correction_type'] === 'missing', 'break_correction_type is "missing"');
    assert_test($row6['requested_break_start'] === "{$workDate} 14:30:00", 'requested_break_start recorded');
    assert_test($row6['requested_break_end'] === "{$workDate} 14:40:00", 'requested_break_end recorded');

    // Confirm NO new row was added to break_records for this shift!
    $breakCount = (int) $pdo->query("SELECT COUNT(*) FROM break_records WHERE attendance_id = {$att1Id}")->fetchColumn();
    assert_test($breakCount === 3, 'No break_records row created prior to approval (count remains 3)');

    // -------------------------------------------------------------
    // Scenario 7: Time Validation & Boundary Enforcements
    // -------------------------------------------------------------
    echo "Scenario 7: Time Validation & Boundary Enforcements...\n";

    // A: Inverted times (end <= start)
    $invFailed = false;
    try {
        submit_break_correction_request($u1, $workDate, 'missing', null, 'short', "{$workDate} 15:00:00", "{$workDate} 14:50:00", 'Inverted');
    } catch (InvalidArgumentException $e) {
        $invFailed = true;
    }
    assert_test($invFailed, 'Inverted break times (end <= start) strictly rejected');

    // B: Future timestamps
    $futFailed = false;
    $futureTime = (new DateTimeImmutable('+2 hours'))->format('Y-m-d H:i:s');
    try {
        submit_break_correction_request($u1, today_str(), 'missing', null, 'short', today_str() . ' 10:00:00', $futureTime, 'Future break');
    } catch (InvalidArgumentException $e) {
        $futFailed = true;
    }
    assert_test($futFailed, 'Future break timestamps strictly rejected');

    // C: Break start before shift clock-in
    $preInFailed = false;
    try {
        submit_break_correction_request($u1, $workDate, 'missing', null, 'short', "{$workDate} 08:00:00", "{$workDate} 08:20:00", 'Before shift');
    } catch (InvalidArgumentException $e) {
        $preInFailed = true;
    }
    assert_test($preInFailed, 'Break start before clock-in strictly rejected');

    // D: Break end after shift clock-out
    $postOutFailed = false;
    try {
        submit_break_correction_request($u1, $workDate, 'missing', null, 'short', "{$workDate} 17:50:00", "{$workDate} 18:10:00", 'After shift');
    } catch (InvalidArgumentException $e) {
        $postOutFailed = true;
    }
    assert_test($postOutFailed, 'Break return after clock-out strictly rejected');

    // -------------------------------------------------------------
    // Scenario 8: Break Overlap Prevention
    // -------------------------------------------------------------
    echo "Scenario 8: Break Overlap Prevention...\n";

    // Overlap with recorded lunch break (12:00 to 13:00)
    $overlapFailed = false;
    try {
        submit_break_correction_request($u1, $workDate, 'missing', null, 'solat', "{$workDate} 12:45:00", "{$workDate} 13:05:00", 'Overlap');
    } catch (InvalidArgumentException $e) {
        $overlapFailed = true;
    }
    assert_test($overlapFailed, 'Break overlapping with existing recorded lunch strictly rejected');

    // Overlap with pending missing break request (14:30 to 14:40)
    $pOverlapFailed = false;
    try {
        submit_break_correction_request($u1, $workDate, 'missing', null, 'solat', "{$workDate} 14:35:00", "{$workDate} 14:55:00", 'Overlap with pending');
    } catch (InvalidArgumentException $e) {
        $pOverlapFailed = true;
    }
    assert_test($pOverlapFailed, 'Break overlapping with another pending break request strictly rejected');

    // -------------------------------------------------------------
    // Scenario 9: Duplicate and Conflict Prevention
    // -------------------------------------------------------------
    echo "Scenario 9: Duplicate and Conflict Prevention...\n";

    // Duplicate request on same lunch break while pending
    $dupBreakFailed = false;
    try {
        submit_break_correction_request($u1, $workDate, 'both', $lunchBreakId, 'lunch', "{$workDate} 12:10:00", "{$workDate} 13:10:00", 'Duplicate');
    } catch (InvalidArgumentException $e) {
        $dupBreakFailed = true;
    }
    assert_test($dupBreakFailed, 'Duplicate pending request on same break record strictly blocked');

    // Duplicate missing break request of same type
    $dupMissingFailed = false;
    try {
        submit_break_correction_request($u1, $workDate, 'missing', null, 'short', "{$workDate} 15:10:00", "{$workDate} 15:20:00", 'Second missing short');
    } catch (InvalidArgumentException $e) {
        $dupMissingFailed = true;
    }
    assert_test($dupMissingFailed, 'Duplicate pending missing break request for same category strictly blocked');

    // -------------------------------------------------------------
    // Scenario 10: Mutual Punch vs Break Conflict Protection
    // -------------------------------------------------------------
    echo "Scenario 10: Mutual Punch vs Break Conflict Protection...\n";

    // Setup an attendance record with a pending punch correction
    $workDatePunch = (new DateTimeImmutable('-2 days'))->format('Y-m-d');
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, status, version)
                   VALUES (?, ?, ?, ?, 'completed', 1)")
        ->execute([$u1, $workDatePunch, "{$workDatePunch} 09:00:00", "{$workDatePunch} 18:00:00"]);
    $attPunchId = (int) $pdo->lastInsertId();

    // Submit a pending punch correction requesting clock-in at 10:00 AM
    submit_attendance_correction_request($u1, $workDatePunch, 'clock_in', "{$workDatePunch} 10:00:00", null, 'Forgot early morning clock in');

    // Attempt to submit a break starting at 09:30 AM (before the pending requested clock-in of 10:00 AM)
    $punchConflictFailed = false;
    try {
        submit_break_correction_request($u1, $workDatePunch, 'missing', null, 'solat', "{$workDatePunch} 09:30:00", "{$workDatePunch} 09:50:00", 'Morning prayer');
    } catch (InvalidArgumentException $e) {
        $punchConflictFailed = true;
    }
    assert_test($punchConflictFailed, 'Break starting before pending requested clock-in is strictly blocked');

    // -------------------------------------------------------------
    // Scenario 11: Category Session Limits Enforcement
    // -------------------------------------------------------------
    echo "Scenario 11: Category Session Limits Enforcement...\n";

    // Shift already has 1 lunch break. Trying to add missing lunch break must fail (max 1 lunch session)
    $maxLunchFailed = false;
    try {
        submit_break_correction_request($u1, $workDate, 'missing', null, 'lunch', "{$workDate} 11:00:00", "{$workDate} 11:30:00", 'Second lunch');
    } catch (InvalidArgumentException $e) {
        $maxLunchFailed = true;
    }
    assert_test($maxLunchFailed, 'Second lunch session rejected by company policy max sessions');

    // -------------------------------------------------------------
    // Scenario 12: Ownership & Security Enforcement
    // -------------------------------------------------------------
    echo "Scenario 12: Ownership & Security Enforcement...\n";

    // User 1 trying to correct User 2's break
    $crossUserFailed = false;
    try {
        submit_break_correction_request($u1, $workDate, 'auto_closed', $autoClosedBreakId, 'lunch', null, "{$workDate} 14:00:00", 'Hacking other user break');
    } catch (InvalidArgumentException $e) {
        $crossUserFailed = true;
    }
    assert_test($crossUserFailed, 'Submitting correction for another employee break strictly rejected');

    // -------------------------------------------------------------
    // Scenario 13: Late Submission Policy (>3 Days Window)
    // -------------------------------------------------------------
    echo "Scenario 13: Late Submission Policy (>3 Days Window)...\n";
    $oldDate = (new DateTimeImmutable('-5 days'))->format('Y-m-d');
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, status, version)
                   VALUES (?, ?, ?, ?, 'completed', 1)")
        ->execute([$u1, $oldDate, "{$oldDate} 09:00:00", "{$oldDate} 17:00:00"]);

    // Without late explanation
    $lateNoExplFailed = false;
    try {
        submit_break_correction_request($u1, $oldDate, 'missing', null, 'solat', "{$oldDate} 13:00:00", "{$oldDate} 13:20:00", 'Old prayer', '');
    } catch (InvalidArgumentException $e) {
        $lateNoExplFailed = true;
    }
    assert_test($lateNoExplFailed, 'Late submission (>3 days) without explanation strictly rejected');

    // With late explanation
    $reqLate = submit_break_correction_request(
        $u1,
        $oldDate,
        'missing',
        null,
        'solat',
        "{$oldDate} 13:00:00",
        "{$oldDate} 13:20:00",
        'Old prayer',
        'Was hospitalized for 4 days'
    );
    $rowLate = $pdo->query("SELECT * FROM attendance_correction_requests WHERE id = {$reqLate['id']}")->fetch();
    assert_test((int)$rowLate['is_late_submission'] === 1, 'is_late_submission flagged as 1');
    assert_test($rowLate['late_reason'] === 'Was hospitalized for 4 days', 'late_reason preserved faithfully');

    // -------------------------------------------------------------
    // Scenario 14: Administrator Notification Dispatch
    // -------------------------------------------------------------
    echo "Scenario 14: Administrator Notification Dispatch...\n";
    $notif = $pdo->query("SELECT * FROM notifications WHERE user_id = {$adminId} AND event_key = 'corr-break-submitted-{$req1['id']}'")->fetch();
    assert_test(!empty($notif), 'In-app notification created for active administrator');
    assert_test($notif['destination'] === '/admin/corrections.php', 'Admin notification points to /admin/corrections.php');

    // -------------------------------------------------------------
    // Scenario 15: Fetch Correction Requests for Staff History
    // -------------------------------------------------------------
    echo "Scenario 15: Fetch Correction Requests for Staff History...\n";
    $history = get_correction_requests_for_user($u1, 'all', 10);
    assert_test(count($history) >= 4, 'get_correction_requests_for_user returns break requests');
    $foundBreakReq = false;
    foreach ($history as $h) {
        if ($h['field_name'] === 'break' && (int)$h['break_id'] === $lunchBreakId) {
            $foundBreakReq = true;
            assert_test($h['current_break_start'] === "{$workDate} 12:00:00", 'current_break_start joined correctly');
            assert_test($h['current_break_end'] === "{$workDate} 13:00:00", 'current_break_end joined correctly');
            break;
        }
    }
    assert_test($foundBreakReq, 'Break history item includes original break timestamps');

} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failed++;
} finally {
    $pdo->rollBack();
    echo "\n[INFO] Sandbox transaction rolled back. Database returned to original state.\n";
}

echo "\n=========================================================\n";
echo " Phase 3B Test Suite Finished: {$passed} Passed, {$failed} Failed\n";
echo "=========================================================\n\n";

exit($failed > 0 ? 1 : 0);

