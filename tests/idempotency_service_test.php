<?php
/** Run: C:\xampp\php\php.exe tests/idempotency_service_test.php */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/idempotency_service.php';

function assert_idem(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function expect_idem_exception(callable $action, string $message, string $class = InvalidArgumentException::class): void
{
    try {
        $action();
    } catch (Throwable $error) {
        assert_idem($error instanceof $class, $message . ' (wrong exception type)');
        return;
    }
    throw new RuntimeException($message . ' (no exception)');
}

function idem_test_user(PDO $pdo, string $suffix): int
{
    $name = 'idem_' . $suffix . '_' . bin2hex(random_bytes(3));
    $pdo->prepare('INSERT INTO users (employee_id,username,password_hash,full_name) VALUES (?,?,?,?)')
        ->execute([$name, $name, 'unused', $name]);
    return (int) $pdo->lastInsertId();
}

function idem_test_attendance(PDO $pdo, int $userId, string $date): int
{
    $pdo->prepare("INSERT INTO attendance (user_id,work_date,status) VALUES (?,?,'not_clocked_in')")
        ->execute([$userId, $date]);
    return (int) $pdo->lastInsertId();
}

function worker_result(string $database, int $userId, string $mode, string $requestKey, array $payload, int $delayMs = 0): array
{
    $args = [PHP_BINARY, __FILE__, 'worker', $database, $mode, (string) $userId, $requestKey, json_encode($payload, JSON_THROW_ON_ERROR), (string) $delayMs];
    $pipes = [];
    $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start idempotency worker.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Idempotency worker failed: ' . $errors . $output);
    }
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

function concurrent_worker_pair(string $database, int $userId, string $requestKey, array $firstPayload, array $secondPayload): array
{
    $workers = [];
    foreach ([['same', $firstPayload, 500], ['same', $secondPayload, 0]] as [$mode, $payload, $delay]) {
        $args = [PHP_BINARY, __FILE__, 'worker', $database, $mode, (string) $userId, $requestKey, json_encode($payload, JSON_THROW_ON_ERROR), (string) $delay];
        $pipes = [];
        $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start concurrent idempotency worker.');
        }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Concurrent worker failed: ' . $errors . $output);
        }
        $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
    return $results;
}

