<?php
/**
 * Staff — My Schedule
 * Simplified, employee-centric schedule view:
 * 1. Content Title: "MY WORK WEEK"
 * 2. Top 3 Hierarchy Cards: Working Hours (10 AM - 8 PM), Work Days (Mon - Sat), Weekly Off (Sunday · DAY OFF)
 * 3. Hero: "THIS WEEK" with 7 daily cards focused on actual daily outcomes, plus weekly target vs actual summary
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$user = current_user();
$userId = (int) $user['id'];
$today = today_str();
$now = now();

// Week offset navigation (0 = current week, -1 = last week, +1 = next week)
$weekOffset = max(-12, min(12, (int) get('week_offset', 0)));

// Compute Monday and Sunday for the target week (ISO week: 1 = Monday, 7 = Sunday)
$currentDayN = (int) $now->format('N');
$targetMonday = (clone $now)->modify('-' . ($currentDayN - 1) . ' days');
if ($weekOffset !== 0) {
    $targetMonday = $targetMonday->modify(($weekOffset > 0 ? '+' : '') . ($weekOffset * 7) . ' days');
}
$weekStart = $targetMonday->format('Y-m-d');
$targetSunday = (clone $targetMonday)->modify('+6 days');
$weekEnd = $targetSunday->format('Y-m-d');

// Sync attendance records for this week to ensure Day Off records exist
sync_attendance_range($userId, $weekStart, $weekEnd);

// Query attendances for this week
$stmt = db()->prepare("SELECT * FROM attendance WHERE user_id = ? AND work_date BETWEEN ? AND ?");
$stmt->execute([$userId, $weekStart, $weekEnd]);
$attendances = [];
foreach ($stmt->fetchAll() as $row) {
    $attendances[$row['work_date']] = $row;
}

// Query approved leaves for this week
$stmt = db()->prepare("SELECT * FROM leave_requests WHERE user_id = ? AND leave_date BETWEEN ? AND ? AND status = 'approved'");
$stmt->execute([$userId, $weekStart, $weekEnd]);
$leaves = [];
foreach ($stmt->fetchAll() as $row) {
    $leaves[$row['leave_date']] = $row;
}

// Get effective schedule for today
$schedule = get_schedule_for_date($userId, $today);
if (!$schedule) {
    $schedule = [
        'start_time' => POLICY_START_TIME,
        'end_time' => POLICY_END_TIME,
        'weekly_off_day' => (int) ($user['weekly_off_day'] ?? 0),
        'work_days' => '1,2,3,4,5,6',
        'target_work_minutes' => POLICY_TARGET_MINUTES,
    ];
}

$offDayNum = (int) ($schedule['weekly_off_day'] ?? 0);
$workDaysArr = array_map('intval', explode(',', (string) ($schedule['work_days'] ?? '1,2,3,4,5,6')));

// Human-friendly working days label
$workDayNames = array_map(fn($d) => weekday_name($d, lang_current()), $workDaysArr);
if (count($workDaysArr) === 6 && $offDayNum === 0) {
    $workDaysSummary = weekday_name(1, lang_current()) . ' — ' . weekday_name(6, lang_current());
} elseif (count($workDaysArr) === 5 && !in_array(0, $workDaysArr, true) && !in_array(6, $workDaysArr, true)) {
    $workDaysSummary = weekday_name(1, lang_current()) . ' — ' . weekday_name(5, lang_current());
} else {
    $workDaysSummary = implode(', ', $workDayNames);
}

// Build 7-day calendar array (Monday to Sunday)
$daysOfWeek = [];
$totalScheduledMins = 0;
$totalWorkedMins = 0;
$completedCount = 0;
$liveWeeklyAttendance = null;
$liveWeeklyBreakStart = null;

for ($i = 0; $i < 7; $i++) {
    $d = (clone $targetMonday)->modify("+$i days");
    $dateKey = $d->format('Y-m-d');
    $dayOfWeek = (int) $d->format('w'); // 0 (Sun) to 6 (Sat)
    $daySchedule = get_schedule_for_date($userId, $dateKey) ?: $schedule;
    $dayOffNum = (int) ($daySchedule['weekly_off_day'] ?? 0);
    $isRestDay = ($dayOfWeek === $dayOffNum);
    $att = $attendances[$dateKey] ?? null;
    $leave = $leaves[$dateKey] ?? null;
    $isToday = ($dateKey === $today);
    $isPast = ($dateKey < $today);
    $isFuture = ($dateKey > $today);
    $isEmployed = (empty($user['joining_date']) || $dateKey >= $user['joining_date'])
        && (empty($user['employment_end_date']) || $dateKey <= $user['employment_end_date']);

    if ($isEmployed && !$isRestDay && (!$leave || $leave['leave_type'] === 'wfh')) {
        $totalScheduledMins += (int) ($daySchedule['target_work_minutes'] ?? 450);
    }
    if ($att) {
        if ($isToday && $att['clock_in'] && !$att['clock_out']) {
            $liveWeeklyAttendance = $att;
            $activeBreak = get_active_break($userId);
            if ($activeBreak && (int) $activeBreak['attendance_id'] === (int) $att['id']) {
                $liveWeeklyBreakStart = (string) $activeBreak['start_time'];
            }
        } else {
            $totalWorkedMins += (int) ($att['work_minutes'] ?? 0);
        }
        if ($att['clock_in'] && $att['clock_out']) {
            $completedCount++;
        }
    }

    $daysOfWeek[] = [
        'date' => $dateKey,
        'day_short' => match (lang_current()) {
            'ms' => ['AHD', 'ISN', 'SEL', 'RAB', 'KHA', 'JUM', 'SAB'][$dayOfWeek],
            'zh' => weekday_name($dayOfWeek, 'zh'),
            default => strtoupper($d->format('D')),
        },
        'day_name' => weekday_name($dayOfWeek, lang_current()),
        'date_day_month' => match (lang_current()) {
            'ms' => $d->format('j') . ' ' . ['Jan', 'Feb', 'Mac', 'Apr', 'Mei', 'Jun', 'Jul', 'Ogo', 'Sep', 'Okt', 'Nov', 'Dis'][(int)$d->format('n') - 1],
            'zh' => $d->format('n月j日'),
            default => $d->format('j M'),
        },
        'day_of_week' => $dayOfWeek,
        'is_rest_day' => $isRestDay,
        'schedule' => $daySchedule,
        'attendance' => $att,
        'leave' => $leave,
        'is_today' => $isToday,
        'is_past' => $isPast,
        'is_future' => $isFuture,
        'is_employed' => $isEmployed,
    ];
}

$pageTitle = __('my_schedule');
$activeMenu = 'schedule';
$bodyClass = 'page-schedule';
$isAdminArea = false;
require __DIR__ . '/../includes/layout_header.php';
?>

<!-- 1. TOP: SINGLE CLEAN TITLE (No duplicate "My Schedule" heading) -->
<div class="schedule-top-bar mb-3">
    <h1 class="h4 mb-1 text-white fw-bold d-flex align-items-center gap-2">
        <i class="bi bi-calendar3 text-accent"></i> <?= e(__('my_work_week')) ?>
    </h1>
    <p class="text-muted small mb-0"><?= e(__('schedule_overview_hint')) ?></p>
</div>

<!-- 2. THREE HIGH-HERARCHY PILLS: VALUE DOMINANT, CLEAR SEPARATION (Desktop ≥ 768px: 100% untouched) -->
<div class="schedule-top-pills d-none d-md-grid mb-4">
    <!-- Card 1: Working Hours -->
    <div class="schedule-top-pill">
        <div class="schedule-top-pill__label">
            <i class="bi bi-clock text-accent"></i> <?= e(__('standard_working_hours')) ?>
        </div>
        <div class="schedule-top-pill__value font-mono">
            <?= e(format_time($today . ' ' . $schedule['start_time'])) ?> — <?= e(format_time($today . ' ' . $schedule['end_time'])) ?>
        </div>
        <div class="schedule-top-pill__sub font-mono">
            <?= e(__('daily_target_hint', ['time' => format_minutes((int) $schedule['target_work_minutes'])])) ?>
        </div>
    </div>

    <!-- Card 2: Work Days -->
    <div class="schedule-top-pill">
        <div class="schedule-top-pill__label">
            <i class="bi bi-calendar-check text-accent"></i> <?= e(__('working_days')) ?>
        </div>
        <div class="schedule-top-pill__value">
            <?= e($workDaysSummary) ?>
        </div>
        <div class="schedule-top-pill__sub">
            <?= e(__('work_days_count', ['count' => count($workDaysArr)])) ?>
        </div>
    </div>

    <!-- Card 3: Weekly Day Off (Strictly Grey / Slate) -->
    <div class="schedule-top-pill schedule-top-pill--off">
        <div class="schedule-top-pill__label">
            <i class="bi bi-moon schedule-moon-icon"></i> <?= e(__('weekly_off')) ?>
        </div>
        <div class="schedule-top-pill__value">
            <?= e(strtoupper(weekday_name($offDayNum, lang_current()))) ?>
        </div>
        <div class="schedule-top-pill__sub">
            <?= e(__('leave_day_off')) ?>
        </div>
    </div>
</div>

<!-- Compact schedule summary for mobile. -->
<div class="schedule-mobile-summary-card d-md-none mb-3 rounded">
    <div class="schedule-mobile-summary-label">
        <i class="bi bi-clock" aria-hidden="true"></i><?= e(__('standard_working_hours')) ?>
    </div>
    <div class="schedule-mobile-summary-hours">
        <?= e(format_time($today . ' ' . $schedule['start_time'])) ?> <span aria-hidden="true">–</span> <?= e(format_time($today . ' ' . $schedule['end_time'])) ?>
    </div>
    <div class="schedule-mobile-summary-target">
        <?= e(__('daily_target_hint', ['time' => format_minutes((int) $schedule['target_work_minutes'])])) ?>
    </div>
    <div class="schedule-mobile-summary-meta">
        <div class="schedule-mobile-summary-fact">
            <span><?= e(__('working_days')) ?></span>
            <strong><?= e($workDaysSummary) ?></strong>
        </div>
        <div class="schedule-mobile-summary-fact">
            <span><?= e(__('weekly_off')) ?></span>
            <strong><?= e(weekday_name($offDayNum, lang_current())) ?></strong>
        </div>
    </div>
</div>

<!-- 3. MAIN HERO: THIS WEEK -->
<div class="card-soft schedule-hero-card mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
        <div>
            <h2 class="h5 mb-0 text-white fw-bold d-flex align-items-center gap-2">
                <i class="bi bi-calendar-week text-accent"></i>
                <?= e($weekOffset === 0 ? __('this_week') : __('week_of', ['date' => format_date($weekStart)])) ?>
            </h2>
            <div class="text-muted small font-mono mt-1">
                <?= e(format_date($weekStart)) ?> — <?= e(format_date($weekEnd)) ?>
            </div>
        </div>
        <div class="schedule-week-nav d-flex flex-column flex-md-row align-items-stretch align-items-md-center gap-2">
            <?php if ($weekOffset !== 0): ?>
                <a href="?week_offset=0" class="btn btn-sm btn-accent schedule-current-week-link text-nowrap w-100 w-md-auto">
                    <i class="bi bi-calendar3 me-1"></i><?= e(__('back_to_current_week')) ?>
                </a>
            <?php endif; ?>
            <div class="btn-group w-100 w-md-auto" role="group" aria-label="<?= e(__('week_navigation')) ?>">
                <a href="?week_offset=<?= $weekOffset - 1 ?>" class="btn btn-sm btn-outline-secondary text-nowrap <?= $weekOffset <= -12 ? 'disabled' : '' ?>" <?= $weekOffset <= -12 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
                    <i class="bi bi-chevron-left me-1"></i><?= e(__('earlier_week')) ?>
                </a>
                <a href="?week_offset=<?= $weekOffset + 1 ?>" class="btn btn-sm btn-outline-secondary text-nowrap <?= $weekOffset >= 12 ? 'disabled' : '' ?>" <?= $weekOffset >= 12 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
                    <?= e(__('later_week')) ?><i class="bi bi-chevron-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <div class="card-body p-3">
        <!-- 7-Column Daily Outcome Cards: Focus entirely on what happened that day -->
        <div class="schedule-calendar-grid">
            <?php foreach ($daysOfWeek as $item): ?>
                <?php
                $cardClasses = ['schedule-day-card'];
                if ($item['is_today']) $cardClasses[] = 'schedule-day-card--today';
                if ($item['is_rest_day']) $cardClasses[] = 'schedule-day-card--off';
                $scheduledTimeRange = str_replace(':00 ', ' ', format_time($item['date'] . ' ' . $item['schedule']['start_time']))
                    . ' – ' . str_replace(':00 ', ' ', format_time($item['date'] . ' ' . $item['schedule']['end_time']));
                ?>
                <div class="<?= implode(' ', $cardClasses) ?>">
                    <!-- Card Header: Day Name + Date -->
                    <div class="schedule-day-header">
                        <span class="schedule-day-name"><?= e($item['day_short']) ?></span>
                        <div>
                            <?php if ($item['is_today']): ?>
                                <span class="schedule-today-pill"><?= e(__('today')) ?></span>
                            <?php else: ?>
                                <span class="schedule-day-date-str"><?= e($item['date_day_month']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Card Body: Status & Real Times (No redundant 10 AM-8 PM repetition) -->
                    <div class="schedule-day-body">
                        <?php if (!$item['is_employed']): ?>
                            <div class="schedule-state-badge badge-scheduled"><?= e(__('not_assigned')) ?></div>
                            <div class="schedule-actual-time text-muted">—</div>
                        <?php elseif ($item['leave'] && $item['leave']['leave_type'] !== 'wfh'): ?>
                            <!-- Approved Leave / WFH -->
                            <div class="schedule-state-badge badge-wfh">
                                <i class="bi bi-calendar2-check me-1"></i> <?= e(__('leave_' . $item['leave']['leave_type'])) ?>
                            </div>
                            <div class="schedule-actual-time text-info"><?= e(__('approved_leave')) ?></div>

                        <?php elseif ($item['is_rest_day']): ?>
                            <!-- Day Off: Pure Grey / Slate -->
                            <div class="schedule-state-badge badge-off">
                                <i class="bi bi-moon schedule-moon-icon me-1"></i> <?= e(__('st_day_off')) ?>
                            </div>
                            <div class="schedule-actual-time text-secondary opacity-75">—</div>

                        <?php elseif ($item['attendance']): ?>
                            <?php
                            $status = (string) $item['attendance']['status'];
                            $clockIn = !empty($item['attendance']['clock_in']) ? format_time($item['attendance']['clock_in']) : null;
                            $clockOut = !empty($item['attendance']['clock_out']) ? format_time($item['attendance']['clock_out']) : null;
                            ?>
                            <?php if ($status === 'completed'): ?>
                                <div class="schedule-state-badge badge-completed">
                                    <i class="bi bi-check-circle-fill me-1"></i> <?= e(__('st_completed')) ?>
                                </div>
                                <div class="schedule-actual-time text-white font-mono">
                                    <?= $clockIn ?> → <?= $clockOut ?>
                                </div>
                            <?php elseif ($status === 'late'): ?>
                                <div class="schedule-state-badge badge-late">
                                    <i class="bi bi-clock-history me-1"></i> <?= e(__('st_late')) ?>
                                </div>
                                <div class="schedule-actual-time text-warning font-mono">
                                    <?= $clockIn ?> → <?= $clockOut ?: e(__('active')) ?>
                                </div>
                            <?php elseif ($status === 'absent'): ?>
                                <div class="schedule-state-badge badge-absent">
                                    <i class="bi bi-x-circle-fill me-1"></i> <?= e(__('st_absent')) ?>
                                </div>
                                <div class="schedule-actual-time schedule-actual-time--hint text-muted font-mono">
                                    <?= e($scheduledTimeRange) ?>
                                </div>
                            <?php elseif ($status === 'present'): ?>
                                <div class="schedule-state-badge badge-present">
                                    <i class="bi bi-person-walking me-1"></i> <?= e(__('st_present')) ?>
                                </div>
                                <div class="schedule-actual-time text-accent font-mono">
                                    <?= e(__('clock_in_col')) ?>: <?= $clockIn ?>
                                </div>
                            <?php elseif ($status === 'on_break'): ?>
                                <div class="schedule-state-badge badge-break">
                                    <i class="bi bi-cup-hot-fill me-1"></i> <?= e(__('st_on_break')) ?>
                                </div>
                                <div class="schedule-actual-time text-warning font-mono">
                                    <?= e(__('clock_in_col')) ?>: <?= $clockIn ?>
                                </div>
                            <?php else: ?>
                                <?= attendance_status_badge($item['attendance']) ?>
                                <div class="schedule-actual-time text-muted font-mono">
                                    <?= $clockIn ? ($clockIn . ' → ' . ($clockOut ?: '—')) : e($scheduledTimeRange) ?>
                                </div>
                            <?php endif; ?>

                        <?php elseif ($item['is_past']): ?>
                            <!-- Past day without attendance = ABSENT -->
                            <div class="schedule-state-badge badge-absent">
                                <i class="bi bi-x-circle-fill me-1"></i> <?= e(__('st_absent')) ?>
                            </div>
                            <div class="schedule-actual-time schedule-actual-time--hint text-muted font-mono">
                                <?= e($scheduledTimeRange) ?>
                            </div>

                        <?php else: ?>
                            <!-- Today before clock-in or Future day -->
                            <div class="schedule-state-badge badge-scheduled">
                                <?= e(__('scheduled')) ?>
                            </div>
                            <div class="schedule-actual-time text-muted font-mono">
                                <?= e($scheduledTimeRange) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Weekly Summary Footer Bar -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 pt-3 mt-3 border-top">
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted"><?= e(__('scheduled_target')) ?>:</span>
                <strong class="text-white font-mono fs-6"><?= e(format_minutes($totalScheduledMins)) ?></strong>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted"><?= e(__('actual_worked')) ?>:</span>
                <strong class="text-accent font-mono fs-6">
                    <?= $liveWeeklyAttendance
                        ? attendance_live_work_html(
                            $liveWeeklyAttendance['clock_in'],
                            null,
                            0,
                            (int) $liveWeeklyAttendance['total_break_minutes'],
                            $liveWeeklyBreakStart,
                            $totalWorkedMins
                        )
                        : e(format_minutes($totalWorkedMins)) ?>
                </strong>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted"><?= e(__('st_completed')) ?>:</span>
                <strong class="text-teal font-mono fs-6"><?= e($completedCount === 1 ? __('schedule_day_single', ['count' => $completedCount]) : __('schedule_days_count', ['count' => $completedCount])) ?></strong>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
