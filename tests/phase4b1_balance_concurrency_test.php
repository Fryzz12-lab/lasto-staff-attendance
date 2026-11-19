<?php
declare(strict_types=1);

/** Isolated Phase 4B.1 balance locking regression test. */
require_once __DIR__ . '/test_database_safety.php';

function p4b1_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

function p4b1_connect(string $database): PDO
{
    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('LASTO_DB_HOST') ?: '127.0.0.1', getenv('LASTO_DB_PORT') ?: '3306', $database),
        getenv('LASTO_DB_USER') ?: 'root',
        getenv('LASTO_DB_PASS') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
}

function p4b1_worker(string $mode, int $reviewId, string $decision, string $note): void
{
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/attendance_service.php';
    require_once __DIR__ . '/../includes/adjustment_service.php';
    try {
        if ($mode === 'replacement') {
            record_leave_replacement($reviewId, (int) $decision, true, 10, $note);
        } elseif ($mode === 'reverse') {
            reverse_offday_adjustment($reviewId, 10, $note);
        } else {
            decide_offday_adjustment($reviewId, $decision, 10, $note);
        }
        fwrite(STDOUT, "COMMITTED\n");
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDOUT, 'REJECTED:' . $error->getMessage() . "\n");
        exit(2);
    }
}

if (($argv[1] ?? '') === 'worker') {
    p4b1_worker((string) $argv[2], (int) $argv[3], (string) $argv[4], (string) ($argv[5] ?? 'Phase 4B.1 test'));
}

$dbName = 'staff_attendance_disposable_phase4b1_' . bin2hex(random_bytes(5));
$server = p4b1_connect('mysql');
$dbCheck = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
$dbCheck->execute([$dbName]);
p4b1_assert((int) $dbCheck->fetchColumn() === 0, 'generated disposable database name is unused');
TestDatabaseSafety::guardDropDatabase($dbName);
$created = false;

