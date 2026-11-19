<?php
declare(strict_types=1);

// Destructive only to a newly created, uniquely named schema on an isolated test server.
function failTest(string $message): never { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
$options = getopt('', ['database:', 'host:', 'port:', 'user:', 'password:']);
$db = (string)($options['database'] ?? '');
$host = strtolower((string)($options['host'] ?? ''));
$port = (int)($options['port'] ?? 0);
$user = (string)($options['user'] ?? '');
$password = (string)($options['password'] ?? '');
if (!preg_match('/\Astaff_attendance_diag_disposable_[a-f0-9]{8}\z/', $db)
    || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || $port !== 33117 || $user === '') {
    failTest('requires a unique disposable schema name, local host, isolated port 33117, and explicit user');
}
$admin = new mysqli($host, $user, $password, '', $port);
if ($admin->connect_errno) failTest('cannot connect to isolated local MariaDB');
$exists = $admin->query("SELECT COUNT(*) AS n FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='" . $admin->real_escape_string($db) . "'")->fetch_assoc();
if ((int)$exists['n'] !== 0) failTest('refusing to reuse an existing schema');
$created = false;
try {
    $admin->query('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $schema = str_replace('staff_attendance', $db, (string)file_get_contents(__DIR__ . '/../database/schema.sql'));
    $admin->multi_query($schema);
    do { if ($result = $admin->store_result()) $result->free(); } while ($admin->more_results() && $admin->next_result());
    if ($admin->errno) throw new RuntimeException('schema import failed: ' . $admin->error);
    $admin->select_db($db);
    foreach (['cpanel_seed.sql', 'migration_phase3b_idempotency.sql'] as $file) {
        $sql = (string)file_get_contents(__DIR__ . '/../database/' . $file);
        $admin->multi_query($sql);
        do { if ($result = $admin->store_result()) $result->free(); } while ($admin->more_results() && $admin->next_result());
        if ($admin->errno) throw new RuntimeException("{$file} failed: " . $admin->error);
    }
    $admin->query("INSERT INTO users(employee_id,username,password_hash,full_name,role) VALUES
        ('SYN-A','syn_a','x','Synthetic A','staff'),('SYN-B','syn_b','x','Synthetic B','staff'),('SYN-C','syn_c','x','Synthetic C','staff')");
    $admin->query("INSERT INTO attendance(user_id,work_date,status,clock_in,clock_out) SELECT id,'2026-10-30','completed','2026-10-30 10:00:00','2026-10-30 09:00:00' FROM users WHERE username='syn_a'");
    $admin->query("INSERT INTO break_records(attendance_id,user_id,break_type,start_time) SELECT a.id,u.id,'lunch','2026-10-30 10:15:00' FROM attendance a JOIN users u ON u.username='syn_b' WHERE a.work_date='2026-10-30'");
    $admin->query("INSERT INTO break_records(attendance_id,user_id,break_type,start_time) SELECT id,user_id,'short','2026-10-30 10:20:00' FROM attendance WHERE work_date='2026-10-30'");
    $admin->query("INSERT INTO break_records(attendance_id,user_id,break_type,start_time,end_time) SELECT id,user_id,'solat','2026-10-30 14:00:00','2026-10-30 13:00:00' FROM attendance WHERE work_date='2026-10-30'");
    $admin->query("INSERT INTO attendance_location_events(attendance_id,user_id,event_type,event_time,location_status) SELECT a.id,u.id,'clock_in','2026-10-30 10:00:00','unavailable' FROM attendance a JOIN users u ON u.username='syn_b' WHERE a.work_date='2026-10-30'");
    $admin->query("INSERT INTO attendance_correction_requests(user_id,attendance_id,work_date,field_name,reason) SELECT u.id,a.id,'2026-10-29','status','Synthetic owner mismatch' FROM attendance a JOIN users u ON u.username='syn_b' WHERE a.work_date='2026-10-30'");
    $admin->query("INSERT INTO attendance_audit_logs(attendance_id,user_id,changed_by,action,created_at) SELECT a.id,u.id,u.id,'synthetic','2026-10-30 10:00:00' FROM attendance a JOIN users u ON u.username='syn_b' WHERE a.work_date='2026-10-30'");
    $admin->query("INSERT INTO attendance_idempotency_operations(request_id,user_id,operation,payload_hash,status,attendance_id,created_at,expires_at) SELECT 'synthetic-mismatch',u.id,'clock_in',UNHEX(REPEAT('00',32)),'succeeded',a.id,'2026-10-30 10:00:00','2026-11-01 00:00:00' FROM attendance a JOIN users u ON u.username='syn_b' WHERE a.work_date='2026-10-30'");
    $admin->query("INSERT INTO attendance(user_id,work_date,status,clock_in) SELECT id,'2026-10-30','on_break','2026-10-30 10:00:00' FROM users WHERE username='syn_c'");
    $admin->query("INSERT INTO break_records(attendance_id,user_id,break_type,start_time) SELECT a.id,a.user_id,'lunch','2026-10-30 12:00:00' FROM attendance a JOIN users u ON u.id=a.user_id WHERE u.username='syn_c'");
    $admin->query("INSERT INTO work_schedules(name,start_time,end_time,is_default,is_active) VALUES ('Synthetic conflicting default','09:00:00','18:00:00',1,1)");
    $admin->query("INSERT INTO schedule_assignments(user_id,schedule_id,effective_from,effective_to,weekly_off_day) SELECT u.id,s.id,'2026-10-30','2026-10-31',7 FROM users u JOIN work_schedules s ON s.name='LASTO Standard Schedule' WHERE u.username='syn_a'");
    $admin->query("INSERT INTO schedule_assignments(user_id,schedule_id,effective_from,effective_to) SELECT u.id,s.id,'2026-10-31',NULL FROM users u JOIN work_schedules s ON s.name='LASTO Standard Schedule' WHERE u.username='syn_a'");
    $admin->query("INSERT INTO schedule_assignments(user_id,schedule_id,effective_from,effective_to) SELECT u.id,s.id,'2026-10-30','2026-10-30' FROM users u JOIN work_schedules s ON s.name='LASTO Standard Schedule' WHERE u.username='syn_c'");
    $admin->query("INSERT INTO schedule_assignments(user_id,schedule_id,effective_from,effective_to) SELECT u.id,s.id,'2026-10-31',NULL FROM users u JOIN work_schedules s ON s.name='LASTO Standard Schedule' WHERE u.username='syn_c'");
    $admin->query("INSERT INTO schedule_assignments(user_id,schedule_id,effective_from,effective_to) SELECT u.id,s.id,'2026-11-01','2026-10-31' FROM users u JOIN work_schedules s ON s.name='LASTO Standard Schedule' WHERE u.username='syn_b'");
    $admin->query("INSERT INTO staff_balance_ledger(employee_id,balance_type,amount,source_type,admin_id,admin_note) SELECT u.id,'cuti_perlu_ganti',-5,'manual',u.id,'Synthetic authorized override fixture' FROM users u WHERE u.username='syn_a'");
    $duplicateRejected = false;
    try {
        $admin->query("INSERT INTO attendance(user_id,work_date) SELECT id,'2026-10-30' FROM users WHERE username='syn_a'");
    } catch (mysqli_sql_exception $ignored) { $duplicateRejected = true; }
    if (!$duplicateRejected) throw new RuntimeException('schema did not reject duplicate attendance user/date');
    $explain = $admin->query("EXPLAIN SELECT id FROM attendance WHERE work_date='2026-10-30'")->fetch_assoc();
    if (!$explain) throw new RuntimeException('EXPLAIN did not return a query plan');
    $before = $admin->query('SELECT (SELECT COUNT(*) FROM attendance) a,(SELECT COUNT(*) FROM break_records) b')->fetch_assoc();
    putenv('ATTENDANCE_DIAGNOSTICS_DB_PASS=' . $password);
    $command = [PHP_BINARY, __DIR__ . '/../cli/attendance_integrity_diagnostics.php', "--database={$db}", "--user={$user}", "--host={$host}", "--port={$port}", '--format=json', '--from=2026-10-30', '--to=2026-10-30'];
    $pipes = [];
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('could not start diagnostics CLI');
    fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) throw new RuntimeException('diagnostics failed: ' . trim($stderr));
    $report = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    $counts = array_column($report['results'], 'matching_rows', 'id');
    foreach (['D01'=>1,'D02'=>1,'D03'=>1,'D04'=>1,'D05'=>1,'D07'=>0,'D08'=>1,'D09'=>1,'D10'=>1,'D11'=>1,'D12'=>1,'D13'=>1,'D14'=>0,'D17'=>1,'D18'=>1,'D19'=>0] as $id=>$expected) {
        if (($counts[$id] ?? null) !== $expected) throw new RuntimeException("{$id} expected {$expected}, got " . ($counts[$id] ?? 'missing'));
    }
    if ($report['mutation_performed'] !== false) throw new RuntimeException('report did not assert read-only behavior');
    $after = $admin->query('SELECT (SELECT COUNT(*) FROM attendance) a,(SELECT COUNT(*) FROM break_records) b')->fetch_assoc();
    if ($before !== $after) throw new RuntimeException('diagnostic execution changed attendance or break row counts');
    echo "PASS: 19 diagnostics executed; anomaly detection, valid open-break handling, date scope, and read-only row counts verified.\n";
} catch (Throwable $error) {
    failTest($error->getMessage());
} finally {
    if ($created && preg_match('/\Astaff_attendance_diag_disposable_[a-f0-9]{8}\z/', $db)) {
        $admin->query('DROP DATABASE `' . $db . '`');
    }
    $admin->close();
}
