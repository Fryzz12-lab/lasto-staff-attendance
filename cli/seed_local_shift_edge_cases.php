<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db();
$status = strtolower((string)$pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS));
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if ($dbName !== 'staff_attendance' || !str_contains($status, '127.0.0.1')) {
    throw new RuntimeException('Refusing to seed: this is not the local staff_attendance database.');
}

$users = $pdo->query("SELECT u.id,u.weekly_off_day,COALESCE(sa.schedule_id,(SELECT id FROM work_schedules WHERE is_default=1 LIMIT 1)) schedule_id FROM users u LEFT JOIN schedule_assignments sa ON sa.user_id=u.id AND sa.effective_to IS NULL WHERE u.role='staff' AND u.is_active=1 ORDER BY u.id LIMIT 10")->fetchAll();
$insert = $pdo->prepare('INSERT IGNORE INTO attendance (user_id,work_date,schedule_id,scheduled_start,scheduled_end,is_day_off,was_scheduled_off_day,scheduled_off_weekday,clock_in,clock_out,status,late_minutes,early_leave_minutes,total_break_minutes,shift_minutes,work_minutes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$break = $pdo->prepare('INSERT INTO break_records (attendance_id,user_id,break_type,sequence_no,start_time,end_time,duration_minutes,allowed_minutes,exceeded,notes) VALUES (?,?,?,?,?,?,?,?,?,?)');
$cases = [
    ['late', '10:35:00', '20:00:00', 35, 0],
    ['late', '11:15:00', '20:00:00', 75, 0],
    ['completed', '10:00:00', '19:00:00', 0, 60],
    ['completed', '10:00:00', '18:30:00', 0, 90],
    ['completed', '10:00:00', '20:00:00', 0, 0],
    ['completed', '09:45:00', '20:00:00', 0, 0],
    ['completed', '10:20:00', '19:30:00', 20, 30],
    ['completed', '10:00:00', '20:00:00', 0, 0],
    ['late', '10:50:00', '18:00:00', 50, 120],
    ['completed', '10:05:00', '19:45:00', 5, 45],
];
$created = 0;
foreach ($users as $i => $user) {
    $case = $cases[$i];
    $date = (new DateTimeImmutable('today'))->modify('-' . (60 + $i) . ' days')->format('Y-m-d');
    [$state,$inTime,$outTime,$late,$early] = $case;
    $in = $date . ' ' . $inTime; $out = $date . ' ' . $outTime;
    $shift = (strtotime($out) - strtotime($in)) / 60;
    $work = max(0, $shift - $case[4]);
    $insert->execute([(int)$user['id'],$date,(int)$user['schedule_id'],'10:00:00','20:00:00',0,0,(int)$user['weekly_off_day'],$in,$out,$state,$late,$early,$case[4],$shift,$work]);
    $attendanceId = (int)$pdo->lastInsertId();
    if ($attendanceId > 0 && $case[4] > 0) {
        $start = $date . ' 13:00:00'; $end = date('Y-m-d H:i:s', strtotime($start) + ($case[4] * 60));
        $break->execute([$attendanceId,(int)$user['id'],'lunch',1,$start,$end,$case[4],60,$case[4] > 60,'Local UAT shift break fixture']);
    }
    if ($attendanceId > 0) $created++;
}
printf("Created %d local shift edge-case attendance records.\n", $created);
