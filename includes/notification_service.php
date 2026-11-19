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

function notification_format_date(string $value, ?string $language = null): string
{
    $language ??= lang_current();
    $format = strlen($value) > 10 ? '!Y-m-d H:i:s' : '!Y-m-d';
    $date = DateTimeImmutable::createFromFormat($format, $value);
    if (!$date || $date->format(strlen($value) > 10 ? 'Y-m-d H:i:s' : 'Y-m-d') !== $value) return $value;
    if ($language === 'zh') return $date->format('Y年n月j日' . (strlen($value) > 10 ? ' H:i' : ''));
    $months = $language === 'ms'
        ? [1=>'Jan', 'Feb', 'Mac', 'Apr', 'Mei', 'Jun', 'Jul', 'Ogo', 'Sep', 'Okt', 'Nov', 'Dis']
        : [1=>'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return $date->format('j ') . $months[(int)$date->format('n')] . $date->format(' Y' . (strlen($value) > 10 ? ', H:i' : ''));
}

function notification_type_label(string $type, string $language): string
{
    if (!in_array($type, ['day_off','annual','emergency','medical','wfh','other'], true)) return strtoupper($type);
    return translate_for_language('notification_type_' . $type, $language);
}

function notification_status_label(string $status, string $language): string
{
    if (!in_array($status, ['pending','approved','rejected'], true)) return $status;
    return translate_for_language('notification_status_' . $status, $language);
}

function notification_request_record(string $eventKey, string $prefix): ?array
{
    if (!preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/D', $eventKey, $match)) return null;
    try {
        $stmt = db()->prepare('SELECT r.leave_type,r.leave_date,r.status,u.full_name FROM leave_requests r JOIN users u ON u.id=r.user_id WHERE r.id=? LIMIT 1');
        $stmt->execute([(int)$match[1]]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $error) {
        error_log('[Notifications] Request lookup unavailable: ' . $error->getMessage());
        return null;
    }
}

function notification_request_content(string $type, string $date, string $status, string $language, ?string $name = null): array
{
    $typeLabel = notification_type_label($type, $language);
    $values = ['type' => $typeLabel, 'date' => notification_format_date($date, $language), 'name' => (string)$name];
    if ($status === 'submitted') {
        return [
            'title' => translate_for_language('notification_submitted_title', $language, $values),
            'message' => translate_for_language($name !== null ? 'notification_submitted_named_message' : 'notification_submitted_message', $language, $values),
        ];
    }
    $key = $status === 'approved' ? 'notification_request_approved' : 'notification_request_declined';
    return [
        'title' => translate_for_language($key . '_title', $language, $values),
        'message' => translate_for_language($key . '_message', $language, $values),
    ];
}

function notification_localized_content(array $notice, ?string $language = null, bool $includeName = false): array
{
    $language ??= lang_current();
    $type = (string)($notice['event_type'] ?? '');
    $eventKey = (string)($notice['event_key'] ?? '');
    if ($type === 'leave_request' || $type === 'leave_decision') {
        $record = null;
        $prefixes = $type === 'leave_request' ? ['leave-submitted-', 'uat-leave-'] : ['leave-reviewed-', 'uat-decision-'];
        foreach ($prefixes as $prefix) {
            $record = notification_request_record($eventKey, $prefix);
            if ($record) break;
        }
        if ($record && ($type === 'leave_request' || in_array((string)$record['status'], ['approved', 'rejected'], true))) {
            return notification_request_content((string)$record['leave_type'], (string)$record['leave_date'],
                $type === 'leave_request' ? 'submitted' : (string)$record['status'], $language,
                $includeName && $type === 'leave_request' ? (string)$record['full_name'] : null);
        }
        // Older records may not have a resolvable request ID. Interpret only known templates.
        $title = (string)($notice['title'] ?? '');
        $message = (string)($notice['message'] ?? '');
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $message, $dateMatch)
            && preg_match('/^(?:New )?(DAY_OFF|ANNUAL|EMERGENCY|MEDICAL|WFH|OTHER) request(?: (approved|declined))?$/iD', $title, $titleMatch)) {
            $status = $type === 'leave_request' ? 'submitted' : (strtolower($titleMatch[2] ?? '') === 'approved' ? 'approved' : 'rejected');
            return notification_request_content(strtolower($titleMatch[1]), $dateMatch[1], $status, $language);
        }
    }
    if (in_array($type, ['shift_ending','missing_clock_out','break_ending'], true)) {
        return [
            'title' => translate_for_language('notification_' . $type . '_title', $language),
            'message' => translate_for_language('notification_' . $type . '_message', $language),
        ];
    }
    return ['title' => (string)($notice['title'] ?? ''), 'message' => (string)($notice['message'] ?? '')];
}

function notification_localized_push_content(?string $category, ?string $tag, string $title, string $body, string $language): array
{
    $eventType = match ($category) {
        'new_leave_request', 'new_wfh_request' => 'leave_request',
        'leave_decision' => 'leave_decision',
        'shift_ending', 'missing_clock_out', 'break_ending' => $category,
        default => null,
    };
    if ($category === 'test_notification') {
        return ['title' => translate_for_language('notification_test_title', $language),
            'message' => translate_for_language('notification_test_message', $language)];
    }
    if ($eventType === null) return ['title' => $title, 'message' => $body];
    $eventKey = (string)$tag;
    if ($eventType === 'leave_request' && preg_match('/^admin-leave-(\d+)$/D', $eventKey, $match)) {
        $eventKey = 'leave-submitted-' . $match[1];
    } elseif ($eventType === 'leave_decision' && preg_match('/^staff-leave-(\d+)$/D', $eventKey, $match)) {
        $eventKey = 'leave-reviewed-' . $match[1];
    }
    return notification_localized_content(['event_type' => $eventType, 'event_key' => $eventKey,
        'title' => $title, 'message' => $body], $language, true);
}

function notification_list(int $userId, bool $unreadOnly = false, int $limit = 10, int $offset = 0): array
{
    $sql = 'SELECT id,event_type,event_key,title,message,destination,created_at,read_at FROM notifications WHERE user_id=?';
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
    $allowed = $role === 'admin'
        ? ['/admin/leave_requests.php', '/admin/corrections.php']
        : ['/staff/leave.php', '/staff/corrections.php'];
    return in_array($notice['destination'] ?? '', $allowed, true) ? $notice['destination'] : '/notifications.php';
}
