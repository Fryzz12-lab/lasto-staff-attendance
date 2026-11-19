<?php
/** Isolated device-preference integration test; no push delivery. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/push_service.php';

$checks = 0;
function verify_push(bool $ok, string $description): void {
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
    verify_push($pdo->query('SELECT DATABASE()')->fetchColumn() === $database, 'Disposable database selected');
    $pdo->prepare('INSERT INTO users (employee_id,username,password_hash,full_name,role,is_active,weekly_off_day,notification_preferences) VALUES (?,?,?,?,?,?,?,?)')
        ->execute(['push_fixture', 'push_fixture', 'unused', 'Push fixture', 'staff', 1, 0, json_encode(['break_ending' => false])]);
    $userId = (int)$pdo->lastInsertId();
    $a = 'https://fcm.googleapis.com/fcm/send/agy-test-a';
    $b = 'https://fcm.googleapis.com/fcm/send/agy-test-b';
    verify_push(push_subscribe_device($userId, $a, 'test-key', 'test-auth'), 'Device A registered');
    verify_push(push_subscribe_device($userId, $b, 'test-key', 'test-auth'), 'Device B registered');
    $deviceA = push_find_device($userId, $a);
    $deviceB = push_find_device($userId, $b);
    verify_push($deviceA !== null && $deviceB !== null, 'Both endpoints belong to staff');
    $aPrefs = push_get_device_preferences($deviceA, push_get_preferences($userId));
    $bPrefs = push_get_device_preferences($deviceB, push_get_preferences($userId));
    verify_push($aPrefs['all'] && $bPrefs['all'], 'Both devices enabled');
    verify_push(!$aPrefs['break_ending'] && !$bPrefs['break_ending'], 'Account category default copied to new devices');
    verify_push(push_set_device_preferences($userId, $a, ['break_ending' => true, 'leave_decision' => false]) !== null, 'Device A override saved');
    $aPrefs = push_get_device_preferences(push_find_device($userId, $a), push_get_preferences($userId));
    $bPrefs = push_get_device_preferences(push_find_device($userId, $b), push_get_preferences($userId));
    verify_push($aPrefs['break_ending'] && !$aPrefs['leave_decision'], 'Device A categories persist after reload');
    verify_push(!$bPrefs['break_ending'] && $bPrefs['leave_decision'], 'Device B categories unaffected');
    verify_push(push_subscribe_device($userId, $a, 'refreshed-key', 'refreshed-auth'), 'Device A key refreshed');
    $aPrefs = push_get_device_preferences(push_find_device($userId, $a), push_get_preferences($userId));
    verify_push($aPrefs['break_ending'] && !$aPrefs['leave_decision'], 'Refresh preserves category choices');
    verify_push(push_update_preferences($userId, ['break_ending' => true, 'leave_decision' => false]), 'Account defaults updated independently');
    $bPrefs = push_get_device_preferences(push_find_device($userId, $b), push_get_preferences($userId));
    verify_push(!$bPrefs['break_ending'] && $bPrefs['leave_decision'], 'Changed account defaults do not alter registered Device B');
    $c = 'https://fcm.googleapis.com/fcm/send/agy-test-c';
    verify_push(push_subscribe_device($userId, $c, 'test-key', 'test-auth'), 'New Device C registered');
    $cPrefs = push_get_device_preferences(push_find_device($userId, $c), push_get_preferences($userId));
    verify_push($cPrefs['break_ending'] && !$cPrefs['leave_decision'], 'New device receives current account defaults');
    verify_push(push_set_device_preferences($userId, '', ['all' => false]) === null, 'Missing endpoint cannot update preferences');
    verify_push(push_set_device_preferences($userId, $a, ['unknown' => false]) === null, 'Unknown category rejected');
    verify_push(push_set_device_preferences($userId, $a, ['break_ending' => 'false']) === null, 'Non-boolean category rejected');
    verify_push(push_unsubscribe_device($userId, $a), 'Device A unsubscribed');
    verify_push(push_find_device($userId, $a) === null && push_find_device($userId, $b) !== null, 'Unsubscribe leaves Device B registered');
    verify_push(push_unsubscribe_device($userId, $c), 'Device C unsubscribed independently');
    verify_push(push_get_preferences($userId)['break_ending'] === true, 'Unsubscribe leaves account defaults unchanged');
    verify_push((int)$pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn() === 1, 'Only Device B remains');
    echo "Push preferences: {$checks}/{$checks} passed\n";
} finally {
    $pdo->exec('USE `' . DB_NAME . '`');
    $pdo->exec('DROP DATABASE `' . $database . '`');
}
