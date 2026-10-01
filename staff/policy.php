<?php
/** Staff — Working Hours & Policy (read-only). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_staff();
if (is_post()) {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
$pageTitle = __('schedules');
$activeMenu = 'policy';
$isAdminArea = false;
$bodyClass = 'policy-page staff-policy-page';
require __DIR__ . '/../includes/layout_header.php';
require __DIR__ . '/../includes/policy_content.php';
require __DIR__ . '/../includes/layout_footer.php';