if (($argv[1] ?? '') === 'worker') {
    $database = $argv[2] ?? '';
    if (!preg_match('/^staff_idem_test_[a-f0-9]{12}$/D', $database)) {
        throw new RuntimeException('Invalid disposable idempotency database.');
    }
    $mode = $argv[3] ?? '';
    $userId = (int) ($argv[4] ?? 0);
    $requestKey = (string) ($argv[5] ?? '');
    $payload = json_decode((string) ($argv[6] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
    $delayMs = (int) ($argv[7] ?? 0);
    $pdo = db();
    $pdo->exec('USE `' . $database . '`');
    if ($mode === 'timeout') {
        $pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
    }
    try {
        $pdo->beginTransaction();
        if ($mode !== 'hold' && $mode !== 'timeout') {
            $lock = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
            $lock->execute([$userId]);
        }
        $reserved = idempotency_reserve($pdo, $userId, $requestKey, 'clock_in', $payload);
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
        if ($mode === 'hold') {
            usleep(3000000);
            $pdo->rollBack();
        } elseif ($reserved['outcome'] === 'reserved') {
            $terminal = idempotency_persist_success($pdo, $userId, (int) $reserved['operation_id'], 200, ['success' => true, 'message' => 'worker complete']);
            $pdo->commit();
            $reserved['terminal'] = $terminal;
        } else {
            $pdo->commit();
        }
        echo json_encode($reserved, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode([
            'error' => get_class($error),
            'retryable_lock' => idempotency_is_retryable_lock_exception($error),
            'message' => $error->getMessage(),
        ], JSON_THROW_ON_ERROR);
    }
    exit;
}

$checks = 0;
$database = 'staff_idem_test_' . bin2hex(random_bytes(6));
$pdo = db();
$pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4');
try {
    $schema = str_replace('`staff_attendance`', '`' . $database . '`', file_get_contents(__DIR__ . '/../database/schema.sql'));
    $pdo->exec($schema);
    $pdo->exec('USE `' . $database . '`');
    $migration = file_get_contents(__DIR__ . '/../database/migration_phase3b_idempotency.sql');
    $pdo->exec($migration);
    $pdo->exec($migration);

    $table = $pdo->query('SHOW TABLES LIKE \'attendance_idempotency_operations\'')->fetchColumn();
    assert_idem($table === 'attendance_idempotency_operations', 'Migration creates the idempotency table');
    $unique = $pdo->query("SHOW INDEX FROM attendance_idempotency_operations WHERE Key_name='uk_idem_user_request'")->fetchAll();
    assert_idem(count($unique) >= 2, 'Migration creates the user-scoped unique request key');
    $foreignKeys = (int) $pdo->query("SELECT COUNT(DISTINCT CONSTRAINT_NAME) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendance_idempotency_operations' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchColumn();
    assert_idem($foreignKeys === 3, 'Migration creates user, attendance, and break ownership foreign keys');
    $idType = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendance_idempotency_operations' AND COLUMN_NAME='id'")->fetchColumn();
    assert_idem($idType === 'bigint(20) unsigned', 'Internal operation ID uses BIGINT UNSIGNED');
    assert_idem($pdo->query('SELECT COUNT(*) FROM attendance_idempotency_operations')->fetchColumn() === 0, 'Migration rerun preserves an empty table');

    $userA = idem_test_user($pdo, 'a');
    $userB = idem_test_user($pdo, 'b');
    $attendanceA = idem_test_attendance($pdo, $userA, '2026-01-02');
    $fixedNow = new DateTimeImmutable('2026-01-02 10:00:00', new DateTimeZone('Asia/Kuala_Lumpur'));
    $key = 'req_' . bin2hex(random_bytes(12));

    expect_idem_exception(fn() => idempotency_validate_request_key('short'), 'Short request key rejected');
    expect_idem_exception(fn() => idempotency_validate_request_key('bad key with spaces'), 'Request key characters rejected');
    expect_idem_exception(fn() => idempotency_validate_operation('Clock In'), 'Operation name rejected');
    assert_idem(idempotency_validate_request_key($key) === $key, 'Valid request key accepted');
    assert_idem(idempotency_payload_fingerprint('clock_in', ['csrf_token' => 'one', 'b' => 2, 'a' => 1]) === idempotency_payload_fingerprint('clock_in', ['a' => 1, 'b' => 2, 'csrf_token' => 'two']), 'Semantic payload fingerprint is deterministic');
    assert_idem(idempotency_payload_fingerprint('clock_in', ['a' => 1]) !== idempotency_payload_fingerprint('clock_in', ['a' => 2]), 'Different business payloads fingerprint differently');
    expect_idem_exception(fn() => idempotency_reserve($pdo, $userA, $key, 'clock_in', ['a' => 1]), 'Reservation requires a transaction', LogicException::class);

    $pdo->beginTransaction();
    $reserved = idempotency_reserve($pdo, $userA, $key, 'clock_in', ['a' => 1], $fixedNow);
    assert_idem($reserved['outcome'] === 'reserved' && $reserved['replayed'] === false, 'Valid request is reserved');
    $pdo->prepare('UPDATE attendance SET clock_in=?, status=? WHERE id=?')->execute(['2026-01-02 10:00:00', 'present', $attendanceA]);
    $pdo->prepare("INSERT INTO attendance_audit_logs (attendance_id,user_id,changed_by,action,field_name,old_value,new_value) VALUES (?,?,?,'clock_in','clock_in',NULL,?)")
        ->execute([$attendanceA, $userA, $userA, '2026-01-02 10:00:00']);
    $stored = idempotency_persist_success($pdo, $userA, (int) $reserved['operation_id'], 200, ['success' => true, 'attendance_id' => $attendanceA], $attendanceA, null, $fixedNow);
    assert_idem($stored['status'] === 'succeeded' && $stored['response']['attendance_id'] === $attendanceA, 'Successful result is persisted');
    $pdo->commit();
    assert_idem((int) $pdo->query('SELECT COUNT(*) FROM attendance_idempotency_operations')->fetchColumn() === 1, 'Operation row committed with the mutation');
    assert_idem($pdo->query('SELECT clock_in FROM attendance WHERE id=' . $attendanceA)->fetchColumn() === '2026-01-02 10:00:00', 'Mutation committed atomically with operation');
    assert_idem((int) $pdo->query('SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id=' . $attendanceA)->fetchColumn() === 1, 'Audit entry committed with the mutation and operation');
    $pdo->exec($migration);
    assert_idem((int) $pdo->query('SELECT COUNT(*) FROM attendance_idempotency_operations WHERE request_id=' . $pdo->quote($key))->fetchColumn() === 1, 'Migration rerun preserves existing operation records');

    $pdo->beginTransaction();
    $replay = idempotency_reserve($pdo, $userA, $key, 'clock_in', ['a' => 1], $fixedNow->modify('+1 hour'));
    assert_idem($replay['outcome'] === 'replay' && $replay['response']['attendance_id'] === $attendanceA, 'Identical request replays the original result');
    $pdo->rollBack();

    $pdo->beginTransaction();
    $conflictPayload = idempotency_reserve($pdo, $userA, $key, 'clock_in', ['a' => 2], $fixedNow);
    $conflictOperation = idempotency_reserve($pdo, $userA, $key, 'clock_out', ['a' => 1], $fixedNow);
    assert_idem($conflictPayload['outcome'] === 'conflict', 'Same key with different payload conflicts');
    assert_idem($conflictOperation['outcome'] === 'conflict', 'Same key with different operation conflicts');
    $pdo->rollBack();

    $rejectKey = 'reject_' . bin2hex(random_bytes(10));
    $pdo->beginTransaction();
    $rejectReservation = idempotency_reserve($pdo, $userA, $rejectKey, 'clock_out', [], $fixedNow);
    $rejectStored = idempotency_persist_rejection($pdo, $userA, (int) $rejectReservation['operation_id'], 409, ['success' => false, 'code' => 'already_clocked_out'], $attendanceA, null, $fixedNow);
    $pdo->commit();
    assert_idem($rejectStored['status'] === 'rejected', 'Confirmed business rejection is persisted');
    $pdo->beginTransaction();
    assert_idem(idempotency_reserve($pdo, $userA, $rejectKey, 'clock_out', [], $fixedNow)['outcome'] === 'replay', 'Rejected result is replayable');
    $pdo->rollBack();

    $sameKeyOtherUser = 'shared_' . bin2hex(random_bytes(10));
    $pdo->beginTransaction();
    assert_idem(idempotency_reserve($pdo, $userA, $sameKeyOtherUser, 'clock_in', [], $fixedNow)['outcome'] === 'reserved', 'User A can reserve a request key');
    $pdo->rollBack();
    $pdo->beginTransaction();
    $otherUserReservation = idempotency_reserve($pdo, $userB, $sameKeyOtherUser, 'clock_in', [], $fixedNow);
    assert_idem($otherUserReservation['outcome'] === 'reserved', 'Different users may use the same request identifier');
    $pdo->rollBack();
    assert_idem(idempotency_get_operation($pdo, $userB, $key) === null, 'Cross-user operation lookup returns not-found');

    $rollbackKey = 'rollback_' . bin2hex(random_bytes(10));
    $pdo->beginTransaction();
    $rollbackReservation = idempotency_reserve($pdo, $userA, $rollbackKey, 'clock_in', [], $fixedNow);
    $pdo->prepare('UPDATE attendance SET status=? WHERE id=?')->execute(['present', $attendanceA]);
    $pdo->prepare("INSERT INTO attendance_audit_logs (attendance_id,user_id,changed_by,action,field_name,new_value) VALUES (?,?,?,'clock_in','clock_in','rollback')")
        ->execute([$attendanceA, $userA, $userA]);
    $pdo->rollBack();
    assert_idem(idempotency_get_operation($pdo, $userA, $rollbackKey) === null, 'Rollback removes an uncommitted reservation');
    assert_idem($pdo->query('SELECT status FROM attendance WHERE id=' . $attendanceA)->fetchColumn() === 'present', 'Rollback preserves the pre-existing attendance state');
    assert_idem((int) $pdo->query('SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id=' . $attendanceA)->fetchColumn() === 1, 'Rollback removes the related audit entry');
    $pdo->beginTransaction();
    assert_idem(idempotency_reserve($pdo, $userA, $rollbackKey, 'clock_in', [], $fixedNow)['outcome'] === 'reserved', 'Rolled-back key can be retried safely');
    $pdo->rollBack();

    $expiredKey = 'expired_' . bin2hex(random_bytes(10));
    $expiredNow = new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('Asia/Kuala_Lumpur'));
    $pdo->beginTransaction();
    $expiredReservation = idempotency_reserve($pdo, $userA, $expiredKey, 'clock_in', [], $expiredNow, 1);
    idempotency_persist_success($pdo, $userA, (int) $expiredReservation['operation_id'], 200, ['success' => true], null, null, $expiredNow);
    $pdo->commit();
    assert_idem(idempotency_get_operation($pdo, $userA, $expiredKey, $expiredNow->modify('+2 seconds'))['outcome'] === 'expired', 'Expired terminal operation is not replayed');
    $pdo->beginTransaction();
    assert_idem(idempotency_reserve($pdo, $userA, $expiredKey, 'clock_in', [], $expiredNow->modify('+2 seconds'))['outcome'] === 'expired', 'Expired request key cannot be silently reused');
    $pdo->rollBack();

    $processingKey = 'processing_' . bin2hex(random_bytes(10));
    $pdo->beginTransaction();
    idempotency_reserve($pdo, $userA, $processingKey, 'clock_in', [], $expiredNow, 1);
    $pdo->commit();
    $pdo->beginTransaction();
    assert_idem(idempotency_cleanup_expired($pdo, $expiredNow->modify('+2 seconds')) === 1, 'Cleanup expires terminal records');
    assert_idem(idempotency_get_operation($pdo, $userA, $processingKey, $expiredNow->modify('+2 seconds'))['status'] === 'processing', 'Cleanup does not destroy processing operations');
    $pdo->rollBack();

    $pdo->beginTransaction();
    expect_idem_exception(fn() => idempotency_persist_success($pdo, $userB, (int) $reserved['operation_id'], 200, ['success' => true]), 'Cross-user terminal persistence rejected');
    expect_idem_exception(fn() => idempotency_persist_success($pdo, $userA, (int) $reserved['operation_id'], 200, ['csrf_token' => 'do not store']), 'Sensitive response values rejected');
    $pdo->rollBack();
    $lockError = new PDOException('deadlock', 1213);
    $lockError->errorInfo = ['40001', 1213, 'deadlock'];
    assert_idem(idempotency_is_retryable_lock_exception($lockError), 'Deadlock is classified as retryable');

    $concurrentKey = 'concurrent_' . bin2hex(random_bytes(10));
    $concurrentResults = concurrent_worker_pair($database, $userA, $concurrentKey, ['value' => 1], ['value' => 1]);
    $outcomes = array_column($concurrentResults, 'outcome');
    sort($outcomes);
    assert_idem($outcomes === ['replay', 'reserved'], 'Concurrent identical requests create one reservation and one replay');
    $pdo->beginTransaction();
    assert_idem((int) $pdo->query("SELECT COUNT(*) FROM attendance_idempotency_operations WHERE user_id={$userA} AND request_id='{$concurrentKey}'")->fetchColumn() === 1, 'Concurrent identical requests create one operation row');
    $pdo->rollBack();

    $conflictKey = 'concurrent_conflict_' . bin2hex(random_bytes(8));
    $conflictResults = concurrent_worker_pair($database, $userA, $conflictKey, ['value' => 1], ['value' => 2]);
    $conflictOutcomes = array_column($conflictResults, 'outcome');
    sort($conflictOutcomes);
    assert_idem($conflictOutcomes === ['conflict', 'reserved'], 'Concurrent conflicting requests produce one conflict');

    $holdKey = 'lock_timeout_' . bin2hex(random_bytes(8));
    $holdArgs = [PHP_BINARY, __FILE__, 'worker', $database, 'hold', (string) $userA, $holdKey, json_encode(['value' => 1], JSON_THROW_ON_ERROR), '0'];
    $pipes = [];
    $holder = proc_open($holdArgs, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($holder)) throw new RuntimeException('Cannot start lock holder.');
    fclose($pipes[0]);
    usleep(300000);
    $timeout = worker_result($database, $userA, 'timeout', $holdKey, ['value' => 1]);
    $holderOutput = stream_get_contents($pipes[1]);
    $holderErrors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($holder) !== 0) throw new RuntimeException('Lock holder failed: ' . $holderErrors . $holderOutput);
    assert_idem(($timeout['error'] ?? '') === 'PDOException' && $timeout['retryable_lock'] === true, 'Lock timeout is surfaced as retryable');

    echo "PASS: {$checks} durable idempotency foundation checks.\n";
} finally {
    $pdo->exec('USE `' . DB_NAME . '`');
    $pdo->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
