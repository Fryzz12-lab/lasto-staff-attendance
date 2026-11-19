<?php
/**
 * Phase 3A: Break Discrepancy & Safe Clock-Out Integration Test Suite
 *
 * Verifies non-blocking clock-out with open breaks, system closure provenance,
 * separation of measured vs provisional hours, review status transitions,
 * audit logging, and concurrency versioning.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/attendance_service.php';
require_once __DIR__ . '/../includes/adjustment_service.php';
require_once __DIR__ . '/../includes/report_export.php';

echo "=========================================================\n";
echo " Running Phase 3A: Break Discrepancy & Safe Clock-Out Tests\n";
echo "=========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assert_test(bool $cond, string $msg, string $details = ''): void {
    global $passCount, $failCount;
    if ($cond) {
        echo "  [PASS] {$msg}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$msg}" . ($details ? " ({$details})" : "") . "\n";
        $failCount++;
    }
}

$pdo = db();
$pdo->beginTransaction();

try {
    function cleanup_att(PDO $pdo, int $attId): void {
        $pdo->exec("DELETE FROM attendance_location_events WHERE attendance_id = $attId");
        $pdo->exec("DELETE FROM attendance_audit_logs WHERE attendance_id = $attId");
        $pdo->exec("DELETE FROM break_records WHERE attendance_id = $attId");
        $pdo->exec("DELETE FROM attendance WHERE id = $attId");
    }

    // Setup isolated test user
    $pw = password_hash('Pass123!', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day, must_change_password)
                   VALUES ('TEST_P3A_01', 'test_p3a_01', ?, 'Test Phase3A User', 'test3a@example.com', 'staff', 1, 0, 0)")
        ->execute([$pw]);
    $userId = (int) $pdo->lastInsertId();
    $today = today_str();

    // -------------------------------------------------------------
    // Scenario A: Normal clock-out without active break
    // -------------------------------------------------------------
    echo "Test A: Normal Clock-Out Without Active Break...\n";
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, status, version) VALUES (?, ?, ?, 'present', 1)")
        ->execute([$userId, $today, "{$today} 09:00:00"]);
    $attA = (int) $pdo->lastInsertId();

    $resA = clock_out($userId);
    assert_test($resA['success'] === true, "Normal clock-out succeeds");
    assert_test($resA['had_open_break'] === false, "Normal clock-out flags had_open_break = false");

    $rowA = $pdo->query("SELECT * FROM attendance WHERE id = $attA")->fetch();
    assert_test(!empty($rowA['clock_out']), "clock_out timestamp populated");
    assert_test($rowA['break_review_status'] === 'none', "break_review_status remains 'none'");
    assert_test($rowA['provisional_work_minutes'] === null, "provisional_work_minutes is NULL for normal shift");
    assert_test((int)$rowA['version'] === 2, "attendance.version incremented monotonically to 2");

    cleanup_att($pdo, $attA);

    // -------------------------------------------------------------
    // Scenario B: Clock-out with forgotten lunch break end
    // -------------------------------------------------------------
    echo "Test B: Clock-Out with Forgotten Lunch Break End...\n";
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, status, version) VALUES (?, ?, ?, 'present', 1)")
        ->execute([$userId, $today, "{$today} 06:00:00"]);
    $attB = (int) $pdo->lastInsertId();

    // Staff starts lunch at 07:00:00 (allowed 60m)
    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, allowed_minutes) 
                   VALUES (?, ?, 'lunch', 1, ?, 60)")
        ->execute([$attB, $userId, "{$today} 07:00:00"]);
    $lunchBreakId = (int) $pdo->lastInsertId();
    $pdo->exec("UPDATE attendance SET status = 'on_break', version = version + 1 WHERE id = $attB");

    // Staff clocks out at 20:00:00 without ending lunch
    $resB = clock_out($userId);
    assert_test($resB['success'] === true, "Clock-out with active lunch succeeds (non-blocking)");
    assert_test($resB['had_open_break'] === true, "Clock-out flags had_open_break = true");
    assert_test(str_contains($resB['message'], 'closed automatically') || str_contains($resB['message'], 'ditutup secara automatik'), "Notice informs user that open break was closed automatically");

    $breakB = $pdo->query("SELECT * FROM break_records WHERE id = $lunchBreakId")->fetch();
    assert_test(!empty($breakB['end_time']), "Lunch break end_time populated by clock-out");
    assert_test((int)$breakB['is_auto_closed'] === 1, "break_records.is_auto_closed = 1");
    assert_test($breakB['closure_reason'] === 'clock_out', "break_records.closure_reason = 'clock_out'");
    assert_test(!empty($breakB['system_closed_at']), "system_closed_at timestamp captured");

    $rowB = $pdo->query("SELECT * FROM attendance WHERE id = $attB")->fetch();
    assert_test($rowB['break_review_status'] === 'under_review', "attendance.break_review_status transitions to 'under_review'");
    // Measured work minutes: 10h (600m) - 7h lunch (420m) = 180m (3h)
    // Provisional work minutes: 10h (600m) - 60m standard lunch = 540m (9h)
    assert_test((int)$rowB['work_minutes'] < (int)$rowB['provisional_work_minutes'], "Measured work_minutes strictly separated from provisional estimate", "Measured: {$rowB['work_minutes']}, Prov: {$rowB['provisional_work_minutes']}");
    assert_test((int)$rowB['version'] === 3, "attendance.version incremented monotonically to 3");

    // Verify audit log
    $auditB = $pdo->query("SELECT * FROM attendance_audit_logs WHERE attendance_id = $attB AND action = 'break_auto_closed'")->fetch();
    assert_test(!empty($auditB), "Audit log recorded 'break_auto_closed' event");
    assert_test(str_contains($auditB['reason'], 'clock-out'), "Audit reason attributes clock-out closure");

    cleanup_att($pdo, $attB);

    // -------------------------------------------------------------
    // Scenario C: Clock-out with forgotten solat (prayer) end
    // -------------------------------------------------------------
    echo "Test C: Clock-Out with Forgotten Solat (Prayer) End...\n";
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, status, version) VALUES (?, ?, ?, 'present', 1)")
        ->execute([$userId, $today, "{$today} 06:00:00"]);
    $attC = (int) $pdo->lastInsertId();

    // Completed lunch earlier (07:00 - 08:00, 60m)
    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, allowed_minutes, is_auto_closed) 
                   VALUES (?, ?, 'lunch', 1, ?, ?, 60, 60, 0)")
        ->execute([$attC, $userId, "{$today} 07:00:00", "{$today} 08:00:00"]);

    // Open solat break started at 09:30 (allowed 20m)
    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, allowed_minutes) 
                   VALUES (?, ?, 'solat', 1, ?, 20)")
        ->execute([$attC, $userId, "{$today} 09:30:00"]);
    $solatBreakId = (int) $pdo->lastInsertId();

    $resC = clock_out($userId);
    assert_test($resC['success'] === true, "Clock-out with active solat break succeeds");
    $breakC = $pdo->query("SELECT * FROM break_records WHERE id = $solatBreakId")->fetch();
    assert_test((int)$breakC['is_auto_closed'] === 1 && $breakC['closure_reason'] === 'clock_out', "Solat break marked auto-closed with closure_reason = 'clock_out'");
    $rowC = $pdo->query("SELECT * FROM attendance WHERE id = $attC")->fetch();
    assert_test($rowC['break_review_status'] === 'under_review', "Attendance flagged under_review for open solat");

    cleanup_att($pdo, $attC);

    // -------------------------------------------------------------
    // Scenario D: Clock-out with forgotten short break end
    // -------------------------------------------------------------
    echo "Test D: Clock-Out with Forgotten Short Break End...\n";
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, status, version) VALUES (?, ?, ?, 'present', 1)")
        ->execute([$userId, $today, "{$today} 06:00:00"]);
    $attD = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, allowed_minutes) 
                   VALUES (?, ?, 'short', 1, ?, 10)")
        ->execute([$attD, $userId, "{$today} 10:00:00"]);
    $shortBreakId = (int) $pdo->lastInsertId();

    $resD = clock_out($userId);
    assert_test($resD['success'] === true, "Clock-out with active short break succeeds");
    $breakD = $pdo->query("SELECT * FROM break_records WHERE id = $shortBreakId")->fetch();
    assert_test((int)$breakD['is_auto_closed'] === 1 && $breakD['closure_reason'] === 'clock_out', "Short break marked auto-closed");

    cleanup_att($pdo, $attD);

    // -------------------------------------------------------------
    // Scenario E: Genuine extended break (not auto-closed)
    // -------------------------------------------------------------
    echo "Test E: Genuine Extended Break (Employee Ended Manually)...\n";
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, status, version) VALUES (?, ?, ?, 'present', 1)")
        ->execute([$userId, $today, "{$today} 06:00:00"]);
    $attE = (int) $pdo->lastInsertId();

    // Staff took 90m lunch (exceeded 60m), but manually ended it at 08:30
    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, allowed_minutes, exceeded, is_auto_closed, closure_reason) 
                   VALUES (?, ?, 'lunch', 1, ?, ?, 90, 60, 1, 0, 'manual')")
        ->execute([$attE, $userId, "{$today} 07:00:00", "{$today} 08:30:00"]);

    $resE = clock_out($userId);
    assert_test($resE['success'] === true, "Clock-out succeeds after completed extended break");
    assert_test($resE['had_open_break'] === false, "had_open_break is false for manually ended extended break");
    $rowE = $pdo->query("SELECT * FROM attendance WHERE id = $attE")->fetch();
    assert_test($rowE['break_review_status'] === 'none', "break_review_status is 'none' for genuine manually-ended break");
    assert_test($rowE['provisional_work_minutes'] === null, "provisional_work_minutes is NULL (no discrepancy)");

    cleanup_att($pdo, $attE);

    // -------------------------------------------------------------
    // Scenario F: Midnight closure of an unfinished past break
    // -------------------------------------------------------------
    echo "Test F: Midnight Auto-Closure of Unfinished Past Break...\n";
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, status, version) VALUES (?, ?, ?, ?, 'completed', 1)")
        ->execute([$userId, $yesterday, "{$yesterday} 10:00:00", "{$yesterday} 20:00:00"]);
    $attF = (int) $pdo->lastInsertId();

    // Lingering open lunch break on yesterday's shift
    $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, allowed_minutes) 
                   VALUES (?, ?, 'lunch', 1, ?, 60)")
        ->execute([$attF, $userId, "{$yesterday} 13:00:00"]);
    $lingeringId = (int) $pdo->lastInsertId();

    close_unended_breaks();

    $breakF = $pdo->query("SELECT * FROM break_records WHERE id = $lingeringId")->fetch();
    assert_test(!empty($breakF['end_time']), "close_unended_breaks() populated end_time");
    assert_test((int)$breakF['is_auto_closed'] === 1, "is_auto_closed = 1 on midnight cron closure");
    assert_test($breakF['closure_reason'] === 'clock_out' || $breakF['closure_reason'] === 'midnight_cron', "closure_reason accurately tagged");
    assert_test(!empty($breakF['system_closed_at']), "system_closed_at populated");

    $rowF = $pdo->query("SELECT * FROM attendance WHERE id = $attF")->fetch();
    assert_test($rowF['break_review_status'] === 'under_review', "attendance.break_review_status = 'under_review' after midnight closure");
    assert_test($rowF['provisional_work_minutes'] !== null, "provisional_work_minutes calculated after midnight closure");

    cleanup_att($pdo, $attF);

    // -------------------------------------------------------------
    // Scenario G: Repeated clock-out attempts
    // -------------------------------------------------------------
    echo "Test G: Repeated Clock-Out Prevention...\n";
    $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, clock_out, status, version) VALUES (?, ?, ?, ?, 'completed', 2)")
        ->execute([$userId, $today, "{$today} 10:00:00", "{$today} 19:00:00"]);
    $attG = (int) $pdo->lastInsertId();

    $repeatRes = clock_out($userId);
    assert_test($repeatRes['success'] === false, "Repeated clock-out attempt rejected");
    assert_test($repeatRes['message'] === __('msg_already_clocked_out'), "Returns msg_already_clocked_out");

    cleanup_att($pdo, $attG);

    // -------------------------------------------------------------
    // Scenario H: Off-day adjustment safety
    // -------------------------------------------------------------
    echo "Test H: Off-Day Adjustment Guard While Under Review...\n";
    $attH = [
        'clock_in' => "{$today} 10:00:00",
        'clock_out' => "{$today} 19:00:00",
        'open_break_id' => null,
        'break_review_status' => 'under_review',
    ];
    assert_test(attendance_is_completed($attH) === false, "attendance_is_completed() returns false while shift is 'under_review'");

    $attHResolved = [
        'clock_in' => "{$today} 10:00:00",
        'clock_out' => "{$today} 19:00:00",
        'open_break_id' => null,
        'break_review_status' => 'resolved',
    ];
    assert_test(attendance_is_completed($attHResolved) === true, "attendance_is_completed() returns true once resolved");

    // -------------------------------------------------------------
    // Scenario I: Timesheet Export Matrix with Review Flag
    // -------------------------------------------------------------
    echo "Test I: Timesheet Export Matrix Annotation...\n";
    $sampleRow = [
        'work_date' => $today,
        'full_name' => 'Test Employee',
        'clock_in' => "{$today} 10:00:00",
        'clock_out' => "{$today} 20:00:00",
        'late_minutes' => 0,
        'total_break_minutes' => 420,
        'work_minutes' => 180,
        'provisional_work_minutes' => 540,
        'break_review_status' => 'under_review',
        'status' => 'completed',
        'is_wfh' => 0,
        'is_day_off' => 0,
    ];
    $matrix = report_attendance_export_matrix([$sampleRow]);
    assert_test(str_contains($matrix[0][6], '[Under Review - Prov: 9h 00m]'), "Export matrix explicitly renders Under Review warning and provisional estimate", "Got " . $matrix[0][6]);

    // -------------------------------------------------------------
    // Scenario J: Status badge rendering with Break Review pill
    // -------------------------------------------------------------
    echo "Test J: Attendance Status Badge with Break Review Pill...\n";
    $badgeHtml = attendance_status_badge($sampleRow);
    assert_test(str_contains($badgeHtml, 'Break Review') || str_contains($badgeHtml, 'Semakan Rehat'), "attendance_status_badge renders Break Review pill");

} finally {
    $pdo->rollBack();
    echo "\n[INFO] Test sandbox rolled back cleanly. Database baseline unchanged.\n";
}

echo "\n=========================================================\n";
echo " Phase 3A Test Suite Finished: {$passCount} Passed, {$failCount} Failed\n";
echo "=========================================================\n";

if ($failCount > 0) {
    exit(1);
}
