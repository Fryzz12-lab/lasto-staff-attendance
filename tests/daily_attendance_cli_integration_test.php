<?php
declare(strict_types=1);

/** Run the actual daily-attendance CLI against a generated disposable database. */
require_once __DIR__ . '/test_database_safety.php';

function cli_test_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

function cli_test_connect(string $database): PDO
{
    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('LASTO_DB_HOST') ?: '127.0.0.1', getenv('LASTO_DB_PORT') ?: '3306', $database),
        getenv('LASTO_DB_USER') ?: 'root',
        getenv('LASTO_DB_PASS') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
}

$host = strtolower(trim((string) (getenv('LASTO_DB_HOST') ?: '127.0.0.1')));
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('CLI mutation tests are restricted to a local MariaDB host.');
}
$database = 'staff_attendance_test_' . bin2hex(random_bytes(6));
putenv('LASTO_DB_NAME=' . $database);
$_ENV['LASTO_DB_NAME'] = $database;
$_SERVER['LASTO_DB_NAME'] = $database;
$server = cli_test_connect('mysql');
$check = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
$check->execute([$database]);
cli_test_assert((int) $check->fetchColumn() === 0, 'generated disposable database name is unused');
TestDatabaseSafety::guardDropDatabase($database);
$created = false;
$sessionPath = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'daily-attendance-cli-' . bin2hex(random_bytes(5));

