<?php
/** Phase 3 scheduled reminders. Run every 5 minutes from cPanel cron. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/push_service.php';
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur')); $date = $now->format('Y-m-d'); $pdo = db(); $created = 0;
foreach ($pdo->query("SELECT id FROM users WHERE role='staff' AND is_active=1")->fetchAll() as $user) {
    $uid = (int)$user['id']; $schedule = get_schedule_for_date($uid, $date); $end = new DateTimeImmutable($date.' '.(string)$schedule['end_time'], new DateTimeZone('Asia/Kuala_Lumpur'));
    $s = $pdo->prepare('SELECT id,clock_in,clock_out FROM attendance WHERE user_id=? AND work_date=? LIMIT 1'); $s->execute([$uid,$date]); $att=$s->fetch(); if (!$att || !$att['clock_in'] || $att['clock_out']) continue;
    $mins=(int)floor(($end->getTimestamp()-$now->getTimestamp())/60);
    if ($mins>=0 && $mins<=15) { $key='shift-ending-'.$date; if (notification_create($uid,'shift_ending',$key,'Shift ending soon','Your scheduled shift ends soon. Remember to clock out.','/staff/')) { push_send_to_user($uid,'Shift ending soon','Your scheduled shift ends soon. Remember to clock out.','/staff/',$key,'shift_ending'); $created++; } }
    if ($now>$end) { $key='missing-clock-out-'.$date; if (notification_create($uid,'missing_clock_out',$key,'Missing clock-out','Today has no clock-out recorded. Please review your attendance.','/staff/attendance.php')) { push_send_to_user($uid,'Missing clock-out','Today has no clock-out recorded. Please review your attendance.','/staff/attendance.php',$key,'missing_clock_out'); $created++; } }
    $b=$pdo->prepare('SELECT id,start_time,allowed_minutes FROM break_records WHERE attendance_id=? AND end_time IS NULL ORDER BY id DESC LIMIT 1'); $b->execute([(int)$att['id']]); $break=$b->fetch();
    if ($break && (int)$break['allowed_minutes']>0) { $be=(new DateTimeImmutable((string)$break['start_time']))->modify('+'.(int)$break['allowed_minutes'].' minutes'); $bm=(int)floor(($be->getTimestamp()-$now->getTimestamp())/60); if ($bm>=0 && $bm<=5) { $key='break-ending-'.(int)$break['id']; if (notification_create($uid,'break_ending',$key,'Break ending soon','Your break is ending soon.','/staff/')) { push_send_to_user($uid,'Break ending soon','Your break is ending soon.','/staff/',$key,'break_ending'); $created++; } } }
}
fwrite(STDOUT,"Reminder run complete: {$created} event attempts\n");
