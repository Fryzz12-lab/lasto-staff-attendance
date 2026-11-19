<?php
/**
 * Logout (CSRF-protected POST handler with push device disassociation)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/push_service.php';

if (!is_logged_in()) {
    redirect('/');
}

$user = current_user();

if (!is_post()) {
    flash_set('warning', __('msg_invalid_logout'));
    redirect($user && $user['role'] === 'admin' ? '/admin/' : '/staff/');
}

$token = $_POST['csrf_token'] ?? null;
if (!csrf_verify(is_string($token) ? $token : null)) {
    flash_set('danger', __('msg_csrf_refresh'));
    redirect($user && $user['role'] === 'admin' ? '/admin/' : '/staff/');
}

// Disassociate current browser's push endpoint for this user on logout
$endpoint = trim((string)($_POST['endpoint'] ?? ''));
if ($endpoint === '') {
    $endpoint = trim((string)($_SESSION['push_endpoint'] ?? ''));
}
if ($endpoint !== '' && push_is_valid_endpoint($endpoint) && $user) {
    try {
        push_unsubscribe_device((int)$user['id'], $endpoint);
    } catch (Throwable $e) {
        error_log('[Logout] Push disassociate error: ' . $e->getMessage());
    }
}

logout_user();
start_app_session();
flash_set('success', __('msg_logged_out'));
redirect('/');
