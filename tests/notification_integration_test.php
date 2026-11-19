<?php
/** Run: php tests/notification_integration_test.php
 * Uses a unique disposable database and no push subscriptions.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/attendance_service.php';
require_once __DIR__ . '/../includes/i18n.php';
$_SESSION = ['lang' => 'en'];

$checks = 0;
function expect_notice(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

function fixture_user(string $name, string $role, bool $active = true): int
{
    db()->prepare('INSERT INTO users (employee_id,username,password_hash,full_name,role,is_active,weekly_off_day) VALUES (?,?,?,?,?,?,?)')
        ->execute([$name, $name, 'unused', $name, $role, $active ? 1 : 0, 0]);
    return (int) db()->lastInsertId();
}

function notices_for(int $userId): array
{
    $stmt = db()->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY id');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

$database = 'staff_attendance_test_' . bin2hex(random_bytes(6));
$pdo = db();
$pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4');
try {
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    if ($schema === false) throw new RuntimeException('Cannot read test schema.');
    $schema = str_replace('`staff_attendance`', '`' . $database . '`', $schema);
    $pdo->exec($schema);
    expect_notice((string) $pdo->query('SELECT DATABASE()')->fetchColumn() === $database, 'Disposable database selected');

    $staff = fixture_user('notice_staff', 'staff');
    $otherStaff = fixture_user('notice_other_staff', 'staff');
    $adminA = fixture_user('notice_admin_a', 'admin');
    $adminB = fixture_user('notice_admin_b', 'admin');
    $inactiveAdmin = fixture_user('notice_inactive_admin', 'admin', false);
    expect_notice((int) $pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn() === 0, 'No device subscriptions or external push delivery');

    $date = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
    if ((int) (new DateTimeImmutable($date))->format('w') === 0) {
        $date = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    }
    submit_leave_request($staff, $date, 'annual', 'Annual leave fixture');
    $leaveId = (int) $pdo->query('SELECT id FROM leave_requests ORDER BY id DESC LIMIT 1')->fetchColumn();
    $key = 'leave-submitted-' . $leaveId;
    foreach ([$adminA, $adminB] as $adminId) {
        $rows = notices_for($adminId);
        expect_notice(count($rows) === 1, 'Each active admin receives one leave request');
        expect_notice($rows[0]['event_type'] === 'leave_request' && $rows[0]['event_key'] === $key, 'Leave event category and key');
        expect_notice($rows[0]['title'] === 'New ANNUAL request' && $rows[0]['message'] === "A staff member submitted a ANNUAL request for {$date}.", 'Leave title and message');
        expect_notice($rows[0]['destination'] === '/admin/leave_requests.php' && $rows[0]['read_at'] === null, 'Admin destination and unread state');
    }
    expect_notice(notices_for($inactiveAdmin) === [] && notices_for($staff) === [] && notices_for($otherStaff) === [], 'Inactive admin and staff do not receive submission');
    expect_notice(!notification_create($adminA, 'leave_request', $key, 'Duplicate', 'Duplicate', '/admin/leave_requests.php'), 'Event key deduplicates for one admin');
    expect_notice(count(notices_for($adminA)) === 1 && notices_for($adminA)[0]['title'] === 'New ANNUAL request', 'Duplicate does not overwrite notification');

    notification_mark_read($adminA, (int) notices_for($adminA)[0]['id']);
    expect_notice(notification_unread_count($adminA) === 0 && notification_unread_count($adminB) === 1, 'Mark read is scoped to recipient');
    review_leave_request($leaveId, 'approved', $adminA, 'Approved fixture');
    $staffRows = notices_for($staff);
    expect_notice(count($staffRows) === 1 && $staffRows[0]['event_type'] === 'leave_decision' && $staffRows[0]['event_key'] === 'leave-reviewed-' . $leaveId, 'Approval notifies staff once');
    expect_notice($staffRows[0]['title'] === 'ANNUAL request approved' && $staffRows[0]['message'] === "Your ANNUAL request for {$date} was approved.", 'Approval title and message');
    expect_notice($staffRows[0]['destination'] === '/staff/leave.php' && $staffRows[0]['read_at'] === null, 'Staff destination and unread state');
    expect_notice(notices_for($otherStaff) === [], 'Other staff cannot receive approval');
    notification_mark_all_read($staff);
    expect_notice(notification_unread_count($staff) === 0 && notices_for($staff)[0]['read_at'] !== null, 'Mark all read updates staff inbox');

    $wfhDate = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    if ((int) (new DateTimeImmutable($wfhDate))->format('w') === 0) {
        $wfhDate = (new DateTimeImmutable($wfhDate))->modify('+1 day')->format('Y-m-d');
    }
    submit_leave_request($otherStaff, $wfhDate, 'wfh', 'WFH fixture');
    $wfhId = (int) $pdo->query('SELECT id FROM leave_requests ORDER BY id DESC LIMIT 1')->fetchColumn();
    foreach ([$adminA, $adminB] as $adminId) {
        $rows = notices_for($adminId);
        $last = end($rows);
        expect_notice(count($rows) === 2 && $last['event_type'] === 'leave_request' && $last['event_key'] === 'leave-submitted-' . $wfhId, 'Both active admins receive WFH');
        expect_notice($last['title'] === 'New WFH request' && $last['message'] === "A staff member submitted a WFH request for {$wfhDate}.", 'WFH title and message');
        expect_notice($last['destination'] === '/admin/leave_requests.php' && $last['read_at'] === null, 'WFH admin destination and unread state');
    }
    review_leave_request($wfhId, 'rejected', $adminB, 'Rejected fixture');
    $rejection = notices_for($otherStaff)[0];
    expect_notice($rejection['event_type'] === 'leave_decision' && $rejection['event_key'] === 'leave-reviewed-' . $wfhId, 'Rejection category and event key');
    expect_notice($rejection['title'] === 'WFH request declined' && $rejection['message'] === "Your WFH request for {$wfhDate} was declined.", 'Rejection title and message');
    expect_notice($rejection['destination'] === '/staff/leave.php' && $rejection['read_at'] === null, 'Rejection destination and unread state');
    expect_notice(count(notices_for($staff)) === 1 && notices_for($inactiveAdmin) === [], 'No cross-recipient decision delivery');

    $beforeRequests = (int) $pdo->query('SELECT COUNT(*) FROM leave_requests')->fetchColumn();
    $beforeNotices = (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
    $pdo->beginTransaction();
    try {
        submit_leave_request($staff, $wfhDate, 'medical', 'Rollback fixture');
        expect_notice((int) $pdo->query('SELECT COUNT(*) FROM leave_requests')->fetchColumn() === $beforeRequests + 1, 'Request exists inside transaction');
        expect_notice((int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === $beforeNotices + 2, 'Admin notifications exist inside transaction');
    } finally {
        $pdo->rollBack();
    }
    expect_notice((int) $pdo->query('SELECT COUNT(*) FROM leave_requests')->fetchColumn() === $beforeRequests, 'Rolled-back request is absent');
    expect_notice((int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === $beforeNotices, 'Rolled-back notifications are absent');
    expect_notice((int) $pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn() === 0, 'No push subscription was created');

    $source = file_get_contents(__DIR__ . '/../includes/attendance_service.php');
    $submissionCategory = <<<'PHP'
($type === 'wfh' ? 'new_wfh_request' : 'new_leave_request')
PHP;
    expect_notice($source !== false && str_contains($source, $submissionCategory), 'Submission push categories are selected by request type');
    expect_notice($source !== false && preg_match('/\x27staff-leave-\x27\s*\.\s*\$requestId,\s*\x27leave_decision\x27/', $source) === 1, 'Decision push uses leave_decision category');
    echo "PASS: {$checks} notification integration and category-contract checks.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec('USE `' . DB_NAME . '`');
    $pdo->exec('DROP DATABASE `' . $database . '`');
}
