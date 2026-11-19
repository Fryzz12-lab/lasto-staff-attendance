<?php
/**
 * Durable request idempotency primitives.
 *
 * This service is deliberately independent of HTTP and attendance mutation
 * code. Callers must acquire their normal employee lock first, start the
 * transaction, use these functions, and own the final commit or rollback.
 */

declare(strict_types=1);

const IDEMPOTENCY_CONTRACT_VERSION = 1;
const IDEMPOTENCY_DEFAULT_RETENTION_SECONDS = 604800; // Seven days.
const IDEMPOTENCY_MIN_REQUEST_KEY_LENGTH = 16;
const IDEMPOTENCY_MAX_REQUEST_KEY_LENGTH = 128;

/** Validate and return a canonical client request identifier. */
function idempotency_validate_request_key(string $requestKey): string
{
    $requestKey = trim($requestKey);
    $length = strlen($requestKey);
    if ($length < IDEMPOTENCY_MIN_REQUEST_KEY_LENGTH || $length > IDEMPOTENCY_MAX_REQUEST_KEY_LENGTH
        || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._~-]*\z/D', $requestKey) !== 1) {
        throw new InvalidArgumentException('Invalid idempotency request key.');
    }
    return $requestKey;
}

/** Validate an operation name without coupling the service to API routes. */
function idempotency_validate_operation(string $operation): string
{
    $operation = trim($operation);
    if ($operation === '' || strlen($operation) > 64 || preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $operation) !== 1) {
        throw new InvalidArgumentException('Invalid idempotency operation.');
    }
    return $operation;
}

/**
 * Normalize semantic payload data. Transport credentials and request IDs are
 * intentionally excluded so retries hash the same business action.
 */
function idempotency_normalize_payload(string $operation, array $payload): array
{
    $operation = idempotency_validate_operation($operation);
    $semantic = [];
    foreach ($payload as $key => $value) {
        if (is_string($key) && in_array(strtolower($key), ['csrf_token', 'idempotency_key', 'request_id'], true)) {
            continue;
        }
        $semantic[(string) $key] = idempotency_normalize_value($value);
    }
    ksort($semantic, SORT_STRING);
    return ['operation' => $operation, 'payload' => $semantic];
}

/** @return mixed */
function idempotency_normalize_value(mixed $value): mixed
{
    if (is_array($value)) {
        $keys = array_keys($value);
        $isList = $keys === [] || $keys === range(0, count($keys) - 1);
        if ($isList) {
            return array_map('idempotency_normalize_value', $value);
        }
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[(string) $key] = idempotency_normalize_value($item);
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }
    if (is_float($value) && !is_finite($value)) {
        throw new InvalidArgumentException('Idempotency payload contains an invalid number.');
    }
    if (is_scalar($value) || $value === null) {
        return $value;
    }
    throw new InvalidArgumentException('Idempotency payload contains an unsupported value.');
}

/** Return a deterministic SHA-256 fingerprint as lowercase hexadecimal. */
function idempotency_payload_fingerprint(string $operation, array $payload): string
{
    $canonical = json_encode(
        idempotency_normalize_payload($operation, $payload),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
    );
    return hash('sha256', $canonical);
}

/** Idempotency is only safe when the caller owns the surrounding transaction. */
function idempotency_require_transaction(PDO $pdo): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Idempotency operations require an active PDO transaction.');
    }
}

function idempotency_now(?DateTimeImmutable $now = null): DateTimeImmutable
{
    return $now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur'));
}

function idempotency_expiry(DateTimeImmutable $now, int $retentionSeconds): string
{
    if ($retentionSeconds < 1 || $retentionSeconds > 31536000) {
        throw new InvalidArgumentException('Idempotency retention must be between one second and one year.');
    }
    return $now->modify('+' . $retentionSeconds . ' seconds')->format('Y-m-d H:i:s');
}

/**
 * Reserve a request or return its replay/conflict state.
 *
 * The caller must lock the employee row before calling this function. This
 * function never commits, so a business mutation and its terminal result can
 * be committed atomically by the caller.
 */
