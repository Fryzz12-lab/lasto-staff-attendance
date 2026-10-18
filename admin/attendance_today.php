<?php
/**
 * Admin — Daily Attendance (filterable)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

$date = (string) get('date', today_str());
$status = (string) get('status', '');
$userId = (int) get('user_id', 0);
$lateFilter = (string) get('late', '');
$breakFilter = (string) get('on_break', '');
$workMode = (string) get('work_mode', '');
if ($workMode !== 'wfh') {
    $workMode = '';
}

$staffListStmt = db()->prepare("SELECT id, full_name FROM users WHERE role='staff' AND (joining_date IS NULL OR joining_date <= ?) AND (is_active = 1 OR ? < CURDATE()) ORDER BY full_name");
$staffListStmt->execute([$date, $date]);
$staffList = $staffListStmt->fetchAll();

ensure_attendance_rows_for_date($date);

$fromWhere = " FROM attendance a
        JOIN users u ON u.id = a.user_id
        WHERE a.work_date = ? AND u.role = 'staff'
          AND (u.joining_date IS NULL OR u.joining_date <= a.work_date)
          AND (u.is_active = 1 OR a.work_date < CURDATE())";
$params = [$date];
if ($status !== '') {
    $fromWhere .= ' AND a.status = ?';
    $params[] = $status;
}
if ($userId > 0) {
    $fromWhere .= ' AND a.user_id = ?';
    $params[] = $userId;
}
if ($workMode === 'wfh') {
    $fromWhere .= ' AND a.is_wfh = 1';
}
if ($lateFilter === 'yes') {
    $fromWhere .= ' AND a.late_minutes > 0';
}
if ($breakFilter === 'yes') {
    $fromWhere .= ' AND EXISTS (SELECT 1 FROM break_records active_break WHERE active_break.attendance_id = a.id AND active_break.end_time IS NULL)';
}

$page = max(1, (int) get('page', 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$countStmt = db()->prepare("SELECT COUNT(*)" . $fromWhere);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$sql = "SELECT a.*, u.full_name, u.employee_id,
               ob.start_time AS open_break_start
        FROM attendance a
        JOIN users u ON u.id = a.user_id
        LEFT JOIN break_records ob ON ob.attendance_id = a.id AND ob.end_time IS NULL
        WHERE a.work_date = ? AND u.role = 'staff'
          AND (u.joining_date IS NULL OR u.joining_date <= a.work_date)
          AND (u.is_active = 1 OR a.work_date < CURDATE())";
if ($status !== '') {
    $sql .= ' AND a.status = ?';
}
if ($userId > 0) {
    $sql .= ' AND a.user_id = ?';
}
if ($workMode === 'wfh') {
    $sql .= ' AND a.is_wfh = 1';
}
if ($lateFilter === 'yes') {
    $sql .= ' AND a.late_minutes > 0';
}
if ($breakFilter === 'yes') {
    $sql .= ' AND EXISTS (SELECT 1 FROM break_records active_break WHERE active_break.attendance_id = a.id AND active_break.end_time IS NULL)';
}
$sql .= " ORDER BY u.full_name LIMIT {$perPage} OFFSET {$offset}";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = __('daily_attendance');
$activeMenu = 'today';
$isAdminArea = true;
require __DIR__ . '/../includes/layout_header.php';
?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <span class="small d-inline-flex align-items-center gap-1" style="color: var(--muted);">
        <i class="bi bi-calendar-event me-1 text-accent" aria-hidden="true"></i>
        <?= e(__('showing_records_for')) ?>: <strong style="color: var(--text); font-weight: 700;"><?= e(format_date($date)) ?></strong>
    </span>
</div>

<div class="card-soft mb-3">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-12">
                <?php render_date_presets('single', ['date' => $date]); ?>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="filter-date"><?= e(__('date')) ?></label>
                <input id="filter-date" type="date" name="date" class="form-control" value="<?= e($date) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="filter-user_id"><?= e(__('employee')) ?></label>
                <select id="filter-user_id" name="user_id" class="form-select">
                    <option value="0"><?= e(__('all_staff')) ?></option>
                    <?php foreach ($staffList as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= $userId === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="filter-status"><?= e(__('filter_attendance_status')) ?></label>
                <select id="filter-status" name="status" class="form-select">
                    <option value=""><?= e(__('all_statuses')) ?></option>
                    <?php foreach (['present','completed','not_clocked_in','day_off','incomplete','absent'] as $st): ?>
                        <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= e(__('st_' . $st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="filter-late"><?= e(__('late')) ?></label>
                <select id="filter-late" name="late" class="form-select">
                    <option value=""><?= e(__('all_statuses')) ?></option>
                    <option value="yes" <?= $lateFilter === 'yes' ? 'selected' : '' ?>><?= e(__('filter_yes')) ?></option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="filter-on_break"><?= e(__('on_break')) ?></label>
                <select id="filter-on_break" name="on_break" class="form-select">
                    <option value=""><?= e(__('all_statuses')) ?></option>
                    <option value="yes" <?= $breakFilter === 'yes' ? 'selected' : '' ?>><?= e(__('filter_yes')) ?></option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="filter-work_mode"><?= e(__('work_arrangement')) ?></label>
                <select id="filter-work_mode" name="work_mode" class="form-select">
                    <option value=""><?= e(__('all_statuses')) ?></option>
                    <option value="wfh" <?= $workMode === 'wfh' ? 'selected' : '' ?>><?= e(__('leave_wfh')) ?></option>
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-accent w-100"><?= e(__('filter')) ?></button>
            </div>
        <div class="col-12 d-flex flex-wrap align-items-center gap-3" style="flex-basis:100%">
                <span class="form-hint" data-auto-filter-hint hidden><?= e(__('filters_auto_hint')) ?></span>
                <a href="<?= e(url('/admin/attendance_today.php')) ?>" class="small staff-filter-reset-link"><?= e(__('filters_reset')) ?></a>
            </div>
        </form>
    </div>
</div>

<div class="card-soft">
    <div class="card-body p-0">
        <?php if (!$rows): ?>
            <div class="empty-state"><?= e(__('no_attendance_date')) ?></div>
        <?php else: ?>
            <div class="table-responsive-card">
                <table class="table mb-0 daily-attendance-table" style="table-layout: fixed; width: 100%;">
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
                        <th><?= e(__('breaks')) ?></th>
                        <th><?= e(__('working')) ?></th>
                        <th><?= e(__('action')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= e($r['full_name']) ?></td>
                            <td><?= e(format_time($r['clock_in'])) ?></td>
                            <td><?= e(format_time($r['clock_out'])) ?></td>
                            <td class="attendance-status-cell"><?= attendance_status_badge($r) ?></td>
                            <td><?= attendance_live_breaks_html((int)$r['total_break_minutes'], $r['open_break_start'] ?? null) ?></td>
                            <td><?= attendance_live_work_html(
                                $r['clock_in'],
                                $r['clock_out'],
                                (int)$r['work_minutes'],
                                (int)$r['total_break_minutes'],
                                $r['open_break_start'] ?? null
                            ) ?></td>
                            <td><a class="btn btn-sm btn-outline-secondary text-nowrap" data-spa="0" href="<?= e(url('/admin/attendance_detail.php?id='.(int)$r['id'])) ?>"><?= e(__('view_details')) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
            $filterParams = array_filter([
                'date'      => $date,
                'status'    => $status !== '' ? $status : null,
                'user_id'   => $userId > 0 ? (string)$userId : null,
                'late'      => $lateFilter !== '' ? $lateFilter : null,
                'on_break'  => $breakFilter !== '' ? $breakFilter : null,
                'work_mode' => $workMode !== '' ? $workMode : null,
            ]);
            echo pagination_links($total, $page, $perPage, $filterParams);
            ?>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>