try {
    $server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $pdo = cli_test_connect($database);
    $schema = str_replace('`staff_attendance`', '`' . $database . '`', (string) file_get_contents(__DIR__ . '/../database/schema.sql'));
    $pdo->exec($schema);
    $pdo->exec((string) file_get_contents(__DIR__ . '/../database/cpanel_seed.sql'));

    if (!mkdir($sessionPath, 0700, true) || !is_writable($sessionPath)) {
        throw new RuntimeException('Could not create isolated CLI session directory.');
    }

    $cliPath = realpath(__DIR__ . '/../cli/daily_attendance.php');
    if ($cliPath === false || !str_starts_with(strtolower($cliPath), strtolower(dirname(__DIR__)))) {
        throw new RuntimeException('Daily attendance CLI path did not resolve inside the repository.');
    }
    $workerEnv = array_merge(getenv(), [
        'LASTO_DB_HOST' => $host,
        'LASTO_DB_NAME' => $database,
        'LASTO_DB_USER' => getenv('LASTO_DB_USER') ?: 'root',
        'LASTO_DB_PASS' => getenv('LASTO_DB_PASS') ?: '',
        'LASTO_VAPID_PUBLIC_KEY' => '',
        'LASTO_VAPID_PRIVATE_KEY' => '',
        'LASTO_MAIL_TRANSPORT' => 'disabled',
    ]);
    $runCli = static function (string $date) use ($cliPath, $sessionPath, $workerEnv): array {
        $pipes = [];
        $command = [PHP_BINARY, '-d', 'session.save_path=' . $sessionPath, $cliPath, '--date', $date];
        $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__), $workerEnv);
        if (!is_resource($process)) throw new RuntimeException('Could not launch daily attendance CLI.');
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]); fclose($pipes[2]);
        return ['code'=>proc_close($process), 'stdout'=>$stdout, 'stderr'=>$stderr, 'command'=>$command];
    };
    $probeCode = 'require ' . var_export(__DIR__ . '/../config/database.php', true) . '; echo db()->query("SELECT DATABASE()")->fetchColumn();';
    $probePipes = [];
    $probe = proc_open([PHP_BINARY, '-r', $probeCode], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $probePipes, dirname(__DIR__), $workerEnv);
    if (!is_resource($probe)) throw new RuntimeException('Could not verify the CLI database environment.');
    fclose($probePipes[0]);
    $probedDatabase = trim((string) stream_get_contents($probePipes[1])); fclose($probePipes[1]);
    $probeError = trim((string) stream_get_contents($probePipes[2])); fclose($probePipes[2]);
    cli_test_assert(proc_close($probe) === 0 && $probeError === '' && $probedDatabase === $database, 'CLI environment resolves to the uniquely named disposable database');
    $assertCliSuccess = static function (array $result, string $label): void {
        cli_test_assert($result['code'] === 0, $label . ' exits 0');
        cli_test_assert(str_contains($result['stdout'], 'Done') && str_contains($result['stdout'], '0 error(s)'), $label . ' reports successful completion');
    };

    $yesterday = (new DateTimeImmutable('yesterday', new DateTimeZone('Asia/Kuala_Lumpur')))->format('Y-m-d');
    $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Kuala_Lumpur')))->format('Y-m-d');
    $twoDaysAgo = (new DateTimeImmutable($yesterday))->modify('-1 day')->format('Y-m-d');
    $yesterdayDow = (int) (new DateTimeImmutable($yesterday))->format('w');
    $weeklyOffDay = ($yesterdayDow + 1) % 7;

    $insertUser = $pdo->prepare('INSERT INTO users (employee_id,username,password_hash,full_name,role,is_active,weekly_off_day,attendance_location_enabled) VALUES (?,?,\'test-hash\',?,\'staff\',1,?,0)');
    $addUser = static function (string $suffix) use ($insertUser, $weeklyOffDay, $pdo): int {
        $insertUser->execute(['CLI_' . $suffix, 'cli_' . strtolower($suffix), 'CLI Synthetic ' . $suffix, $weeklyOffDay]);
        return (int) $pdo->lastInsertId();
    };
    $addAttendance = static function (int $userId, string $date, ?string $clockOut, string $status = 'present') use ($pdo): int {
        $stmt = $pdo->prepare('INSERT INTO attendance (user_id,work_date,clock_in,clock_out,status,version) VALUES (?,?,?, ?,?,1)');
        $stmt->execute([$userId, $date, $date . ' 10:00:00', $clockOut, $status]);
        return (int) $pdo->lastInsertId();
    };
    $addOpenBreak = static function (int $userId, int $attendanceId, string $date) use ($pdo): int {
        $stmt = $pdo->prepare("INSERT INTO break_records (attendance_id,user_id,break_type,sequence_no,start_time,allowed_minutes) VALUES (?,?,'short',1,?,10)");
        $stmt->execute([$attendanceId, $userId, $date . ' 12:00:00']);
        return (int) $pdo->lastInsertId();
    };

    $userA = $addUser('SUCCESS_A');
    $attA = $addAttendance($userA, $yesterday, null);
    $breakA = $addOpenBreak($userA, $attA, $yesterday);
    $userB = $addUser('SUCCESS_B');
    $attB = $addAttendance($userB, $yesterday, null);
    $breakB = $addOpenBreak($userB, $attB, $yesterday);
    $userCurrent = $addUser('CURRENT');
    $attCurrent = $addAttendance($userCurrent, $today, null);
    $breakCurrent = $addOpenBreak($userCurrent, $attCurrent, $today);
    $currentBefore = $pdo->query("SELECT a.status,a.version,a.total_break_minutes,b.start_time,b.end_time,b.duration_minutes,b.is_auto_closed FROM attendance a JOIN break_records b ON b.attendance_id=a.id WHERE a.id={$attCurrent}")->fetch();
    $userGenerated = $addUser('GENERATED'); // no yesterday attendance row: CLI should create one

    $userUnrelated = $addUser('HISTORY');
    $attUnrelated = $addAttendance($userUnrelated, $twoDaysAgo, $twoDaysAgo . ' 18:00:00', 'completed');
    $pdo->prepare("INSERT INTO break_records (attendance_id,user_id,break_type,sequence_no,start_time,end_time,allowed_minutes,duration_minutes,exceeded) VALUES (?,?,'short',1,?, ?,10,10,0)")
        ->execute([$attUnrelated, $userUnrelated, $twoDaysAgo . ' 12:00:00', $twoDaysAgo . ' 12:10:00']);
    $unrelatedBefore = $pdo->query("SELECT a.status,a.version,a.clock_in,a.clock_out,a.total_break_minutes,b.end_time,b.duration_minutes FROM attendance a JOIN break_records b ON b.attendance_id=a.id WHERE a.id={$attUnrelated}")->fetch();

    $notificationCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
    $subscriptionCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn();
    cli_test_assert($notificationCountBefore === 0 && $subscriptionCountBefore === 0, 'notification and push tables are empty before CLI execution');

    $first = $runCli($yesterday);
    cli_test_assert(str_contains($first['stdout'], 'processing ' . $yesterday . ' to ' . $yesterday), 'real CLI received only the selected synthetic date');
    $assertCliSuccess($first, 'first daily CLI run');
    foreach ([[$attA, $breakA], [$attB, $breakB]] as [$attendanceId, $breakId]) {
        $row = $pdo->query("SELECT b.end_time,b.duration_minutes,b.exceeded,b.is_auto_closed,b.closure_reason,a.total_break_minutes,a.work_minutes,a.break_review_status,a.version FROM break_records b JOIN attendance a ON a.id=b.attendance_id WHERE b.id={$breakId}")->fetch();
        cli_test_assert($row['end_time'] === $yesterday . ' 20:00:00' && (int) $row['duration_minutes'] === 480, 'eligible synthetic break closes at policy time with calculated duration');
        cli_test_assert((int) $row['exceeded'] === 1 && (int) $row['is_auto_closed'] === 1 && $row['closure_reason'] === 'midnight_cron', 'eligible break retains overtime and review classification');
        cli_test_assert((int) $row['total_break_minutes'] === 480 && (int) $row['work_minutes'] === 0 && $row['break_review_status'] === 'under_review', 'attendance totals and review state recalculate correctly');
        cli_test_assert((int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id={$attendanceId} AND action='break_auto_closed'")->fetchColumn() === 1, 'eligible repair writes exactly one auto-close audit');
    }
    cli_test_assert((int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE user_id={$userGenerated} AND work_date='{$yesterday}'")->fetchColumn() === 1, 'daily CLI creates the missing synthetic attendance row');
    $currentAfter = $pdo->query("SELECT a.status,a.version,a.total_break_minutes,b.start_time,b.end_time,b.duration_minutes,b.is_auto_closed FROM attendance a JOIN break_records b ON b.attendance_id=a.id WHERE a.id={$attCurrent}")->fetch();
    cli_test_assert($pdo->query("SELECT end_time FROM break_records WHERE id={$breakCurrent}")->fetchColumn() === null && $currentAfter == $currentBefore, 'current-day ineligible attendance and break remain unchanged');
    $unrelatedAfter = $pdo->query("SELECT a.status,a.version,a.clock_in,a.clock_out,a.total_break_minutes,b.end_time,b.duration_minutes FROM attendance a JOIN break_records b ON b.attendance_id=a.id WHERE a.id={$attUnrelated}")->fetch();
    cli_test_assert($unrelatedAfter == $unrelatedBefore, 'unrelated historical attendance and closed break remain unchanged');
    cli_test_assert((int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === $notificationCountBefore, 'CLI creates no in-app notifications');

    $second = $runCli($yesterday);
    $assertCliSuccess($second, 'repeated daily CLI run');
    foreach ([$attA, $attB] as $attendanceId) {
        cli_test_assert((int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id={$attendanceId} AND action='break_auto_closed'")->fetchColumn() === 1, 'repeated CLI run does not duplicate repair audit');
    }

    // Add one failing and one healthy employee, then inject a recalculation error
    // only for the failing employee. The CLI must isolate/count this failure.
    $userFail = $addUser('FAILURE');
    $attFail = $addAttendance($userFail, $yesterday, null);
    $breakFail = $addOpenBreak($userFail, $attFail, $yesterday);
    $userLater = $addUser('LATER_SUCCESS');
    $attLater = $addAttendance($userLater, $yesterday, null);
    $breakLater = $addOpenBreak($userLater, $attLater, $yesterday);
    $pdo->exec("CREATE TRIGGER cli_fail_one_employee BEFORE UPDATE ON attendance FOR EACH ROW
        BEGIN IF OLD.user_id={$userFail} AND NEW.total_break_minutes <> OLD.total_break_minutes THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected CLI repair failure'; END IF; END");
    $failedRun = $runCli($yesterday);
    $pdo->exec('DROP TRIGGER cli_fail_one_employee');
    cli_test_assert($failedRun['code'] === 1, 'CLI returns exit 1 under the existing nonzero-error contract');
    cli_test_assert(str_contains($failedRun['stdout'], '1 error(s)') && str_contains($failedRun['stderr'], '1 per-record maintenance failure'), 'CLI summary counts the isolated repair failure');
    cli_test_assert(str_contains($failedRun['stderr'], 'Past-shift break repair failed for break ' . $breakFail) && str_contains($failedRun['stderr'], 'injected CLI repair failure'), 'error log identifies break and failure without employee PII');
    cli_test_assert(!str_contains($failedRun['stderr'], 'CLI Synthetic') && !str_contains($failedRun['stderr'], 'CLI_FAILURE'), 'CLI diagnostic omits synthetic employee name and identifier');

    $failRow = $pdo->query("SELECT b.end_time,a.total_break_minutes,a.break_review_status,a.version FROM break_records b JOIN attendance a ON a.id=b.attendance_id WHERE b.id={$breakFail}")->fetch();
    cli_test_assert($failRow['end_time'] === null && (int) $failRow['total_break_minutes'] === 0, 'failed candidate retains open break and prior totals');
    cli_test_assert($failRow['break_review_status'] === 'none' && (int) $failRow['version'] === 2, 'failed closure rolls back its version/review updates while prior incomplete status remains');
    cli_test_assert((int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id={$attFail} AND action='break_auto_closed'")->fetchColumn() === 0, 'failed candidate has no partial auto-close audit');
    $laterRow = $pdo->query("SELECT b.end_time,a.total_break_minutes,a.break_review_status FROM break_records b JOIN attendance a ON a.id=b.attendance_id WHERE b.id={$breakLater}")->fetch();
    cli_test_assert($laterRow['end_time'] === $yesterday . ' 20:00:00' && (int) $laterRow['total_break_minutes'] === 480, 'healthy employee is repaired despite another employee failure');
    cli_test_assert((int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id={$attLater} AND action='break_auto_closed'")->fetchColumn() === 1, 'healthy employee repair has one complete audit event');

    $retry = $runCli($yesterday);
    $assertCliSuccess($retry, 'failure retry after removing injected trigger');
    $retryRow = $pdo->query("SELECT b.end_time,a.total_break_minutes FROM break_records b JOIN attendance a ON a.id=b.attendance_id WHERE b.id={$breakFail}")->fetch();
    cli_test_assert($retryRow['end_time'] === $yesterday . ' 20:00:00' && (int) $retryRow['total_break_minutes'] === 480, 'rerun repairs the previously rolled-back candidate');
    cli_test_assert((int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id={$attFail} AND action='break_auto_closed'")->fetchColumn() === 1, 'successful retry writes exactly one required audit');
    cli_test_assert((int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 0, 'notifications remain disabled throughout all CLI runs');

    echo "Daily attendance CLI integration suite completed.\n";
} finally {
    if ($created) {
        TestDatabaseSafety::guardDropDatabase($database);
        $server->exec("DROP DATABASE `{$database}`");
    }
    if (is_dir($sessionPath)) {
        foreach (glob($sessionPath . DIRECTORY_SEPARATOR . '*') ?: [] as $sessionFile) {
            if (is_file($sessionFile)) unlink($sessionFile);
        }
        rmdir($sessionPath);
    }
}
