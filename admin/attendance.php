<?php
/**
 * Admin — Attendance Records (date range)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

$from    = (string) get('from', now()->modify('-7 days')->format('Y-m-d'));
$to      = (string) get('to', today_str());
$userId  = (int) get('user_id', 0);
$status  = (string) get('status', '');
$page    = max(1, (int) get('page', 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

$staffList = db()->query("SELECT id, full_name FROM users WHERE role='staff' ORDER BY full_name")->fetchAll();

// Build shared WHERE clause for both COUNT and SELECT
$where  = " FROM attendance a JOIN users u ON u.id = a.user_id WHERE a.work_date BETWEEN ? AND ? AND u.role='staff'";
$params = [$from, $to];
if ($userId > 0) { $where .= ' AND a.user_id = ?'; $params[] = $userId; }
if ($status !== '') { $where .= ' AND a.status = ?'; $params[] = $status; }

// Total count (for pagination)
$countStmt = db()->prepare("SELECT COUNT(*)" . $where);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

// Paginated rows
$sql  = "SELECT a.*, u.full_name, u.employee_id,
    li.location_status AS in_location_status, li.accuracy_metres AS in_location_accuracy,
    lo.location_status AS out_location_status, lo.accuracy_metres AS out_location_accuracy" .
    str_replace(' WHERE ', " LEFT JOIN attendance_location_events li ON li.attendance_id=a.id AND li.event_type='clock_in'
    LEFT JOIN attendance_location_events lo ON lo.attendance_id=a.id AND lo.event_type='clock_out' WHERE ", $where);
$sql .= " ORDER BY a.work_date DESC, u.full_name LIMIT {$perPage} OFFSET {$offset}";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle  = __('attendance_records');
$activeMenu = 'attendance';
$isAdminArea = true;
$bodyClass = 'admin-attendance-records';
require __DIR__ . '/../includes/layout_header.php';
?>

<div class="card-soft mb-3">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-12">
                <?php render_date_presets('range', ['from' => $from, 'to' => $to]); ?>
            </div>
            <div class="col-md-2"><label class="form-label" for="filter-from"><?= e(__('filter_from')) ?></label><input id="filter-from" type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
            <div class="col-md-2"><label class="form-label" for="filter-to"><?= e(__('filter_to')) ?></label><input id="filter-to" type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
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
                    <?php foreach (['present','late','on_break','completed','not_clocked_in','day_off','incomplete','absent'] as $st): ?>
                        <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= e(__('st_' . $st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-accent w-100"><?= e(__('filter')) ?></button></div>
        <div class="col-12 d-flex flex-wrap align-items-center gap-3" style="flex-basis:100%">
                <span class="form-hint" data-auto-filter-hint hidden><?= e(__('filters_auto_hint')) ?></span>
                <a href="<?= e(url('/admin/attendance.php')) ?>" class="small"><?= e(__('filters_reset')) ?></a>
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
                <table class="table mb-0 attendance-records-table" style="table-layout: fixed; width: 100%;">
                    <colgroup>
                        <col style="width: 12%;">
                        <col style="width: 20%;">
                        <col style="width: 10%;">
                        <col style="width: 10%;">
                        <col style="width: 19%;">
                        <col style="width: 9%;">
                        <col style="width: 9%;">
                        <col style="width: 11%;">
                    </colgroup>
                    <thead>
                    <tr>
                        <th><?= e(__('date')) ?></th>
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
                            <td><?= e(format_date($r['work_date'])) ?></td>
                            <td><?= e($r['full_name']) ?></td>
                            <td>
                                <?= e(format_time($r['clock_in'])) ?>
                                <?php if ($r['clock_in']): ?><small class="d-block text-muted"><?= e(attendance_location_label($r['in_location_status'] === null ? null : ['location_status' => $r['in_location_status'], 'accuracy_metres' => $r['in_location_accuracy']])) ?></small><?php endif; ?>
                            </td>
                            <td><?= e(format_time($r['clock_out'])) ?><?php if ($r['clock_out']): ?><small class="d-block text-muted"><?= e(attendance_location_label($r['out_location_status'] === null ? null : ['location_status' => $r['out_location_status'], 'accuracy_metres' => $r['out_location_accuracy']])) ?></small><?php endif; ?></td>
                            <td><?= attendance_status_badge($r) ?></td>
                            <td><?= e(format_minutes((int)$r['total_break_minutes'])) ?></td>
                            <td><?= e(format_minutes((int)$r['work_minutes'])) ?></td>
                            <td><a class="btn btn-sm btn-outline-secondary text-nowrap" data-spa="0" href="<?= e(url('/admin/attendance_detail.php?' . http_build_query(['id' => (int)$r['id'], 'return_source' => 'records', 'return_to' => $_SERVER['REQUEST_URI'] ?? '/admin/attendance.php']))) ?>"><?= e(__('view_details')) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="attendance-records-mobile" aria-label="Attendance records">
                <?php foreach ($rows as $r): ?>
                    <article class="attendance-record-mobile">
                        <div class="attendance-record-mobile__top">
                            <div class="attendance-record-mobile__identity">
                                <strong><?= e($r['full_name']) ?></strong>
                                <span><?= e(format_date($r['work_date'])) ?></span>
                            </div>
                            <div class="attendance-record-mobile__status"><?= attendance_status_badge($r) ?></div>
                        </div>
                        <div class="attendance-record-mobile__grid">
                            <div><span>Clock In</span><strong><?= e(format_time($r['clock_in'])) ?></strong><small><?= $r['clock_in'] ? e(attendance_location_label($r['in_location_status'] === null ? null : ['location_status' => $r['in_location_status'], 'accuracy_metres' => $r['in_location_accuracy']])) : '' ?></small></div>
                            <div><span>Clock Out</span><strong><?= e(format_time($r['clock_out'])) ?></strong><small><?= $r['clock_out'] ? e(attendance_location_label($r['out_location_status'] === null ? null : ['location_status' => $r['out_location_status'], 'accuracy_metres' => $r['out_location_accuracy']])) : '' ?></small></div>
                            <div><span>Break Time</span><strong><?= e(format_minutes((int)$r['total_break_minutes'])) ?></strong></div>
                            <div><span>Working Time</span><strong><?= e(format_minutes((int)$r['work_minutes'])) ?></strong></div>
                        </div>
                        <a class="btn btn-sm btn-outline-secondary attendance-record-mobile__action" data-spa="0" href="<?= e(url('/admin/attendance_detail.php?' . http_build_query(['id' => (int)$r['id'], 'return_source' => 'records', 'return_to' => $_SERVER['REQUEST_URI'] ?? '/admin/attendance.php']))) ?>"><?= e(__('view_details')) ?></a>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php
            $filterParams = array_filter([
                'from'    => $from,
                'to'      => $to,
                'user_id' => $userId > 0 ? (string)$userId : null,
                'status'  => $status !== '' ? $status : null,
            ]);
            echo pagination_links($total, $page, $perPage, $filterParams);
            ?>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>

