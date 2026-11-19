<?php
declare(strict_types=1);

/** Disposable-database concurrency and rollback tests for scheduled break repair. */
require_once __DIR__ . '/test_database_safety.php';
if (($argv[1] ?? '') === 'worker') {
    $dbName = (string) (getenv('LASTO_DB_NAME') ?: '');
} else {
    $dbName = 'staff_attendance_disposable_phase4b2_' . bin2hex(random_bytes(5));
    putenv('LASTO_DB_NAME=' . $dbName);
    $_ENV['LASTO_DB_NAME'] = $dbName;
    $_SERVER['LASTO_DB_NAME'] = $dbName;
}
$host = strtolower(trim((string) (getenv('LASTO_DB_HOST') ?: '127.0.0.1')));
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Mutation tests are restricted to a local MariaDB host.');
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/attendance_service.php';

function p4b2_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

function p4b2_connect(string $database): PDO
{
    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('LASTO_DB_HOST') ?: '127.0.0.1', getenv('LASTO_DB_PORT') ?: '3306', $database),
        getenv('LASTO_DB_USER') ?: 'root',
        getenv('LASTO_DB_PASS') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
}

function p4b2_worker(string $mode, int $userId): void
{
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/attendance_service.php';
    try {
        if ($mode === 'cleanup') {
            close_unended_breaks();
        } elseif ($mode === 'end') {
            $result = end_break($userId);
            fwrite(STDOUT, !empty($result['success']) ? "SUCCESS\n" : "NOOP\n");
            exit(0);
        } elseif ($mode === 'clockout') {
            $result = clock_out($userId);
            fwrite(STDOUT, !empty($result['success']) ? "SUCCESS\n" : "NOOP\n");
            exit(0);
        } else {
            throw new InvalidArgumentException('Unknown test worker operation.');
        }
        fwrite(STDOUT, "DONE\n");
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(2);
    }
}

if (($argv[1] ?? '') === 'worker') p4b2_worker((string) $argv[2], (int) ($argv[3] ?? 0));

$server = p4b2_connect('mysql');
$dbCheck = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
$dbCheck->execute([$dbName]);
p4b2_assert((int) $dbCheck->fetchColumn() === 0, 'generated disposable database name is unused');
TestDatabaseSafety::guardDropDatabase($dbName);
$created = false;

