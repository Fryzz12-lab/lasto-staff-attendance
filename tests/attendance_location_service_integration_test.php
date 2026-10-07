<?php
declare(strict_types=1);

// Run against a local database with the attendance-location migration applied.
// All fixture changes are rolled back, including office settings and user preference.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/attendance_service.php';
require_once __DIR__ . '/../includes/attendance_location.php';

function expect_service(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = db();
$userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
if (!$userId) throw new RuntimeException('A local test user is required.');
$originalIp = $_SERVER['REMOTE_ADDR'] ?? null;
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE users SET attendance_location_enabled=1 WHERE id=?')->execute([$userId]);
    $setting = $pdo->prepare('INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    foreach ([
        'office_latitude' => '3.139', 'office_longitude' => '101.6869',
        'office_radius_metres' => '100', 'office_public_ip' => '203.0.113.10',
    ] as $key => $value) $setting->execute([$key, $value]);

    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    $insertAttendance = $pdo->prepare('INSERT INTO attendance (user_id,work_date) VALUES (?,?)');
    $attendanceIds = [];
    foreach (['2098-10-01', '2098-10-02', '2098-10-03'] as $date) {
        $insertAttendance->execute([$userId, $date]);
        $attendanceIds[] = (int) $pdo->lastInsertId();
    }

    $start = fn(int $attendanceId, string $type): int => attendance_location_start($attendanceId, $userId, $type, date('Y-m-d H:i:s'));
    $check = function (int $eventId, string $status, ?string $proximity, string $network) use ($pdo): void {
        $stmt = $pdo->prepare('SELECT location_status,office_proximity,office_network FROM attendance_location_events WHERE id=?');
        $stmt->execute([$eventId]);
        $row = $stmt->fetch();
        expect_service($row && $row['location_status'] === $status && $row['office_proximity'] === $proximity && $row['office_network'] === $network, "$status service state failed");
    };

    $eventId = $start($attendanceIds[0], 'clock_in');
    expect_service(attendance_location_finalize($eventId, $userId, [
        'location_status' => 'captured', 'latitude' => 3.139, 'longitude' => 101.6869, 'accuracy_metres' => 80,
    ]), 'Capture could not be finalized');
    $check($eventId, 'captured', 'within', 'office');

    $eventId = $start($attendanceIds[0], 'clock_out');
    expect_service(attendance_location_finalize($eventId, $userId, [
        'location_status' => 'captured', 'latitude' => 3.15, 'longitude' => 101.6869, 'accuracy_metres' => 180,
    ]), 'Low accuracy could not be finalized');
    $check($eventId, 'low_accuracy', 'outside', 'office');

    $eventId = $start($attendanceIds[1], 'clock_in');
    expect_service(attendance_location_finalize($eventId, $userId, ['location_status' => 'permission_denied']), 'Permission denial could not be finalized');
    $check($eventId, 'permission_denied', null, 'office');

    $_SERVER['REMOTE_ADDR'] = '198.51.100.20';
    $eventId = $start($attendanceIds[1], 'clock_out');
    expect_service(attendance_location_finalize($eventId, $userId, ['location_status' => 'timed_out']), 'Timeout could not be finalized');
    $check($eventId, 'timed_out', null, 'other');

    $eventId = $start($attendanceIds[2], 'clock_in');
    expect_service(attendance_location_finalize($eventId, $userId, ['location_status' => 'unavailable']), 'Unavailable state could not be finalized');
    $check($eventId, 'unavailable', null, 'other');

    $pdo->prepare('UPDATE users SET attendance_location_enabled=0 WHERE id=?')->execute([$userId]);
    $eventId = $start($attendanceIds[2], 'clock_out');
    $check($eventId, 'sharing_disabled', null, 'other');
    echo "Attendance location service states passed; fixtures will be rolled back.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($originalIp === null) unset($_SERVER['REMOTE_ADDR']);
    else $_SERVER['REMOTE_ADDR'] = $originalIp;
}