function idempotency_reserve(
    PDO $pdo,
    int $userId,
    string $requestKey,
    string $operation,
    array $payload,
    ?DateTimeImmutable $now = null,
    int $retentionSeconds = IDEMPOTENCY_DEFAULT_RETENTION_SECONDS
): array {
    idempotency_require_transaction($pdo);
    if ($userId < 1) {
        throw new InvalidArgumentException('A valid authenticated user is required.');
    }
    $requestKey = idempotency_validate_request_key($requestKey);
    $operation = idempotency_validate_operation($operation);
    $fingerprint = idempotency_payload_fingerprint($operation, $payload);
    $now = idempotency_now($now);
    $expiresAt = idempotency_expiry($now, $retentionSeconds);

    $select = $pdo->prepare(
        'SELECT id, user_id, request_id, operation, payload_hash, status,
                response_http_status, response_body, attendance_id, break_id,
                created_at, completed_at, expires_at
         FROM attendance_idempotency_operations
         WHERE user_id = ? AND request_id = ?
         FOR UPDATE'
    );
    $select->execute([$userId, $requestKey]);
    $existing = $select->fetch();

    if (!$existing) {
        try {
            $insert = $pdo->prepare(
                'INSERT INTO attendance_idempotency_operations
                    (request_id, user_id, operation, payload_hash, status, expires_at)
                 VALUES (?, ?, ?, UNHEX(?), \'processing\', ?)'
            );
            $insert->execute([$requestKey, $userId, $operation, $fingerprint, $expiresAt]);
            return [
                'contract_version' => IDEMPOTENCY_CONTRACT_VERSION,
                'outcome' => 'reserved',
                'operation_id' => (int) $pdo->lastInsertId(),
                'request_id' => $requestKey,
                'replayed' => false,
                'response' => null,
            ];
        } catch (PDOException $error) {
            if (!idempotency_is_duplicate_key_exception($error)) {
                throw $error;
            }
            // A concurrent caller may have inserted the unique request key.
            $select->execute([$userId, $requestKey]);
            $existing = $select->fetch();
            if (!$existing) {
                throw $error;
            }
        }
    }

    return idempotency_interpret_existing($existing, $operation, $fingerprint, $now);
}

/** Persist a successful or confirmed business-rejection response. */
function idempotency_persist_terminal(
    PDO $pdo,
    int $userId,
    int $operationId,
    string $status,
    int $httpStatus,
    array $response,
    ?int $attendanceId = null,
    ?int $breakId = null,
    ?DateTimeImmutable $completedAt = null
): array {
    idempotency_require_transaction($pdo);
    if (!in_array($status, ['succeeded', 'rejected'], true)) {
        throw new InvalidArgumentException('Invalid terminal idempotency status.');
    }
    if ($httpStatus < 100 || $httpStatus > 599) {
        throw new InvalidArgumentException('Invalid original HTTP status.');
    }
    if ($userId < 1 || $operationId < 1) {
        throw new InvalidArgumentException('Invalid idempotency ownership.');
    }
    idempotency_assert_owned_reference($pdo, 'attendance', $attendanceId, $userId);
    idempotency_assert_owned_reference($pdo, 'break_records', $breakId, $userId);
    $responseJson = idempotency_encode_response($response);
    $completedAt = idempotency_now($completedAt);

    $lock = $pdo->prepare(
        'SELECT id, user_id, status FROM attendance_idempotency_operations
         WHERE id = ? AND user_id = ? FOR UPDATE'
    );
    $lock->execute([$operationId, $userId]);
    $row = $lock->fetch();
    if (!$row) {
        throw new InvalidArgumentException('Idempotency operation was not found.');
    }
    if ($row['status'] !== 'processing') {
        throw new LogicException('Idempotency operation is already terminal.');
    }

    $update = $pdo->prepare(
        'UPDATE attendance_idempotency_operations
         SET status = ?, response_http_status = ?, response_body = ?,
             attendance_id = ?, break_id = ?, completed_at = ?
         WHERE id = ? AND user_id = ? AND status = \'processing\''
    );
    $update->execute([
        $status, $httpStatus, $responseJson, $attendanceId, $breakId,
        $completedAt->format('Y-m-d H:i:s'), $operationId, $userId,
    ]);
    if ($update->rowCount() !== 1) {
        throw new LogicException('Idempotency operation could not be completed.');
    }

    $select = $pdo->prepare('SELECT * FROM attendance_idempotency_operations WHERE id = ? AND user_id = ?');
    $select->execute([$operationId, $userId]);
    return idempotency_format_row($select->fetch(), $completedAt);
}

function idempotency_persist_success(
    PDO $pdo,
    int $userId,
    int $operationId,
    int $httpStatus,
    array $response,
    ?int $attendanceId = null,
    ?int $breakId = null,
    ?DateTimeImmutable $completedAt = null
): array {
    return idempotency_persist_terminal($pdo, $userId, $operationId, 'succeeded', $httpStatus, $response, $attendanceId, $breakId, $completedAt);
}

function idempotency_persist_rejection(
    PDO $pdo,
    int $userId,
    int $operationId,
    int $httpStatus,
    array $response,
    ?int $attendanceId = null,
    ?int $breakId = null,
    ?DateTimeImmutable $completedAt = null
): array {
    return idempotency_persist_terminal($pdo, $userId, $operationId, 'rejected', $httpStatus, $response, $attendanceId, $breakId, $completedAt);
}

/** Read one operation for its owner; another user receives not-found. */
function idempotency_get_operation(PDO $pdo, int $userId, string $requestKey, ?DateTimeImmutable $now = null): ?array
{
    if ($userId < 1) {
        throw new InvalidArgumentException('A valid authenticated user is required.');
    }
    $requestKey = idempotency_validate_request_key($requestKey);
    $stmt = $pdo->prepare('SELECT * FROM attendance_idempotency_operations WHERE user_id = ? AND request_id = ? LIMIT 1');
    $stmt->execute([$userId, $requestKey]);
    $row = $stmt->fetch();
    return $row ? idempotency_format_row($row, idempotency_now($now)) : null;
}

/**
 * Convert terminal rows past retention into non-replayable tombstones. The
 * request key remains reserved, so cleanup cannot silently permit reuse.
 */
function idempotency_cleanup_expired(PDO $pdo, ?DateTimeImmutable $now = null, int $limit = 500): int
{
    idempotency_require_transaction($pdo);
    if ($limit < 1 || $limit > 10000) {
        throw new InvalidArgumentException('Invalid idempotency cleanup limit.');
    }
    $now = idempotency_now($now)->format('Y-m-d H:i:s');
    $limit = (int) $limit;
    $stmt = $pdo->prepare(
        "UPDATE attendance_idempotency_operations
         SET status = 'expired', response_http_status = NULL, response_body = NULL,
             attendance_id = NULL, break_id = NULL
         WHERE status IN ('succeeded', 'rejected') AND expires_at <= ?
         ORDER BY id LIMIT {$limit}"
    );
    $stmt->execute([$now]);
    return $stmt->rowCount();
}

/** Classify MariaDB deadlock and lock-timeout errors for future API callers. */
function idempotency_is_retryable_lock_exception(Throwable $error): bool
{
    if (!$error instanceof PDOException) {
        return false;
    }
    $sqlState = (string) ($error->errorInfo[0] ?? $error->getCode());
    $driverCode = (int) ($error->errorInfo[1] ?? 0);
    return in_array($sqlState, ['40001', '41000'], true) || in_array($driverCode, [1205, 1213], true);
}

function idempotency_is_duplicate_key_exception(PDOException $error): bool
{
    return (string) ($error->errorInfo[0] ?? $error->getCode()) === '23000'
        && (int) ($error->errorInfo[1] ?? 0) === 1062;
}

function idempotency_interpret_existing(array $row, string $operation, string $fingerprint, DateTimeImmutable $now): array
{
    if ((string) $row['operation'] !== $operation || !hash_equals((string) $row['payload_hash'], hex2bin($fingerprint))) {
        return [
            'contract_version' => IDEMPOTENCY_CONTRACT_VERSION,
            'outcome' => 'conflict',
            'operation_id' => (int) $row['id'],
            'request_id' => (string) $row['request_id'],
            'replayed' => false,
            'response' => null,
        ];
    }

    if ((string) $row['status'] === 'expired'
        || (in_array((string) $row['status'], ['succeeded', 'rejected'], true)
            && new DateTimeImmutable((string) $row['expires_at']) <= $now)) {
        return [
            'contract_version' => IDEMPOTENCY_CONTRACT_VERSION,
            'outcome' => 'expired',
            'operation_id' => (int) $row['id'],
            'request_id' => (string) $row['request_id'],
            'replayed' => false,
            'response' => null,
        ];
    }

    if ((string) $row['status'] === 'processing') {
        return [
            'contract_version' => IDEMPOTENCY_CONTRACT_VERSION,
            'outcome' => 'processing',
            'operation_id' => (int) $row['id'],
            'request_id' => (string) $row['request_id'],
            'replayed' => false,
            'response' => null,
        ];
    }

    if (!in_array((string) $row['status'], ['succeeded', 'rejected'], true)) {
        throw new RuntimeException('Unknown idempotency operation status.');
    }
    $response = json_decode((string) $row['response_body'], true);
    if (!is_array($response)) {
        throw new RuntimeException('Stored idempotency response is invalid.');
    }
    return [
        'contract_version' => IDEMPOTENCY_CONTRACT_VERSION,
        'outcome' => 'replay',
        'operation_id' => (int) $row['id'],
        'request_id' => (string) $row['request_id'],
        'replayed' => true,
        'response_http_status' => (int) $row['response_http_status'],
        'response' => $response,
    ];
}

function idempotency_format_row(array $row, DateTimeImmutable $now): array
{
    $status = (string) $row['status'];
    $expired = $status === 'expired'
        || (in_array($status, ['succeeded', 'rejected'], true)
            && new DateTimeImmutable((string) $row['expires_at']) <= $now);
    $response = null;
    if (!$expired && in_array($status, ['succeeded', 'rejected'], true)) {
        $response = json_decode((string) $row['response_body'], true);
        if (!is_array($response)) {
            throw new RuntimeException('Stored idempotency response is invalid.');
        }
    }
    return [
        'contract_version' => IDEMPOTENCY_CONTRACT_VERSION,
        'operation_id' => (int) $row['id'],
        'request_id' => (string) $row['request_id'],
        'user_id' => (int) $row['user_id'],
        'operation' => (string) $row['operation'],
        'status' => $expired ? 'expired' : $status,
        'outcome' => $expired ? 'expired' : ($status === 'processing' ? 'processing' : 'terminal'),
        'response_http_status' => $expired || $row['response_http_status'] === null ? null : (int) $row['response_http_status'],
        'response' => $response,
        'attendance_id' => $row['attendance_id'] === null ? null : (int) $row['attendance_id'],
        'break_id' => $row['break_id'] === null ? null : (int) $row['break_id'],
        'created_at' => (string) $row['created_at'],
        'completed_at' => $row['completed_at'],
        'expires_at' => (string) $row['expires_at'],
    ];
}

function idempotency_encode_response(array $response): string
{
    idempotency_assert_safe_value($response);
    return json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** @param mixed $value */
function idempotency_assert_safe_value(mixed $value): void
{
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/(?:password|csrf|secret|access_token|refresh_token)/i', $key)) {
                throw new InvalidArgumentException('Sensitive values cannot be stored in an idempotency response.');
            }
            idempotency_assert_safe_value($item);
        }
        return;
    }
    if (is_float($value) && !is_finite($value)) {
        throw new InvalidArgumentException('Idempotency response contains an invalid number.');
    }
    if (!(is_scalar($value) || $value === null)) {
        throw new InvalidArgumentException('Idempotency response contains an unsupported value.');
    }
}

function idempotency_assert_owned_reference(PDO $pdo, string $table, ?int $recordId, int $userId): void
{
    if ($recordId === null) {
        return;
    }
    if (!in_array($table, ['attendance', 'break_records'], true) || $recordId < 1) {
        throw new InvalidArgumentException('Invalid idempotency record reference.');
    }
    $stmt = $pdo->prepare("SELECT user_id FROM {$table} WHERE id = ? LIMIT 1");
    $stmt->execute([$recordId]);
    $owner = $stmt->fetchColumn();
    if ($owner === false || (int) $owner !== $userId) {
        throw new InvalidArgumentException('Idempotency record reference is not owned by the authenticated user.');
    }
}
