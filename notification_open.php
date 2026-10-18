<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_post()) redirect('/notifications.php');
require_csrf();
$user = current_user();
$stmt = db()->prepare('SELECT id,destination FROM notifications WHERE id=? AND user_id=? LIMIT 1');
$stmt->execute([(int)post('id'), (int)$user['id']]);
$item = $stmt->fetch();
if (!$item) redirect('/notifications.php');
notification_mark_read((int)$user['id'], (int)$item['id']);
redirect(notification_destination($item, (string)$user['role']));
