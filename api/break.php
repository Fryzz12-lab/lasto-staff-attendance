<?php
/**
 * Break Start / End API
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => __('msg_session_api')], 401);
}
if (!empty(current_user()['must_change_password'])) {
    json_response(['success' => false, 'message' => __('account_setup_hint')], 403);
}

if (!is_post()) {
    header('Allow: POST');
    json_response(['success' => false, 'message' => 'POST required.'], 405);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$token = $input['csrf_token'] ?? null;
if (!csrf_verify(is_string($token) ? $token : null)) {
    json_response(['success' => false, 'message' => __('msg_csrf_refresh')], 403);
}

$action = $input['action'] ?? '';
$userId = (int) current_user()['id'];

try {
    if ($action === 'start') {
        $type = (string) ($input['type'] ?? '');
        json_response(start_break($userId, $type));
    }

    if ($action === 'end') {
        json_response(end_break($userId));
    }

    json_response(['success' => false, 'message' => __('msg_unknown_action')], 400);

} catch (Throwable $error) {
    error_log((string) $error);
    json_response(['success' => false, 'message' => __('msg_process_fail')], 500);
}
