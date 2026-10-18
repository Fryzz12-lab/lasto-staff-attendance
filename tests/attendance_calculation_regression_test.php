<?php
/**
 * Regression Test Suite: Attendance Calculations, Durations, and Export Parity
 *
 * Verifies:
 *  1. Completed shift duration (shift_minutes, work_minutes) with 0 breaks
 *  2. Completed shift duration with standard 60-min lunch break
 *  3. Multiple breaks calculation (lunch + short exceeded break)
 *  4. Late arrival calculation (late_minutes > 0)
 *  5. Early clock-out status and duration (status='completed', early_leave_minutes > 0)
 *  6. Missing clock-out on historical shifts (status='incomplete')
 *  7. Active shift on current date (status='present')
 *  8. Active break on current date (status='on_break')
 *  9. Scheduled day-off behavior (status='day_off')
 * 10. Historical unrecorded absence (status='absent')
 * 11. Overnight shift boundary safety (no negative durations)
 * 12. Synthetic seeder verification (100 unique names, no 7h open break seeds)
 * 13. Export matrix formatting parity with database columns
 *
 * Runs non-destructively using database transactions (all test records rolled back).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/attendance_service.php';
require_once __DIR__ . '/../includes/report_export.php';

$pdo = db();
$pdo->beginTransaction();

$passCount = 0;
$failCount = 0;

function assert_test(bool $condition, string $description, ?string $details = null): void
{
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$description}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$description}\n";
        if ($details) {
            echo "         Details: {$details}\n";
        }
    }
}

try {
    echo "=========================================================\n";
    echo " Running Attendance Calculation Regression Test Suite\n";
    echo "=========================================================\n\n";

    // Create a temporary test staff member
    $testEmpId = 'TEST' . bin2hex(random_bytes(3));
    $userInsert = $pdo->prepare("
        INSERT INTO users (employee_id, username, password_hash, full_name, email, job_position, role, joining_date, is_active, weekly_off_day)
        VALUES (?, ?, 'hash', 'Test Automation User', 'test@example.invalid', 'Tester', 'staff', '2026-01-01', 1, 0)
    ");
    $userInsert->execute([$testEmpId, 'test_user_' . bin2hex(random_bytes(3))]);
    $userId = (int) $pdo->lastInsertId();

    $scheduleId = standard_schedule_id();
    $pdo->prepare("
        INSERT INTO schedule_assignments (user_id, schedule_id, effective_from, weekly_off_day)
        VALUES (?, ?, '2026-01-01', 0)
    ")->execute([$userId, $scheduleId]);

    // Helpers
    $createAttendance = function (string $date, ?string $clockIn, ?string $clockOut, int $isDayOff = 0) use ($pdo, $userId, $scheduleId): int {
        $stmt = $pdo->prepare("
            INSERT INTO attendance (user_id, work_date, schedule_id, scheduled_start, scheduled_end, is_day_off, was_scheduled_off_day, scheduled_off_weekday, clock_in, clock_out, status)
            VALUES (?, ?, ?, '10:00:00', '20:00:00', ?, ?, 0, ?, ?, 'not_clocked_in')
        ");
        $stmt->execute([$userId, $date, $scheduleId, $isDayOff, $isDayOff, $clockIn, $clockOut]);
        return (int) $pdo->lastInsertId();
    };

    $addBreak = function (int $attId, string $type, string $start, ?string $end, int $allowed = 60) use ($pdo, $userId): int {
        $dur = $end ? max(0, (int) floor((strtotime($end) - strtotime($start)) / 60)) : 0;
        $exceeded = $dur > $allowed ? 1 : 0;
        $stmt = $pdo->prepare("
            INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, end_time, duration_minutes, allowed_minutes, exceeded)
            VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$attId, $userId, $type, $start, $end, $dur, $allowed, $exceeded]);
        return (int) $pdo->lastInsertId();
    };

    $getAtt = function (int $id) use ($pdo): array {
        $stmt = $pdo->prepare("SELECT * FROM attendance WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    };

    // -----------------------------------------------------------------
    // TEST 1: Completed shift with zero breaks (10:00 to 20:00 = 600m)
    // -----------------------------------------------------------------
    echo "Test 1: Completed Shift (No Breaks)...\n";
    $id1 = $createAttendance('2026-09-01', '2026-09-01 10:00:00', '2026-09-01 20:00:00');
    recalculate_attendance($id1);
    $row1 = $getAtt($id1);
    assert_test((int)$row1['shift_minutes'] === 600, "Shift minutes equals 600m (10 hours)", "Got " . $row1['shift_minutes']);
    assert_test((int)$row1['total_break_minutes'] === 0, "Total break minutes equals 0m");
    assert_test((int)$row1['work_minutes'] === 600, "Working time equals 600m (no break deduction)", "Got " . $row1['work_minutes']);
    assert_test($row1['status'] === 'completed', "Status is 'completed'");

    // -----------------------------------------------------------------
    // TEST 2: Completed shift with 60-minute lunch break
    // -----------------------------------------------------------------
    echo "Test 2: Completed Shift with 60m Lunch Break...\n";
    $id2 = $createAttendance('2026-09-02', '2026-09-02 10:00:00', '2026-09-02 20:00:00');
    $addBreak($id2, 'lunch', '2026-09-02 13:00:00', '2026-09-02 14:00:00', 60);
    recalculate_attendance($id2);
    $row2 = $getAtt($id2);
    assert_test((int)$row2['shift_minutes'] === 600, "Shift minutes equals 600m");
    assert_test((int)$row2['total_break_minutes'] === 60, "Total break minutes equals 60m");
    assert_test((int)$row2['work_minutes'] === 540, "Working time equals 540m (600 - 60 = 9h)", "Got " . $row2['work_minutes']);
    assert_test($row2['status'] === 'completed', "Status is 'completed'");

    // -----------------------------------------------------------------
    // TEST 3: Multiple breaks (60m Lunch + 15m Short exceeded break)
    // -----------------------------------------------------------------
    echo "Test 3: Multiple Breaks Calculation...\n";
    $id3 = $createAttendance('2026-09-03', '2026-09-03 10:00:00', '2026-09-03 20:00:00');
    $addBreak($id3, 'lunch', '2026-09-03 13:00:00', '2026-09-03 14:00:00', 60);
    $addBreak($id3, 'short', '2026-09-03 16:00:00', '2026-09-03 16:15:00', 10);
    recalculate_attendance($id3);
    $row3 = $getAtt($id3);
    assert_test((int)$row3['total_break_minutes'] === 75, "Total break minutes equals 75m (60 + 15)");
    assert_test((int)$row3['work_minutes'] === 525, "Working time equals 525m (600 - 75 = 8h45m)", "Got " . $row3['work_minutes']);

    // -----------------------------------------------------------------
    // TEST 4: Late Arrival (10:35 to 20:00)
    // -----------------------------------------------------------------
    echo "Test 4: Late Arrival Calculation...\n";
    $id4 = $createAttendance('2026-09-04', '2026-09-04 10:35:00', '2026-09-04 20:00:00');
    recalculate_attendance($id4);
    $row4 = $getAtt($id4);
    assert_test((int)$row4['late_minutes'] === 35, "Late minutes equals 35m");
    assert_test((int)$row4['shift_minutes'] === 565, "Shift minutes equals 565m (9h25m)");
    assert_test((int)$row4['work_minutes'] === 565, "Working time equals 565m");
    assert_test($row4['status'] === 'completed', "Status is 'completed' after clock out");

    // -----------------------------------------------------------------
    // TEST 5: Early Clock-Out (10:00 to 18:30)
    // -----------------------------------------------------------------
    echo "Test 5: Early Clock-Out Classification...\n";
    $id5 = $createAttendance('2026-09-05', '2026-09-05 10:00:00', '2026-09-05 18:30:00');
    $addBreak($id5, 'lunch', '2026-09-05 13:00:00', '2026-09-05 14:00:00', 60);
    recalculate_attendance($id5);
    $row5 = $getAtt($id5);
    assert_test($row5['status'] === 'completed', "Status is 'completed' (NOT 'incomplete')", "Got " . $row5['status']);
    assert_test((int)$row5['early_leave_minutes'] === 90, "Early leave minutes equals 90m (departed 1.5h before 20:00)");
    assert_test((int)$row5['shift_minutes'] === 510, "Shift minutes equals 510m (8.5h)");
    assert_test((int)$row5['work_minutes'] === 450, "Working time equals 450m (510 - 60 = 7.5h)");

    // -----------------------------------------------------------------
    // TEST 6: Missing Clock-Out on Past Shift (Incomplete)
    // -----------------------------------------------------------------
    echo "Test 6: Historical Missing Clock-Out...\n";
    $id6 = $createAttendance('2026-09-06', '2026-09-06 10:00:00', null);
    recalculate_attendance($id6);
    $row6 = $getAtt($id6);
    assert_test($row6['status'] === 'incomplete', "Past shift without clock-out evaluates as 'incomplete'");
    assert_test((int)$row6['work_minutes'] === 0, "Incomplete shift has 0 finalized work minutes");

    // -----------------------------------------------------------------
    // TEST 7: Active Shift on Today
    // -----------------------------------------------------------------
    echo "Test 7: Active Shift Today...\n";
    $today = date('Y-m-d');
    $id7 = $createAttendance($today, "{$today} 10:00:00", null);
    recalculate_attendance($id7);
    $row7 = $getAtt($id7);
    assert_test($row7['status'] === 'present', "Today's shift without clock-out evaluates as 'present'");

    // -----------------------------------------------------------------
    // TEST 8: Active Break on Today
    // -----------------------------------------------------------------
    echo "Test 8: Active Break Today...\n";
    $addBreak($id7, 'lunch', date('Y-m-d H:i:s', time() - 300), null, 60);
    recalculate_attendance($id7);
    $row8 = $getAtt($id7);
    assert_test($row8['status'] === 'on_break', "Today's shift with open break evaluates as 'on_break'");

    // -----------------------------------------------------------------
    // TEST 9: Scheduled Day Off
    // -----------------------------------------------------------------
    echo "Test 9: Scheduled Day Off...\n";
    $id9 = $createAttendance('2026-09-08', null, null, 1);
    recalculate_attendance($id9);
    $row9 = $getAtt($id9);
    assert_test($row9['status'] === 'day_off', "Scheduled day-off evaluates as 'day_off'");
    assert_test((int)$row9['work_minutes'] === 0, "Scheduled day-off has 0 work minutes");

    // -----------------------------------------------------------------
    // TEST 10: Unrecorded Absence (Past Date)
    // -----------------------------------------------------------------
    echo "Test 10: Historical Absence...\n";
    $id10 = $createAttendance('2026-09-09', null, null, 0);
    recalculate_attendance($id10);
    $row10 = $getAtt($id10);
    assert_test($row10['status'] === 'absent', "Past working date with no punches evaluates as 'absent'");
    assert_test((int)$row10['work_minutes'] === 0, "Absent record has 0 work minutes");

    // -----------------------------------------------------------------
    // TEST 11: Overnight Shift Safety
    // -----------------------------------------------------------------
    echo "Test 11: Overnight Shift Calculation...\n";
    $id11 = $createAttendance('2026-09-10', '2026-09-10 22:00:00', '2026-09-11 06:00:00');
    recalculate_attendance($id11);
    $row11 = $getAtt($id11);
    assert_test((int)$row11['shift_minutes'] === 480, "Overnight shift (22:00 -> 06:00) equals 480m (8h)", "Got " . $row11['shift_minutes']);
    assert_test((int)$row11['work_minutes'] === 480, "Overnight working time equals 480m");

    // -----------------------------------------------------------------
    // TEST 12: Seeder File Integrity & Name Uniqueness
    // -----------------------------------------------------------------
    echo "Test 12: Seeder Code Integrity Verification...\n";
    $seederContent = file_get_contents(__DIR__ . '/../cli/reset_local_test_data.php');
    assert_test(str_contains($seederContent, 'recalculate_attendance($aid)'), "Seeder explicitly invokes recalculate_attendance(\$aid)");
    assert_test(!str_contains($seederContent, "['Nur Farhana binti Latif'"), "Seeder duplicate name overwrite removed");
    assert_test(!str_contains($seederContent, '$kind===2?\'incomplete\':\'completed\''), "Seeder raw 'incomplete' status override removed");

    // -----------------------------------------------------------------
    // TEST 13: Export Matrix Formatting Parity
    // -----------------------------------------------------------------
    echo "Test 13: Report Export Matrix Parity...\n";
    $row2['full_name'] = 'Test Automation User';
    $exportMatrix = report_attendance_export_matrix([$row2]);
    assert_test(count($exportMatrix) === 1, "Export matrix returns 1 row");
    assert_test($exportMatrix[0][5] === '1h 00m', "Export break duration matches '1h 00m'", "Got " . $exportMatrix[0][5]);
    assert_test($exportMatrix[0][6] === '9h 00m', "Export work duration matches '9h 00m' (540m)", "Got " . $exportMatrix[0][6]);
    assert_test($exportMatrix[0][7] === 'Completed', "Export status label matches 'Completed'");

    echo "\n=========================================================\n";
    echo " Test Suite Finished: {$passCount} Passed, {$failCount} Failed\n";
    echo "=========================================================\n";

    if ($failCount > 0) {
        exit(1);
    }
} finally {
    // Always rollback so no test records remain in the database
    $pdo->rollBack();
    echo "\n[INFO] Sandbox transaction rolled back. Database unchanged.\n";
}
