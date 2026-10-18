<?php
declare(strict_types=1);

/** Called inside the Leave/WFH transaction; browser preferences do not affect inbox records. */
function notification_create(int $userId, string $type, string $key, string $title, string $message, string $destination): bool
{
    $stmt = db()->prepare('INSERT INTO notifications (user_id,event_type,event_key,title,message,destination) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id');
    $stmt->execute([$userId, $type, $key, $title, $message, $destination]);
    return $stmt->rowCount() === 1;
}

function notification_unread_count(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function notification_list(int $userId, bool $unreadOnly = false, int $limit = 10, int $offset = 0): array
{
    $sql = 'SELECT id,event_type,title,message,destination,created_at,read_at FROM notifications WHERE user_id=?';
    if ($unreadOnly) $sql .= ' AND read_at IS NULL';
    $sql .= ' ORDER BY created_at DESC,id DESC LIMIT ? OFFSET ?';
    $stmt = db()->prepare($sql);
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function notification_mark_read(int $userId, int $id): void
{
    db()->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND user_id=?')->execute([$id,$userId]);
}

function notification_mark_all_read(int $userId): void
{
    db()->prepare('UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL')->execute([$userId]);
}

function notification_destination(array $notice, string $role): string
{
    // Resolve only known destinations. Stale or tampered rows go to the inbox.
    $expected = $role === 'admin' ? '/admin/leave_requests.php' : '/staff/leave.php';
    return $notice['destination'] === $expected ? $expected : '/notifications.php';
}
