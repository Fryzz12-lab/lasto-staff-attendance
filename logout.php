<?php
/**
 * Logout
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

logout_user();
start_app_session();
flash_set('success', __('msg_logged_out'));
redirect('/');
