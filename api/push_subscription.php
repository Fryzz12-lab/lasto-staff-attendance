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

// GET method returns VAPID public key and current user's preferences
if (!is_post()) {
    try {
        $publicKey = push_get_public_key();
        $preferences = push_get_preferences($userId);
        json_response([
            'success' => true,
            'vapid_public_key' => $publicKey,
            'preferences' => $preferences,
        ]);
    } catch (Throwable $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 500);
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
            json_response(['success' => false, 'message' => 'Invalid subscription payload.'], 400);
        }
        if (!push_is_valid_endpoint($endpoint)) {
            json_response(['success' => false, 'message' => 'Unsupported push service endpoint.'], 400);
        }

        $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        push_subscribe_device($userId, $endpoint, $p256dh, $auth, $userAgent);

        $curPrefs = push_get_preferences($userId);
        $curPrefs['all'] = true;
        db()->prepare('UPDATE push_subscriptions SET notification_preferences=? WHERE user_id=? AND endpoint=?')->execute([json_encode(array_merge($curPrefs, ['all' => true])), $userId, $endpoint]);

        json_response([
            'success' => true,
            'message' => 'Push notifications enabled on this device.',
            'preferences' => $curPrefs,
        ]);
    }

    if ($action === 'unsubscribe') {
        $endpoint = trim((string)($input['endpoint'] ?? ''));
        if ($endpoint) {
            push_unsubscribe_device($userId, $endpoint);
        }

        $curPrefs = push_get_preferences($userId);
        $curPrefs['all'] = false;
        if ($endpoint) db()->prepare('UPDATE push_subscriptions SET notification_preferences=? WHERE user_id=? AND endpoint=?')->execute([json_encode(array_merge($curPrefs, ['all' => false])), $userId, $endpoint]);

        json_response([
            'success' => true,
            'message' => 'Push notifications disabled on this device.',
            'preferences' => $curPrefs,
        ]);
    }

    if ($action === 'update_preferences') {
        $preferences = $input['preferences'] ?? null;
        if (!is_array($preferences)) {
            json_response(['success' => false, 'message' => 'Invalid preferences data.'], 400);
        }

        $current = push_get_preferences($userId);
        $merged = array_merge($current, $preferences);
        $endpoint = trim((string)($input['endpoint'] ?? ''));
        if ($endpoint) db()->prepare('UPDATE push_subscriptions SET notification_preferences=? WHERE user_id=? AND endpoint=?')->execute([json_encode($merged), $userId, $endpoint]);
        else push_update_preferences($userId, $merged);

        json_response([
            'success' => true,
            'message' => 'Notification preferences saved.',
            'preferences' => $merged,
        ]);
    }

    if ($action === 'test') {
        if (!is_admin()) {
            json_response(['success' => false, 'message' => __('msg_no_permission')], 403);
        }
        $targetUrl = '/admin/profile.php';
        $res = push_send_to_user(
            $userId,
            'Staff Attendance Alert',
            'Test push notification received successfully! 🎉',
            $targetUrl,
            'test-notice-' . time()
        );

        if (($res['sent'] ?? 0) > 0) {
            json_response([
                'success' => true,
                'message' => 'Test notification sent to your device!',
                'result' => $res,
            ]);
        } else {
            $msg = $res['skipped'] ?? 'Could not deliver test notification. Ensure this device is registered.';
            json_response(['success' => false, 'message' => $msg, 'result' => $res], 400);
        }
    }

    json_response(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars((string)$action)], 400);

} catch (Throwable $e) {
    error_log('[PushAPI] Error: ' . $e->getMessage());
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
