<?php
/** Bulk realistic staff and attendance fixtures for local development. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/attendance_service.php';

$count = 1000;
$pdo = db();
$scheduleId = standard_schedule_id();
$now = now();
$today = $now->format('Y-m-d');
$passwordHash = password_hash('Welcome!2026', PASSWORD_DEFAULT);
$existingMax = (int)($pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(employee_id, 3) AS UNSIGNED)), 0) FROM users WHERE employee_id LIKE 'ST%'")->fetchColumn() ?: 0);
$malayGiven = ['Ahmad','Aisyah','Aiman','Aina','Amir','Azlan','Balqis','Danish','Farah','Faris','Hafiz','Hakim','Hana','Haziq','Irfan','Izzati','Liyana','Mariam','Nabila','Nadia','Naufal','Nurul','Qistina','Rafiq','Siti','Syafiq','Syahirah','Wafi','Zulaikha'];
$malayFamily = ['Abdullah','Ahmad','Ali','Aziz','Hassan','Husain','Ismail','Jamal','Kamarudin','Rahman','Rahim','Ramli','Roslan','Salleh','Shukor','Yusof','Zainal'];
$chinese = [['陈','Chen'],['林','Lim'],['李','Lee'],['黄','Wong'],['张','Cheong'],['王','Wong'],['刘','Liew'],['杨','Yeong'],['吴','Ng'],['许','Koh'],['郑','Teh'],['梁','Leong']];
$chineseGiven = ['Wei','Ming','Jia Yi','Mei Ling','Xin Yi','Jun Hao','Yu Ting','Zhi Hao','Pei Wen','Yong Kang','Hui Min','Jian Wei','Yi Xuan'];
$positions = ['Operations Executive','Customer Support Executive','Finance Assistant','Human Resources Executive','Software Developer','Sales Coordinator','Office Administrator','Marketing Executive'];
$makeName = static function (int $i) use ($malayGiven,$malayFamily,$chinese,$chineseGiven): string {
    if ($i % 5 === 0) {
        [$han,$roman] = $chinese[$i % count($chinese)];
        return $han . $chineseGiven[$i % count($chineseGiven)] . ' (' . $roman . ' ' . str_replace(' ', '', $chineseGiven[$i % count($chineseGiven)]) . ')';
    }
    $given = $malayGiven[$i % count($malayGiven)];
    $family = $malayFamily[intdiv($i, count($malayGiven)) % count($malayFamily)];
    return ($i % 3 === 0 ? 'Nur ' : '') . $given . ' ' . (($i % 2) ? 'bin ' : 'binti ') . $family;
};
$date = static fn(int $offset): string => $now->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');

$pdo->beginTransaction();
try {
    $insertUser = $pdo->prepare('INSERT INTO users (employee_id,username,password_hash,full_name,email,job_position,role,joining_date,is_active,weekly_off_day) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $insertSchedule = $pdo->prepare('INSERT INTO schedule_assignments (user_id,schedule_id,effective_from,weekly_off_day) VALUES (?,?,?,?)');
    $insertBreak = $pdo->prepare('INSERT INTO break_records (attendance_id,user_id,break_type,sequence_no,start_time,end_time,duration_minutes,allowed_minutes,exceeded) VALUES (?,?,?,?,?,?,?,?,?)');
    $created = 0;
    for ($i = 1; $i <= $count; $i++) {
        $join = $date(-30 - ($i % 420));
        $weeklyOff = $i % 7 === 0 ? 0 : 6;
        $number = $existingMax + $i;
        $username = 'staff' . str_pad((string)$number, 5, '0', STR_PAD_LEFT);
        $insertUser->execute(['ST' . str_pad((string)$number, 5, '0', STR_PAD_LEFT),$username,$passwordHash,$makeName($i),$username . '@local.invalid',$positions[$i % count($positions)],'staff',$join,1,$weeklyOff]);
        $userId = (int)$pdo->lastInsertId();
        $insertSchedule->execute([$userId,$scheduleId,$join,$weeklyOff]);
        for ($offset = -14; $offset <= 0; $offset++) {
            $on = $date($offset);
            $att = ensure_attendance_row($userId, $on);
            if ((int)$att['is_day_off']) continue;
            $pattern = ($i + abs($offset)) % 11;
            if ($offset === 0) {
                $in = $now->format('H:i') >= '10:00' && $pattern !== 1
                    ? $now->modify('-' . min(120, 20 + ($i % 90)) . ' minutes')->format('Y-m-d H:i:s')
                    : null;
                $pdo->prepare('UPDATE attendance SET clock_in=?,clock_out=NULL WHERE id=?')->execute([$in,$att['id']]);
            } elseif ($pattern === 1) {
                $pdo->prepare('UPDATE attendance SET clock_in=NULL,clock_out=NULL WHERE id=?')->execute([$att['id']]);
                reconcile_attendance_day_status($userId,$on);
                continue;
            } else {
                $in = $on . ' ' . (($pattern === 2 ? '10:18:00' : '10:00:00'));
                $out = $on . (($pattern === 3 ? '18:30:00' : '20:00:00'));
                if ($pattern === 4 || ($offset === 0 && $pattern === 5)) $out = null;
                $pdo->prepare('UPDATE attendance SET clock_in=?,clock_out=? WHERE id=?')->execute([$in,$out,$att['id']]);
            }
            if ($offset !== 0 && $pattern % 3 === 0) {
                $start = $on . ' 13:00:00'; $end = $on . ' 14:00:00';
                $insertBreak->execute([$att['id'],$userId,'lunch',1,$start,$end,60,60,0]);
            }
            if ($offset !== 0 && $pattern % 8 === 0) {
                $start = $on . ' 16:00:00';
                $insertBreak->execute([$att['id'],$userId,'short',1,$start,null,0,10,0]);
            }
            reconcile_attendance_day_status($userId,$on);
            recalculate_attendance((int)$att['id']);
        }
        $created++;
    }
    $pdo->commit();
    echo "Created {$created} staff accounts with recent attendance records.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
