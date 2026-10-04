<?php
/**
 * Sidebar navigation
 */

declare(strict_types=1);

$user = current_user();
$activeMenu = $activeMenu ?? '';
$badgeCounts = chrono_badge_counts($user);

/**
 * @param string|null $badgeKey Key used by live poller (leave, incomplete, …)
 */
function nav_item(
    string $key,
    string $href,
    string $icon,
    string $label,
    string $activeMenu,
    ?string $badgeKey = null,
    int $badgeCount = 0
): string {
    $active = $activeMenu === $key ? ' active' : '';
    $badgeAttr = $badgeKey !== null ? ' data-badge-key="' . e($badgeKey) . '"' : '';
    $badgeHtml = '';
    if ($badgeKey !== null) {
        $on = $badgeCount > 0;
        $badgeHtml =
            '<span class="nav-badge' . ($on ? ' is-on' : '') . '"' .
            ' data-badge="' . e($badgeKey) . '"' .
            ($on ? '' : ' hidden') .
            ' aria-label="' . e((string) $badgeCount) . '">' .
            ($on ? e(chrono_format_badge_n($badgeCount)) : '') .
            '</span>';
    }

    return '<a class="nav-link' . $active . '" href="' . e(url($href)) . '"' . $badgeAttr . '>' .
        '<i class="bi ' . e($icon) . '"></i>' .
        '<span class="nav-link__label">' . e($label) . '</span>' .
        $badgeHtml .
        '</a>';
}

$b = static function (string $key) use ($badgeCounts): int {
    return (int) ($badgeCounts[$key] ?? 0);
};

if ($user && $user['role'] === 'admin' && ($isAdminArea ?? true)):
    echo nav_item('dashboard', '/admin/', 'bi-speedometer2', __('dashboard'), $activeMenu);
    echo nav_item('staff', '/admin/staff.php', 'bi-people', __('staff_management'), $activeMenu);
    echo nav_item('today', '/admin/attendance_today.php', 'bi-calendar-check', __('daily_attendance'), $activeMenu);
    echo nav_item(
        'attendance',
        '/admin/attendance.php',
        'bi-journal-text',
        __('attendance_records'),
        $activeMenu,
        'incomplete',
        $b('incomplete')
    );
    echo nav_item('breaks', '/admin/breaks.php', 'bi-cup-hot', __('break_records'), $activeMenu);
    echo nav_item('offday_adjustments', '/admin/offday_adjustments.php', 'bi-arrow-repeat', __('offday_adjustments'), $activeMenu);
    echo nav_item('schedules', '/admin/schedules.php', 'bi-calendar3', __('schedules'), $activeMenu);
    if (setting_bool('enable_leave_module', true)) {
        echo nav_item(
            'leave',
            '/admin/leave_requests.php',
            'bi-calendar2-week',
            __('leave_requests'),
            $activeMenu,
            'leave',
            $b('leave')
        );
    }
    echo nav_item('reports', '/admin/reports.php', 'bi-file-earmark-bar-graph', __('reports'), $activeMenu);
    echo nav_item('settings', '/admin/settings.php', 'bi-gear', __('settings'), $activeMenu);
    echo nav_item('profile', '/admin/profile.php', 'bi-person', __('my_profile'), $activeMenu);
    echo '<div class="d-lg-none">' . nav_item('my_punch_in', '/staff/', 'bi-fingerprint', __('my_punch_in'), $activeMenu) . '</div>';
else:
    echo nav_item('dashboard', '/staff/', 'bi-house', __('dashboard'), $activeMenu);
    echo nav_item('attendance', '/staff/attendance.php', 'bi-calendar-check', __('my_attendance'), $activeMenu);
    echo nav_item('breaks', '/staff/breaks.php', 'bi-cup-hot', __('my_breaks'), $activeMenu);
    echo nav_item('schedule', '/staff/schedule.php', 'bi-calendar3', __('my_schedule'), $activeMenu);
    echo nav_item('policy', '/staff/policy.php', 'bi-journal-check', __('schedules'), $activeMenu);
    if (setting_bool('enable_leave_module', true)) {
        echo nav_item(
            'leave',
            '/staff/leave.php',
            'bi-calendar2-week',
            __('leave_requests'),
            $activeMenu,
            'leave',
            $b('leave')
        );
    }
    echo nav_item('profile', '/staff/profile.php', 'bi-person', __('my_profile'), $activeMenu);
    if ($user && $user['role'] === 'admin') {
        echo nav_item('admin_console', '/admin/', 'bi-shield-lock', __('admin_panel'), $activeMenu);
    }
endif;
