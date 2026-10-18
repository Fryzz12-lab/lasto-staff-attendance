<?php
/**
 * Application bootstrap — include on every page
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/attendance_service.php';
require_once __DIR__ . '/attendance_location.php';
require_once __DIR__ . '/adjustment_service.php';
require_once __DIR__ . '/badge_service.php';
require_once __DIR__ . '/notification_service.php';
require_once __DIR__ . '/date_filter.php';
require_once __DIR__ . '/account_recovery.php';
require_once __DIR__ . '/security_questions.php';

start_app_session();
