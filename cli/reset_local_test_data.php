<?php
/** Local XAMPP reset and deterministic Malaysian UAT seed. Never run on cPanel. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/maintenance.php';
require_once __DIR__ . '/../includes/attendance_service.php';
require_once __DIR__ . '/../includes/notification_service.php';

$pdo = db();
$identity = $pdo->query('SELECT @@hostname AS host, DATABASE() AS db, @@port AS port')->fetch();
$host = strtolower((string)($identity['host'] ?? ''));
$dbName = (string)($identity['db'] ?? '');
if (!in_array(strtolower(DB_HOST), ['localhost', '127.0.0.1', '::1'], true)
    || !in_array($host, ['localhost', '127.0.0.1', '::1', strtolower(gethostname())], true)
    || $dbName !== 'staff_attendance' || APP_ENV === 'production' || APP_ENV === 'staging') {
    throw new RuntimeException('Refusing reset: the active connection is not the local XAMPP staff_attendance database.');
}

$backup = backup_database();
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
if ($schema === false) throw new RuntimeException('Cannot read local schema.');
$pdo->exec($schema);
$pdo->exec(file_get_contents(__DIR__ . '/../database/cpanel_seed.sql'));
$pdo = db();
$office = $pdo->prepare("INSERT INTO offices (name,code,address) VALUES (?,?,?)");
foreach ([['Kuala Lumpur HQ','KL-HQ','Kuala Lumpur, Malaysia'],['Penang Branch','PNG','George Town, Penang'],['Sabah Branch','SBH','Kota Kinabalu, Sabah']] as $row) $office->execute($row);
$officeIds = array_map('intval', $pdo->query('SELECT id FROM offices ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$scheduleId = (int)$pdo->query("SELECT id FROM work_schedules WHERE is_default=1 LIMIT 1")->fetchColumn();
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Kuala_Lumpur'));
$date = static fn(int $offset): string => $today->modify(($offset >= 0 ? '+' : '').$offset.' days')->format('Y-m-d');
$names = [
 'Muhammad Danish bin Azman','Nur Aisyah binti Roslan','Ahmad Firdaus bin Zulkifli','Siti Hajar binti Rahman','Nurul Syafiqah binti Ismail','Muhammad Hafiz bin Abdullah','Aina Sofea binti Hassan','Amirul Hakim bin Yusof','Farah Nabila binti Ahmad','Irfan Hakimi bin Salleh','Balqis Izzati binti Ali','Rafiq bin Kamarudin','Liyana binti Jamal','Haziq Danish bin Rahim','Nadia Qistina binti Ismail','Azlan bin Shukor','Mariam binti Roslan','Syahirah binti Aziz','Wafi bin Zainal','Aiman bin Husain',
 'Lim Wei En','Tan Jia Hui','Wong Zi Xuan','Lee Xin Yi','Chen 嘉慧','林伟恩','黄子轩','Cheong Jun Hao','Liew Yu Ting','Ng Pei Wen','Koh Jian Wei','Teh Mei Ling','Leong Hui Min','Yeong Yong Kang','Lim Jia Yi','Tan Wei Ming','Wong Xin Yi','Lee Zhi Hao','Chen Yi Xuan','林欣怡','黄美玲','刘雨婷','张俊','王佳怡','李建伟',
 'Arvind Kumar','Kavitha Devi','Pravin Raj','Shalini Nair','Viknesh Kumar','Deepa Devi','Ramesh Raj','Anitha Nair','Suresh Kumar','Priya Devi',
 'Dayang Nurul Izzati','Alvin anak Jantan','Josephine Gimbad','Matthew Lajim','Roselyn Jumin','Aqilah binti Japar','Daniel anak Masing','Melanie Gindau','Hana anak Laja','Julius Gimbang','Nur Farhana binti Latif','Puteri Aina binti Omar','Syed Amir bin Syed Ali','Fatin Amani binti Noor','Adam Rayyan bin Hamzah','Sofea binti Karim','Hakim bin Razak','Mira Alya binti Harun','Naufal bin Azmi','Zulaikha binti Samad','Qistina binti Mahmud','Danish bin Nordin','Afiqah binti Yatim','Izzuddin bin Saad','Alya binti Fauzi','Haris bin Latif','Nurin binti Yusri','Faiz bin Hamdan','Aqil bin Rahman','Huda binti Mustafa','Syazwan bin Othman','Maya binti Abdullah','Rizal bin Hamid','Amani binti Ridzuan','Ehsan bin Mokhtar','Nisa binti Azhar','Faris bin Rashid','Sabrina binti Azmi','Rayyan bin Halim','Sarah binti Anwar','Zikri bin Fadzil','Amirah binti Iskandar','Ilham bin Yasin','Solehah binti Nasir','Haikal bin Zakaria','Alya Nur binti Rauf','Izzat bin Hamzah','Murni binti Bahar','Rafi bin Jalil','Nadia binti Yamin','Qayyum bin Shamsul','Hanim binti Saif','Faizal bin Osman','Siti Nur binti Kamal','Kamal bin Razak','Zara binti Ismail','Yusuf bin Harun','Misha binti Khalid','Adib bin Rashid','Rania binti Azman','Hakimah binti Rahim','Luqman bin Hassan','Nurin binti Ahmad','Aiman bin Jamal','Alya binti Salleh','Fikri bin Yusof','Mariam binti Ali','Nabil bin Roslan','Hana binti Ismail','Syafiq bin Zulkifli','Aisyah binti Rahman','Irfan bin Ahmad','Farah binti Abdullah','Azim bin Aziz','Suhana binti Kamarudin','Rizwan bin Salleh','Nadia binti Shukor','Amir bin Rahman','Yana binti Roslan','Fauzan bin Ali','Haziq bin Hassan','Siti Aminah binti Yusof','Zain bin Ahmad','Aqilah binti Rahim','Dhia binti Ismail','Raihan bin Abdullah','Nabila binti Zainal','Faris bin Karim','Nur Amira binti Yusof','Syahmi bin Rahman','Aina binti Roslan','Adnan bin Ali','Mina binti Hassan','Izzah binti Ahmad','Danish bin Yusof','Nur Hidayah binti Ismail','Rafiq bin Rahman','Siti Mariam binti Salleh','Azhar bin Abdullah','Nur Shazana binti Karim','Yassin bin Hassan','Alya binti Roslan','Iman bin Yusof','Nadia binti Rahman','Syakir bin Ali','Farzana binti Ahmad','Rizqi bin Ismail','Sabrina binti Rahman'
];
// Keep the requested UAT mix at 60 Malay, 25 Chinese, 10 Indian, and 5 East Malaysian names.
if (count($names) < 100) throw new RuntimeException('UAT name list is incomplete.');
$pdo->beginTransaction();
try {
    $userInsert = $pdo->prepare('INSERT INTO users (employee_id,username,password_hash,full_name,email,job_position,office_id,role,joining_date,is_active,must_change_password,weekly_off_day) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $scheduleInsert = $pdo->prepare('INSERT INTO schedule_assignments (user_id,schedule_id,effective_from,weekly_off_day) VALUES (?,?,?,?)');
    $accounts = []; $credentials = [];
    $admins = [['admin.lasto01','Noraini binti Hassan'],['admin.lasto02','Lim Wei Ming'],['admin.lasto03','Arvind Kumar']];
    foreach ($admins as $i => [$username,$name]) { $password='Adm!'.bin2hex(random_bytes(8)); $userInsert->execute(['ADM'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT),$username,password_hash($password,PASSWORD_DEFAULT),$name,$username.'@example.invalid','LASTO Administrator',$officeIds[$i%3],'admin',$date(-365),1,0,6]); $id=(int)$pdo->lastInsertId(); $scheduleInsert->execute([$id,$scheduleId,$date(-365),6]); $accounts[]=['username'=>$username,'name'=>$name,'role'=>'admin','password'=>$password,'scenario'=>'administrator']; }
    $staffInsertIds=[];
    foreach (array_slice($names,0,100) as $i => $name) {
        $n=$i+1; $username='uat.staff'.str_pad((string)$n,3,'0',STR_PAD_LEFT); $password='Stf!'.bin2hex(random_bytes(8)); $off=$n%7; $active=$n!==100; $join=$date(-60-($n%120));
        $userInsert->execute(['ST'.str_pad((string)$n,4,'0',STR_PAD_LEFT),$username,password_hash($password,PASSWORD_DEFAULT),$name,$username.'@example.invalid','UAT Staff',$officeIds[$n%count($officeIds)],'staff',$join,$active?1:0,0,$off]); $id=(int)$pdo->lastInsertId(); $staffInsertIds[]=$id; $scheduleInsert->execute([$id,$scheduleId,$join,$off]); $scenario=['normal','late','missing-clock-out','active-break','off-day','pending-leave','pending-wfh','approved-wfh','rejected-leave','mixed'][$i%10]; $accounts[]=['username'=>$username,'name'=>$name,'role'=>'staff','password'=>$password,'scenario'=>$scenario];
    }
    $attInsert=$pdo->prepare('INSERT INTO attendance (user_id,work_date,schedule_id,scheduled_start,scheduled_end,is_day_off,was_scheduled_off_day,scheduled_off_weekday,is_wfh,clock_in,clock_out,status,late_minutes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $breakInsert=$pdo->prepare('INSERT INTO break_records (attendance_id,user_id,break_type,sequence_no,start_time,end_time,duration_minutes,allowed_minutes,exceeded) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach ($staffInsertIds as $idx=>$uid) {
        $off=$idx%7;
        $kind=$idx%10;
        for($d=-29;$d<=0;$d++){
            $on=$date($d);
            $dow=(int)(new DateTimeImmutable($on))->format('w');
            if($dow===$off){
                $attInsert->execute([$uid,$on,$scheduleId,'10:00:00','20:00:00',1,1,$off,0,null,null,'day_off',0]);
                $aid=(int)$pdo->lastInsertId();
                recalculate_attendance($aid);
                continue;
            }
            $in=null;
            $out=null;
            $isWfh=($kind===7)?1:0;
            $breaksToInsert=[];
            if($d===0){
                if($kind===3){
                    $in=$on.' 10:00:00';
                    $out=null;
                    $breaksToInsert[]=['lunch',1,$on.' 13:00:00',null,0,60,0];
                }elseif($kind===5||$kind===6){
                    $in=null;
                    $out=null;
                }elseif($kind===2){
                    $in=$on.' 10:00:00';
                    $out=null;
                }else{
                    $in=$on.($kind===1?' 10:35:00':' 10:00:00');
                    $out=null;
                }
            }else{
                if($kind===5 && ($d===-1||$d===-7)){
                    $in=$on.' 10:00:00';
                    $out=null;
                }elseif($kind===6 && ($d===-2||$d===-5)){
                    $in=null;
                    $out=null;
                }else{
                    $in=$on.($kind===1?' 10:35:00':' 10:00:00');
                    $out=$on.($kind===2?' 18:30:00':' 20:00:00');
                    $breaksToInsert[]=['lunch',1,$on.' 13:00:00',$on.' 14:00:00',60,60,0];
                    if($kind===4 && ($d%2===0)){
                        $breaksToInsert[]=['short',1,$on.' 16:00:00',$on.' 16:15:00',15,10,1];
                    }elseif($kind===8){
                        $breaksToInsert[]=['solat',1,$on.' 16:30:00',$on.' 16:50:00',20,20,0];
                    }
                }
            }
            $initStatus=!$in?($on<today_str()?'absent':'not_clocked_in'):($out?'completed':'present');
            $lateMins=($in && str_ends_with($in,'10:35:00'))?35:0;
            $attInsert->execute([$uid,$on,$scheduleId,'10:00:00','20:00:00',0,0,$off,$isWfh,$in,$out,$initStatus,$lateMins]);
            $aid=(int)$pdo->lastInsertId();
            foreach($breaksToInsert as [$bType,$bSeq,$bStart,$bEnd,$bDur,$bAllowed,$bExceeded]){
                $breakInsert->execute([$aid,$uid,$bType,$bSeq,$bStart,$bEnd,$bDur,$bAllowed,$bExceeded]);
            }
            recalculate_attendance($aid);
        }
    }
    $leave=$pdo->prepare('INSERT INTO leave_requests (user_id,leave_date,leave_type,reason,status,reviewed_by,reviewed_at,admin_note) VALUES (?,?,?,?,?,?,?,?)'); $adminId=(int)$pdo->query("SELECT id FROM users WHERE username='admin.lasto01'")->fetchColumn(); $notifications=[];
    foreach (array_slice($staffInsertIds,0,6) as $i=>$uid) { $type=$i%2?'wfh':'annual'; $state=['pending','approved','rejected'][$i%3]; $when=$date(-($i+1)); $leave->execute([$uid,$when,$type,'UAT realistic request',$state,$state==='pending'?null:$adminId,$state==='pending'?null:$date(-$i),'UAT decision']); $rid=(int)$pdo->lastInsertId(); if($state==='pending'){notification_create($adminId,'leave_request','uat-leave-'.$rid,'New '.strtoupper($type).' request','A staff member submitted a request for '.$when.'.','/admin/leave_requests.php');}else{notification_create($uid,'leave_decision','uat-decision-'.$rid,'Request '.ucfirst($state),'Your request for '.$when.' was '.$state.'.','/staff/leave.php');} }
    $pdo->exec("UPDATE notifications SET read_at=NOW() WHERE id IN (SELECT id FROM (SELECT id FROM notifications ORDER BY id LIMIT 2) AS seeded_read)");
    $pdo->commit();
    $credDir=__DIR__.'/../tests/uat'; if(!is_dir($credDir)) mkdir($credDir,0700,true); $manifest=['generated_at'=>$today->format(DATE_ATOM),'database'=>$dbName,'accounts'=>$accounts]; file_put_contents($credDir.'/LOCAL_TEST_CREDENTIALS.md',"# Local UAT credentials\n\nGenerated: {$manifest['generated_at']}\n\n| Username | Name | Role | Password | Scenario |\n|---|---|---|---|---|\n".implode("\n",array_map(static fn($a)=>'| '.implode(' | ',$a).' |',$accounts))."\n"); file_put_contents($credDir.'/LOCAL_TEST_CREDENTIALS.csv',"username,name,role,password,scenario\n".implode("\n",array_map(static fn($a)=>implode(',',array_map(static fn($v)=>'"'.str_replace('"','""',$v).'"',$a)),$accounts))."\n"); file_put_contents($credDir.'/SCENARIO_MAPPING.md',"# UAT scenario mapping\n\n| Scenario | Account |\n|---|---|\n".implode("\n",array_map(static fn($a)=>'| '.$a['scenario'].' | '.$a['username'].' |',$accounts))."\n");
    echo "Local reset complete. Backup: {$backup}\nAccounts: 3 admins, 100 staff\nCredentials: {$credDir}/LOCAL_TEST_CREDENTIALS.md\n";
} catch (Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
