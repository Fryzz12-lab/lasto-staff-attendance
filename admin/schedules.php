<?php
/** Admin — Working Hours & Policy */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();
if (is_post()) {
    require_csrf();
    redirect('/admin/schedules.php');
}
$pageTitle = __('schedules');
$activeMenu = 'schedules';
$isAdminArea = true;
$bodyClass = 'policy-page staff-policy-page';
require __DIR__ . '/../includes/layout_header.php';
?>
<?php require __DIR__ . '/../includes/policy_content.php'; ?>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