try {
    $server->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $pdo = p4b2_connect($dbName);
    $schema = [
        "CREATE TABLE users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, role VARCHAR(20) NOT NULL, is_active TINYINT NOT NULL DEFAULT 1, weekly_off_day TINYINT NOT NULL DEFAULT 0, attendance_location_enabled TINYINT NULL, office_id INT NULL) ENGINE=InnoDB",
        "CREATE TABLE system_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT NULL) ENGINE=InnoDB",
        "CREATE TABLE work_schedules (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), start_time TIME, end_time TIME, is_flexible TINYINT, work_days VARCHAR(30), target_work_minutes INT, is_default TINYINT, is_active TINYINT) ENGINE=InnoDB",
        "CREATE TABLE schedule_assignments (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED, schedule_id INT UNSIGNED, effective_from DATE, effective_to DATE, weekly_off_day TINYINT) ENGINE=InnoDB",
        "CREATE TABLE offices (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_ip VARCHAR(45) NULL) ENGINE=InnoDB",
        "CREATE TABLE leave_requests (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED, leave_date DATE, leave_type VARCHAR(30), status VARCHAR(20)) ENGINE=InnoDB",
        "CREATE TABLE attendance (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, work_date DATE NOT NULL, clock_in DATETIME NULL, clock_out DATETIME NULL, scheduled_end TIME NULL, is_day_off TINYINT NOT NULL DEFAULT 0, was_scheduled_off_day TINYINT NOT NULL DEFAULT 0, scheduled_off_weekday TINYINT NULL, total_break_minutes INT NOT NULL DEFAULT 0, shift_minutes INT NOT NULL DEFAULT 0, work_minutes INT NOT NULL DEFAULT 0, provisional_work_minutes INT NULL, early_leave_minutes INT NOT NULL DEFAULT 0, late_minutes INT NOT NULL DEFAULT 0, status VARCHAR(30) NOT NULL DEFAULT 'present', break_review_status VARCHAR(40) NOT NULL DEFAULT 'none', version INT NOT NULL DEFAULT 1, updated_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE break_records (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, attendance_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, break_type VARCHAR(30) NOT NULL, sequence_no INT NOT NULL DEFAULT 1, start_time DATETIME NOT NULL, end_time DATETIME NULL, allowed_minutes INT NOT NULL, duration_minutes INT NOT NULL DEFAULT 0, exceeded TINYINT NOT NULL DEFAULT 0, is_auto_closed TINYINT NOT NULL DEFAULT 0, closure_reason VARCHAR(50) NULL, system_closed_at DATETIME NULL, KEY idx_break_open (end_time, attendance_id)) ENGINE=InnoDB",
        "CREATE TABLE attendance_audit_logs (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, attendance_id INT UNSIGNED NULL, user_id INT UNSIGNED NOT NULL, changed_by INT UNSIGNED NOT NULL, action VARCHAR(60) NOT NULL, field_name VARCHAR(60) NULL, old_value TEXT NULL, new_value TEXT NULL, reason TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
        "CREATE TABLE attendance_location_events (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, attendance_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, office_id INT NULL, event_type VARCHAR(30), event_time DATETIME, location_status VARCHAR(30), client_ip VARCHAR(45) NULL, office_network VARCHAR(30), location_finalized_at DATETIME NULL) ENGINE=InnoDB",
    ];
    foreach ($schema as $sql) $pdo->exec($sql);
    $weekday = (int) (new DateTimeImmutable('today'))->format('w');
    $offDay = ($weekday + 1) % 7;
    for ($id = 1; $id <= 12; $id++) {
        $pdo->prepare('INSERT INTO users (id,role,is_active,weekly_off_day,attendance_location_enabled) VALUES (?,\'staff\',1,?,0)')->execute([$id, $offDay]);
    }
    $pdo->exec("INSERT INTO work_schedules (name,start_time,end_time,is_flexible,work_days,target_work_minutes,is_default,is_active) VALUES ('LASTO Standard Schedule','10:00','20:00',0,'0,1,2,3,4,5,6',450,1,1)");

    $makeFixture = static function (PDO $pdo, int $userId, string $date, ?string $clockOut, string $breakStart, string $status = 'present'): array {
        $stmt = $pdo->prepare('INSERT INTO attendance (user_id,work_date,clock_in,clock_out,scheduled_end,status,version) VALUES (?,?,?, ?,\'20:00:00\',?,1)');
        $stmt->execute([$userId, $date, $date . ' 10:00:00', $clockOut, $status]);
        $attendanceId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO break_records (attendance_id,user_id,break_type,sequence_no,start_time,allowed_minutes) VALUES (?,?,'short',1,?,10)");
        $stmt->execute([$attendanceId, $userId, $breakStart]);
        return [$attendanceId, (int) $pdo->lastInsertId()];
    };
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    $yesterday = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
    $workerEnv = array_merge(getenv(), ['LASTO_DB_NAME' => $dbName]);

    $spawn = static function (string $mode, int $userId) use ($workerEnv): array {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $mode, (string) $userId], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__), $workerEnv);
        if (!is_resource($process)) throw new RuntimeException('Unable to start isolated maintenance worker.');
        fclose($pipes[0]);
        return [$process, $pipes[1], $pipes[2]];
    };
    $finish = static function (array $worker, ?int $knownExitCode = null): array {
        [$process, $stdout, $stderr] = $worker;
        $out = trim((string) stream_get_contents($stdout)); fclose($stdout);
        $err = trim((string) stream_get_contents($stderr)); fclose($stderr);
        $closedCode = proc_close($process);
        return ['code'=>($knownExitCode !== null && $knownExitCode >= 0 ? $knownExitCode : $closedCode), 'out'=>$out, 'err'=>$err];
    };
    $holdEmployee = static function (int $userId, array $modes, ?int $expectedWaiters = null) use ($pdo, $spawn, $finish): array {
        $blocker = p4b2_connect((string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $blocker->beginTransaction();
        $stmt = $blocker->prepare('SELECT id FROM users WHERE id=? FOR UPDATE'); $stmt->execute([$userId]); $stmt->fetchColumn();
        $workers = [];
        foreach ($modes as $mode) $workers[] = $spawn($mode, $userId);
        usleep(200000);
        $states = [];
        $waiting = 0;
        foreach ($workers as $worker) {
            $state = proc_get_status($worker[0]);
            $states[] = $state;
            if ($state['running']) $waiting++;
        }
        $requiredWaiters = $expectedWaiters ?? count($modes);
        p4b2_assert($waiting >= $requiredWaiters, 'concurrent workers reached the expected employee-lock wait state');
        $blocker->commit();
        $results = [];
        foreach ($workers as $index => $worker) {
            $results[] = $finish($worker, $states[$index]['running'] ? null : (int) $states[$index]['exitcode']);
        }
        return $results;
    };

    // Before-fix duplicate audit reproduction: both independent connections
    // read the same open candidate, then run the old unconditional write path.
    [$beforeAtt, $beforeBreak] = $makeFixture($pdo, 1, $yesterday, null, $yesterday . ' 12:00:00');
    $a = p4b2_connect($dbName); $b = p4b2_connect($dbName);
    foreach ([[$a, $beforeBreak], [$b, $beforeBreak]] as [$conn, $breakId]) {
        $stmt = $conn->prepare('SELECT id,attendance_id,user_id,start_time,allowed_minutes FROM break_records WHERE id=? AND end_time IS NULL');
        $stmt->execute([$breakId]);
        p4b2_assert((bool) $stmt->fetch(), 'before-fix connections both observed the same open break');
    }
    foreach ([[$a, $beforeAtt, $beforeBreak], [$b, $beforeAtt, $beforeBreak]] as [$conn, $attId, $breakId]) {
        $end = $yesterday . ' 20:00:00';
        $conn->prepare("UPDATE break_records SET end_time=?,duration_minutes=480,exceeded=1,is_auto_closed=1,closure_reason='midnight_cron',system_closed_at=NOW() WHERE id=?")
            ->execute([$end, $breakId]);
        $conn->prepare("UPDATE attendance SET break_review_status=CASE WHEN break_review_status='none' THEN 'under_review' ELSE break_review_status END,version=version+1 WHERE id=?")->execute([$attId]);
        $conn->prepare("INSERT INTO attendance_audit_logs (attendance_id,user_id,changed_by,action,field_name,new_value,reason) VALUES (?,1,1,'break_auto_closed','break_end',?,'old non-atomic repair')")->execute([$attId, $end]);
    }
    p4b2_assert((int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id={$beforeAtt} AND action='break_auto_closed'")->fetchColumn() === 2, 'before-fix interleaving reproduces duplicate auto-close audit events');
    p4b2_assert((int) $pdo->query("SELECT version FROM attendance WHERE id={$beforeAtt}")->fetchColumn() === 3, 'before-fix interleaving increments attendance version twice');

    // Before-fix interrupted sequence: autocommitted break update survives a later failure.
    [$partialAtt, $partialBreak] = $makeFixture($pdo, 2, $yesterday, null, $yesterday . ' 12:00:00');
    $pdo->prepare("UPDATE break_records SET end_time=?,duration_minutes=480,is_auto_closed=1,closure_reason='midnight_cron' WHERE id=?")
        ->execute([$yesterday . ' 20:00:00', $partialBreak]);
    p4b2_assert((int) $pdo->query("SELECT total_break_minutes FROM attendance WHERE id={$partialAtt}")->fetchColumn() === 0, 'before-fix interruption leaves break closure with stale attendance totals');
    p4b2_assert((int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id={$partialAtt}")->fetchColumn() === 0, 'before-fix interruption leaves no audit row');

    // Normal policy-based past-shift repair.
    [$normalAtt, $normalBreak] = $makeFixture($pdo, 3, $yesterday, null, $yesterday . ' 12:00:00');
    close_unended_breaks();
    $normal = $pdo->prepare('SELECT end_time,duration_minutes,exceeded,is_auto_closed,closure_reason FROM break_records WHERE id=?'); $normal->execute([$normalBreak]); $normal = $normal->fetch();
    p4b2_assert($normal['end_time'] === $yesterday . ' 20:00:00' && (int) $normal['duration_minutes'] === 480, 'normal past-shift closure preserves POLICY_END_TIME timestamp and duration calculation');
    p4b2_assert((int) $normal['exceeded'] === 1 && (int) $normal['is_auto_closed'] === 1 && $normal['closure_reason'] === 'midnight_cron', 'normal past-shift overtime and review classification remain unchanged');
    $normalAttRow = $pdo->query("SELECT total_break_minutes,work_minutes,break_review_status,version FROM attendance WHERE id={$normalAtt}")->fetch();
    p4b2_assert((int) $normalAttRow['total_break_minutes'] === 480 && (int) $normalAttRow['work_minutes'] === 0 && $normalAttRow['break_review_status'] === 'under_review', 'attendance totals and review state are consistent after closure');
    p4b2_assert((int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id={$normalAtt} AND action='break_auto_closed'")->fetchColumn() === 1, 'one auto-close audit event is written');

    // Ineligible current-day/open-clock shift remains untouched.
    [$ineligibleAtt, $ineligibleBreak] = $makeFixture($pdo, 4, $today, null, $today . ' 12:00:00');
    close_unended_breaks();
    p4b2_assert($pdo->query("SELECT end_time FROM break_records WHERE id={$ineligibleBreak}")->fetchColumn() === null, 'ineligible current-day open break remains unchanged');

    // Concurrent duplicate cleanup; both workers scan while employee lock is held.
    [$raceAtt, $raceBreak] = $makeFixture($pdo, 5, $yesterday, null, $yesterday . ' 12:00:00');
    $raceResults = $holdEmployee(5, ['cleanup','cleanup']);
    p4b2_assert(count(array_filter($raceResults, static fn($r) => $r['code'] === 0)) === 2, 'two cleanup processes finish without deadlock');
    p4b2_assert((int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id={$raceAtt} AND action='break_auto_closed'")->fetchColumn() === 1, 'two concurrent cleanup runs write one auto-close audit');
    p4b2_assert((int) $pdo->query("SELECT version FROM attendance WHERE id={$raceAtt}")->fetchColumn() === 2, 'two concurrent cleanup runs increment attendance once');

    // Cleanup and employee end-break serialize on the same employee lock.
    [$endAtt, $endBreak] = $makeFixture($pdo, 6, $today, $today . ' 20:00:00', $today . ' 12:00:00', 'completed');
    $endResults = $holdEmployee(6, ['cleanup','end']);
    p4b2_assert(count(array_filter($endResults, static fn($r) => $r['code'] === 0)) === 2, 'cleanup and end-break complete without deadlock');
    p4b2_assert($pdo->query("SELECT end_time FROM break_records WHERE id={$endBreak}")->fetchColumn() !== null, 'cleanup/end-break race leaves the break closed');
    p4b2_assert((int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id={$endAtt} AND action IN ('break_end','break_auto_closed')")->fetchColumn() === 1, 'cleanup/end-break race records exactly one break closure event');

    // Clock-out and cleanup: cleanup eligibility requires persisted clock_out;
    // employee-first locking lets clock-out close its break before cleanup rechecks.
    [$clockAtt, $clockBreak] = $makeFixture($pdo, 7, $today, null, $today . ' 12:00:00');
    $clockResults = $holdEmployee(7, ['cleanup','clockout'], 1);
    p4b2_assert(count(array_filter($clockResults, static fn($r) => $r['code'] === 0)) === 2, 'cleanup and clock-out complete without deadlock');
    p4b2_assert($pdo->query("SELECT end_time FROM break_records WHERE id={$clockBreak}")->fetchColumn() !== null, 'clock-out race leaves the employee break closed');
    p4b2_assert((int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id={$clockAtt} AND action IN ('break_end','break_auto_closed')")->fetchColumn() === 1, 'clock-out race records exactly one break closure event');

    // A candidate that has already been ended is a no-op: no second audit.
    [$zeroAtt, $zeroBreak] = $makeFixture($pdo, 8, $yesterday, null, $yesterday . ' 12:00:00');
    $pdo->prepare('UPDATE break_records SET end_time=? WHERE id=?')->execute([$yesterday . ' 18:00:00', $zeroBreak]);
    p4b2_assert(close_unended_break_candidate($zeroBreak, $zeroAtt, 8, 'midnight_cron') === false, 'already-ended candidate returns a no-op');
    p4b2_assert((int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id={$zeroAtt}")->fetchColumn() === 0, 'no-op candidate writes no duplicate audit');

    // Force a matched conditional update to affect zero rows (no changed values).
    [$zeroUpdateAtt, $zeroUpdateBreak] = $makeFixture($pdo, 8, $yesterday, null, $yesterday . ' 12:00:00');
    $pdo->exec("CREATE TRIGGER phase4b2_noop_break BEFORE UPDATE ON break_records FOR EACH ROW
        BEGIN
            SET NEW.end_time=OLD.end_time, NEW.duration_minutes=OLD.duration_minutes,
                NEW.exceeded=OLD.exceeded, NEW.is_auto_closed=OLD.is_auto_closed,
                NEW.closure_reason=OLD.closure_reason, NEW.system_closed_at=OLD.system_closed_at;
        END");
    p4b2_assert(close_unended_break_candidate($zeroUpdateBreak, $zeroUpdateAtt, 8, 'midnight_cron') === false, 'conditional break update with zero affected rows is a no-op');
    $pdo->exec('DROP TRIGGER phase4b2_noop_break');
    p4b2_assert($pdo->query("SELECT end_time FROM break_records WHERE id={$zeroUpdateBreak}")->fetchColumn() === null, 'zero-row conditional update leaves break open');
    p4b2_assert((int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id={$zeroUpdateAtt}")->fetchColumn() === 0, 'zero-row conditional update writes no attendance or audit changes');

    // Recalculation failure rolls back break, attendance and audit together.
    [$recalcAtt, $recalcBreak] = $makeFixture($pdo, 9, $yesterday, null, $yesterday . ' 12:00:00');
    $pdo->exec("CREATE TRIGGER phase4b2_fail_recalc BEFORE UPDATE ON attendance FOR EACH ROW
        BEGIN IF NEW.total_break_minutes <> OLD.total_break_minutes THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected recalculation failure'; END IF; END");
    $recalcFailures = close_unended_breaks();
    $pdo->exec('DROP TRIGGER phase4b2_fail_recalc');
    p4b2_assert($recalcFailures > 0, 'recalculation failure is returned to the scheduled caller');
    p4b2_assert($pdo->query("SELECT end_time FROM break_records WHERE id={$recalcBreak}")->fetchColumn() === null, 'recalculation failure rolls back the break closure');
    $recalcRow = $pdo->query("SELECT total_break_minutes,version FROM attendance WHERE id={$recalcAtt}")->fetch();
    p4b2_assert((int) $recalcRow['total_break_minutes'] === 0 && (int) $recalcRow['version'] === 1, 'recalculation failure rolls back attendance totals and version');
    p4b2_assert((int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id={$recalcAtt}")->fetchColumn() === 0, 'recalculation failure writes no audit');

    // Audit insertion failure rolls back the closure and recalculated totals.
    [$auditAtt, $auditBreak] = $makeFixture($pdo, 10, $yesterday, null, $yesterday . ' 12:00:00');
    $pdo->exec("CREATE TRIGGER phase4b2_fail_audit BEFORE INSERT ON attendance_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected audit failure'");
    $auditFailures = close_unended_breaks();
    $pdo->exec('DROP TRIGGER phase4b2_fail_audit');
    p4b2_assert($auditFailures > 0, 'audit failure is returned to the scheduled caller');
    p4b2_assert($pdo->query("SELECT end_time FROM break_records WHERE id={$auditBreak}")->fetchColumn() === null, 'audit failure rolls back the break closure');
    $auditRow = $pdo->query("SELECT total_break_minutes,version FROM attendance WHERE id={$auditAtt}")->fetch();
    p4b2_assert((int) $auditRow['total_break_minutes'] === 0 && (int) $auditRow['version'] === 1, 'audit failure rolls back recalculated attendance values');
    p4b2_assert((int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id={$auditAtt}")->fetchColumn() === 0, 'failed audit leaves no partial audit row');

    // Repeated maintenance is idempotent and does not duplicate audit.
    [$repeatAtt, $repeatBreak] = $makeFixture($pdo, 11, $yesterday, null, $yesterday . ' 12:00:00');
    mark_incomplete_attendances();
    mark_incomplete_attendances();
    p4b2_assert($pdo->query("SELECT end_time FROM break_records WHERE id={$repeatBreak}")->fetchColumn() !== null, 'repeated scheduled cleanup closes the stale break');
    p4b2_assert((int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id={$repeatAtt} AND action='break_auto_closed'")->fetchColumn() === 1, 'repeated scheduled cleanup remains idempotent');

    // A per-employee failure does not prevent cleanup of other employees.
    [$independentAttA, $independentBreakA] = $makeFixture($pdo, 12, $yesterday, null, $yesterday . ' 12:00:00');
    [$independentAttB, $independentBreakB] = $makeFixture($pdo, 3, $yesterday, null, $yesterday . ' 13:00:00');
    $pdo->exec("CREATE TRIGGER phase4b2_fail_one_employee BEFORE UPDATE ON attendance FOR EACH ROW
        BEGIN IF OLD.user_id=12 AND NEW.total_break_minutes <> OLD.total_break_minutes THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected one-employee failure'; END IF; END");
    $employeeFailures = close_unended_breaks();
    $pdo->exec('DROP TRIGGER phase4b2_fail_one_employee');
    p4b2_assert($employeeFailures > 0, 'per-employee repair failure is counted');
    p4b2_assert($pdo->query("SELECT end_time FROM break_records WHERE id={$independentBreakA}")->fetchColumn() === null, 'failed employee repair rolls back only that employee transaction');
    p4b2_assert($pdo->query("SELECT end_time FROM break_records WHERE id={$independentBreakB}")->fetchColumn() !== null, 'maintenance continues and repairs another employee after a failure');

    echo "Phase 4B.2 disposable scheduled-break suite completed.\n";
} finally {
    if ($created) {
        TestDatabaseSafety::guardDropDatabase($dbName);
        $server->exec("DROP DATABASE `{$dbName}`");
    }
}
