<?php
/** HTTP adapter for the durable idempotency service. */

declare(strict_types=1);

require_once __DIR__ . '/idempotency_service.php';

/**
 * Run one attendance API mutation inside the same transaction as its durable
 * request result. The caller must perform session and CSRF checks first.
 *
 * The callback returns result, attendance_id, and break_id fields. Employee
 * lock acquisition precedes idempotency reservation, matching the existing
 * mutation lock order.
 */
function idempotent_attendance_api_response(
    int $userId,
    string $requestKey,
    string $operation,
    array $semanticPayload,
    callable $mutate
): void {
    try {
        $requestKey = idempotency_validate_request_key($requestKey);
        $operation = idempotency_validate_operation($operation);
    } catch (InvalidArgumentException $error) {
        json_response(['success' => false, 'outcome' => 'invalid_request', 'message' => $error->getMessage()], 400);
    }

    $pdo = db();
    try {
        if ($pdo->inTransaction()) {
            throw new LogicException('Attendance API idempotency cannot join an unrelated transaction.');
        }
        $pdo->beginTransaction();

        // Keep a stable acquisition order: employee first, operation key next.
        $employeeLock = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $employeeLock->execute([$userId]);
        if (!$employeeLock->fetchColumn()) {
            throw new RuntimeException('Authenticated employee no longer exists.');
        }

        $reservation = idempotency_reserve($pdo, $userId, $requestKey, $operation, $semanticPayload);
        if ($reservation['outcome'] === 'conflict') {
            $pdo->rollBack();
            json_response([
                'success' => false,
                'outcome' => 'key_conflict',
                'request_id' => $requestKey,
                'operation_id' => $reservation['operation_id'],
                'message' => 'Idempotency key was already used for a different operation or payload.',
            ], 409);
        }
        if ($reservation['outcome'] === 'expired') {
            $pdo->rollBack();
            json_response([
                'success' => false,
                'outcome' => 'operation_expired',
                'request_id' => $requestKey,
                'operation_id' => $reservation['operation_id'],
                'message' => 'This idempotency key has expired. Start a new action with a new key.',
            ], 409);
        }
        if ($reservation['outcome'] === 'processing') {
            $pdo->commit();
            json_response([
                'success' => false,
                'outcome' => 'processing',
                'request_id' => $requestKey,
                'operation_id' => $reservation['operation_id'],
                'retry_after' => 2,
            ], 202);
        }
        if ($reservation['outcome'] === 'replay') {
            $pdo->commit();
            $response = $reservation['response'];
            $response['replayed'] = true;
            json_response($response, (int) $reservation['response_http_status']);
        }
        if ($reservation['outcome'] !== 'reserved') {
            throw new RuntimeException('Unknown idempotency reservation outcome.');
        }

        $mutation = $mutate();
        if (!is_array($mutation) || !isset($mutation['result']) || !is_array($mutation['result'])) {
            throw new LogicException('Attendance mutation returned an invalid result.');
        }
        $result = $mutation['result'];
        $state = resolve_attendance_state($userId);
        $attendanceId = isset($mutation['attendance_id'])
            ? (int) $mutation['attendance_id']
            : (isset($result['attendance_id']) ? (int) $result['attendance_id'] : ($state['attendance_id'] ?? null));
        $breakId = isset($mutation['break_id'])
            ? (int) $mutation['break_id']
            : (isset($result['break_id']) ? (int) $result['break_id'] : ($state['active_break']['id'] ?? null));

        $response = $result + [
            'contract_version' => IDEMPOTENCY_CONTRACT_VERSION,
            'outcome' => !empty($result['success']) ? 'confirmed_success' : 'confirmed_rejection',
            'request_id' => $requestKey,
            'operation_id' => (int) $reservation['operation_id'],
            'replayed' => false,
            'attendance_id' => $attendanceId,
            'break_id' => $breakId,
            'server_timestamp' => now()->format('Y-m-d H:i:s'),
            'state' => $state,
        ];
        $httpStatus = 200; // Preserve the current API's business-result status behavior.

        if (!empty($result['success'])) {
            idempotency_persist_success($pdo, $userId, (int) $reservation['operation_id'], $httpStatus, $response, $attendanceId, $breakId);
        } else {
            idempotency_persist_rejection($pdo, $userId, (int) $reservation['operation_id'], $httpStatus, $response, $attendanceId, $breakId);
        }

        $pdo->commit();
        json_response($response, $httpStatus);
    } catch (InvalidArgumentException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['success' => false, 'outcome' => 'confirmed_rejection', 'message' => $error->getMessage()], 400);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log((string) $error);
        json_response(['success' => false, 'outcome' => 'server_failure', 'message' => __('msg_process_fail')], 500);
    }
}
