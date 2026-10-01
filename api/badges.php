<?php
/**
 * Live sidebar badge counts (JSON poll)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/badge_service.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => __('msg_session_api')], 401);
}

$user = current_user();
$badges = chrono_badge_counts($user);

json_response([
    'success' => true,
    'role' => $user['role'] ?? '',
    'badges' => $badges,
    'ts' => time(),
]);
