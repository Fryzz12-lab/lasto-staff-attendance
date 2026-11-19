<?php
/**
 * Push Subscription & Preferences API
 * Staff Attendance & Workforce Management System
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/push_service.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => __('msg_session_api')], 401);
}

$user = current_user();
$userId = (int) $user['id'];

// GET reports only the requesting browser's registered endpoint.
if (!is_post()) {
    try {
        $publicKey = push_get_public_key();
        $endpoint = trim((string)($_GET['endpoint'] ?? ''));
        $device = push_find_device($userId, $endpoint);
        $preferences = $device
            ? push_get_device_preferences($device, push_get_preferences($userId))
            : array_merge(push_get_preferences($userId), ['all' => false]);
        json_response([
            'success' => true,
            'vapid_public_key' => $publicKey,
            'preferences' => $preferences,
            'subscribed' => $device !== null,
        ]);
    } catch (Throwable $e) {
        error_log('[PushAPI] Configuration error: ' . $e->getMessage());
        json_response(['success' => false, 'message' => __('push_ui_settings_unavailable')], 500);
    }
}

// POST actions
$input = json_decode((string)file_get_contents('php://input'), true) ?: [];
$token = $input['csrf_token'] ?? null;
if (!csrf_verify(is_string($token) ? $token : null)) {
    json_response(['success' => false, 'message' => __('msg_csrf_refresh')], 403);
}

$action = $input['action'] ?? '';

try {
    if ($action === 'subscribe') {
        $sub = $input['subscription'] ?? [];
        $endpoint = trim((string)($sub['endpoint'] ?? ''));
        $p256dh = trim((string)($sub['keys']['p256dh'] ?? ''));
        $auth = trim((string)($sub['keys']['auth'] ?? ''));

        if (!$endpoint || !$p256dh || !$auth) {
            json_response(['success' => false, 'message' => __('push_api_invalid_subscription')], 400);
        }
        if (!push_is_valid_endpoint($endpoint)) {
            json_response(['success' => false, 'message' => __('push_api_unsupported_endpoint')], 400);
        }

        $requestedPreferences = $input['preferences'] ?? [];
        if (!is_array($requestedPreferences)) json_response(['success' => false, 'message' => __('push_api_invalid_preferences')], 400);
        $allowed = array_keys(push_get_preferences($userId));
        foreach ($requestedPreferences as $key => $value) {
            if (!in_array($key, $allowed, true) || !is_bool($value)) {
                json_response(['success' => false, 'message' => __('push_api_invalid_preference')], 400);
            }
        }
        $requestedPreferences['all'] = true;

        $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        db()->beginTransaction();
        try {
            if (!push_subscribe_device($userId, $endpoint, $p256dh, $auth, $userAgent)) {
                throw new RuntimeException(__('push_api_save_failed'));
            }
            $curPrefs = push_set_device_preferences($userId, $endpoint, $requestedPreferences);
            if ($curPrefs === null || !push_set_device_language($userId, $endpoint, lang_current())) {
                throw new RuntimeException(__('push_api_enable_failed'));
            }
            db()->commit();
            $_SESSION['push_endpoint'] = $endpoint;
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }

        json_response([
            'success' => true,
            'message' => __('push_api_enabled'),
            'preferences' => $curPrefs,
        ]);
    }

    if ($action === 'unsubscribe') {
        $endpoint = trim((string)($input['endpoint'] ?? ''));
        if ($endpoint === '') json_response(['success' => false, 'message' => __('push_api_endpoint_required')], 400);
        if (!push_unsubscribe_device($userId, $endpoint)) throw new RuntimeException(__('push_api_remove_failed'));
        if (isset($_SESSION['push_endpoint']) && $_SESSION['push_endpoint'] === $endpoint) {
            unset($_SESSION['push_endpoint']);
        }

        json_response([
            'success' => true,
            'message' => __('push_api_disabled'),
            'preferences' => array_merge(push_get_preferences($userId), ['all' => false]),
            'subscribed' => false,
        ]);
    }

    if ($action === 'update_preferences') {
        $preferences = $input['preferences'] ?? null;
        if (!is_array($preferences)) {
            json_response(['success' => false, 'message' => __('push_api_invalid_preferences')], 400);
        }
        $endpoint = trim((string)($input['endpoint'] ?? ''));
        if ($endpoint === '') json_response(['success' => false, 'message' => __('push_api_endpoint_required')], 400);
        $allowed = array_keys(push_get_preferences($userId));
        foreach ($preferences as $key => $value) {
            if (!in_array($key, $allowed, true) || !is_bool($value)) {
                json_response(['success' => false, 'message' => __('push_api_invalid_preference')], 400);
            }
        }
        $merged = push_set_device_preferences($userId, $endpoint, $preferences);
        if ($merged === null) json_response(['success' => false, 'code' => 'device_not_registered', 'message' => __('push_api_not_registered')], 404);

        json_response([
            'success' => true,
            'message' => __('push_api_preferences_saved'),
            'preferences' => $merged,
        ]);
    }

    if ($action === 'set_language') {
        $endpoint = trim((string)($input['endpoint'] ?? ''));
        if ($endpoint === '') json_response(['success' => false, 'message' => __('push_api_endpoint_required')], 400);
        if (!push_set_device_language($userId, $endpoint, lang_current())) {
            json_response(['success' => false, 'code' => 'device_not_registered', 'message' => __('push_api_not_registered')], 404);
        }
        json_response(['success' => true]);
    }

    if ($action === 'sync') {
        $endpoint = trim((string)($input['endpoint'] ?? ''));
        if ($endpoint === '' || !push_is_valid_endpoint($endpoint)) {
            json_response(['success' => false, 'message' => __('push_api_unsupported_endpoint')], 400);
        }

        $_SESSION['push_endpoint'] = $endpoint;
        $p256dh = trim((string)($input['p256dh'] ?? ''));
        $auth = trim((string)($input['auth'] ?? ''));
        $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, user_id, p256dh, auth, notification_preferences FROM push_subscriptions WHERE endpoint = ? LIMIT 1');
        $stmt->execute([$endpoint]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ((int)$existing['user_id'] !== $userId) {
                $newPrefs = json_encode(push_new_device_preferences($userId));
                $p256dhVal = $p256dh !== '' ? $p256dh : (string)$existing['p256dh'];
                $authVal = $auth !== '' ? $auth : (string)$existing['auth'];

                $up = $pdo->prepare('UPDATE push_subscriptions SET user_id = ?, p256dh = ?, auth = ?, user_agent = ?, notification_preferences = ?, updated_at = NOW() WHERE id = ?');
                $up->execute([$userId, $p256dhVal, $authVal, $userAgent, $newPrefs, (int)$existing['id']]);

                push_set_device_language($userId, $endpoint, lang_current());

                json_response([
                    'success' => true,
                    'action_taken' => 'reassigned',
                    'user_id' => $userId,
                ]);
            } else {
                if ($p256dh !== '' && $auth !== '') {
                    $up = $pdo->prepare('UPDATE push_subscriptions SET p256dh = ?, auth = ?, user_agent = ?, updated_at = NOW() WHERE id = ?');
                    $up->execute([$p256dh, $auth, $userAgent, (int)$existing['id']]);
                }
                json_response([
                    'success' => true,
                    'action_taken' => 'updated',
                    'user_id' => $userId,
                ]);
            }
        } else {
            if ($p256dh !== '' && $auth !== '') {
                push_subscribe_device($userId, $endpoint, $p256dh, $auth, $userAgent);
                push_set_device_language($userId, $endpoint, lang_current());
                json_response([
                    'success' => true,
                    'action_taken' => 'registered',
                    'user_id' => $userId,
                ]);
            } else {
                json_response([
                    'success' => true,
                    'action_taken' => 'unregistered',
                    'user_id' => $userId,
                ]);
            }
        }
    }

    if ($action === 'test') {
        if (!is_admin()) {
            json_response(['success' => false, 'message' => __('msg_no_permission')], 403);
        }
        $targetUrl = '/admin/profile.php';
        $res = push_send_to_user(
            $userId,
            __('notification_test_title'),
            __('notification_test_message'),
            $targetUrl,
            'test-notice-' . time(),
            'test_notification'
        );

        if (($res['sent'] ?? 0) > 0) {
            json_response([
                'success' => true,
                'message' => __('push_api_test_sent'),
                'result' => $res,
            ]);
        } else {
            $msg = isset($res['skipped']) ? __('push_api_no_subscriptions') : __('push_api_test_failed');
            json_response(['success' => false, 'message' => $msg, 'result' => $res], 400);
        }
    }

    json_response(['success' => false, 'message' => __('push_api_unknown_action')], 400);

} catch (Throwable $e) {
    error_log('[PushAPI] Error: ' . $e->getMessage());
    json_response(['success' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : __('push_ui_test_failed')], 500);
}
