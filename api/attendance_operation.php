<?php
/** Read-only attendance state and idempotency reconciliation endpoint. */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/idempotency_service.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (!is_logged_in()) {
    json_response(['success' => false, 'outcome' => 'session_expired', 'message' => __('msg_session_api')], 401);
}
if (!empty(current_user()['must_change_password'])) {
    json_response(['success' => false, 'outcome' => 'session_expired', 'message' => __('account_setup_hint')], 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    json_response(['success' => false, 'outcome' => 'invalid_request', 'message' => 'GET required.'], 405);
}

$userId = (int) current_user()['id'];
$requestKey = $_GET['request_id'] ?? null;

try {
    // The capability probe is read-only. A missing Phase 3B table disables
    // keyed actions instead of allowing the browser to attempt legacy writes.
    $pdo = db();
    try {
        $pdo->query(
            'SELECT request_id, user_id, operation, payload_hash, status,
                    response_http_status, response_body, attendance_id, break_id, expires_at
             FROM attendance_idempotency_operations LIMIT 0'
        );
        $idempotencyAvailable = true;
    } catch (PDOException $error) {
        $idempotencyAvailable = false;
    }

    $state = resolve_attendance_state($userId);
    if ($requestKey === null || $requestKey === '') {
        if (!$idempotencyAvailable) {
            json_response([
                'success' => true,
                'contract_version' => 1,
                'outcome' => 'unavailable',
                'idempotency_supported' => false,
                'state' => $state,
            ], 503);
        }
        json_response([
            'success' => true,
            'contract_version' => 1,
            'outcome' => 'ready',
            'idempotency_supported' => true,
            'state' => $state,
        ]);
    }

    if (!$idempotencyAvailable) {
        json_response([
            'success' => false,
            'contract_version' => 1,
            'outcome' => 'unavailable',
            'idempotency_supported' => false,
            'state' => $state,
        ], 503);
    }
    if (!is_string($requestKey)) {
        json_response(['success' => false, 'contract_version' => 1, 'outcome' => 'invalid_request'], 400);
    }

    $requestKey = idempotency_validate_request_key($requestKey);
    $operation = idempotency_get_operation($pdo, $userId, $requestKey);
    if ($operation === null) {
        json_response([
            'success' => true,
            'contract_version' => 1,
            'outcome' => 'unknown',
            'request_id' => $requestKey,
            'state' => $state,
        ]);
    }

    $outcome = match ($operation['status']) {
        'succeeded' => 'confirmed_success',
        'rejected' => 'confirmed_rejection',
        'processing' => 'processing',
        'expired' => 'expired',
        default => 'unknown',
    };
    $response = [
        'success' => true,
        'contract_version' => 1,
        'outcome' => $outcome,
        'request_id' => $requestKey,
        'operation_id' => $operation['operation_id'],
        'operation' => $operation['operation'],
        'state' => $state,
    ];
    if (in_array($outcome, ['confirmed_success', 'confirmed_rejection'], true)) {
        $response['result'] = $operation['response'];
        $response['response_http_status'] = $operation['response_http_status'];
    }
    if ($outcome === 'processing') {
        $response['retry_after'] = 2;
    }
    json_response($response, $outcome === 'processing' ? 202 : 200);
} catch (InvalidArgumentException $error) {
    json_response(['success' => false, 'contract_version' => 1, 'outcome' => 'invalid_request'], 400);
} catch (Throwable $error) {
    error_log('[Attendance reconciliation] ' . $error->getMessage());
    json_response(['success' => false, 'contract_version' => 1, 'outcome' => 'server_unavailable'], 503);
}
