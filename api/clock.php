<?php
/**
 * Clock In / Clock Out API
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
$user = current_user();
$userId = (int) $user['id'];

try {
    if ($action === 'location_preference') {
        $preference = $input['preference'] ?? null;
        if (!in_array($preference, ['enabled', 'disabled'], true)) {
            json_response(['success' => false, 'message' => 'Invalid location preference.'], 400);
        }
        db()->prepare('UPDATE users SET attendance_location_enabled=? WHERE id=?')
            ->execute([$preference === 'enabled' ? 1 : 0, $userId]);
        json_response(['success' => true]);
    }
    if ($action === 'location_result') {
        $eventId = filter_var($input['event_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$eventId || !attendance_location_finalize((int) $eventId, $userId, $input)) {
            json_response(['success' => false, 'message' => 'Location result could not be saved.'], 400);
        }
        json_response(['success' => true]);
    }
    if ($action === 'clock_in') {
        json_response(clock_in($userId));
    }

    if ($action === 'clock_out') {
        json_response(clock_out($userId));
    }

    json_response(['success' => false, 'message' => __('msg_unknown_action')], 400);

} catch (InvalidArgumentException $error) {
    json_response(['success' => false, 'message' => $error->getMessage()], 400);
} catch (Throwable $error) {
    error_log((string) $error);
    json_response(['success' => false, 'message' => __('msg_process_fail')], 500);
}
