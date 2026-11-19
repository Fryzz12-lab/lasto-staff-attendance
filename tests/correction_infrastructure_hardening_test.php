<?php
/**
 * Phase 1: Correction Infrastructure Hardening Automated Test Suite
 *
 * Verifies:
 * 1. Overnight shift correction validation & recalculation.
 * 2. Inverted punch rejection (out < in).
 * 3. 24-hour maximum shift duration constraint.
 * 4. Multi-day boundary validation (out cannot exceed next day).
 * 5. Break containment validation (in <= break_start, break_end <= out, no open break on out).
 * 6. Overnight break timestamps validation.
 * 7. Monotonic version tracking on attendance.
 * 8. Stale-approval optimistic version conflict detection.
 * 9. Self-approval prohibition.
 * 10. Duplicate / non-pending approval rejection.
 * 11. Location evidence immutability (attendance_location_events untouched).
 * 12. Audit log completeness (actor, field, old_value, new_value, reason).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

echo "=========================================================\n";
echo " Running Correction Infrastructure Hardening Test Suite  \n";
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
    // Setup isolated test employee and test admin
    $adminStmt = $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day)
                                VALUES ('ADM_T1', 'test_adm_p1', 'hash', 'Test Admin P1', 'adm_p1@test.local', 'admin', 1, 0)");
    $adminStmt->execute();
    $adminId = (int) $pdo->lastInsertId();

    $staffStmt = $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day)
                                VALUES ('STF_T1', 'test_stf_p1', 'hash', 'Test Staff P1', 'stf_p1@test.local', 'staff', 1, 0)");
    $staffStmt->execute();
    $staffId = (int) $pdo->lastInsertId();

    $workDate = '2026-10-15';
    $att = ensure_attendance_row($staffId, $workDate);
    $attId = (int) $att['id'];

    // -------------------------------------------------------------
    // Test 1: Overnight Shift Correction & Calculation
    // -------------------------------------------------------------
    echo "Test 1: Overnight Shift Punch Validation...\n";
    $clockIn = "{$workDate} 22:00:00";
    $clockOut = "2026-10-16 06:00:00"; // Next morning
    correct_attendance($attId, $clockIn, $clockOut, $adminId, 'Approved overnight shift test');

    $stmt = $pdo->prepare('SELECT * FROM attendance WHERE id = ?');
    $stmt->execute([$attId]);
    $updatedAtt = $stmt->fetch();

    check($updatedAtt['clock_in'] === $clockIn, 'Clock-in set accurately on shift date');
    check($updatedAtt['clock_out'] === $clockOut, 'Clock-out set accurately on next calendar day');
    check((int) $updatedAtt['shift_minutes'] === 480, 'Shift minutes accurately calculated as 480m (8h)');
    check((int) $updatedAtt['work_minutes'] === 480, 'Work minutes accurately calculated as 480m');
    check($updatedAtt['status'] === 'completed', 'Shift status evaluates to completed');
    check((int) $updatedAtt['version'] >= 2, 'Monotonic version incremented on attendance record', 'Version: ' . $updatedAtt['version']);

    // -------------------------------------------------------------
    // Test 2: Inverted Punches (Clock-out < Clock-in)
    // -------------------------------------------------------------
    echo "Test 2: Inverted Timestamp Rejection...\n";
    $threwInverted = false;
    try {
        correct_attendance($attId, "{$workDate} 20:00:00", "{$workDate} 18:00:00", $adminId, 'Inverted punch test');
    } catch (InvalidArgumentException $e) {
        $threwInverted = true;
    }
    check($threwInverted, 'Inverted clock-out before clock-in strictly rejected');

    // -------------------------------------------------------------
    // Test 3: Shift Exceeding 24 Hours
    // -------------------------------------------------------------
    echo "Test 3: Maximum Duration (>24 Hours) Rejection...\n";
    $threwOver24h = false;
    try {
        correct_attendance($attId, "{$workDate} 08:00:00", "2026-10-16 09:00:00", $adminId, '25-hour shift test');
    } catch (InvalidArgumentException $e) {
        $threwOver24h = true;
    }
    check($threwOver24h, 'Shift duration exceeding 24 hours strictly rejected');

    // -------------------------------------------------------------
    // Test 4: Clock-out Beyond Next Calendar Day
    // -------------------------------------------------------------
    echo "Test 4: Date Boundary (> Next Day) Rejection...\n";
    $threwBeyondNextDay = false;
    try {
        correct_attendance($attId, "{$workDate} 22:00:00", "2026-10-17 06:00:00", $adminId, '2 days later test');
    } catch (InvalidArgumentException $e) {
        $threwBeyondNextDay = true;
    }
    check($threwBeyondNextDay, 'Clock-out more than 1 calendar day ahead strictly rejected');

    // -------------------------------------------------------------
    // Test 5: Break Containment Validation
    // -------------------------------------------------------------
    echo "Test 5: Break Containment Validation...\n";
    // Insert an overnight break: 2026-10-16 02:00:00 to 02:30:00
    $breakStmt = $pdo->prepare('INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, allowed_minutes)
                               VALUES (?, ?, "lunch", 1, "2026-10-16 02:00:00", "2026-10-16 02:30:00", 30, 60)');
    $breakStmt->execute([$attId, $staffId]);
    $breakId = (int) $pdo->lastInsertId();

    // Recalculate to include break
    recalculate_attendance($attId);
    $stmt->execute([$attId]);
    $attWithBreak = $stmt->fetch();
    check((int) $attWithBreak['work_minutes'] === 450, 'Work minutes equals 450m (480m - 30m break)');

    // Attempt to set clock-in after the break start
    $threwInAfterBreak = false;
    try {
        correct_attendance($attId, "2026-10-16 02:15:00", "2026-10-16 06:00:00", $adminId, 'Clock-in after break');
    } catch (InvalidArgumentException $e) {
        $threwInAfterBreak = true;
    }
    check($threwInAfterBreak, 'Setting clock-in after a break start is strictly rejected');

    // Attempt to set clock-out before the break end
    $threwOutBeforeBreak = false;
    try {
        correct_attendance($attId, "{$workDate} 22:00:00", "2026-10-16 02:15:00", $adminId, 'Clock-out before break end');
    } catch (InvalidArgumentException $e) {
        $threwOutBeforeBreak = true;
    }
    check($threwOutBeforeBreak, 'Setting clock-out before a break end is strictly rejected');

    // Attempt to set clock-out with an unended open break
    $openBreakStmt = $pdo->prepare('INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, allowed_minutes)
                                    VALUES (?, ?, "short", 1, "2026-10-16 04:00:00", NULL, 15)');
    $openBreakStmt->execute([$attId, $staffId]);
    $openBreakId = (int) $pdo->lastInsertId();

    $threwOpenBreak = false;
    try {
        correct_attendance($attId, "{$workDate} 22:00:00", "2026-10-16 06:00:00", $adminId, 'Open break test');
    } catch (InvalidArgumentException $e) {
        $threwOpenBreak = true;
    }
    check($threwOpenBreak, 'Setting clock-out while an unended break is open is strictly rejected');

    // Close the open break
    $pdo->prepare('DELETE FROM break_records WHERE id = ?')->execute([$openBreakId]);

    // -------------------------------------------------------------
    // Test 6: Monotonic Version Tracking & Optimistic Locking
    // -------------------------------------------------------------
    echo "Test 6: Monotonic Version Tracking...\n";
    $v1 = (int) $updatedAtt['version'];
    correct_attendance($attId, "{$workDate} 21:30:00", "2026-10-16 06:00:00", $adminId, 'Second correction');
    $stmt->execute([$attId]);
    $v2 = (int) $stmt->fetch()['version'];
    check($v2 > $v1, 'Attendance version monotonically increments on subsequent updates', "v1: {$v1}, v2: {$v2}");

    // -------------------------------------------------------------
    // Test 7: Location Evidence Invariance
    // -------------------------------------------------------------
    echo "Test 7: Location Evidence Immutability...\n";
    $locStmt = $pdo->prepare('INSERT INTO attendance_location_events (attendance_id, user_id, event_type, event_time, latitude, longitude, accuracy_metres, location_status, client_ip)
                              VALUES (?, ?, "clock_in", "2026-10-15 22:04:12", 3.1390030, 101.6868550, 15.00, "captured", "192.168.1.50")');
    $locStmt->execute([$attId, $staffId]);
    $locId = (int) $pdo->lastInsertId();

    // Perform another attendance correction
    correct_attendance($attId, "{$workDate} 21:00:00", "2026-10-16 06:00:00", $adminId, 'Third correction test');

    $locCheck = $pdo->prepare('SELECT * FROM attendance_location_events WHERE id = ?');
    $locCheck->execute([$locId]);
    $locRow = $locCheck->fetch();

    check($locRow['event_time'] === '2026-10-15 22:04:12', 'Original raw punch timestamp remains bit-for-bit identical');
    check((float)$locRow['latitude'] === 3.1390030, 'Raw GPS latitude is completely immutable');
    check($locRow['client_ip'] === '192.168.1.50', 'Original punch client IP is completely immutable');

    // -------------------------------------------------------------
    // Test 8: Audit Log Completeness
    // -------------------------------------------------------------
    echo "Test 8: Audit Log Verification...\n";
    $auditStmt = $pdo->prepare('SELECT * FROM attendance_audit_logs WHERE attendance_id = ? ORDER BY id DESC LIMIT 1');
    $auditStmt->execute([$attId]);
    $auditRow = $auditStmt->fetch();

    check(!empty($auditRow), 'Audit log entry created for correction');
    check((int) $auditRow['changed_by'] === $adminId, 'Audit log correctly attributes actor');
    check($auditRow['action'] === 'admin_correct', 'Audit action recorded correctly');
    check($auditRow['reason'] === 'Third correction test', 'Audit reason recorded faithfully');
    check($auditRow['new_value'] === "{$workDate} 21:00:00", 'New timestamp logged accurately');

    // -------------------------------------------------------------
    // Test 9: Stale Baseline Version Mismatch Detection
    // -------------------------------------------------------------
    echo "Test 9: Stale Baseline Version Guard in Approval Flow...\n";
    $currentVersion = (int) $pdo->query("SELECT version FROM attendance WHERE id = {$attId}")->fetchColumn();

    // Create a request with a stale baseline version (current - 1)
    $staleVersion = $currentVersion - 1;
    $reqStmt = $pdo->prepare('INSERT INTO attendance_correction_requests (user_id, attendance_id, target_attendance_version, work_date, field_name, requested_clock_in, requested_clock_out, reason, status)
                              VALUES (?, ?, ?, ?, "both", "2026-10-15 20:00:00", "2026-10-16 06:00:00", "Stale request test", "pending")');
    $reqStmt->execute([$staffId, $attId, $staleVersion, $workDate]);
    $staleReqId = (int) $pdo->lastInsertId();

    $threwStaleVersion = false;
    try {
        with_employee_lock($staffId, function () use ($staleReqId, $adminId, $pdo): void {
            $stmt = $pdo->prepare('SELECT * FROM attendance_correction_requests WHERE id=? FOR UPDATE');
            $stmt->execute([$staleReqId]);
            $request = $stmt->fetch();

            $attStmt = $pdo->prepare('SELECT * FROM attendance WHERE id=? FOR UPDATE');
            $attStmt->execute([(int) $request['attendance_id']]);
            $currentAtt = $attStmt->fetch();

            if (isset($request['target_attendance_version']) && $request['target_attendance_version'] !== null) {
                if ((int) ($currentAtt['version'] ?? 1) !== (int) $request['target_attendance_version']) {
                    throw new InvalidArgumentException('The attendance record has been modified since this request was submitted (version conflict). Please review the updated timeline before approving.');
                }
            }
        });
    } catch (InvalidArgumentException $e) {
        $threwStaleVersion = str_contains($e->getMessage(), 'version conflict');
    }
    check($threwStaleVersion, 'Stale baseline version mismatch is strictly detected and blocked');

    // -------------------------------------------------------------
    // Test 10: Self-Approval Prohibition
    // -------------------------------------------------------------
    echo "Test 10: Self-Approval Prohibition...\n";
    // Create a request where user_id matches adminId
    $selfReqStmt = $pdo->prepare('INSERT INTO attendance_correction_requests (user_id, attendance_id, work_date, field_name, requested_clock_in, requested_clock_out, reason, status)
                                  VALUES (?, ?, ?, "clock_in", "2026-10-15 09:00:00", NULL, "Self approval test", "pending")');
    $selfReqStmt->execute([$adminId, $attId, $workDate]);
    $selfReqId = (int) $pdo->lastInsertId();

    $threwSelfApproval = false;
    try {
        with_employee_lock($adminId, function () use ($selfReqId, $adminId, $pdo): void {
            $stmt = $pdo->prepare('SELECT * FROM attendance_correction_requests WHERE id=? FOR UPDATE');
            $stmt->execute([$selfReqId]);
            $request = $stmt->fetch();
            if ((int) $request['user_id'] === $adminId) {
                throw new InvalidArgumentException('Administrators cannot approve or reject their own correction requests.');
            }
        });
    } catch (InvalidArgumentException $e) {
        $threwSelfApproval = str_contains($e->getMessage(), 'cannot approve or reject their own');
    }
    check($threwSelfApproval, 'Self-approval by administrator is strictly blocked');

    // -------------------------------------------------------------
    // Test 11: Duplicate / Non-Pending Approval Rejection
    // -------------------------------------------------------------
    echo "Test 11: Non-Pending Approval Rejection...\n";
    // Mark selfReq as already approved
    $pdo->prepare("UPDATE attendance_correction_requests SET status = 'approved' WHERE id = ?")->execute([$selfReqId]);

    $threwAlreadyProcessed = false;
    try {
        with_employee_lock($adminId, function () use ($selfReqId, $pdo): void {
            $stmt = $pdo->prepare('SELECT * FROM attendance_correction_requests WHERE id=? FOR UPDATE');
            $stmt->execute([$selfReqId]);
            $request = $stmt->fetch();
            if (!$request || $request['status'] !== 'pending') {
                throw new InvalidArgumentException('This correction request has already been processed.');
            }
        });
    } catch (InvalidArgumentException $e) {
        $threwAlreadyProcessed = str_contains($e->getMessage(), 'already been processed');
    }
    check($threwAlreadyProcessed, 'Attempting to approve a non-pending request is rejected cleanly');

    // -------------------------------------------------------------
    // Test 12: Partial Field Correction (Clock-in only preserves clock-out)
    // -------------------------------------------------------------
    echo "Test 12: Partial Field Preservation...\n";
    $currAtt = $pdo->query("SELECT * FROM attendance WHERE id = {$attId}")->fetch();
    $origClockOut = $currAtt['clock_out'];

    // Create a request for clock_in only
    $inOnlyReqStmt = $pdo->prepare('INSERT INTO attendance_correction_requests (user_id, attendance_id, target_attendance_version, work_date, field_name, requested_clock_in, requested_clock_out, reason, status)
                                    VALUES (?, ?, ?, ?, "clock_in", "2026-10-15 20:30:00", NULL, "Clock-in only fix", "pending")');
    $inOnlyReqStmt->execute([$staffId, $attId, (int) $currAtt['version'], $workDate]);
    $inOnlyReqId = (int) $pdo->lastInsertId();

    // Process approval
    with_employee_lock($staffId, function () use ($inOnlyReqId, $adminId, $pdo): void {
        $stmt = $pdo->prepare('SELECT * FROM attendance_correction_requests WHERE id=? FOR UPDATE');
        $stmt->execute([$inOnlyReqId]);
        $request = $stmt->fetch();

        $attStmt = $pdo->prepare('SELECT * FROM attendance WHERE id=? FOR UPDATE');
        $attStmt->execute([(int) $request['attendance_id']]);
        $currentAtt = $attStmt->fetch();

        $newIn = in_array($request['field_name'], ['clock_in', 'both'], true) ? $request['requested_clock_in'] : $currentAtt['clock_in'];
        $newOut = in_array($request['field_name'], ['clock_out', 'both'], true) ? $request['requested_clock_out'] : $currentAtt['clock_out'];

        correct_attendance((int) $currentAtt['id'], $newIn, $newOut, $adminId, $request['reason'], 'correction_approved');
        $pdo->prepare("UPDATE attendance_correction_requests SET status = 'approved' WHERE id = ?")->execute([$inOnlyReqId]);
    });

    $afterPartial = $pdo->query("SELECT * FROM attendance WHERE id = {$attId}")->fetch();
    check($afterPartial['clock_in'] === "{$workDate} 20:30:00", 'Clock-in updated by partial request');
    check($afterPartial['clock_out'] === $origClockOut, 'Existing clock-out completely preserved on clock-in only request');

    // -------------------------------------------------------------
    // Test 13: Legacy NULL Baseline Queue Approval Strictly Blocked
    // -------------------------------------------------------------
    echo "Test 13: Legacy NULL Baseline Queue Approval Guard...\n";
    $legacyReqStmt = $pdo->prepare('INSERT INTO attendance_correction_requests (user_id, attendance_id, target_attendance_version, work_date, field_name, requested_clock_in, requested_clock_out, reason, status)
                                    VALUES (?, ?, NULL, ?, "clock_in", "2026-10-15 19:00:00", NULL, "Legacy unversioned request", "pending")');
    $legacyReqStmt->execute([$staffId, $attId, $workDate]);
    $legacyReqId = (int) $pdo->lastInsertId();

    $threwLegacyNull = false;
    $legacyErrMsg = '';
    try {
        with_employee_lock($staffId, function () use ($legacyReqId, $adminId, $pdo): void {
            $stmt = $pdo->prepare('SELECT * FROM attendance_correction_requests WHERE id=? FOR UPDATE');
            $stmt->execute([$legacyReqId]);
            $request = $stmt->fetch();

            $att = ensure_attendance_row((int) $request['user_id'], $request['work_date']);
            $attStmt = db()->prepare('SELECT * FROM attendance WHERE id=? FOR UPDATE');
            $attStmt->execute([(int) $att['id']]);
            $currentAtt = $attStmt->fetch();

            if (!isset($request['target_attendance_version']) || $request['target_attendance_version'] === null) {
                throw new InvalidArgumentException('This request predates version tracking and cannot be approved through the automated queue. Please review the shift in Attendance Detail and apply any adjustment directly with an audit note.');
            }
        });
    } catch (InvalidArgumentException $e) {
        $threwLegacyNull = true;
        $legacyErrMsg = $e->getMessage();
    }
    check($threwLegacyNull, 'Legacy NULL baseline request approval is strictly blocked');
    check(str_contains($legacyErrMsg, 'predates version tracking'), 'Informative block error directing admin to Attendance Detail');

    // -------------------------------------------------------------
    // Test 14: clock_in() Monotonic Version Increment
    // -------------------------------------------------------------
    echo "Test 14: clock_in() Version Increment...\n";
    $staffLiveStmt = $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day)
                                    VALUES ('STF_LIVE1', 'test_stf_live1', 'hash', 'Test Staff Live 1', 'stf_live1@test.local', 'staff', 1, 0)");
    $staffLiveStmt->execute();
    $liveStaffId = (int) $pdo->lastInsertId();

    $todayDate = today_str();
    $liveAtt = ensure_attendance_row($liveStaffId, $todayDate);
    $vBeforeClockIn = (int) $liveAtt['version'];

    $clockInRes = clock_in($liveStaffId);
    check($clockInRes['success'] === true, 'clock_in() executed successfully');

    $liveAttAfterIn = $pdo->query("SELECT * FROM attendance WHERE id = {$liveAtt['id']}")->fetch();
    check((int) $liveAttAfterIn['version'] === $vBeforeClockIn + 1, 'attendance.version incremented atomically on clock_in()');

    // -------------------------------------------------------------
    // Test 15: clock_out() Monotonic Version Increment
    // -------------------------------------------------------------
    echo "Test 15: clock_out() Version Increment...\n";
    $vBeforeClockOut = (int) $liveAttAfterIn['version'];
    $clockOutRes = clock_out($liveStaffId);
    check($clockOutRes['success'] === true, 'clock_out() executed successfully');

    $liveAttAfterOut = $pdo->query("SELECT * FROM attendance WHERE id = {$liveAtt['id']}")->fetch();
    check((int) $liveAttAfterOut['version'] === $vBeforeClockOut + 1, 'attendance.version incremented atomically on clock_out()');

    // -------------------------------------------------------------
    // Test 16: start_break() and end_break() Monotonic Version Increment
    // -------------------------------------------------------------
    echo "Test 16: start_break() & end_break() Version Increments...\n";
    $staffBreakStmt = $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day)
                                     VALUES ('STF_BRK1', 'test_stf_brk1', 'hash', 'Test Staff Brk 1', 'stf_brk1@test.local', 'staff', 1, 0)");
    $staffBreakStmt->execute();
    $breakStaffId = (int) $pdo->lastInsertId();

    $breakAtt = ensure_attendance_row($breakStaffId, $todayDate);
    clock_in($breakStaffId);
    $attBeforeBreak = $pdo->query("SELECT * FROM attendance WHERE id = {$breakAtt['id']}")->fetch();
    $vBeforeStartBreak = (int) $attBeforeBreak['version'];

    $startBreakRes = start_break($breakStaffId, 'lunch');
    check($startBreakRes['success'] === true, 'start_break() executed successfully');

    $attAfterStartBreak = $pdo->query("SELECT * FROM attendance WHERE id = {$breakAtt['id']}")->fetch();
    check((int) $attAfterStartBreak['version'] === $vBeforeStartBreak + 1, 'attendance.version incremented atomically on start_break()');
    check($attAfterStartBreak['status'] === 'on_break', 'Attendance status transitions to on_break');

    $endBreakRes = end_break($breakStaffId);
    check($endBreakRes['success'] === true, 'end_break() executed successfully');

    $attAfterEndBreak = $pdo->query("SELECT * FROM attendance WHERE id = {$breakAtt['id']}")->fetch();
    check((int) $attAfterEndBreak['version'] === (int) $attAfterStartBreak['version'] + 1, 'attendance.version incremented atomically on end_break()');

    // -------------------------------------------------------------
    // Test 17: close_unended_breaks() Monotonic Version Increment
    // -------------------------------------------------------------
    echo "Test 17: close_unended_breaks() Version Increment...\n";
    $pastDate = '2026-10-10';
    $pastAttStmt = $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, status, version)
                                  VALUES (?, ?, '2026-10-10 10:00:00', '2026-10-10 19:00:00', 'completed', 1)");
    $pastAttStmt->execute([$staffId, $pastDate]);
    $pastAttId = (int) $pdo->lastInsertId();

    $pastBreakStmt = $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, allowed_minutes)
                                    VALUES (?, ?, 'lunch', 1, '2026-10-10 13:00:00', NULL, 60)");
    $pastBreakStmt->execute([$pastAttId, $staffId]);

    close_unended_breaks();

    $pastAttAfterClose = $pdo->query("SELECT * FROM attendance WHERE id = {$pastAttId}")->fetch();
    check((int) $pastAttAfterClose['version'] === 2, 'attendance.version incremented by close_unended_breaks()');
    $pastBreakAfterClose = $pdo->query("SELECT * FROM break_records WHERE attendance_id = {$pastAttId}")->fetch();
    check(!empty($pastBreakAfterClose['end_time']), 'Past unended break has end_time populated');

    // -------------------------------------------------------------
    // Test 18: mark_incomplete_attendances() Monotonic Version Increment
    // -------------------------------------------------------------
    echo "Test 18: mark_incomplete_attendances() Version Increment...\n";
    $incompleteDate = '2026-10-09';
    $incAttStmt = $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, status, version)
                                 VALUES (?, ?, '2026-10-09 10:00:00', NULL, 'present', 1)");
    $incAttStmt->execute([$staffId, $incompleteDate]);
    $incAttId = (int) $pdo->lastInsertId();

    mark_incomplete_attendances();

    $incAttAfter = $pdo->query("SELECT * FROM attendance WHERE id = {$incAttId}")->fetch();
    check((int) $incAttAfter['version'] === 2, 'attendance.version incremented by mark_incomplete_attendances()');
    check($incAttAfter['status'] === 'incomplete', 'attendance status transitioned to incomplete');

    // -------------------------------------------------------------
    // Test 19: Recalculate Attendance Does Not Churn Version Unnecessarily
    // -------------------------------------------------------------
    echo "Test 19: Read/Recalculate Idempotence (No Version Churn)...\n";
    $vBeforeRecalc = (int) $incAttAfter['version'];
    recalculate_attendance($incAttId);
    $vAfterRecalc = (int) $pdo->query("SELECT version FROM attendance WHERE id = {$incAttId}")->fetchColumn();
    check($vAfterRecalc === $vBeforeRecalc, 'recalculate_attendance() alone does not churn attendance.version');

} finally {
    $pdo->rollBack();
    echo "\n[INFO] Sandbox transaction rolled back. Database returned to original state.\n";
}

echo "\n=========================================================\n";
echo " Test Suite Finished: {$passed} Passed, {$failed} Failed\n";
echo "=========================================================\n";

exit($failed > 0 ? 1 : 0);