try {
    $server->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $pdo = p4b1_connect($dbName);
    $schema = [
        "CREATE TABLE users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, role VARCHAR(20) NOT NULL, is_active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB",
        "CREATE TABLE attendance (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, clock_in DATETIME NULL, clock_out DATETIME NULL, status VARCHAR(30) NOT NULL DEFAULT 'completed', break_review_status VARCHAR(40) NOT NULL DEFAULT 'none') ENGINE=InnoDB",
        "CREATE TABLE offday_adjustment_reviews (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, employee_id INT UNSIGNED NOT NULL, attendance_id INT UNSIGNED NOT NULL UNIQUE, status VARCHAR(20) NOT NULL DEFAULT 'pending', decision VARCHAR(40) NULL, amount TINYINT NOT NULL DEFAULT 0, admin_id INT NULL, admin_note VARCHAR(500) NULL, decided_at DATETIME NULL, updated_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE staff_balance_ledger (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, employee_id INT UNSIGNED NOT NULL, balance_type VARCHAR(40) NOT NULL, amount SMALLINT NOT NULL, source_type VARCHAR(40) NOT NULL, source_id INT NULL, review_id INT NULL, decision VARCHAR(50) NULL, reversal_of_id INT NULL, admin_id INT NOT NULL, admin_note VARCHAR(500) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_employee_balance (employee_id,balance_type)) ENGINE=InnoDB",
        "CREATE TABLE leave_requests (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE leave_replacement_reviews (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, leave_request_id INT UNSIGNED NOT NULL, employee_id INT UNSIGNED NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'active', requires_replacement TINYINT NOT NULL, amount TINYINT NOT NULL DEFAULT 0, admin_id INT NOT NULL, admin_note VARCHAR(500) NULL, reversal_of_id INT NULL, decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
    ];
    foreach ($schema as $sql) $pdo->exec($sql);
    $pdo->exec("INSERT INTO users (id,role,is_active) VALUES (1,'staff',1),(2,'staff',1),(3,'staff',1),(4,'staff',1),(5,'staff',1),(10,'admin',1)");

    $makeReview = static function (PDO $pdo, int $employeeId): int {
        $stmt = $pdo->prepare("INSERT INTO attendance (user_id,clock_in,clock_out,status) VALUES (?, '2026-01-01 10:00:00','2026-01-01 20:00:00','completed')");
        $stmt->execute([$employeeId]);
        $attendanceId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO offday_adjustment_reviews (employee_id,attendance_id) VALUES (?,?)');
        $stmt->execute([$employeeId, $attendanceId]);
        return (int) $pdo->lastInsertId();
    };

    // Before-fix reproduction: two independent transactions lock separate reviews,
    // both read the same balance, then commit individually valid debits.
    $pdo->exec("INSERT INTO staff_balance_ledger (employee_id,balance_type,amount,source_type,admin_id,admin_note) VALUES (1,'cuti_perlu_ganti',1,'manual',10,'Initial disposable balance')");
    $beforeReviewA = $makeReview($pdo, 1);
    $beforeReviewB = $makeReview($pdo, 1);
    $connA = p4b1_connect($dbName);
    $connB = p4b1_connect($dbName);
    $connA->beginTransaction();
    $connB->beginTransaction();
    foreach ([[$connA, $beforeReviewA], [$connB, $beforeReviewB]] as [$conn, $reviewId]) {
        $stmt = $conn->prepare('SELECT r.id FROM offday_adjustment_reviews r JOIN attendance a ON a.id=r.attendance_id WHERE r.id=? FOR UPDATE');
        $stmt->execute([$reviewId]);
        p4b1_assert((bool) $stmt->fetchColumn(), 'independent connection locked its own pending review');
    }
    $balances = [];
    foreach ([$connA, $connB] as $conn) {
        $balances[] = (int) $conn->query("SELECT COALESCE(SUM(amount),0) FROM staff_balance_ledger WHERE employee_id=1 AND balance_type='cuti_perlu_ganti'")->fetchColumn();
    }
    p4b1_assert($balances === [1, 1], 'before-fix transactions both observed initial balance 1');
    foreach ([[$connA, $beforeReviewA], [$connB, $beforeReviewB]] as [$conn, $reviewId]) {
        $stmt = $conn->prepare("INSERT INTO staff_balance_ledger (employee_id,balance_type,amount,source_type,source_id,review_id,decision,admin_id,admin_note) VALUES (1,'cuti_perlu_ganti',-1,'offday_attendance',?,?,'settle_cuti_ganti',10,'Race reproduction')");
        $stmt->execute([$reviewId, $reviewId]);
        $stmt = $conn->prepare("UPDATE offday_adjustment_reviews SET status='decided',decision='settle_cuti_ganti',amount=1,admin_id=10 WHERE id=?");
        $stmt->execute([$reviewId]);
    }
    $connA->commit();
    $connB->commit();
    $negative = (int) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM staff_balance_ledger WHERE employee_id=1 AND balance_type='cuti_perlu_ganti'")->fetchColumn();
    p4b1_assert($negative === -1, 'before-fix interleaving reproduced both decisions and final balance -1');

    $workerEnv = array_merge(getenv(), ['LASTO_DB_NAME' => $dbName]);
    $runWorkers = static function (array $jobs, string $mode = 'offday') use ($workerEnv): array {
        $processes = [];
        foreach ($jobs as [$reviewId, $decision]) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __FILE__, 'worker', $mode, (string) $reviewId, $decision, 'Concurrent regression test'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, dirname(__DIR__), $workerEnv);
            if (!is_resource($process)) throw new RuntimeException('Unable to launch isolated decision worker.');
            fclose($pipes[0]);
            $processes[] = [$process, $pipes[1], $pipes[2]];
        }
        $results = [];
        foreach ($processes as [$process, $stdout, $stderr]) {
            $out = stream_get_contents($stdout); fclose($stdout);
            $err = stream_get_contents($stderr); fclose($stderr);
            $code = proc_close($process);
            $results[] = ['code' => $code, 'out' => trim((string) $out), 'err' => trim((string) $err)];
        }
        return $results;
    };

    $singleReview = $makeReview($pdo, 1);
    $singleApproval = $runWorkers([[$singleReview, 'replacement_off']])[0];
    p4b1_assert($singleApproval['code'] === 0, 'normal single approval commits');
    $singleLedger = $pdo->prepare('SELECT COUNT(*) FROM staff_balance_ledger WHERE review_id=?'); $singleLedger->execute([$singleReview]);
    p4b1_assert((int) $singleLedger->fetchColumn() === 1, 'normal approval creates exactly one ledger entry');

    // A balance of one with two independent pending debits: only one may commit.
    $pdo->exec("INSERT INTO staff_balance_ledger (employee_id,balance_type,amount,source_type,admin_id,admin_note) VALUES (2,'cuti_perlu_ganti',1,'manual',10,'Initial disposable balance')");
    $reviewA = $makeReview($pdo, 2);
    $reviewB = $makeReview($pdo, 2);
    $concurrent = $runWorkers([[$reviewA, 'settle_cuti_ganti'], [$reviewB, 'settle_cuti_ganti']]);
    $committed = count(array_filter($concurrent, static fn($r) => $r['code'] === 0));
    p4b1_assert($committed === 1, 'two concurrent approvals for different reviews serialize; exactly one commits');
    $state = $pdo->prepare('SELECT status FROM offday_adjustment_reviews WHERE id IN (?,?) ORDER BY id');
    $state->execute([$reviewA, $reviewB]);
    $statuses = array_column($state->fetchAll(), 'status');
    p4b1_assert(count(array_filter($statuses, static fn($s) => $s === 'decided')) === 1 && count(array_filter($statuses, static fn($s) => $s === 'pending')) === 1, 'losing balance decision remains pending');
    $balance = (int) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM staff_balance_ledger WHERE employee_id=2 AND balance_type='cuti_perlu_ganti'")->fetchColumn();
    p4b1_assert($balance === 0, 'final balance is zero after one approved settlement');
    $ledgerCount = (int) $pdo->query("SELECT COUNT(*) FROM staff_balance_ledger WHERE employee_id=2 AND source_type='offday_attendance'")->fetchColumn();
    p4b1_assert($ledgerCount === 1, 'one ledger row was written for the approved decision');
    $repeatResult = $runWorkers([[$reviewA, 'settle_cuti_ganti']])[0];
    p4b1_assert($repeatResult['code'] !== 0, 'repeated decision is rejected without a duplicate ledger entry');

    $reversePipes = [];
    $reverseProcess = proc_open([PHP_BINARY, __FILE__, 'worker', 'reverse', (string) $reviewA, '', 'Reversal regression test'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $reversePipes, dirname(__DIR__), $workerEnv);
    if (!is_resource($reverseProcess)) throw new RuntimeException('Unable to launch reversal worker.');
    fclose($reversePipes[0]);
    $reverseOut = trim((string) stream_get_contents($reversePipes[1])); fclose($reversePipes[1]);
    $reverseErr = trim((string) stream_get_contents($reversePipes[2])); fclose($reversePipes[2]);
    $reverseCode = proc_close($reverseProcess);
    p4b1_assert($reverseCode === 0 && $reverseOut === 'COMMITTED', 'balance reversal commits under the employee lock');
    $reversed = $pdo->prepare('SELECT status FROM offday_adjustment_reviews WHERE id=?'); $reversed->execute([$reviewA]);
    p4b1_assert($reversed->fetchColumn() === 'reversed', 'reversal updates the review state');
    $reversedBalance = (int) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM staff_balance_ledger WHERE employee_id=2 AND balance_type='cuti_perlu_ganti'")->fetchColumn();
    p4b1_assert($reversedBalance === 1, 'reversal ledger entry restores the prior balance');

    // Same review, competing approval and rejection.
    $sameReview = $makeReview($pdo, 3);
    $sameReviewRace = $runWorkers([[$sameReview, 'replacement_off'], [$sameReview, 'no_adjustment']]);
    p4b1_assert(count(array_filter($sameReviewRace, static fn($r) => $r['code'] === 0)) === 1, 'concurrent approval and rejection of one review cannot both commit');
    $sameStatus = $pdo->prepare('SELECT status FROM offday_adjustment_reviews WHERE id=?');
    $sameStatus->execute([$sameReview]);
    p4b1_assert($sameStatus->fetchColumn() === 'decided', 'competing decision leaves one terminal review status');
    $sameLedger = $pdo->prepare('SELECT COUNT(*) FROM staff_balance_ledger WHERE review_id=?'); $sameLedger->execute([$sameReview]);
    p4b1_assert((int) $sameLedger->fetchColumn() <= 1, 'competing decisions create no duplicate ledger rows');

    // Insufficient balance retains the established rejection behavior.
    $insufficientReview = $makeReview($pdo, 3);
    $insufficient = $runWorkers([[$insufficientReview, 'settle_cuti_ganti']])[0];
    p4b1_assert($insufficient['code'] !== 0 && str_contains($insufficient['out'], 'exceeds the current'), 'settlement with insufficient balance is rejected');
    $insufficientStatus = $pdo->prepare('SELECT status FROM offday_adjustment_reviews WHERE id=?'); $insufficientStatus->execute([$insufficientReview]);
    p4b1_assert($insufficientStatus->fetchColumn() === 'pending', 'insufficient-balance decision leaves review pending');
    $insufficientLedger = $pdo->prepare('SELECT COUNT(*) FROM staff_balance_ledger WHERE review_id=?'); $insufficientLedger->execute([$insufficientReview]);
    p4b1_assert((int) $insufficientLedger->fetchColumn() === 0, 'insufficient-balance decision writes no ledger row');

    // A normal rejection records the review without changing the balance.
    $rejectionReview = $makeReview($pdo, 4);
    $rejection = $runWorkers([[$rejectionReview, 'no_adjustment']])[0];
    p4b1_assert($rejection['code'] === 0, 'normal no-adjustment rejection is recorded');
    $rejectionLedger = $pdo->prepare('SELECT COUNT(*) FROM staff_balance_ledger WHERE review_id=?'); $rejectionLedger->execute([$rejectionReview]);
    p4b1_assert((int) $rejectionLedger->fetchColumn() === 0, 'normal no-adjustment decision does not change balance');

    // Different employee row locks do not serialize each other.
    $lockA = p4b1_connect($dbName); $lockB = p4b1_connect($dbName);
    $lockA->beginTransaction(); $lockB->beginTransaction();
    $lockA->query('SELECT id FROM users WHERE id=4 FOR UPDATE')->fetchColumn();
    $lockB->query('SELECT id FROM users WHERE id=5 FOR UPDATE')->fetchColumn();
    $lockB->commit(); $lockA->rollBack();
    p4b1_assert(true, 'different employees can acquire independent locks');

    // Leave replacement goes through the same employee-level serialization.
    $pdo->exec("INSERT INTO leave_requests (id,user_id) VALUES (1,4)");
    $replacementRun = $runWorkers([[1, '4']], 'replacement')[0];
    p4b1_assert($replacementRun['code'] === 0, 'leave replacement balance workflow commits through employee lock');
    p4b1_assert((int) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM staff_balance_ledger WHERE employee_id=4 AND balance_type='cuti_perlu_ganti'")->fetchColumn() === 1, 'leave replacement ledger balance remains correct');
    $pdo->exec('INSERT INTO leave_requests (id,user_id) VALUES (2,4)');
    $replacementRace = $runWorkers([[2, '4'], [2, '4']], 'replacement');
    p4b1_assert(count(array_filter($replacementRace, static fn($r) => $r['code'] === 0)) === 1, 'concurrent duplicate leave replacement decisions commit once');
    p4b1_assert((int) $pdo->query('SELECT COUNT(*) FROM leave_replacement_reviews WHERE leave_request_id=2 AND status=\'active\'')->fetchColumn() === 1, 'one active replacement review exists after duplicate race');

    // Ledger insertion failure must roll back the earlier review update.
    $rollbackReview = $makeReview($pdo, 5);
    $pdo->exec("CREATE TRIGGER phase4b1_fail_ledger BEFORE INSERT ON staff_balance_ledger FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected ledger failure'");
    $failed = $runWorkers([[$rollbackReview, 'replacement_off']])[0];
    $pdo->exec('DROP TRIGGER phase4b1_fail_ledger');
    p4b1_assert($failed['code'] !== 0, 'injected ledger insertion failure is surfaced');
    $check = $pdo->prepare('SELECT status FROM offday_adjustment_reviews WHERE id=?'); $check->execute([$rollbackReview]);
    p4b1_assert($check->fetchColumn() === 'pending', 'ledger failure rolls back review update');
    p4b1_assert((int) $pdo->query("SELECT COUNT(*) FROM staff_balance_ledger WHERE employee_id=5")->fetchColumn() === 0, 'ledger failure leaves no partial ledger row');

    // A review update failure occurs before ledger insertion and leaves no effects.
    $updateFailureReview = $makeReview($pdo, 5);
    $pdo->exec("CREATE TRIGGER phase4b1_fail_review BEFORE UPDATE ON offday_adjustment_reviews FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected review update failure'");
    $updateFailed = $runWorkers([[$updateFailureReview, 'replacement_off']])[0];
    $pdo->exec('DROP TRIGGER phase4b1_fail_review');
    p4b1_assert($updateFailed['code'] !== 0, 'injected review update failure is surfaced');
    $check->execute([$updateFailureReview]);
    p4b1_assert($check->fetchColumn() === 'pending', 'review update failure leaves review pending');
    $partialLedger = $pdo->prepare('SELECT COUNT(*) FROM staff_balance_ledger WHERE review_id=?'); $partialLedger->execute([$updateFailureReview]);
    p4b1_assert((int) $partialLedger->fetchColumn() === 0, 'review update failure inserts no ledger entry');

    $initial = (int) $pdo->query("SELECT COUNT(*) FROM staff_balance_ledger WHERE employee_id=1 AND source_type='manual'")->fetchColumn();
    p4b1_assert($initial === 1, 'pre-existing disposable ledger entry is preserved');
    echo "Phase 4B.1 isolated concurrency suite completed.\n";
} finally {
    if ($created) {
        TestDatabaseSafety::guardDropDatabase($dbName);
        $server->exec("DROP DATABASE `{$dbName}`");
    }
}
