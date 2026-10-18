<?php
/**
 * CLI Tool: Attendance Calculation Auditor & Repair Tool
 *
 * Inspects and repairs attendance records with uncalculated or inconsistent
 * durations (shift_minutes, total_break_minutes, work_minutes, status).
 *
 * Usage:
 *   php cli/repair_attendance_calculations.php               # Dry-run mode (default, no changes)
 *   php cli/repair_attendance_calculations.php --dry-run     # Explicit dry-run
 *   php cli/repair_attendance_calculations.php --apply       # Executes repair inside a transaction
 *   php cli/repair_attendance_calculations.php --from=2026-10-10 --to=2026-10-17
 *   php cli/repair_attendance_calculations.php --apply --force # Include records with admin overrides
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/maintenance.php';
require_once __DIR__ . '/../includes/attendance_service.php';

$apply = in_array('--apply', $argv, true);
$dryRun = in_array('--dry-run', $argv, true) || !$apply;
$force = in_array('--force', $argv, true);

$from = null;
$to = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--from=')) {
        $from = substr($arg, 7);
    } elseif (str_starts_with($arg, '--to=')) {
        $to = substr($arg, 5);
    }
}

echo "=========================================================\n";
echo " LASTO Attendance Calculation Audit & Repair Tool\n";
echo "=========================================================\n";
echo "Mode: " . ($dryRun ? "DRY-RUN (Simulating changes, DB will not be modified)" : "APPLY (Live execution with automatic backup)") . "\n";
if ($from && $to) {
    echo "Date Range: {$from} to {$to}\n";
} else {
    echo "Date Range: ALL RECORDS\n";
}
echo "---------------------------------------------------------\n\n";

$pdo = db();

// Find admin user for audit log attribution
$adminId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
if ($adminId <= 0) {
    $adminId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
}
if ($adminId <= 0) {
    throw new RuntimeException("No administrator user account found for audit logging attribution.");
}

// Build query to inspect records
$params = [];
$whereClauses = [];

if ($from !== null) {
    $whereClauses[] = "a.work_date >= ?";
    $params[] = $from;
}
if ($to !== null) {
    $whereClauses[] = "a.work_date <= ?";
    $params[] = $to;
}

$whereSql = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$stmt = $pdo->prepare("
    SELECT a.*, u.full_name, u.employee_id 
    FROM attendance a 
    JOIN users u ON u.id = a.user_id 
    {$whereSql} 
    ORDER BY a.work_date, a.user_id
");
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check which records have manual administrator edits in audit logs
$manualEditsStmt = $pdo->query("
    SELECT DISTINCT attendance_id 
    FROM attendance_audit_logs 
    WHERE attendance_id IS NOT NULL 
      AND action IN ('admin_override', 'manual_edit', 'manual_correction', 'correct_break')
");
$manuallyEditedIds = array_flip($manualEditsStmt->fetchAll(PDO::FETCH_COLUMN));

$repairedCount = 0;
$skippedManualCount = 0;
$alreadyCorrectCount = 0;
$anomalies = [];

$totalWorkMinutesBefore = 0;
$totalWorkMinutesAfter = 0;

foreach ($records as $r) {
    $aid = (int) $r['id'];
    $uid = (int) $r['user_id'];
    $workDate = (string) $r['work_date'];
    $clockIn = $r['clock_in'] ? (string) $r['clock_in'] : null;
    $clockOut = $r['clock_out'] ? (string) $r['clock_out'] : null;
    $oldStatus = (string) $r['status'];
    $oldShift = (int) $r['shift_minutes'];
    $oldBreak = (int) $r['total_break_minutes'];
    $oldWork = (int) $r['work_minutes'];

    $totalWorkMinutesBefore += $oldWork;

    // Simulate expected values
    $bStmt = $pdo->prepare("SELECT COALESCE(SUM(duration_minutes), 0) FROM break_records WHERE attendance_id = ? AND end_time IS NOT NULL");
    $bStmt->execute([$aid]);
    $expectedBreak = (int) $bStmt->fetchColumn();

    $expectedShift = 0;
    $expectedWork = 0;
    $expectedEarly = (int) $r['early_leave_minutes'];
    $expectedStatus = $oldStatus;
    $expectedLate = attendance_late_minutes($workDate, $clockIn);

    if (!$clockIn) {
        $expectedStatus = (int) $r['is_day_off'] ? 'day_off' : ($workDate < today_str() ? 'absent' : 'not_clocked_in');
    } elseif ($clockOut) {
        $inDt = new DateTimeImmutable($clockIn);
        $outDt = new DateTimeImmutable($clockOut);
        $expectedShift = max(0, (int) floor(($outDt->getTimestamp() - $inDt->getTimestamp()) / 60));
        $expectedWork = max(0, $expectedShift - $expectedBreak);
        if (setting_bool('enable_early_leave_calc', true) && !empty($r['scheduled_end'])) {
            $schedEnd = new DateTimeImmutable($workDate . ' ' . POLICY_END_TIME);
            if ($outDt < $schedEnd) {
                $expectedEarly = (int) floor(($schedEnd->getTimestamp() - $outDt->getTimestamp()) / 60);
            }
        }
        $expectedStatus = 'completed';
    } else {
        $active = get_active_break($uid);
        if ($workDate < today_str()) {
            $expectedStatus = 'incomplete';
        } elseif ($active && (int) $active['attendance_id'] === $aid) {
            $expectedStatus = 'on_break';
        } elseif ($expectedLate > 0) {
            $expectedStatus = 'late';
        } else {
            $expectedStatus = 'present';
        }
    }

    $needsRepair = ($oldShift !== $expectedShift)
        || ($oldWork !== $expectedWork)
        || ($oldBreak !== $expectedBreak)
        || ($oldStatus !== $expectedStatus);

    if (!$needsRepair) {
        $totalWorkMinutesAfter += $oldWork;
        $alreadyCorrectCount++;
        continue;
    }

    // Check manual override safety
    if (isset($manuallyEditedIds[$aid]) && !$force) {
        $skippedManualCount++;
        $totalWorkMinutesAfter += $oldWork;
        continue;
    }

    $totalWorkMinutesAfter += $expectedWork;
    $repairedCount++;

    $anomalies[] = [
        'id' => $aid,
        'date' => $workDate,
        'staff' => "{$r['full_name']} ({$r['employee_id']})",
        'clock_in' => $clockIn ? substr($clockIn, 11, 5) : '—',
        'clock_out' => $clockOut ? substr($clockOut, 11, 5) : '—',
        'old_shift' => $oldShift,
        'new_shift' => $expectedShift,
        'old_break' => $oldBreak,
        'new_break' => $expectedBreak,
        'old_work' => $oldWork,
        'new_work' => $expectedWork,
        'old_status' => $oldStatus,
        'new_status' => $expectedStatus,
    ];
}

echo "Scan Results:\n";
echo "  Total Records Analyzed:     " . count($records) . "\n";
echo "  Records Needing Repair:     " . $repairedCount . "\n";
echo "  Skipped (Manual Override):  " . $skippedManualCount . "\n";
echo "  Records Already Consistent: " . $alreadyCorrectCount . "\n";
echo "  Total Work Time (Current):  " . format_minutes($totalWorkMinutesBefore) . "\n";
echo "  Total Work Time (Target):   " . format_minutes($totalWorkMinutesAfter) . "\n\n";

if ($repairedCount > 0) {
    echo "Sample Discrepancies (first 15 records):\n";
    printf("%-6s | %-10s | %-28s | %-13s | %-18s | %-18s\n", "ID", "Date", "Staff", "In / Out", "Work Time", "Status");
    echo str_repeat("-", 105) . "\n";
    foreach (array_slice($anomalies, 0, 15) as $a) {
        $workDiff = "{$a['old_work']}m -> {$a['new_work']}m";
        $statusDiff = "{$a['old_status']} -> {$a['new_status']}";
        $punch = "{$a['clock_in']}-{$a['clock_out']}";
        printf("%-6d | %-10s | %-28s | %-13s | %-18s | %-18s\n",
            $a['id'], $a['date'], mb_strimwidth($a['staff'], 0, 28, '..'), $punch, $workDiff, $statusDiff
        );
    }
    if (count($anomalies) > 15) {
        echo "... and " . (count($anomalies) - 15) . " more records.\n";
    }
    echo "\n";
}

if ($dryRun) {
    echo "[DRY RUN COMPLETE] No changes were written to the database.\n";
    echo "To perform the repair, execute:\n";
    echo "  php cli/repair_attendance_calculations.php --apply" . ($from ? " --from={$from} --to={$to}" : "") . "\n\n";
    exit(0);
}

// Live execution
echo "Starting repair...\n";
$backupPath = backup_database();
echo "Database backup created: {$backupPath}\n";

$pdo->beginTransaction();
try {
    $auditInsert = $pdo->prepare("
        INSERT INTO attendance_audit_logs 
            (attendance_id, user_id, changed_by, action, field_name, old_value, new_value, reason)
        VALUES (?, ?, ?, 'recalculation_repair', 'work_minutes', ?, ?, ?)
    ");

    $processed = 0;
    foreach ($anomalies as $item) {
        $aid = $item['id'];
        recalculate_attendance($aid);

        $oldVal = "work:{$item['old_work']}m,shift:{$item['old_shift']}m,status:{$item['old_status']}";
        $newVal = "work:{$item['new_work']}m,shift:{$item['new_shift']}m,status:{$item['new_status']}";
        $reason = "System maintenance: recalculate uncomputed shift durations";

        // Extract user_id for this record
        $uStmt = $pdo->prepare("SELECT user_id FROM attendance WHERE id = ?");
        $uStmt->execute([$aid]);
        $rowUid = (int) $uStmt->fetchColumn();

        $auditInsert->execute([$aid, $rowUid, $adminId, $oldVal, $newVal, $reason]);
        $processed++;
    }

    $pdo->commit();
    echo "[SUCCESS] Repaired {$processed} attendance records.\n";
    echo "New Company-Wide Total Working Time: " . format_minutes($totalWorkMinutesAfter) . "\n\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "[ERROR] Repair aborted and rolled back: " . $e->getMessage() . "\n");
    exit(1);
}
