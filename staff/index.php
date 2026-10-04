<?php
/**
 * Staff Dashboard — Streamlined Operational View
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$user = current_user();
if ($user['role'] === 'admin') {
    // Admins using staff dashboard for own attendance is fine
}

mark_incomplete_attendances();
$data = staff_dashboard_data((int) $user['id']);
$att = $data['attendance'];
$schedule = $data['schedule'];
$activeBreak = $data['active_break'];
$usage = $data['break_usage'];
$breaks = $data['breaks'];
$approvedLeaveDay = has_approved_leave((int) $user['id'], $data['date']);
$scheduledOffDay = !$approvedLeaveDay && (!empty($att['was_scheduled_off_day']) || ($schedule && is_scheduled_off($schedule, $data['date'])));

$pageTitle = __('dashboard');
$activeMenu = 'dashboard';
$bodyClass = 'staff-dashboard page-dashboard';
$isAdminArea = false;

// Calculate any overruns across today's break sessions
$overruns = [];
foreach ($breaks as $b) {
    if (!empty($b['exceeded']) || (!empty($b['overrun_minutes']) && (int) $b['overrun_minutes'] > 0)) {
        $ovMins = (int) ($b['overrun_minutes'] ?? 0);
        if ($ovMins <= 0 && !empty($b['duration_minutes']) && !empty($b['allowed_minutes'])) {
            $ovMins = max(0, (int) $b['duration_minutes'] - (int) $b['allowed_minutes']);
        }
        $overruns[] = [
            'type' => break_type_name($b['break_type']),
            'minutes' => $ovMins,
        ];
    }
}

// Build session-based activity list for today
$breakTypeCounts = [];
foreach ($breaks as $b) {
    $t = $b['break_type'];
    $breakTypeCounts[$t] = ($breakTypeCounts[$t] ?? 0) + 1;
}

$activities = [];
if (!empty($att['clock_in'])) {
    $isLate = !empty($att['late_minutes']) && (int) $att['late_minutes'] > 0;
    $activities[] = [
        'ts' => strtotime($att['clock_in']),
        'time_display' => format_time($att['clock_in']),
        'activity' => __('clock_in'),
        'icon' => 'bi-box-arrow-in-right',
        'icon_type' => 'in',
        'duration' => '—',
        'badge' => $isLate ? __('badge_late_arrival') : __('badge_on_time'),
        'badge_color' => $isLate ? 'red' : 'green',
        'sub' => $isLate ? '+' . format_minutes((int) $att['late_minutes']) : null,
    ];
}

foreach ($breaks as $b) {
    $bType = $b['break_type'];
    $baseName = match ($bType) {
        'lunch' => 'Lunch Break',
        'solat' => 'Solat Break',
        'short' => 'Short Break',
        default => ucfirst($bType) . ' Break',
    };
    if (lang_current() !== 'en') {
        $baseName = break_type_name($bType);
    }
    $title = ($breakTypeCounts[$bType] > 1) ? "{$baseName} #{$b['sequence_no']}" : $baseName;
    $isActive = empty($b['end_time']);
    $startTimeStr = format_time($b['start_time']);

    if ($isActive) {
        $timeDisplay = "{$startTimeStr} – Present";
        $durText = format_minutes(elapsed_minutes_since($b['start_time']));
        $badge = __('badge_in_progress');
        $badgeColor = 'amber';
        $sub = null;
    } else {
        $endTimeStr = format_time($b['end_time']);
        $timeDisplay = "{$startTimeStr} – {$endTimeStr}";
        $dur = (int) $b['duration_minutes'];
        $durText = format_minutes($dur);
        $isExceeded = !empty($b['exceeded']);
        $ovMins = (int) ($b['overrun_minutes'] ?? 0);
        if ($isExceeded) {
            $ovActual = $ovMins > 0 ? $ovMins : max(0, $dur - (int) $b['allowed_minutes']);
            $badge = __('badge_over_limit');
            $badgeColor = 'red';
            $sub = '+' . format_minutes($ovActual);
        } else {
            $badge = __('badge_within_limit');
            $badgeColor = 'green';
            $sub = null;
        }
    }

    $activities[] = [
        'ts' => strtotime($b['start_time']),
        'time_display' => $timeDisplay,
        'activity' => $title,
        'icon' => match ($bType) {
            'lunch' => 'bi-cup-hot-fill',
            'solat' => 'bi-moon-stars-fill',
            default => 'bi-pause-circle-fill',
        },
        'icon_type' => $bType,
        'duration' => $durText,
        'badge' => $badge,
        'badge_color' => $badgeColor,
        'sub' => $sub,
    ];
}

if (!empty($att['clock_out'])) {
    $activities[] = [
        'ts' => strtotime($att['clock_out']),
        'time_display' => format_time($att['clock_out']),
        'activity' => __('clock_out'),
        'icon' => 'bi-box-arrow-right',
        'icon_type' => 'out',
        'duration' => format_minutes((int) $att['shift_minutes']),
        'badge' => __('badge_completed'),
        'badge_color' => 'green',
        'sub' => format_minutes((int) $att['work_minutes']) . ' total',
    ];
}

usort($activities, fn($a, $b) => $a['ts'] <=> $b['ts']);

require __DIR__ . '/../includes/layout_header.php';
?>

<!-- Compact Header: Greeting & Integrated Status Strip -->
<div class="dash-compact-header mb-3">
    <div class="dash-greeting-block">
        <h2 class="dash-greeting-title mb-1"><?= e(greeting_text()) ?>, <?= e(greeting_display_name($user['full_name'])) ?></h2>
        <div class="dash-date-line text-muted">
            <strong class="text-white-emphasis fw-bold"><?= e(format_date($data['date'])) ?></strong>
            <span class="opacity-75"> · <?= e(weekday_name((int) now()->format('w'), lang_current())) ?></span>
        </div>
    </div>
    <!-- Desktop 3-pill meta strip -->
    <div class="dash-meta-strip d-none d-md-flex">
        <div class="dash-meta-pill">
            <span class="meta-pill-label"><?= e(__('schedule')) ?></span>
            <span class="meta-pill-val">
                <?php if ($data['is_day_off']): ?>
                    <?= e(__('st_day_off')) ?>
                <?php elseif ($schedule): ?>
                    <?= e(format_time($data['date'] . ' ' . $schedule['start_time'])) ?> – <?= e(format_time($data['date'] . ' ' . $schedule['end_time'])) ?>
                <?php else: ?>
                    <?= e(__('not_assigned')) ?>
                <?php endif; ?>
            </span>
        </div>
        <div class="dash-meta-pill">
            <span class="meta-pill-label"><?= e(__('current_status')) ?></span>
            <span class="meta-pill-val"><?= attendance_status_badge($att) ?></span>
        </div>
        <?php if (!$data['is_day_off'] && $schedule): ?>
            <div class="dash-meta-pill">
                <span class="meta-pill-label"><?= e(__('daily_target')) ?></span>
                <span class="meta-pill-val font-monospace"><?= e(format_minutes((int) ($schedule['target_work_minutes'] ?? 450))) ?> total</span>
            </div>
        <?php endif; ?>
    </div>
    <!-- Mobile Integrated Context Strip (Item 1: Schedule, Target & Current Status grouped together) -->
    <div class="dash-mobile-context-strip d-md-none mt-2 p-2 px-3 rounded">
        <div class="d-flex align-items-center justify-content-between">
            <div class="mobile-context-info">
                <?php if ($data['is_day_off']): ?>
                    <span class="text-muted small"><i class="bi bi-calendar-x me-1"></i><?= e(__('st_day_off')) ?></span>
                <?php elseif ($schedule): ?>
                    <div class="context-sched font-monospace fw-semibold text-white-emphasis small">
                        <?= e(format_time($data['date'] . ' ' . $schedule['start_time'])) ?> – <?= e(format_time($data['date'] . ' ' . $schedule['end_time'])) ?>
                    </div>
                    <div class="context-target text-muted small">
                        <?= e(format_minutes((int) ($schedule['target_work_minutes'] ?? 450))) ?> target
                    </div>
                <?php else: ?>
                    <span class="text-muted small"><?= e(__('not_assigned')) ?></span>
                <?php endif; ?>
            </div>
            <div class="mobile-context-status">
                <?= attendance_status_badge($att) ?>
            </div>
        </div>
    </div>
</div>

<?php if (!$att): ?>
    <div class="dash-hero-panel dash-hero-off mb-4">
        <h3 class="h5 text-white-emphasis"><?= e(__('not_assigned')) ?></h3>
        <p class="text-muted mb-0"><?= e(__('employment_date_unavailable')) ?></p>
    </div>
<?php elseif ($approvedLeaveDay): ?>
    <!-- State: Scheduled Day Off -->
    <div class="dash-hero-panel dash-hero-off mb-4">
        <div class="d-flex align-items-center gap-3 mb-2">
            <span class="status-badge badge-off fs-6 px-3 py-1"><?= e(__('st_day_off')) ?></span>
            <h3 class="h5 mb-0 text-white-emphasis"><?= e(__('day_off_message')) ?></h3>
        </div>
        <p class="text-muted mb-0 small"><?= e(__('policy_changes_hint')) ?></p>
    </div>

<?php elseif (!$att['clock_in']): ?>
    <!-- State: Ready to Clock In -->
    <div class="dash-hero-panel dash-hero-ready mb-4">
        <div class="dash-hero-status-row d-flex flex-wrap justify-content-between align-items-center mb-3">
            <div class="dash-hero-status-title d-flex align-items-center gap-2">
                <span class="live-pulse-dot bg-secondary"></span>
                <span class="h5 mb-0 text-white-emphasis text-uppercase fw-bold"><?= e(__('ready_to_start')) ?></span>
            </div>
            <div class="dash-hero-status-meta text-muted small">
                <?= e(__('server_time')) ?>: <span class="font-monospace text-light" data-live-server-time><?= e(now()->format('g:i A')) ?></span>
            </div>
        </div>
        <p class="text-muted mb-4 fs-6">
            <?php if ($scheduledOffDay): ?>
                This is your scheduled day off. If you are working today, clock in normally; an administrator will review the day later.
            <?php else: ?>
                <?= e(__('scheduled_start_hint', [
                    'time' => $schedule ? format_time($data['date'] . ' ' . $schedule['start_time']) : '10:00 AM',
                ])) ?>
            <?php endif; ?>
        </p>
        <button type="button" class="btn btn-accent btn-action-lg px-5 py-3 fw-bold fs-5 shadow-sm"
                data-action="clock_in"
                data-confirm="<?= e(__('confirm_clock_in')) ?>"
                data-confirm-title="<?= e(__('clock_in')) ?>"
                data-confirm-label="<?= e(__('clock_in')) ?>">
            <i class="bi bi-box-arrow-in-right me-2"></i><?= e(__('clock_in')) ?>
        </button>
    </div>

<?php elseif ($activeBreak): ?>
    <!-- State: Currently on Break -->
    <div class="dash-hero-panel dash-hero-break mb-4">
        <div class="dash-hero-status-row d-flex flex-wrap justify-content-between align-items-center mb-3">
            <div class="dash-hero-status-title d-flex align-items-center gap-2">
                <span class="live-pulse-dot bg-warning"></span>
                <span class="h5 mb-0 text-warning text-uppercase fw-bold">
                    <?= e(__('on_break_title', ['label' => break_label($activeBreak['break_type'], (int) $activeBreak['sequence_no'])])) ?>
                </span>
            </div>
            <div class="dash-hero-status-meta text-muted small">
                <?= e(__('started')) ?>: <span class="text-light fw-semibold"><?= e(format_time($activeBreak['start_time'])) ?></span>
            </div>
        </div>

        <div class="dash-hero-metrics mb-4">
            <div class="dash-metric-item">
                <div class="dash-metric-label"><?= e(__('time_on_break')) ?></div>
                <div class="dash-metric-value text-warning font-monospace" data-live-start="<?= e(datetime_iso_kl($activeBreak['start_time'])) ?>">
                    <?= e(format_minutes(elapsed_minutes_since($activeBreak['start_time']))) ?>
                </div>
                <div class="dash-metric-sub text-muted small">Active break session</div>
            </div>
            <div class="dash-metric-item">
                <div class="dash-metric-label"><?= e(__('allocated_break_time')) ?></div>
                <div class="dash-metric-value font-monospace text-light"><?= e(format_minutes((int) $activeBreak['allowed_minutes'])) ?></div>
                <div class="dash-metric-sub text-muted small"><?= e(__('allocated_break_hint')) ?></div>
            </div>
            <?php $estEndTs = strtotime($activeBreak['start_time']) + ((int) $activeBreak['allowed_minutes'] * 60); ?>
            <div class="dash-metric-item">
                <div class="dash-metric-label"><?= e(__('est_break_end')) ?></div>
                <div class="dash-metric-value font-monospace text-light"><?= e(date('g:i A', $estEndTs)) ?></div>
                <div class="dash-metric-sub text-muted small">Expected return time</div>
            </div>
            <div class="dash-metric-item">
                <div class="dash-metric-label"><?= e(__('total_working_time')) ?></div>
                <div class="dash-metric-value font-monospace text-muted">
                    <?= attendance_live_work_html($att['clock_in'], $att['clock_out'], (int) ($att['work_minutes'] ?? 0), (int) ($att['total_break_minutes'] ?? 0), (string) $activeBreak['start_time']) ?>
                </div>
                <div class="dash-metric-sub text-muted small"><?= e(__('working_time_paused')) ?></div>
            </div>
        </div>

        <div class="dash-hero-action-footer d-flex flex-wrap align-items-center justify-content-between pt-3 border-top border-secondary-subtle gap-3">
            <div class="text-muted small">
                <i class="bi bi-info-circle me-1 text-warning"></i> Click End Break when you return to your workstation.
            </div>
            <button type="button" class="btn btn-warning btn-action-lg px-4 py-2 fw-bold shadow-sm"
                    data-action="end_break"
                    data-confirm="<?= e(__('confirm_end_break')) ?>"
                    data-confirm-title="<?= e(__('end_break')) ?>"
                    data-confirm-label="<?= e(__('end_break')) ?>">
                <i class="bi bi-stop-circle me-2"></i><?= e(__('end_break')) ?>
            </button>
        </div>
    </div>

<?php elseif ($att['clock_out']): ?>
    <!-- State: Shift Completed (Neutral tone + exception warning) -->
    <div class="dash-hero-panel dash-hero-completed mb-3">
        <div class="dash-hero-status-row d-flex align-items-center justify-content-between mb-2">
            <div class="dash-hero-status-title d-flex align-items-center gap-2">
                <span class="live-pulse-dot bg-success"></span>
                <span class="h5 mb-0 text-white-emphasis text-uppercase fw-bold"><?= e(__('shift_completed')) ?></span>
            </div>
            <div class="dash-hero-status-meta text-muted small d-none d-md-block">
                <?= e(__('server_time')) ?>: <span data-live-server-time><?= e(now()->format('g:i A')) ?></span>
            </div>
        </div>

        <!-- Desktop 4-column metric grid -->
        <div class="dash-hero-metrics mb-3 d-none d-md-grid">
            <div class="dash-metric-item">
                <div class="dash-metric-label"><?= e(__('clock_in_col')) ?></div>
                <div class="dash-metric-value font-monospace text-light"><?= e(format_time($att['clock_in'])) ?></div>
            </div>
            <div class="dash-metric-item">
                <div class="dash-metric-label"><?= e(__('clock_out_col')) ?></div>
                <div class="dash-metric-value font-monospace text-light"><?= e(format_time($att['clock_out'])) ?></div>
            </div>
            <div class="dash-metric-item">
                <div class="dash-metric-label"><?= e(__('actual_working_time')) ?></div>
                <div class="dash-metric-value font-monospace text-accent"><?= e(format_minutes((int) $att['work_minutes'])) ?></div>
            </div>
            <div class="dash-metric-item">
                <div class="dash-metric-label"><?= e(__('shift_duration')) ?></div>
                <div class="dash-metric-value font-monospace text-muted"><?= e(format_minutes((int) $att['shift_minutes'])) ?></div>
            </div>
        </div>

        <!-- Mobile Optimized Summary: Time span -> Late warning directly under time -> Actual work -->
        <div class="dash-hero-mobile-summary d-md-none py-1">
            <div class="mobile-summary-span font-monospace fs-5 fw-bold text-white-emphasis mb-3">
                <?= e(format_time($att['clock_in'])) ?> <span class="text-muted fs-6">→</span> <?= e(format_time($att['clock_out'])) ?>
            </div>
            <div class="dash-mobile-metrics-grid">
                <div class="mobile-metric-col">
                    <span class="mobile-metric-label text-muted small d-block"><?= e(__('actual_work')) ?></span>
                    <span class="mobile-metric-val font-monospace text-accent fw-bold fs-5"><?= e(format_minutes((int) $att['work_minutes'])) ?></span>
                </div>
                <div class="mobile-metric-col">
                    <span class="mobile-metric-label text-muted small d-block"><?= e(__('shift_duration')) ?></span>
                    <span class="mobile-metric-val font-monospace text-light fw-semibold fs-6"><?= e(format_minutes((int) $att['shift_minutes'])) ?></span>
                </div>
                <div class="mobile-metric-col">
                    <span class="mobile-metric-label text-muted small d-block"><?= e(__('breaks')) ?></span>
                    <span class="mobile-metric-val font-monospace text-muted fw-semibold fs-6"><?= e(format_minutes((int) ($att['total_break_minutes'] ?? 0))) ?></span>
                </div>
            </div>
        </div>

        <p class="text-muted small mb-0 d-none d-md-block"><i class="bi bi-check2-circle text-success me-1"></i><?= e(__('shift_vs_work_hint')) ?></p>
    </div>

<?php else: ?>
    <!-- State: Currently Working (Primary Hero) -->
    <div class="dash-hero-panel dash-hero-working mb-4">
        <div class="dash-hero-status-row d-flex flex-wrap justify-content-between align-items-center mb-3">
            <div class="dash-hero-status-title d-flex align-items-center gap-2">
                <span class="live-pulse-dot bg-success"></span>
                <span class="dash-working-title h5 mb-0 text-white-emphasis text-uppercase fw-bold">
                    <?= e(__('youre_currently_working')) ?>
                </span>
            </div>
            <div class="dash-hero-status-meta text-muted small">
                <?= e(__('clock_in_col')) ?> <span class="text-white-emphasis fw-semibold"><?= e(format_time($att['clock_in'])) ?></span>
                <?php if (!empty($att['late_minutes'])): ?>
                    <span class="text-warning ms-1">(<?= e(__('late_by', ['time' => format_minutes((int) $att['late_minutes'])])) ?>)</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Desktop metrics grid -->
        <div class="dash-hero-metrics mb-3 d-none d-md-grid">
            <div class="dash-metric-item">
                <div class="dash-metric-label"><?= e(__('total_working_time')) ?></div>
                <div class="dash-metric-value dash-metric-hero-work text-accent font-monospace">
                    <?= attendance_live_work_html(
                        $att['clock_in'],
                        $att['clock_out'],
                        (int) ($att['work_minutes'] ?? 0),
                        (int) ($att['total_break_minutes'] ?? 0),
                        null
                    ) ?>
                </div>
                <div class="dash-metric-sub text-muted small"><?= e(__('actual_working_time')) ?></div>
            </div>
            <div class="dash-metric-item">
                <div class="dash-metric-label"><?= e(__('daily_target')) ?></div>
                <div class="dash-metric-value font-monospace">
                    <?= e(format_minutes((int) ($schedule['target_work_minutes'] ?? 450))) ?>
                </div>
                <div class="dash-metric-sub text-muted small">Total target</div>
            </div>
            <div class="dash-metric-item">
                <div class="dash-metric-label"><?= e(__('breaks')) ?></div>
                <div class="dash-metric-value font-monospace">
                    <?= count($breaks) ?> <span class="fs-6 fw-normal text-muted"><?= count($breaks) === 1 ? e(__('break_singular')) : e(__('break_plural')) ?></span>
                </div>
                <div class="dash-metric-sub text-muted small">
                    <?= attendance_live_breaks_html((int) ($att['total_break_minutes'] ?? 0), null) ?> total
                </div>
            </div>
        </div>

        <!-- Mobile Compressed Summary -->
        <div class="dash-hero-mobile-summary d-md-none py-2 mb-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div>
                    <span class="text-muted small d-block"><?= e(__('actual_working_time')) ?></span>
                    <div class="font-monospace fs-4 fw-bold text-accent">
                        <?= attendance_live_work_html(
                            $att['clock_in'],
                            $att['clock_out'],
                            (int) ($att['work_minutes'] ?? 0),
                            (int) ($att['total_break_minutes'] ?? 0),
                            null
                        ) ?>
                    </div>
                </div>
                <div class="text-end">
                    <span class="text-muted small d-block"><?= e(__('daily_target')) ?></span>
                    <span class="font-monospace text-light fw-bold fs-6">
                        <?= e(format_minutes((int) ($schedule['target_work_minutes'] ?? 450))) ?>
                    </span>
                    <div class="text-muted small">
                        <?= count($breaks) ?> <?= count($breaks) === 1 ? e(__('break_singular')) : e(__('break_plural')) ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($overruns)): ?>
            <div class="dash-overrun-banner mb-3">
                <div class="d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle-fill text-danger me-2 fs-5"></i>
                    <div>
                        <strong class="text-danger"><?= e(__('break_overrun')) ?>:</strong>
                        <span class="text-white-emphasis ms-1">
                            <?php
                            $ovList = [];
                            foreach ($overruns as $ov) {
                                $ovList[] = e($ov['type']) . ' exceeded by ' . e(format_minutes($ov['minutes']));
                            }
                            echo implode(', ', $ovList);
                            ?>
                        </span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="dash-hero-divider mb-3"></div>

        <div class="dash-action-row d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="dash-break-actions-group">
                <div class="dash-action-label text-muted small fw-semibold text-uppercase tracking-wider mb-2">
                    <i class="bi bi-cup-hot me-1 text-accent"></i><?= e(__('available_breaks')) ?>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach (break_limits() as $type => $rule): ?>
                        <?php
                            $canStart = !empty($usage[$type]['can_start']);
                            $used = (int) ($usage[$type]['used_count'] ?? 0);
                            $max = (int) $rule['max_sessions'];
                            $remaining = max(0, $max - $used);
                            $label = $type === 'lunch' ? break_type_name($type) : break_label($type, $used + 1);
                            $duration = format_minutes((int) $rule['suggest_session']);
                            $estMinutes = (int) $rule['suggest_session'];
                            $estTime = now()->modify("+{$estMinutes} minutes")->format('g:i A');
                            $icon = match($type) {
                                'lunch' => '🍱',
                                'solat' => '🕌',
                                default => '☕',
                            };
                        ?>
                        <button type="button"
                            class="btn dash-btn-break dash-btn-break-<?= e($type) ?>"
                            data-action="start_break"
                            data-type="<?= e($type) ?>"
                            data-confirm="<?= e(__('confirm_start_break_type', ['type' => $label])) ?>"
                            data-confirm-title="<?= e(__('start_break_button') . ' ' . $label) ?>"
                            data-confirm-label="<?= e(__('start_break_button') . ' ' . $label) ?>"
                            <?= $canStart ? '' : 'disabled' ?>
                            title="<?= $canStart ? e(__('est_break_end')) . ' ~' . e($estTime) : 'Session limit reached' ?>">
                            <span class="break-btn-icon"><?= $icon ?></span>
                            <span class="break-btn-content">
                                <span class="break-btn-title"><?= e(__('start_break_button')) ?> <?= e($label) ?> · <?= e($duration) ?></span>
                                <span class="break-btn-sub"><?= $remaining ?> remaining</span>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="dash-clockout-group ms-sm-auto pt-2 pt-sm-0">
                <div class="dash-action-label text-muted small fw-semibold text-uppercase tracking-wider mb-2 text-sm-end invisible d-none d-sm-block">
                    Action
                </div>
                <button type="button" class="btn btn-clock-out"
                        data-action="clock_out"
                        data-confirm="<?= e(__('confirm_clock_out')) ?>"
                        data-confirm-title="<?= e(__('clock_out')) ?>"
                        data-confirm-label="<?= e(__('clock_out')) ?>">
                    <i class="bi bi-box-arrow-right me-2"></i><?= e(__('clock_out')) ?>
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>




<!-- Today's Activity (Executive Session Feed) -->
<?php if (!empty($att['clock_in']) || !empty($activities)): ?>
<div class="dash-activity-card">
    <div class="dash-activity-header d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom">
        <h3 class="dash-activity-title h5 mb-0 d-flex align-items-center gap-2">
            <i class="bi bi-clock-history text-accent"></i>
            <span><?= e(__('todays_activity')) ?></span>
        </h3>
        <a href="<?= e(url('staff/attendance.php')) ?>" class="dash-view-more-link">
            <?= e(__('view_history')) ?> <i class="bi bi-arrow-right ms-1"></i>
        </a>
    </div>

    <?php if (empty($activities)): ?>
        <div class="dash-activity-empty py-4 text-center text-muted">
            <i class="bi bi-calendar-event fs-3 d-block mb-2 opacity-50"></i>
            <?= e(__('no_activity_today')) ?>
        </div>
    <?php else: ?>
        <div class="dash-activity-feed">
            <?php foreach ($activities as $act): ?>
                <div class="dash-activity-strip">
                    <div class="activity-strip-left d-flex align-items-start align-items-md-center gap-3 flex-grow-1">
                        <div class="activity-strip-icon icon-type-<?= e($act['icon_type'] ?? 'in') ?> mt-1 mt-md-0">
                            <i class="bi <?= e($act['icon']) ?>"></i>
                        </div>
                        <div class="activity-strip-details flex-grow-1">
                            <div class="activity-strip-name fw-semibold"><?= e($act['activity']) ?></div>
                            <div class="activity-strip-meta text-muted small mt-1">
                                <span class="activity-strip-time"><?= e($act['time_display']) ?></span>
                                <?php if ($act['duration'] !== '—'): ?>
                                    <span class="activity-strip-dur ms-2">
                                        <i class="bi bi-stopwatch me-1" aria-hidden="true"></i><?= e($act['duration']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="activity-strip-badge-line mt-2 d-md-none">
                                <span class="badge-result badge-result-<?= e($act['badge_color']) ?>">
                                    <span><?= e($act['badge']) ?></span>
                                    <?php if (!empty($act['sub'])): ?>
                                        <span class="badge-result-sub ms-1 <?= $act['badge_color'] === 'red' ? 'text-danger-emphasis fw-bold' : 'opacity-75' ?>">
                                            <?= e($act['sub']) ?>
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="activity-strip-right d-none d-md-block">
                        <div class="activity-strip-badge-wrap">
                            <span class="badge-result badge-result-<?= e($act['badge_color']) ?>">
                                <span><?= e($act['badge']) ?></span>
                                <?php if (!empty($act['sub'])): ?>
                                    <span class="badge-result-sub ms-1 <?= $act['badge_color'] === 'red' ? 'text-danger-emphasis fw-bold' : 'opacity-75' ?>">
                                        <?= e($act['sub']) ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="modal fade dashboard-confirm-modal" id="dashboardConfirmModal" tabindex="-1" aria-labelledby="dashboardConfirmTitle" aria-describedby="dashboardConfirmMessage">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="dashboardConfirmTitle"></h2>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= e(__('cancel')) ?>"></button>
            </div>
            <div class="modal-body">
                <p id="dashboardConfirmMessage" class="mb-0"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-action-cancel><?= e(__('cancel')) ?></button>
                <button type="button" class="btn btn-accent" data-action-confirm></button>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
