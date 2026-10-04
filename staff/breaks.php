<?php
/**
 * Staff — My Breaks
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$userId = (int) current_user()['id'];
$from = (string) get('from', now()->modify('-14 days')->format('Y-m-d'));
$to = (string) get('to', today_str());

$page = max(1, (int) get('page', 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$countStmt = db()->prepare(
    "SELECT COUNT(*)
     FROM break_records b
     JOIN attendance a ON a.id = b.attendance_id
     WHERE b.user_id = ? AND a.work_date BETWEEN ? AND ?"
);
$countStmt->execute([$userId, $from, $to]);
$total = (int) $countStmt->fetchColumn();

$stmt = db()->prepare(
    "SELECT b.*, a.work_date
     FROM break_records b
     JOIN attendance a ON a.id = b.attendance_id
     WHERE b.user_id = ? AND a.work_date BETWEEN ? AND ?
     ORDER BY b.start_time DESC LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute([$userId, $from, $to]);
$rows = $stmt->fetchAll();

$pageTitle = __('my_breaks');
$activeMenu = 'breaks';
$isAdminArea = false;
$bodyClass = 'staff-history-page';
require __DIR__ . '/../includes/layout_header.php';
?>

<h2 class="h4 mb-3 d-none d-lg-block"><?= e(__('my_breaks')) ?></h2>

<div class="card-soft attendance-filter-card mb-3">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-12">
                <?php render_date_presets('range', ['from' => $from, 'to' => $to]); ?>
            </div>
            <div class="col-6 col-md-4">
                <label class="form-label" for="filter-from"><?= e(__('filter_from')) ?></label>
                <input id="filter-from" type="date" name="from" class="form-control" value="<?= e($from) ?>">
            </div>
            <div class="col-6 col-md-4">
                <label class="form-label" for="filter-to"><?= e(__('filter_to')) ?></label>
                <input id="filter-to" type="date" name="to" class="form-control" value="<?= e($to) ?>">
            </div>
            <div class="col-12 col-md-4">
                <button class="btn btn-accent"><?= e(__('filter')) ?></button>
            </div>
        <div class="col-12 d-flex flex-wrap align-items-center gap-3" style="flex-basis:100%">
                <span class="form-hint" data-auto-filter-hint hidden><?= e(__('filters_auto_hint')) ?></span>
                <a href="<?= e(url('/staff/breaks.php')) ?>" class="small"><?= e(__('filters_reset')) ?></a>
            </div>
        </form>
    </div>
</div>

<div class="card-soft">
    <div class="card-header"><?= e(__('break_records')) ?></div>
    <div class="card-body p-0">
        <?php if (!$rows): ?>
            <div class="empty-state"><i class="bi bi-cup-hot"></i><?= e(__('no_break_range')) ?></div>
        <?php else: ?>
            <!-- Desktop Table (≥ 768px): 100% untouched -->
            <div class="table-responsive-card d-none d-md-block">
                <table class="table mb-0">
                    <thead>
                    <tr>
                        <th><?= e(__('date')) ?></th>
                        <th><?= e(__('type')) ?></th>
                        <th><?= e(__('start')) ?></th>
                        <th><?= e(__('end')) ?></th>
                        <th><?= e(__('duration')) ?></th>
                        <th><?= e(__('allowed')) ?></th>
                        <th><?= e(__('note')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr class="<?= (int)$r['exceeded'] ? 'table-warning' : '' ?>">
                            <td><?= e(format_date($r['work_date'])) ?></td>
                            <td><?= e(break_label($r['break_type'], (int) $r['sequence_no'])) ?></td>
                            <td><?= e(format_time($r['start_time'])) ?></td>
                            <td><?= $r['end_time'] ? e(format_time($r['end_time'])) : '<em>' . e(__('open')) . '</em>' ?></td>
                            <td><?= break_duration_html($r['start_time'], $r['end_time'], (int) $r['duration_minutes']) ?></td>
                            <td><?= e(format_minutes((int) $r['allowed_minutes'])) ?></td>
                            <td><?= (int)$r['exceeded'] ? '<span class="status-badge badge-exceeded">' . e(__('exceeded')) . '</span>' : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Stacked Card Feed (< 768px): Only affects mobile -->
            <div class="staff-breaks-mobile-feed d-md-none p-3">
                <?php foreach ($rows as $r): ?>
                    <div class="attendance-mobile-record-card staff-break-record p-2 px-3 mb-2 rounded border <?= (int)$r['exceeded'] ? 'border-warning-subtle' : '' ?>">
                        <div class="staff-break-record__heading">
                            <span class="staff-break-record__title fw-bold text-white-emphasis">
                                <?= e(break_label($r['break_type'], (int) $r['sequence_no'])) ?>
                            </span>
                            <div class="staff-break-record__status">
                                <?= (int)$r['exceeded'] ? '<span class="status-badge badge-exceeded">' . e(__('exceeded')) . '</span>' : '<span class="text-success small"><i class="bi bi-check2"></i> ' . e(__('badge_within_limit')) . '</span>' ?>
                            </div>
                        </div>
                        <div class="staff-break-record__details font-monospace small">
                            <span class="text-muted"><?= e(format_date($r['work_date'])) ?></span>
                            <span class="text-white-emphasis">
                                <?= e(format_time($r['start_time'])) ?>
                                <span class="text-muted">→</span>
                                <?= $r['end_time'] ? e(format_time($r['end_time'])) : '<span class="text-warning">' . e(__('open')) . '</span>' ?>
                            </span>
                        </div>
                        <div class="staff-break-record__duration font-monospace small">
                            <span><span class="text-muted"><?= e(__('duration')) ?>:</span> <strong><?= break_duration_html($r['start_time'], $r['end_time'], (int) $r['duration_minutes']) ?></strong></span>
                            <span><span class="text-muted"><?= e(__('allowed')) ?>:</span> <strong><?= e(format_minutes((int) $r['allowed_minutes'])) ?></strong></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php
            echo pagination_links($total, $page, $perPage, [
                'from' => $from,
                'to'   => $to,
            ]);
            ?>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
