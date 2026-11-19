<?php
/**
 * Phase 1: stale-break isolation regression tests.
 * Uses one transaction and rolls it back; it never resets existing data.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

function phase1_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: {$message}\n";
}

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, "BLOCKED: database unavailable: {$e->getMessage()}\n");
    exit(2);
}

$pdo->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(4));
    $password = password_hash('Phase1-Test-Only', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (employee_id, username, password_hash, full_name, email, role, is_active, weekly_off_day, must_change_password) VALUES (?, ?, ?, 'Phase 1 Test', ?, 'staff', 1, 0, 0)");
    $stmt->execute(["PH1_{$suffix}", "ph1_{$suffix}", $password, "ph1_{$suffix}@example.com"]);
    $userLookup = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $userLookup->execute(["ph1_{$suffix}"]);
    $userId = (int) $userLookup->fetchColumn();
    phase1_assert($userId > 0, 'isolated test user was created');
    $today = today_str();
    $yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');

    $insertAttendance = $pdo->prepare("INSERT INTO attendance (user_id, work_date, clock_in, status, version) VALUES (?, ?, ?, 'present', 1)");
    $insertAttendance->execute([$userId, $yesterday, "{$yesterday} 10:00:00"]);
    $oldAttendanceId = (int) $pdo->lastInsertId();
    $insertAttendance->execute([$userId, $today, "{$today} 10:00:00"]);
    $todayAttendanceId = (int) $pdo->lastInsertId();

    $insertBreak = $pdo->prepare("INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, allowed_minutes) VALUES (?, ?, 'lunch', 1, ?, 60)");
    $insertBreak->execute([$oldAttendanceId, $userId, "{$yesterday} 13:00:00"]);
    $oldBreakId = (int) $pdo->lastInsertId();

    $clockOut = clock_out($userId);
    phase1_assert($clockOut['success'] === false, 'clock-out rejects a stale prior-day break');
    phase1_assert((string) $clockOut['message'] !== '', 'clock-out returns a meaningful stale-break message');

    $start = start_break($userId, 'short');
    phase1_assert($start['success'] === false, 'new break request rejects a stale prior-day break');

    $end = end_break($userId);
    phase1_assert($end['success'] === false, 'end-break does not end a stale prior-day break');

    $oldBreak = $pdo->prepare('SELECT end_time FROM break_records WHERE id = ?');
    $oldBreak->execute([$oldBreakId]);
    phase1_assert($oldBreak->fetchColumn() === null, 'stale break timestamp remains unchanged');

    $todayRow = $pdo->prepare('SELECT clock_out FROM attendance WHERE id = ?');
    $todayRow->execute([$todayAttendanceId]);
    phase1_assert($todayRow->fetchColumn() === null, 'today attendance remains clocked in');

    $audit = $pdo->prepare("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id = ? AND action = 'stale_break_detected'");
    $audit->execute([$todayAttendanceId]);
    phase1_assert((int) $audit->fetchColumn() >= 3, 'stale-break attempts are audited');

    $pdo->rollBack();
    echo "Phase 1 stale-break isolation tests completed.\n";
    exit(0);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    exit(1);
}
