<?php
/** Run: php tests/notification_localization_test.php; uses a disposable database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/notification_service.php';
require_once __DIR__ . '/../includes/push_service.php';
$_SESSION = ['lang' => 'en'];

$checks = 0;
function verify_translation(bool $ok, string $description): void {
    global $checks;
    if (!$ok) throw new RuntimeException($description);
    $checks++;
}
$database = 'staff_attendance_test_' . bin2hex(random_bytes(6));
$pdo = db();
$pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4');
try {
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    if ($schema === false) throw new RuntimeException('Schema missing');
    $pdo->exec(str_replace('`staff_attendance`', '`' . $database . '`', $schema));
    verify_translation($pdo->query('SELECT DATABASE()')->fetchColumn() === $database, 'Disposable database selected');
    $pdo->prepare('INSERT INTO users (employee_id,username,password_hash,full_name,role) VALUES (?,?,?,?,?)')
        ->execute(['locale_staff', 'locale_staff', 'unused', '<script>alert(1)</script>', 'staff']);
    $staff = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO users (employee_id,username,password_hash,full_name,role) VALUES (?,?,?,?,?)')
        ->execute(['locale_admin', 'locale_admin', 'unused', 'Admin', 'admin']);
    $admin = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO leave_requests (user_id,leave_date,leave_type,reason,status) VALUES (?,?,?,?,?)')
        ->execute([$staff, '2026-10-21', 'wfh', 'fixture', 'pending']);
    $requestId = (int)$pdo->lastInsertId();
    notification_create($admin, 'leave_request', 'leave-submitted-' . $requestId, 'New WFH request', 'A staff member submitted a WFH request for 2026-10-21.', '/admin/leave_requests.php');
    $adminNotice = notification_list($admin)[0];
    verify_translation($adminNotice['event_key'] === 'leave-submitted-' . $requestId, 'Event key included for structured localization');

    $keys = ['notifications_title','notifications_view_all','notifications_filter_label','notice_device_hint','notice_test_button',
        'push_recovery_progress','push_recovery_retained','push_recovery_failed','push_recovery_cleanup',
        'push_ui_blocked','push_ui_unregistered','push_ui_enabled','push_ui_disabled','push_ui_preference_failed',
        'push_api_enabled','push_api_disabled','push_api_not_registered','push_api_test_sent',
        'notification_submitted_title','notification_submitted_message','notification_submitted_named_message',
        'notification_request_approved_title','notification_request_declined_message',
        'notification_shift_ending_title','notification_missing_clock_out_message','notification_break_ending_title'];
    $englishDictionary = require __DIR__ . '/../assets/lang/en.php';
    foreach (['en','ms','zh'] as $language) {
        $dictionary = require __DIR__ . '/../assets/lang/' . $language . '.php';
        foreach (array_keys($englishDictionary) as $key) {
            if (preg_match('/^(notification_|notifications_|notice_|push_ui_|push_api_|push_recovery_|toast_dismiss)/', $key)) {
                verify_translation(array_key_exists($key, $dictionary), "$language covers notification key $key");
            }
        }
        foreach ($keys as $key) {
            verify_translation(array_key_exists($key, $dictionary), "$language defines $key directly");
            verify_translation(translate_for_language($key, $language) !== $key, "$language translates $key");
        }
        foreach (['day_off','annual','emergency','medical','wfh','other'] as $type) {
            verify_translation(notification_type_label($type, $language) !== 'notification_type_' . $type, "$language labels $type");
        }
        foreach (['pending','approved','rejected'] as $status) {
            verify_translation(notification_status_label($status, $language) !== 'notification_status_' . $status, "$language labels $status");
        }
        $submitted = notification_localized_content($adminNotice, $language);
        verify_translation(!str_contains($submitted['title'], 'WFH') || $language === 'en', "$language replaces raw WFH request code");
        verify_translation(str_contains($submitted['message'], notification_format_date('2026-10-21', $language)), "$language formats submission date");
        verify_translation($submitted['title'] === notification_localized_content(notification_list($admin)[0], $language)['title'], "$language bell and center use same content");
        $native = notification_localized_push_content('new_wfh_request', 'admin-leave-' . $requestId, 'English title', 'English body', $language);
        verify_translation($native['title'] === $submitted['title'], "$language native and inbox titles agree");
        verify_translation(str_contains($native['message'], '<script>alert(1)</script>'), "$language native push includes staff name as plain text");
        foreach (['submitted','approved','rejected'] as $state) {
            $annual = notification_request_content('annual', '2026-10-21', $state, $language);
            verify_translation(str_contains($annual['title'], notification_type_label('annual', $language))
                && str_contains($annual['message'], notification_format_date('2026-10-21', $language)), "$language annual $state content");
        }
        foreach (['shift_ending','missing_clock_out','break_ending','test_notification'] as $category) {
            $reminder = notification_localized_push_content($category, 'fixture', 'English title', 'English body', $language);
            verify_translation($reminder['title'] !== 'English title' && $reminder['message'] !== 'English body', "$language native $category localized");
        }
    }
    verify_translation(translate_for_language('missing_key', 'ms') === 'missing_key', 'Missing key falls back to key');
    verify_translation(translate_for_language('notifications_title', 'xx') === translate_for_language('notifications_title', 'en'), 'Unsupported language falls back to English');
    verify_translation(notification_format_date('2026-10-21', 'en') === '21 Oct 2026', 'English date');
    verify_translation(notification_format_date('2026-10-21', 'ms') === '21 Okt 2026', 'Malay date');
    verify_translation(notification_format_date('2026-10-21', 'zh') === '2026年10月21日', 'Simplified Chinese date');
    verify_translation(notification_format_date('invalid', 'zh') === 'invalid', 'Malformed date falls back unchanged');

    $legacy = ['event_type' => 'leave_decision', 'event_key' => 'old-key', 'title' => 'ANNUAL request approved',
        'message' => 'Your ANNUAL request for 2026-10-21 was approved.'];
    $legacyZh = notification_localized_content($legacy, 'zh');
    verify_translation(str_contains($legacyZh['message'], '2026年10月21日') && !str_contains($legacyZh['title'], 'ANNUAL'), 'Known legacy English decision localizes');
    $unknown = ['event_type' => 'other', 'event_key' => 'old', 'title' => '<b>Legacy</b>', 'message' => '<img src=x onerror=alert(1)>'];
    verify_translation(notification_localized_content($unknown, 'ms')['title'] === '<b>Legacy</b>', 'Unknown legacy content falls back unchanged');
    verify_translation(str_contains(e(notification_localized_content($unknown, 'ms')['message']), '&lt;img'), 'Unknown legacy content is escaped at render');
    verify_translation(!str_contains(e(notification_localized_push_content('new_wfh_request', 'admin-leave-' . $requestId, '', '', 'en')['message']), '<script>'), 'User name is escaped when rendered as HTML');

    $pdo->prepare("UPDATE leave_requests SET status='approved' WHERE id=?")->execute([$requestId]);
    notification_create($staff, 'leave_decision', 'leave-reviewed-' . $requestId, 'WFH request approved', 'Your WFH request for 2026-10-21 was approved.', '/staff/leave.php');
    $uatLegacy = ['event_type' => 'leave_decision', 'event_key' => 'uat-decision-' . $requestId,
        'title' => 'Request Approved', 'message' => 'Your request for 2026-10-21 was approved.'];
    verify_translation(str_contains(notification_localized_content($uatLegacy, 'zh')['title'], '居家办公'), 'Legacy UAT decision resolves request without English title parsing');
    foreach (['en','ms','zh'] as $language) {
        $decision = notification_localized_content(notification_list($staff)[0], $language);
        verify_translation(str_contains($decision['message'], notification_format_date('2026-10-21', $language)), "$language approval date");
        verify_translation($decision['title'] === notification_localized_push_content('leave_decision', 'staff-leave-' . $requestId, '', '', $language)['title'], "$language approval native title");
    }
    $pdo->prepare("UPDATE leave_requests SET status='rejected' WHERE id=?")->execute([$requestId]);
    verify_translation(str_contains(notification_localized_content(notification_list($staff)[0], 'en')['title'], 'declined'), 'Rejection renders declined status');
    verify_translation(notification_status_label('rejected', 'ms') === 'Ditolak', 'Malay rejected status label');
    verify_translation(notification_status_label('approved', 'zh') === '已批准', 'Chinese approved status label');
    verify_translation(notification_status_label('pending', 'en') === 'Pending', 'English pending status label');

    $endpoint = 'https://fcm.googleapis.com/fcm/send/locale-fixture';
    verify_translation(push_subscribe_device($staff, $endpoint, 'dummy', 'dummy'), 'Disposable subscription fixture created');
    verify_translation(push_set_device_language($staff, $endpoint, 'zh'), 'Endpoint language saved');
    $device = push_find_device($staff, $endpoint);
    verify_translation(push_device_language($device, 'en') === 'zh', 'Native push selects endpoint language');
    verify_translation(push_set_device_preferences($staff, $endpoint, ['break_ending' => false])['language'] === 'zh', 'Category update preserves endpoint language');
    verify_translation(push_device_language(['notification_preferences' => null], 'ms') === 'ms', 'Legacy endpoint uses default language');
    verify_translation(!push_set_device_language($staff, $endpoint, 'fr'), 'Unsupported endpoint language rejected');

    foreach (['includes/notification_bell.php','notifications.php'] as $path) {
        $source = file_get_contents(__DIR__ . '/../' . $path);
        verify_translation($source !== false && str_contains($source, 'notification_localized_content(')
            && str_contains($source, 'notification_format_date(') && str_contains($source, 'e($localized['), "$path uses shared localized, escaped rendering");
    }
    foreach (['admin/profile.php','staff/profile.php'] as $path) {
        $source = file_get_contents(__DIR__ . '/../' . $path);
        verify_translation($source !== false && str_contains($source, "__('notice_")
            && str_contains($source, 'aria-label='), "$path exposes translated notification settings and labels");
    }
    $clientSource = file_get_contents(__DIR__ . '/../includes/layout_footer.php');
    verify_translation($clientSource !== false && str_contains($clientSource, "__('push_ui_blocked')")
        && str_contains($clientSource, "__('toast_dismiss')"), 'Browser status and accessibility strings are supplied to JavaScript');
    echo "Notification localization: {$checks}/{$checks} passed\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec('USE `' . DB_NAME . '`');
    $pdo->exec('DROP DATABASE `' . $database . '`');
}
