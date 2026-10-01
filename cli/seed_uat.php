<?php
/** Repeatable UAT records. Only accounts recorded in the private manifest are reset. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/maintenance.php';
require_once __DIR__ . '/../includes/attendance_service.php';
require_once __DIR__ . '/../includes/i18n.php';
$_SESSION = ['lang' => 'en'];

$manifestPath = __DIR__ . '/../storage/uat-manifest.json';
$old = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR) : null;
$reset = in_array('--reset', $argv, true);
$remove = in_array('--remove', $argv, true);
if (APP_ENV === 'production' && !$remove) throw new RuntimeException('UAT seeding is disabled in production.');
if ($old && !$reset && !$remove) { echo "UAT already exists. Use --reset to restart only the test accounts, or --remove before deployment.\n"; exit; }
if (!$old && $remove) { echo "No UAT manifest exists. Nothing removed.\n"; exit; }
echo 'Backup: ' . backup_database() . PHP_EOL;
$pdo = db();
$pdo->beginTransaction();
try {
    if ($old) {
        $ids = array_map('intval', array_column($old['accounts'], 'id'));
        $idList = implode(',', $ids);
        foreach ($old['accounts'] as $account) {
            $stmt = $pdo->prepare('SELECT username FROM users WHERE id=?');
            $stmt->execute([$account['id']]);
            $username = $stmt->fetchColumn();
            if ($username !== false && $username !== $account['username']) throw new RuntimeException('UAT account identity changed; refusing reset.');
        }
        // Do not erase an administrator identity referenced by real staff history.
        foreach (['attendance_audit_logs' => 'changed_by', 'leave_requests' => 'reviewed_by',
            'attendance_correction_requests' => 'reviewed_by', 'account_recovery_tokens' => 'created_by'] as $table => $column) {
            if ((int) $pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$column} IN ({$idList}) AND user_id NOT IN ({$idList})")->fetchColumn()) {
                throw new RuntimeException('UAT account is referenced by non-UAT records. Review those references before removal.');
            }
        }
        foreach (['attendance_audit_logs','account_recovery_tokens','user_security_questions','attendance_correction_requests',
            'leave_requests','break_records','attendance','schedule_assignments','users'] as $table) {
            $column = $table === 'users' ? 'id' : 'user_id';
            $pdo->exec("DELETE FROM {$table} WHERE {$column} IN ({$idList})");
        }
    }
    if ($remove) {
        $pdo->commit();
        unlink($manifestPath);
        echo "UAT accounts and their records removed. Other staff records preserved.\n";
        exit;
    }
    $today = today_str();
    $now = now();
    $date = static fn(int $offset): string => $now->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
    $off = ((int) $now->format('w') + 1) % 7;
    $scheduleId = standard_schedule_id();
    $password = 'Uat!' . bin2hex(random_bytes(7));
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $definitions = [
        ['admin','Admin Ujian LASTO','admin','admin'],
        ['nurul','Nurul Aisyah binti Rahman','staff','ready'],
        ['wei','李伟 (Lee Wei)','staff','working'],
        ['siti','Siti Hajar binti Ahmad','staff','lunch'],
        ['ming','陈明 (Tan Ming)','staff','solat'],
        ['aisyah','Nur Aisyah binti Ismail','staff','short_over'],
        ['jun','张俊 (Cheong Jun)','staff','exhausted'],
        ['farid','Muhammad Farid bin Hassan','staff','completed'],
        ['mei','黄美玲 (Wong Mei Ling)','staff','off'],
        ['hafiz','Muhammad Hafiz bin Abdullah','staff','leave'],
        ['xinyi','林欣怡 (Lim Xin Yi)','staff','wfh'],
        ['danial','Muhammad Danial Haziq bin Daniel Iskandar','staff','requests'],
        ['yuting','刘雨婷 (Liew Yu Ting)','staff','setup'],
        ['azlan','Mohd Azlan bin Yusof','staff','inactive'],
        ['jiayi','王佳怡 (Wong Jia Yi)','staff','future'],
        ['hakim','Abdul Hakim bin Zulkifli','staff','ended'],
        ['yong','杨永康 (Yeong Yong Kang)','staff','new_joiner'],
        ['amin','Muhammad Amin bin Roslan','staff','missing_out'],
    ];
    $accounts = [];
    $insert = $pdo->prepare('INSERT INTO users (employee_id,username,password_hash,full_name,email,job_position,role,joining_date,employment_end_date,weekly_off_day,is_active,must_change_password) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($definitions as [$key,$name,$role,$scenario]) {
        $join = $scenario === 'future' ? $date(7) : (in_array($scenario, ['setup','new_joiner']) ? $today : $date(-35));
        $end = $scenario === 'ended' ? $date(-3) : null;
        $employeeId = generate_employee_id();
        $username = 'uat_' . $key;
        $weeklyOff = $scenario === 'off' ? (int) $now->format('w') : $off;
        $insert->execute([$employeeId,$username,$hash,$name,$username . '@example.invalid',
            $role === 'admin' ? 'UAT Administrator' : 'UAT Staff', $role,$join,$end,$weeklyOff,1,$scenario === 'setup' ? 1 : 0]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO schedule_assignments (user_id,schedule_id,effective_from,weekly_off_day) VALUES (?,?,?,?)')
            ->execute([$id,$scheduleId,$join,$weeklyOff]);
        if ($scenario !== 'setup') {
            $questions = $pdo->prepare('INSERT INTO user_security_questions (user_id,question_key,answer_hash) VALUES (?,?,?)');
            $questions->execute([$id,'sq_first_school',password_hash('sekolah ujian',PASSWORD_DEFAULT)]);
            $questions->execute([$id,'sq_first_pet',password_hash('kucing ujian',PASSWORD_DEFAULT)]);
        }
        $accounts[$key] = compact('id','employeeId','username','name','role','scenario','join','end','weeklyOff');
    }
    $adminId = $accounts['admin']['id'];
    $breakInsert = $pdo->prepare('INSERT INTO break_records (attendance_id,user_id,break_type,sequence_no,start_time,end_time,duration_minutes,allowed_minutes,exceeded,notes) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $addBreak = static function (int $attId, int $userId, string $type, int $sequence, string $start, ?string $end) use ($breakInsert): void {
        $allowed = ['lunch'=>60,'solat'=>20,'short'=>10][$type];
        $seconds = $end ? max(0, strtotime($end)-strtotime($start)) : 0;
        $breakInsert->execute([$attId,$userId,$type,$sequence,$start,$end,(int)floor($seconds/60),$allowed,$seconds>$allowed*60?1:0,'UAT scenario']);
    };
    $leaveInsert = $pdo->prepare('INSERT INTO leave_requests (user_id,leave_date,leave_type,reason,status,reviewed_by,reviewed_at,admin_note) VALUES (?,?,?,?,?,?,?,?)');
    $addLeave = static function (int $userId,string $on,string $type,string $status) use ($leaveInsert,$adminId): void {
        $leaveInsert->execute([$userId,$on,$type,'UAT: ' . $type . ' request',$status,$status==='pending'?null:$adminId,
            $status==='pending'?null:now()->format('Y-m-d H:i:s'),$status==='pending'?null:'UAT decision']);
    };
    $updatePunches = $pdo->prepare('UPDATE attendance SET clock_in=?,clock_out=? WHERE id=?');
    $examples = [];
    foreach ($accounts as $key => $account) {
        if ($account['role'] !== 'staff') continue;
        $userId = $account['id'];
        for ($offset=-28; $offset<0; $offset++) {
            $on = $date($offset);
            if ($on < $account['join'] || ($account['end'] && $on > $account['end'])) continue;
            $att = ensure_attendance_row($userId,$on);
            if ((int)$att['is_day_off']) continue;
            $pattern = abs($offset) % 9;
            $in = $on . ' ' . match($pattern) {1=>'10:00:59',2=>'10:01:00',3=>'11:13:00',default=>'10:00:00'};
            $out = $on . ($pattern===4?' 16:30:00':' 20:00:00');
            if ($pattern===5) { $in=null; $out=null; }
            if ($pattern===6) $out=null;
            if ($pattern===7) $addLeave($userId,$on,'wfh','approved');
            if ($pattern===8) { $addLeave($userId,$on,'medical','approved'); $in=null; $out=null; }
            $updatePunches->execute([$in,$out,$att['id']]);
            if ($in) {
                $addBreak((int)$att['id'],$userId,'lunch',1,$on.' 13:00:00',$on.($pattern===3?' 14:13:00':($pattern===2?' 14:00:01':' 14:00:00')));
                if ($pattern===0) {
                    foreach ([['solat',1,'14:30','14:50'],['solat',2,'16:00','16:20'],['solat',3,'18:00','18:20'],
                        ['short',1,'12:00','12:10'],['short',2,'15:00','15:10'],['short',3,'17:00','17:10']] as [$type,$seq,$start,$end]) {
                        $addBreak((int)$att['id'],$userId,$type,$seq,$on.' '.$start.':00',$on.' '.$end.':00');
                    }
                }
            }
            reconcile_attendance_day_status($userId,$on);
            recalculate_attendance((int)$att['id']);
            if ($key==='nurul') $examples[(string)$pattern] = ['date'=>$on,'attendance_id'=>(int)$att['id'],
                'label'=>['all seven breaks / target met','10:00:59 on-time boundary','10:01:00 late and lunch +1 second',
                    '73 minutes late / lunch 73 minutes','early clock-out','absent','missing clock-out','WFH completed','approved medical leave'][$pattern]];
        }
        if (in_array($account['scenario'],['future','ended','setup'])) continue;
        if ($account['scenario']==='leave') $addLeave($userId,$today,'annual','approved');
        if ($account['scenario']==='wfh') $addLeave($userId,$today,'wfh','approved');
        $att = ensure_attendance_row($userId,$today);
        $scenario = $account['scenario'];
        if (in_array($scenario,['working','lunch','solat','short_over','exhausted','completed','wfh'])) {
            $secondsToday = $now->getTimestamp() - (new DateTimeImmutable($today.' 00:00:00'))->getTimestamp();
            $baseSeconds = max(1,min(10800,$secondsToday));
            $clockIn = $now->modify('-'.$baseSeconds.' seconds');
            $clockOut = $scenario==='completed' ? $now->modify('-1 seconds')->format('Y-m-d H:i:s') : null;
            $updatePunches->execute([$clockIn->format('Y-m-d H:i:s'),$clockOut,$att['id']]);
            if (in_array($scenario,['lunch','solat','short_over'])) {
                $age = min($scenario==='short_over'?780:120,max(0,$baseSeconds-1));
                $type = $scenario==='short_over'?'short':$scenario;
                $addBreak((int)$att['id'],$userId,$type,1,$now->modify('-'.$age.' seconds')->format('Y-m-d H:i:s'),null);
            } elseif ($scenario==='exhausted') {
                $step = max(0,(int)floor(($baseSeconds-2)/16));
                $cursor = 1;
                foreach (['lunch'=>1,'solat'=>3,'short'=>3] as $type=>$count) for($seq=1;$seq<=$count;$seq++) {
                    $start=$clockIn->modify('+'.($cursor*$step).' seconds');
                    $end=$start->modify('+'.$step.' seconds');
                    $addBreak((int)$att['id'],$userId,$type,$seq,$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'));
                    $cursor+=2;
                }
            } elseif ($scenario==='working') {
                $start=$clockIn->modify('+'.(int)floor($baseSeconds/4).' seconds');
                $end=$clockIn->modify('+'.(int)floor($baseSeconds/2).' seconds');
                $addBreak((int)$att['id'],$userId,'lunch',1,$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'));
            }
            recalculate_attendance((int)$att['id']);
        }
    }
    foreach (['medical','wfh','day_off','annual','emergency','other'] as $index=>$type) {
        $on=$date($index+2);
        // WFH approval must be on a working day.
        if($type==='wfh' && (int)(new DateTimeImmutable($on))->format('w')===$off) $on=$date($index+9);
        $addLeave($accounts['danial']['id'],$on,$type,'pending');
        $addLeave($accounts['nurul']['id'],$on,$type,$index%2?'rejected':'approved');
    }
    // Preserve an explicitly overdue clock-out for admin correction testing.
    $missingDate=$date(-1);
    while ((int)(new DateTimeImmutable($missingDate))->format('w')===$off) $missingDate=(new DateTimeImmutable($missingDate))->modify('-1 day')->format('Y-m-d');
    $missingAtt=ensure_attendance_row($accounts['amin']['id'],$missingDate);
    $updatePunches->execute([$missingDate.' 10:00:00',null,$missingAtt['id']]);
    recalculate_attendance((int)$missingAtt['id']);
    foreach (['pending','approved','rejected'] as $index=>$state) {
        $on=$date(-($index+10));
        $att=ensure_attendance_row($accounts['danial']['id'],$on);
        $pdo->prepare("INSERT INTO attendance_correction_requests (user_id,attendance_id,work_date,field_name,requested_clock_in,requested_clock_out,reason,status,reviewed_by,reviewed_at) VALUES (?,?,?,'both',?,?,?,?,?,?)")
            ->execute([$accounts['danial']['id'],$att['id'],$on,$on.' 10:00:00',$on.' 20:00:00','UAT legacy correction review',$state,$state==='pending'?null:$adminId,$state==='pending'?null:$now->format('Y-m-d H:i:s')]);
        if($state==='approved') correct_attendance((int)$att['id'],$on.' 10:00:00',$on.' 20:00:00',$adminId,'UAT approved correction','correction_approved');
    }
    $pdo->prepare('UPDATE users SET is_active=0 WHERE id=?')->execute([$accounts['azlan']['id']]);
    $manifest=['generated_at'=>$now->format(DATE_ATOM),'today'=>$today,'from'=>$date(-28),'to'=>$today,'password'=>$password,
        'accounts'=>$accounts,'examples'=>$examples,'missing_clock_out'=>['date'=>$missingDate,'attendance_id'=>(int)$missingAtt['id']]];
    // Write before commit so a failed file write rolls back the fixtures.
    $temporary=$manifestPath.'.tmp';
    if(file_put_contents($temporary,json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))===false) throw new RuntimeException('Cannot write UAT manifest.');
    $pdo->commit();
    if(!rename($temporary,$manifestPath)) throw new RuntimeException('UAT committed; recover manifest from '.$temporary);
    echo count($accounts)." UAT accounts created. Credentials and exact dates: storage/uat-manifest.json\n";
} catch(Throwable $error) {
    if($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
