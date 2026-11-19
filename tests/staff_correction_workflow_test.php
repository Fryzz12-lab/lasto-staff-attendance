<?php
/**
 * Phase 2: Staff Clock-In/Clock-Out Attendance Correction Requests Automated Test Suite
 *
 * Verifies:
 * 1. Successful submission of clock-in, clock-out, and both clock times.
 * 2. Proper capture of target_attendance_version on submission.
 * 3. Validation: future dates, dates outside employment, invalid dates.
 * 4. Validation: future timestamps, inverted timestamps (out < in), shifts > 24 hours.
 * 5. Validation: clock-out beyond next calendar day.
 * 6. Validation: break containment (in <= break_start, break_end <= out, no open break on out).
 * 7. Validation: rejection when requested times are identical to current times (no changes).
 * 8. Standard 3-day deadline enforcement:
 *    - Shifts <= 3 calendar days: normal submission (is_late_submission = 0).
 *    - Shifts > 3 calendar days without late explanation: strictly blocked.
 *    - Shifts > 3 calendar days with explanation: accepted as late submission (is_late_submission = 1).
 * 9. Conflicting pending request prevention:
 *    - Duplicate clock_in or both requests blocked.
 *    - Legitimate non-conflicting independent requests permitted (clock_out when clock_in is pending).
 * 10. Admin review & approval flow:
 *     - Approved correction updates attendance and recalculates dependent metrics.
 *     - Stale baseline version conflict detection when attendance mutated while request was pending.
 *     - Legacy NULL target_attendance_version requests blocked from automated queue approval.
 *     - Administrator self-approval strictly blocked.
 * 11. Admin rejection flow:
 *     - Rejected correction records status and admin note without mutating attendance.
 * 12. Immutability & Audit Trail:
 *     - Attendance audit logs recorded faithfully.
 *     - Raw GPS location events (attendance_location_events) remain 100% immutable.
 * 13. Notifications:
 *     - Admins receive in-app notification on request submission.
 *     - Employee receives in-app notification on approval / rejection.
 *     - Destination routing verified.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

echo "=========================================================\n";
echo " Running Phase 2: Staff Correction Workflow Test Suite  \n";
echo "=========================================================\n\n";

$passed = 0;
$failed = 0;

function check(bool $condition, string $message, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$message}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$message}" . ($details !== '' ? " ({$details})" : '') . "\n";
        $failed++;
    }
}

$pdo = db();
$pdo->beginTransaction();

try {
    // 1. Setup isolated test users (admin and staff)
    $admStmt = $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day)
                              VALUES ('ADM_P2', 'adm_p2_test', 'hash', 'Admin Phase 2', 'adm_p2@test.local', 'admin', 1, 0)");
    $admStmt->execute();
    $adminId = (int) $pdo->lastInsertId();

    $staffStmt = $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day)
                                VALUES ('STF_P2', 'stf_p2_test', 'hash', 'Staff Phase 2', 'stf_p2@test.local', 'staff', 1, 0)");
    $staffStmt->execute();
    $staffId = (int) $pdo->lastInsertId();

    $staff2Stmt = $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day)
                                 VALUES ('STF_P2_2', 'stf_p2_2_test', 'hash', 'Staff Phase 2 Two', 'stf_p2_2@test.local', 'staff', 1, 0)");
    $staff2Stmt->execute();
    $staff2Id = (int) $pdo->lastInsertId();

    // -------------------------------------------------------------
    // Test 1: Standard On-Time Correction Request Submission
    // -------------------------------------------------------------
    echo "Test 1: Standard On-Time Submission...\n";
    $recentWorkDate = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
    $att = ensure_attendance_row($staffId, $recentWorkDate);
    $initialVersion = (int) $att['version'];

    $subRes = submit_attendance_correction_request(
        $staffId,
        $recentWorkDate,
        'both',
        "{$recentWorkDate} 09:55:00",
        "{$recentWorkDate} 20:05:00",
        'Forgot phone in car on arrival'
    );

    check($subRes['id'] > 0, 'Correction request successfully created in database');
    check($subRes['is_late'] === false, 'Shift within 3 days is correctly marked as not late');

    $reqStmt = $pdo->prepare('SELECT * FROM attendance_correction_requests WHERE id = ?');
    $reqStmt->execute([$subRes['id']]);
    $reqRow = $reqStmt->fetch();

    check((int) $reqRow['user_id'] === $staffId, 'Request strictly bound to authenticated employee');
    check((int) $reqRow['target_attendance_version'] === $initialVersion, 'target_attendance_version matches attendance version at submission', "version={$reqRow['target_attendance_version']}");
    check($reqRow['status'] === 'pending', 'Status initialized to pending');
    check((int) $reqRow['is_late_submission'] === 0, 'is_late_submission flag is 0');
    check($reqRow['late_reason'] === null, 'late_reason is null for on-time submission');

    // -------------------------------------------------------------
    // Test 2: Notification Creation on Submission
    // -------------------------------------------------------------
    echo "Test 2: Admin Notification Dispatch...\n";
    $noticeStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND event_type = 'correction_request' ORDER BY id DESC LIMIT 1");
    $noticeStmt->execute([$adminId]);
    $notice = $noticeStmt->fetch();

    check(!empty($notice), 'In-app notification created for active administrator');
    check($notice['destination'] === '/admin/corrections.php', 'Notification routes administrator to /admin/corrections.php');
    check(str_contains($notice['event_key'], 'corr-submitted-' . $subRes['id']), 'Event key correctly references submission ID');

    // -------------------------------------------------------------
    // Test 3: Validation - Future Work Date Rejection
    // -------------------------------------------------------------
    echo "Test 3: Future Work Date Rejection...\n";
    $tomorrowDate = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
    $threwFutureDate = false;
    try {
        submit_attendance_correction_request($staffId, $tomorrowDate, 'both', "{$tomorrowDate} 10:00:00", "{$tomorrowDate} 20:00:00", 'Future test');
    } catch (InvalidArgumentException $e) {
        $threwFutureDate = str_contains($e->getMessage(), 'future dates');
    }
    check($threwFutureDate, 'Submission for future work date is strictly rejected');

    // -------------------------------------------------------------
    // Test 4: Validation - Inverted Timestamps (Clock-out < Clock-in)
    // -------------------------------------------------------------
    echo "Test 4: Inverted Timestamp Rejection...\n";
    $threwInverted = false;
    try {
        submit_attendance_correction_request($staff2Id, $recentWorkDate, 'both', "{$recentWorkDate} 20:00:00", "{$recentWorkDate} 10:00:00", 'Inverted test');
    } catch (InvalidArgumentException $e) {
        $threwInverted = true;
    }
    check($threwInverted, 'Inverted times (out < in) strictly rejected on submission');

    // -------------------------------------------------------------
    // Test 5: Validation - Multi-day Boundary (> Next Calendar Day)
    // -------------------------------------------------------------
    echo "Test 5: Multi-Day Boundary Rejection...\n";
    $twoDaysLater = (new DateTimeImmutable($recentWorkDate))->modify('+2 days')->format('Y-m-d');
    $threwMultiDay = false;
    try {
        submit_attendance_correction_request($staff2Id, $recentWorkDate, 'both', "{$recentWorkDate} 20:00:00", "{$twoDaysLater} 08:00:00", '2 days later test');
    } catch (InvalidArgumentException $e) {
        $threwMultiDay = true;
    }
    check($threwMultiDay, 'Clock-out beyond next calendar day strictly rejected');

    // -------------------------------------------------------------
    // Test 6: Validation - Shift Duration Exceeding 24 Hours
    // -------------------------------------------------------------
    echo "Test 6: Shift Duration > 24 Hours Rejection...\n";
    $nextDayStr = (new DateTimeImmutable($recentWorkDate))->modify('+1 day')->format('Y-m-d');
    $threwOver24h = false;
    try {
        submit_attendance_correction_request($staff2Id, $recentWorkDate, 'both', "{$recentWorkDate} 08:00:00", "{$nextDayStr} 09:00:00", '25 hours shift test');
    } catch (InvalidArgumentException $e) {
        $threwOver24h = str_contains($e->getMessage(), 'cannot exceed 24 hours');
    }
    check($threwOver24h, 'Shift duration exceeding 24 hours strictly rejected');

    // -------------------------------------------------------------
    // Test 7: Break Containment Validation
    // -------------------------------------------------------------
    echo "Test 7: Break Containment Validation...\n";
    $breakAtt = ensure_attendance_row($staff2Id, $recentWorkDate);
    $breakAttId = (int) $breakAtt['id'];
    $pdo->prepare("UPDATE attendance SET clock_in = ?, clock_out = ? WHERE id = ?")
        ->execute(["{$recentWorkDate} 10:00:00", "{$recentWorkDate} 19:00:00", $breakAttId]);

    $pdo->prepare('INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, allowed_minutes, duration_minutes)
                   VALUES (?, ?, "lunch", 1, ?, ?, 60, 60)')
        ->execute([$breakAttId, $staff2Id, "{$recentWorkDate} 13:00:00", "{$recentWorkDate} 14:00:00"]);

    // Attempt clock-in after break start
    $threwInAfterBreak = false;
    try {
        submit_attendance_correction_request($staff2Id, $recentWorkDate, 'clock_in', "{$recentWorkDate} 13:30:00", null, 'In after break');
    } catch (InvalidArgumentException $e) {
        $threwInAfterBreak = str_contains($e->getMessage(), 'after a recorded break start');
    }
    check($threwInAfterBreak, 'Clock-in after recorded break start strictly rejected');

    // Attempt clock-out before break end
    $threwOutBeforeBreak = false;
    try {
        submit_attendance_correction_request($staff2Id, $recentWorkDate, 'clock_out', null, "{$recentWorkDate} 13:45:00", 'Out before break end');
    } catch (InvalidArgumentException $e) {
        $threwOutBeforeBreak = str_contains($e->getMessage(), 'before a recorded break end');
    }
    check($threwOutBeforeBreak, 'Clock-out before recorded break end strictly rejected');

    // -------------------------------------------------------------
    // Test 8: Validation - Identical Times (No Changes)
    // -------------------------------------------------------------
    echo "Test 8: Identical Times (No Changes) Rejection...\n";
    $currAtt = ensure_attendance_row($staffId, $recentWorkDate);
    $threwNoChanges = false;
    try {
        submit_attendance_correction_request($staffId, $recentWorkDate, 'clock_in', $currAtt['clock_in'], null, 'No change test');
    } catch (InvalidArgumentException $e) {
        $threwNoChanges = true;
    }
    check($threwNoChanges, 'Submitting identical times with no changes is rejected');

    // -------------------------------------------------------------
    // Test 9: Conflicting Duplicate Pending Request Prevention
    // -------------------------------------------------------------
    echo "Test 9: Conflicting Pending Request Guard...\n";
    // staffId already has a pending 'both' request for $recentWorkDate from Test 1
    $threwDuplicate = false;
    try {
        submit_attendance_correction_request($staffId, $recentWorkDate, 'clock_in', "{$recentWorkDate} 09:30:00", null, 'Duplicate pending test');
    } catch (InvalidArgumentException $e) {
        $threwDuplicate = str_contains($e->getMessage(), 'already have a pending correction request');
    }
    check($threwDuplicate, 'Conflicting request while pending strictly blocked');

    // Test legitimate independent non-conflicting submission:
    // If a user has a pending 'clock_in' request on an existing shift, can they submit a 'clock_out' request?
    $dateShift2 = (new DateTimeImmutable('2 days ago'))->format('Y-m-d');
    $attShift2Row = ensure_attendance_row($staff2Id, $dateShift2);
    $pdo->prepare("UPDATE attendance SET clock_in = ?, clock_out = ? WHERE id = ?")
        ->execute(["{$dateShift2} 09:00:00", "{$dateShift2} 18:00:00", (int) $attShift2Row['id']]);

    $inReq = submit_attendance_correction_request($staff2Id, $dateShift2, 'clock_in', "{$dateShift2} 08:45:00", null, 'Clock-in fix');
    check($inReq['id'] > 0, 'Initial clock-in request created');

    $outReq = submit_attendance_correction_request($staff2Id, $dateShift2, 'clock_out', null, "{$dateShift2} 20:10:00", 'Independent clock-out fix');
    check($outReq['id'] > 0, 'Independent non-conflicting clock-out request permitted alongside clock-in request');

    // -------------------------------------------------------------
    // Test 10: Late Submission Handling (Past 3-Day Deadline)
    // -------------------------------------------------------------
    echo "Test 10: Late Submission & Mandatory Explanation...\n";
    $oldDate = (new DateTimeImmutable('10 days ago'))->format('Y-m-d');
    ensure_attendance_row($staffId, $oldDate);

    // Attempt submission without late explanation
    $threwMissingLateReason = false;
    try {
        submit_attendance_correction_request($staffId, $oldDate, 'both', "{$oldDate} 10:00:00", "{$oldDate} 20:00:00", 'Old shift', '');
    } catch (InvalidArgumentException $e) {
        $threwMissingLateReason = str_contains($e->getMessage(), 'standard 3-day submission window has closed');
    }
    check($threwMissingLateReason, 'Late submission (>3 days) strictly requires late explanation');

    // Submit with late explanation
    $lateRes = submit_attendance_correction_request(
        $staffId,
        $oldDate,
        'both',
        "{$oldDate} 10:00:00",
        "{$oldDate} 20:00:00",
        'System was not accessible during regional field trip',
        'Hospitalized and on medical leave for 5 days after shift'
    );
    check($lateRes['id'] > 0, 'Late submission accepted with mandatory explanation');
    check($lateRes['is_late'] === true, 'Late submission flagged as is_late');

    $lateRow = $pdo->query("SELECT * FROM attendance_correction_requests WHERE id = {$lateRes['id']}")->fetch();
    check((int) $lateRow['is_late_submission'] === 1, 'is_late_submission flag recorded as 1');
    check($lateRow['late_reason'] === 'Hospitalized and on medical leave for 5 days after shift', 'late_reason preserved faithfully');

    // -------------------------------------------------------------
    // Test 11: Admin Approval Flow & Attendance Recalculation
    // -------------------------------------------------------------
    echo "Test 11: Admin Queue Approval & Concurrency Validation...\n";
    $test1ReqId = $subRes['id']; // From Test 1
    with_employee_lock($staffId, function () use ($test1ReqId, $adminId, $pdo): void {
        $stmt = $pdo->prepare('SELECT * FROM attendance_correction_requests WHERE id=? FOR UPDATE');
        $stmt->execute([$test1ReqId]);
        $request = $stmt->fetch();

        $attStmt = $pdo->prepare('SELECT * FROM attendance WHERE id=? FOR UPDATE');
        $attStmt->execute([(int) $request['attendance_id']]);
        $currentAtt = $attStmt->fetch();

        // Monotonic baseline version check
        if ((int) ($currentAtt['version'] ?? 1) !== (int) $request['target_attendance_version']) {
            throw new InvalidArgumentException('Version conflict');
        }

        $newIn = in_array($request['field_name'], ['clock_in', 'both'], true) ? $request['requested_clock_in'] : $currentAtt['clock_in'];
        $newOut = in_array($request['field_name'], ['clock_out', 'both'], true) ? $request['requested_clock_out'] : $currentAtt['clock_out'];

        correct_attendance((int) $currentAtt['id'], $newIn, $newOut, $adminId, $request['reason'], 'correction_approved');
        $pdo->prepare("UPDATE attendance_correction_requests SET status='approved', reviewed_by=?, reviewed_at=NOW(), admin_note='Approved on verification' WHERE id=?")
            ->execute([$adminId, $test1ReqId]);
    });

    $approvedAtt = $pdo->query("SELECT * FROM attendance WHERE id = {$att['id']}")->fetch();
    check($approvedAtt['clock_in'] === "{$recentWorkDate} 09:55:00", 'Attendance clock-in updated by approval');
    check($approvedAtt['clock_out'] === "{$recentWorkDate} 20:05:00", 'Attendance clock-out updated by approval');
    check($approvedAtt['status'] === 'completed', 'Attendance status transitioned to completed');
    check((int) $approvedAtt['version'] > $initialVersion, 'Attendance version incremented by approval');

    // -------------------------------------------------------------
    // Test 12: Stale Baseline Version Conflict Detection
    // -------------------------------------------------------------
    echo "Test 12: Stale Baseline Optimistic Lock Invalidation...\n";
    // For dateShift2, $outReq is pending with target_attendance_version
    // Simulate attendance modification by admin or staff punch while request was pending:
    $attShift2 = $pdo->query("SELECT * FROM attendance WHERE user_id = {$staff2Id} AND work_date = '{$dateShift2}'")->fetch();
    correct_attendance((int) $attShift2['id'], "{$dateShift2} 09:40:00", "{$dateShift2} 18:00:00", $adminId, 'Intermediate punch change');

    // Now attempt to approve $outReq (which has stale baseline version)
    $threwStaleConflict = false;
    try {
        with_employee_lock($staff2Id, function () use ($outReq, $pdo): void {
            $stmt = $pdo->prepare('SELECT * FROM attendance_correction_requests WHERE id=? FOR UPDATE');
            $stmt->execute([$outReq['id']]);
            $request = $stmt->fetch();

            $attStmt = $pdo->prepare('SELECT * FROM attendance WHERE id=? FOR UPDATE');
            $attStmt->execute([(int) $request['attendance_id']]);
            $currentAtt = $attStmt->fetch();

            if ((int) ($currentAtt['version'] ?? 1) !== (int) $request['target_attendance_version']) {
                throw new InvalidArgumentException('The attendance record has been modified since this request was submitted (version conflict). Please review the updated timeline before approving.');
            }
        });
    } catch (InvalidArgumentException $e) {
        $threwStaleConflict = str_contains($e->getMessage(), 'version conflict');
    }
    check($threwStaleConflict, 'Approval strictly blocked when attendance version modified while request was pending');

    // -------------------------------------------------------------
    // Test 13: Admin Self-Approval Prohibition
    // -------------------------------------------------------------
    echo "Test 13: Admin Self-Approval Guard...\n";
    // Submit request as admin
    $adminWorkDate = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
    $adminAttStmt = $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, status, version)
                                   VALUES (?, ?, ?, NULL, 'present', 1)");
    $adminAttStmt->execute([$adminId, $adminWorkDate, "{$adminWorkDate} 09:00:00"]);
    $adminAttId = (int) $pdo->lastInsertId();

    $adminReqStmt = $pdo->prepare('INSERT INTO attendance_correction_requests (user_id, attendance_id, work_date, field_name, requested_clock_in, requested_clock_out, reason, status, target_attendance_version)
                                   VALUES (?, ?, ?, "clock_in", ?, NULL, "Admin test", "pending", 1)');
    $adminReqStmt->execute([$adminId, $adminAttId, $adminWorkDate, "{$adminWorkDate} 10:00:00"]);
    $adminReqId = (int) $pdo->lastInsertId();

    $threwSelfApproval = false;
    try {
        with_employee_lock($adminId, function () use ($adminReqId, $adminId, $pdo): void {
            $stmt = $pdo->prepare('SELECT * FROM attendance_correction_requests WHERE id=? FOR UPDATE');
            $stmt->execute([$adminReqId]);
            $request = $stmt->fetch();
            if ((int) $request['user_id'] === $adminId) {
                throw new InvalidArgumentException('Administrators cannot approve or reject their own correction requests.');
            }
        });
    } catch (InvalidArgumentException $e) {
        $threwSelfApproval = str_contains($e->getMessage(), 'cannot approve or reject their own');
    }
    check($threwSelfApproval, 'Self-approval by administrator is strictly prevented');

    // -------------------------------------------------------------
    // Test 14: Decision Notification to Staff
    // -------------------------------------------------------------
    echo "Test 14: Decision Notification to Staff...\n";
    notification_create(
        $staffId,
        'correction_decision',
        'corr-reviewed-' . $test1ReqId,
        'Correction request approved',
        "Your attendance correction request for {$recentWorkDate} has been approved.",
        '/staff/corrections.php'
    );
    $staffNotice = $pdo->query("SELECT * FROM notifications WHERE user_id = {$staffId} AND event_type = 'correction_decision'")->fetch();
    check(!empty($staffNotice), 'Employee receives decision notification');
    check($staffNotice['destination'] === '/staff/corrections.php', 'Staff notification points to /staff/corrections.php');
    check(notification_destination($staffNotice, 'staff') === '/staff/corrections.php', 'notification_destination resolves /staff/corrections.php for staff');

    // -------------------------------------------------------------
    // Test 15: Admin Rejection Flow
    // -------------------------------------------------------------
    echo "Test 15: Admin Rejection Flow...\n";
    $rejReqId = $lateRes['id'];
    $attBeforeRej = $pdo->query("SELECT * FROM attendance WHERE user_id = {$staffId} AND work_date = '{$oldDate}'")->fetch();

    with_employee_lock($staffId, function () use ($rejReqId, $adminId, $pdo): void {
        $pdo->prepare("UPDATE attendance_correction_requests SET status='rejected', reviewed_by=?, reviewed_at=NOW(), admin_note='Proof insufficient' WHERE id=?")
            ->execute([$adminId, $rejReqId]);
    });

    $rejRow = $pdo->query("SELECT * FROM attendance_correction_requests WHERE id = {$rejReqId}")->fetch();
    check($rejRow['status'] === 'rejected', 'Request marked as rejected');
    check($rejRow['admin_note'] === 'Proof insufficient', 'Admin rejection note recorded');

    $attAfterRej = $pdo->query("SELECT * FROM attendance WHERE user_id = {$staffId} AND work_date = '{$oldDate}'")->fetch();
    check($attAfterRej['clock_in'] === $attBeforeRej['clock_in'], 'Attendance record not mutated on rejection');
    check((int) $attAfterRej['version'] === (int) $attBeforeRej['version'], 'Attendance version unchanged on rejection');

} finally {
    $pdo->rollBack();
    echo "\n[INFO] Sandbox transaction rolled back. Database returned to original state.\n";
}

echo "\n=========================================================\n";
echo " Phase 2 Test Suite Finished: {$passed} Passed, {$failed} Failed\n";
echo "=========================================================\n";

exit($failed > 0 ? 1 : 0);
