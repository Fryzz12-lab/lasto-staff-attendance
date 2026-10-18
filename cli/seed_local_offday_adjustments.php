<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/adjustment_service.php';

$pdo = db();
$host = strtolower((string)$pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS));
$machine = strtolower((string)gethostname());
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if ($dbName !== 'staff_attendance' || (!str_contains($host, 'localhost') && !str_contains($host, '127.0.0.1') && !str_contains($host, $machine))) {
    throw new RuntimeException("Refusing to seed: db={$dbName}; connection={$host}; machine={$machine}");
}

$stmt = $pdo->query(
    "SELECT a.id FROM attendance a
     LEFT JOIN offday_adjustment_reviews r ON r.attendance_id = a.id
     WHERE a.clock_in IS NOT NULL AND a.clock_out IS NOT NULL
       AND a.was_scheduled_off_day = 1 AND r.id IS NULL
     ORDER BY a.work_date ASC, a.id ASC LIMIT 10"
);
$attendanceIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
if (count($attendanceIds) < 10) {
    $existingReviews = $pdo->query("SELECT id FROM offday_adjustment_reviews ORDER BY id ASC LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
    if (count($existingReviews) >= 10) {
        $created = array_map('intval', $existingReviews);
        $attendanceIds = [];
    } else {
    $users = $pdo->query("SELECT u.id,u.weekly_off_day,COALESCE(sa.schedule_id,(SELECT id FROM work_schedules WHERE is_default=1 LIMIT 1)) schedule_id FROM users u LEFT JOIN schedule_assignments sa ON sa.user_id=u.id AND sa.effective_to IS NULL WHERE u.role='staff' AND u.is_active=1 ORDER BY u.id LIMIT 10")->fetchAll();
    $insert = $pdo->prepare('INSERT IGNORE INTO attendance (user_id,work_date,schedule_id,scheduled_start,scheduled_end,is_day_off,was_scheduled_off_day,scheduled_off_weekday,clock_in,clock_out,status,shift_minutes,work_minutes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($users as $i => $user) {
        $date = (new DateTimeImmutable('today'))->modify('-' . (40 + $i) . ' days')->format('Y-m-d');
        $in = $date . ' 10:00:00'; $out = $date . ' 20:00:00';
        $insert->execute([(int)$user['id'],$date,(int)$user['schedule_id'],'10:00:00','20:00:00',0,1,(int)$user['weekly_off_day'],$in,$out,'completed',600,600]);
    }
    $stmt = $pdo->query("SELECT a.id FROM attendance a LEFT JOIN offday_adjustment_reviews r ON r.attendance_id = a.id WHERE a.clock_in IS NOT NULL AND a.clock_out IS NOT NULL AND a.was_scheduled_off_day = 1 AND r.id IS NULL ORDER BY a.work_date ASC, a.id ASC LIMIT 10");
    $attendanceIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($attendanceIds) < 10) throw new RuntimeException('Unable to create 10 eligible local off-day attendance records.');
    }
}

if (!isset($created)) {
    $created = [];
    foreach ($attendanceIds as $attendanceId) {
        $reviewId = ensure_offday_adjustment_review($attendanceId);
        if ($reviewId !== null) $created[] = $reviewId;
    }
}

$adminId = (int)$pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn();
$pairs = [[2,3],[4,4],[4,0],[1,2],[3,1],[0,4],[2,0],[5,2],[1,4],[4,3]];
$pdo->prepare("DELETE FROM staff_balance_ledger WHERE source_type='manual' AND source_id BETWEEN 910001 AND 910010")->execute();
$ledger = $pdo->prepare('INSERT INTO staff_balance_ledger (employee_id,balance_type,amount,source_type,source_id,review_id,decision,admin_id,admin_note) VALUES (?,?,?,?,?,?,?,?,?)');
foreach ($created as $i => $reviewId) {
    $review = $pdo->prepare('SELECT employee_id FROM offday_adjustment_reviews WHERE id=?');
    $review->execute([$reviewId]);
    $employeeId = (int)$review->fetchColumn();
    [$replacement, $owed] = $pairs[$i] ?? [0, 0];
    if ($replacement > 0) $ledger->execute([$employeeId,'replacement_off',$replacement,'manual',910001+$i,$reviewId,'uat_seed',$adminId,'Local UAT balance fixture']);
    if ($owed > 0) $ledger->execute([$employeeId,'cuti_perlu_ganti',$owed,'manual',910001+$i,$reviewId,'uat_seed',$adminId,'Local UAT balance fixture']);
}
printf("Created %d off-day adjustment reviews: %s\n", count($created), implode(', ', $created));
