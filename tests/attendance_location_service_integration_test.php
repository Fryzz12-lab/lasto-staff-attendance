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
    $pdo->prepare('UPDATE users SET attendance_location_enabled=1, office_id=NULL WHERE id=?')->execute([$userId]);
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

    // Multi-Office Verification:
    // 1. Create a second branch (Penang Branch)
    $pdo->prepare('INSERT INTO offices (name, code, latitude, longitude, radius_meters, public_ip, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute(['LASTO Penang Branch', 'PNG', '5.4164000', '100.3327000', 100, '203.0.113.55', 1]);
    $branch2Id = (int) $pdo->lastInsertId();

    // 2. Re-enable location and assign user to Penang Branch
    $pdo->prepare('UPDATE users SET attendance_location_enabled=1, office_id=? WHERE id=?')->execute([$branch2Id, $userId]);

    $insertAttendance->execute([$userId, '2098-10-04']);
    $multiAttId = (int) $pdo->lastInsertId();

    // 3. User checks in near Penang Branch (within 10m)
    $eventIdBranch = $start($multiAttId, 'clock_in');
    expect_service(attendance_location_finalize($eventIdBranch, $userId, [
        'location_status' => 'captured', 'latitude' => 5.416405, 'longitude' => 100.332705, 'accuracy_metres' => 15,
    ]), 'Penang branch check-in finalize failed');

    $eventRow = $pdo->query("SELECT office_id, distance_metres, office_proximity FROM attendance_location_events WHERE id = {$eventIdBranch}")->fetch();
    expect_service((int) $eventRow['office_id'] === $branch2Id, 'Evaluated office_id must match assigned Penang branch');
    expect_service($eventRow['office_proximity'] === 'within', 'Clock-in at Penang coordinates must evaluate to within');
    expect_service((float) $eventRow['distance_metres'] <= 15.0, 'Distance must accurately reflect proximity in metres');

    // 4. User is in KL while assigned to Penang (policy: assigned_only)
    $setting->execute(['office_attendance_policy', 'assigned_only']);
    $eventIdKL = $start($multiAttId, 'clock_out');
    expect_service(attendance_location_finalize($eventIdKL, $userId, [
        'location_status' => 'captured', 'latitude' => 3.139000, 'longitude' => 101.686900, 'accuracy_metres' => 20,
    ]), 'KL clock-out finalize failed');

    $eventRowKL = $pdo->query("SELECT office_id, distance_metres, office_proximity FROM attendance_location_events WHERE id = {$eventIdKL}")->fetch();
    expect_service($eventRowKL['office_proximity'] === 'outside', 'User far from assigned branch must evaluate to outside');
    expect_service((float) $eventRowKL['distance_metres'] > 250000, 'Distance should reflect ~290km from Penang');

    // 5. Test flexible roving policy (policy: any_branch)
    // Make sure HQ has coordinates set in offices table
    $pdo->prepare('UPDATE offices SET latitude=3.139000, longitude=101.686900, is_active=1 WHERE id=1')->execute();
    $setting->execute(['office_attendance_policy', 'any_branch']);

    $insertAttendance->execute([$userId, '2098-10-05']);
    $rovingAttId = (int) $pdo->lastInsertId();
    $eventIdRoving = $start($rovingAttId, 'clock_in');
    expect_service(attendance_location_finalize($eventIdRoving, $userId, [
        'location_status' => 'captured', 'latitude' => 3.139000, 'longitude' => 101.686900, 'accuracy_metres' => 20,
    ]), 'Roving check-in finalize failed');

    $eventRowRoving = $pdo->query("SELECT office_id, distance_metres, office_proximity FROM attendance_location_events WHERE id = {$eventIdRoving}")->fetch();
    expect_service($eventRowRoving['office_proximity'] === 'within', 'Roving policy must allow check-in at recognised HQ branch');
    expect_service((int) $eventRowRoving['office_id'] === 1, 'Roving check-in should identify and record HQ office ID');

    echo "Attendance location service & multi-branch states passed; fixtures will be rolled back.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($originalIp === null) unset($_SERVER['REMOTE_ADDR']);
    else $_SERVER['REMOTE_ADDR'] = $originalIp;
}
