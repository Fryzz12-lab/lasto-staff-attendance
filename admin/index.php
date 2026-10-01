<?php
/**
 * Admin attendance dashboard
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

mark_incomplete_attendances();
$date = (string) get('date', today_str());

$totalStmt = db()->prepare("SELECT COUNT(*) FROM users
    WHERE role = 'staff' AND is_active = 1
      AND (joining_date IS NULL OR joining_date <= ?)
      AND (employment_end_date IS NULL OR employment_end_date >= ?)");
$totalStmt->execute([$date, $date]);
$totalStaff = (int) $totalStmt->fetchColumn();

ensure_attendance_rows_for_date($date);

$counts = [
    'present' => 0,
    'late' => 0,
    'on_break' => 0,
    'absent' => 0,
    'day_off' => 0,
    'not_clocked_in' => 0,
    'completed' => 0,
    'incomplete' => 0,
    'wfh' => 0,
];

$sql = "SELECT a.*, u.full_name, u.employee_id,
               ob.start_time AS open_break_start
        FROM attendance a
        JOIN users u ON u.id = a.user_id
        LEFT JOIN break_records ob ON ob.attendance_id = a.id AND ob.end_time IS NULL
        WHERE a.work_date = ? AND u.role = 'staff'
          AND (u.joining_date IS NULL OR u.joining_date <= a.work_date)
          AND (u.is_active = 1 OR a.work_date < CURDATE())
        ORDER BY u.full_name";
$stmt = db()->prepare($sql);
$stmt->execute([$date]);
$allRows = $stmt->fetchAll();

foreach ($allRows as $r) {
    $st = (string) $r['status'];

    // Singular states — exactly one of these applies per row.
    // on_break/late/present/wfh are independent facts counted below.
    if (in_array($st, ['absent', 'day_off', 'not_clocked_in', 'completed', 'incomplete'], true)) {
        $counts[$st]++;
    }

    // Independent facts — multiple can be true for one record simultaneously.
    // Example: a WFH staff member who is late and on break counts in all three.
    if (!empty($r['clock_in'])) {
        $counts['present']++;       // anyone who has clocked in = present
    }
    if ((int) ($r['late_minutes'] ?? 0) > 0) {
        $counts['late']++;          // late regardless of break or WFH state
    }
    if (!empty($r['open_break_start'])) {
        $counts['on_break']++;      // has an open break right now
    }
    if (!empty($r['is_wfh'])) {
        $counts['wfh']++;           // approved WFH arrangement for this date
    }
}

$page = max(1, (int) get('page', 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;
$total = count($allRows);
$rows = array_slice($allRows, $offset, $perPage);

$presentTotal = $counts['present'];
$ringMax = max(1, $totalStaff);

$pageTitle = __('dashboard');
$bodyClass = 'admin-dashboard';
$activeMenu = 'dashboard';
$isAdminArea = true;
require __DIR__ . '/../includes/layout_header.php';

$rings = [
    ['key' => 'total',      'class' => 'total',      'label' => __('total_staff'),    'value' => $totalStaff],
    ['key' => 'present',    'class' => 'present',    'label' => __('present'),         'value' => $counts['present']],
    ['key' => 'late',       'class' => 'late',       'label' => __('late'),            'value' => $counts['late']],
    ['key' => 'break',      'class' => 'break',      'label' => __('on_break'),        'value' => $counts['on_break']],
    ['key' => 'wfh',        'class' => 'wfh',        'label' => __('st_wfh'),          'value' => $counts['wfh']],
    ['key' => 'off',        'class' => 'off',        'label' => __('day_off'),         'value' => $counts['day_off']],
    ['key' => 'notclocked', 'class' => 'notclocked', 'label' => __('not_clocked_in'), 'value' => $counts['not_clocked_in']],
];
?>

<section class="chrono-command mb-3">
    <div class="dash-admin-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
        <div class="dash-admin-heading">
            <div class="chrono-command__sub"><?= e(__('attendance_management')) ?></div>
            <h2 class="chrono-command__title mb-0"><?= e(__('admin_overview')) ?></h2>
            <div class="text-muted small mt-1"><?= e(format_date($date)) ?> · <?= e(__('live_pulse_hint')) ?></div>
        </div>
        <form class="dash-admin-filter d-flex flex-wrap align-items-center gap-2 mb-0" method="get">
            <?php render_date_presets('single', ['date' => $date, 'class' => 'date-presets--compact mb-0']); ?>
            <div class="dash-admin-date-field">
                <label class="form-label" for="filter-date"><?= e(__('date')) ?></label>
                <input id="filter-date" type="date" name="date" class="form-control" value="<?= e($date) ?>">
            </div>
            <div class="dash-admin-filter-footer">
                <span class="form-hint"><?= e(__('filters_auto_hint')) ?></span>
                <a href="<?= e(url('/admin/index.php')) ?>"><?= e(__('filters_reset')) ?></a>
            </div>
        </form>
    </div>

    <div class="chrono-rings">
        <?php foreach ($rings as $ring): ?>
            <div class="card-soft chrono-ring chrono-ring--<?= e($ring['class']) ?>" data-pulse-ring data-value="<?= (int)$ring['value'] ?>" data-max="<?= (int)$ringMax ?>">
                <div class="chrono-ring__gauge">
                    <svg class="chrono-ring__svg" viewBox="0 0 100 100" aria-hidden="true">
                        <circle class="pulse-ring__track" cx="50" cy="50" r="42"></circle>
                        <circle class="pulse-ring__meter" cx="50" cy="50" r="42"></circle>
                    </svg>
                    <div class="chrono-ring__value" data-count-to="<?= (int)$ring['value'] ?>">0</div>
                </div>
                <div class="chrono-ring__label"><?= e($ring['label']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<div class="card-soft hud-frame">
    <div class="card-header daily-attendance-card-header d-flex align-items-center justify-content-between">
        <div class="daily-attendance-header-title d-flex align-items-center gap-2">
            <span class="fw-bold"><?= e($date === today_str() ? __('todays_attendance') : __('attendance_for_date', ['date' => format_date($date)])) ?></span>
        </div>
        <div class="daily-attendance-header-action">
            <a href="<?= e(url('/admin/attendance_today.php?date=' . urlencode($date))) ?>" class="btn btn-sm btn-outline-secondary"><?= e(__('all_records')) ?></a>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (!$rows): ?>
            <div class="empty-state"><?= e(__('no_attendance_data')) ?></div>
        <?php else: ?>
            <div class="table-responsive-card">
                <table class="table mb-0 daily-attendance-table align-middle" style="table-layout: fixed; width: 100%;">
                    <colgroup>
                        <col style="width: 22%;">
                        <col style="width: 9%;">
                        <col style="width: 9%;">
                        <col style="width: 17%;">
                        <col style="width: 11%;">
                        <col style="width: 17%;">
                        <col style="width: 15%;">
                    </colgroup>
                    <thead>
                    <tr>
                        <th><?= e(__('employee')) ?></th>
                        <th><?= e(__('clock_in_col')) ?></th>
                        <th><?= e(__('clock_out_col')) ?></th>
                        <th><?= e(__('status')) ?></th>
                        <th><?= e(__('break_time')) ?></th>
                        <th><?= e(__('total_working_time')) ?></th>
                        <th><?= e(__('action')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr class="<?= in_array($r['status'], ['incomplete', 'on_break'], true) ? 'table-warning' : '' ?>">
                            <td>
                                <div class="fw-semibold"><?= e($r['full_name']) ?></div>
                            </td>
                            <td>
                                <?= e(format_time($r['clock_in'])) ?>
                            </td>
                            <td><?= e(format_time($r['clock_out'])) ?></td>
                            <td class="attendance-status-cell"><?= attendance_status_badge($r) ?></td>
                            <td><?= attendance_live_breaks_html((int) $r['total_break_minutes'], $r['open_break_start'] ?? null) ?></td>
                            <td><?= attendance_live_work_html(
                                $r['clock_in'],
                                $r['clock_out'],
                                (int) $r['work_minutes'],
                                (int) $r['total_break_minutes'],
                                $r['open_break_start'] ?? null
                            ) ?></td>
                            <td>
                                <a class="btn btn-sm btn-outline-secondary text-nowrap" data-spa="0" href="<?= e(url('/admin/attendance_detail.php?id=' . (int)$r['id'])) ?>"><?= e(__('view_details')) ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php echo pagination_links($total, $page, $perPage, ['date' => $date]); ?>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
