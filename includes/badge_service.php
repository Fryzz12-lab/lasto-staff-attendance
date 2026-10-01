<?php
/**
 * Live sidebar badge counts (pending queues / updates)
 */

declare(strict_types=1);

/**
 * @return array<string, int>
 */
function chrono_badge_counts(?array $user = null): array
{
    $user = $user ?? current_user();
    if (!$user) {
        return [];
    }

    if (($user['role'] ?? '') === 'admin') {
        return chrono_admin_badge_counts();
    }

    return chrono_staff_badge_counts((int) $user['id']);
}

/**
 * @return array<string, int>
 */
function chrono_admin_badge_counts(): array
{
    $pdo = db();
    $leave = 0;
    $incomplete = 0;

    if (setting_bool('enable_leave_module', true)) {
        $leave = (int) $pdo->query(
            "SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'"
        )->fetchColumn();
    }

    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM attendance a
         JOIN users u ON u.id = a.user_id
         WHERE u.role = 'staff' AND u.is_active = 1 AND a.status = 'incomplete'"
    );
    $incomplete = (int) $stmt->fetchColumn();

    return [
        'leave' => $leave,
        'incomplete' => $incomplete,
    ];
}

/**
 * Staff: reviewed leave/WFH decisions not yet opened
 *
 * @return array<string, int>
 */
function chrono_staff_badge_counts(int $userId): array
{
    $leave = 0;
    if (setting_bool('enable_leave_module', true)) {
        $seen = $_SESSION['leave_updates_seen_at'] ?? '1970-01-01 00:00:00';
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM leave_requests
             WHERE user_id = ?
               AND status IN ('approved', 'rejected')
               AND reviewed_at IS NOT NULL
               AND reviewed_at > ?"
        );
        $stmt->execute([$userId, $seen]);
        $leave = (int) $stmt->fetchColumn();
    }

    return [
        'leave' => $leave,
    ];
}

function chrono_mark_leave_updates_seen(): void
{
    $_SESSION['leave_updates_seen_at'] = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur')))
        ->format('Y-m-d H:i:s');
}

function chrono_format_badge_n(int $n): string
{
    if ($n <= 0) {
        return '';
    }
    return $n > 99 ? '99+' : (string) $n;
}
