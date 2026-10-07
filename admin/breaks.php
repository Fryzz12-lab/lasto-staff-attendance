<?php
/**
 * Admin — Break Records (one row per employee per day)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

$from = (string) get('from', now()->modify('-7 days')->format('Y-m-d'));
$to = (string) get('to', today_str());
$exceededOnly = (string) get('exceeded', '') === '1';
$page    = max(1, (int) get('page', 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

$sql = "SELECT
            a.id AS attendance_id,
            a.work_date,
            a.user_id,
            u.full_name,
            a.total_break_minutes,
            agg.break_count,
            agg.has_exceeded,
            lb.break_type AS last_break_type,
            lb.sequence_no AS last_sequence_no,
            lb.start_time AS last_start_time,
            lb.end_time AS last_end_time,
            lb.duration_minutes AS last_duration_minutes,
            lb.exceeded AS last_exceeded
        FROM attendance a
        JOIN users u ON u.id = a.user_id
        JOIN (
            SELECT b1.attendance_id,
                   COUNT(*) AS break_count,
                   MAX(b1.exceeded) AS has_exceeded,
                   (
                       SELECT b2.id
                       FROM break_records b2
                       WHERE b2.attendance_id = b1.attendance_id
                       ORDER BY b2.start_time DESC, b2.id DESC
                       LIMIT 1
                   ) AS last_break_id
            FROM attendance scoped
            JOIN break_records b1 ON b1.attendance_id = scoped.id
            WHERE scoped.work_date BETWEEN ? AND ?
            GROUP BY b1.attendance_id
        ) agg ON agg.attendance_id = a.id
        JOIN break_records lb ON lb.id = agg.last_break_id
        WHERE a.work_date BETWEEN ? AND ?";
$params = [$from, $to];
$rowParams = [$from, $to, $from, $to];
if ($exceededOnly) {
    $sql .= ' AND agg.has_exceeded = 1';
}
$sql .= " ORDER BY a.work_date DESC, lb.start_time DESC, a.id DESC LIMIT {$perPage} OFFSET {$offset}";

$countSql = "SELECT COUNT(*)
        FROM attendance a
        JOIN users u ON u.id = a.user_id
        WHERE a.work_date BETWEEN ? AND ?";
if ($exceededOnly) {
    $countSql .= ' AND EXISTS (SELECT 1 FROM break_records b WHERE b.attendance_id = a.id AND b.exceeded = 1)';
} else {
    $countSql .= ' AND EXISTS (SELECT 1 FROM break_records b WHERE b.attendance_id = a.id)';
}
$cStmt = db()->prepare($countSql);
$cStmt->execute($params);
$total = (int) $cStmt->fetchColumn();

$stmt = db()->prepare($sql);
$stmt->execute($rowParams);
$rows = $stmt->fetchAll();

$viewQs = http_build_query(array_filter([
    'from' => $from,
    'to' => $to,
    'exceeded' => $exceededOnly ? '1' : null,
]));

$pageTitle = __('break_records');
$activeMenu = 'breaks';
$isAdminArea = true;
$bodyClass = 'admin-break-records';
require __DIR__ . '/../includes/layout_header.php';
?>

<div class="card-soft mb-3">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-12">
                <?php render_date_presets('range', ['from' => $from, 'to' => $to]); ?>
            </div>
            <div class="col-md-3"><label class="form-label" for="filter-from"><?= e(__('filter_from')) ?></label><input id="filter-from" type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
            <div class="col-md-3"><label class="form-label" for="filter-to"><?= e(__('filter_to')) ?></label><input id="filter-to" type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
            <div class="col-md-3">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="exceeded" value="1" id="ex" <?= $exceededOnly ? 'checked' : '' ?>>
                    <label class="form-check-label" for="ex"><?= e(__('exceeded_only')) ?></label>
                </div>
            </div>
            <div class="col-md-3"><button class="btn btn-accent w-100"><?= e(__('filter')) ?></button></div>
        <div class="col-12 d-flex flex-wrap align-items-center gap-3" style="flex-basis:100%">
                <span class="form-hint" data-auto-filter-hint hidden><?= e(__('filters_auto_hint')) ?></span>
                <a href="<?= e(url('/admin/breaks.php')) ?>" class="small"><?= e(__('filters_reset')) ?></a>
            </div>
        </form>
    </div>
</div>

<div class="card-soft">
    <div class="card-body p-0">
        <?php if (!$rows): ?>
            <div class="empty-state"><?= e(__('no_break_records')) ?></div>
        <?php else: ?>
            <div class="table-responsive-card break-records-table-desktop">
                <table class="table mb-0 break-records-table" style="table-layout: fixed; width: 100%;">
                    <colgroup>
                        <col style="width: 10%;">
                        <col style="width: 17%;">
                        <col style="width: 13%;">
                        <col style="width: 10%;">
                        <col style="width: 10%;">
                        <col style="width: 11%;">
                        <col style="width: 12%;">
                        <col style="width: 5%;">
                        <col style="width: 12%;">
                    </colgroup>
                    <thead>
                    <tr>
                        <th><?= e(__('date')) ?></th>
                        <th><?= e(__('employee')) ?></th>
                        <th><?= e(__('last_break')) ?></th>
                        <th><?= e(__('start_time')) ?></th>
                        <th><?= e(__('end_time')) ?></th>
                        <th><?= e(__('duration')) ?></th>
                        <th><?= e(__('total_break_time')) ?></th>
                        <th><?= e(__('break_count')) ?></th>
                        <th><?= e(__('action')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr class="<?= (int)$r['last_exceeded'] ? 'table-warning' : '' ?>">
                            <td><?= e(format_date($r['work_date'])) ?></td>
                            <td class="text-break"><?= e($r['full_name']) ?></td>
                            <td><?= e(break_label((string)$r['last_break_type'], (int)$r['last_sequence_no'])) ?></td>
                            <td><?= e(format_time($r['last_start_time'])) ?></td>
                            <td><?= $r['last_end_time'] ? e(format_time($r['last_end_time'])) : '<em>' . e(__('open')) . '</em>' ?></td>
                            <td>
                                <div class="break-duration-cell d-inline-flex align-items-center">
                                    <span><?= break_duration_html($r['last_start_time'], $r['last_end_time'], (int)$r['last_duration_minutes']) ?></span>
                                </div>
                            </td>
                            <td class="fw-semibold">
                                <?= break_total_live_html(
                                    (int)$r['total_break_minutes'],
                                    empty($r['last_end_time']) ? (string)$r['last_start_time'] : null
                                ) ?>
                            </td>
                            <td><?= e((string)(int)$r['break_count']) ?></td>
                            <td>
                                <a class="btn btn-sm btn-outline-secondary text-nowrap"
                                   href="<?= e(url('/admin/break_detail.php?attendance_id=' . (int)$r['attendance_id'] . ($viewQs !== '' ? '&' . $viewQs : ''))) ?>">
                                    <?= e(__('view_details')) ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="break-records-mobile break-records-cards-mobile" aria-label="Break records">
                <?php foreach ($rows as $r): $isExceeded = (int)$r['last_exceeded'] === 1; ?>
                    <article class="break-record-mobile<?= $isExceeded ? ' is-exceeded' : '' ?>">
                        <div class="break-record-mobile__top"><div class="break-record-mobile__identity"><strong><?= e($r['full_name']) ?></strong><span><?= e(format_date($r['work_date'])) ?> · <?= e(break_label((string)$r['last_break_type'], (int)$r['last_sequence_no'])) ?></span></div><span class="break-record-mobile__status"><?= $isExceeded ? 'EXCEEDED' : 'WITHIN LIMIT' ?></span></div>
                        <div class="break-record-mobile__grid">
                            <div><span>Start</span><strong><?= e(format_time($r['last_start_time'])) ?></strong></div><div><span>End</span><strong><?= $r['last_end_time'] ? e(format_time($r['last_end_time'])) : e(__('open')) ?></strong></div>
                            <div><span>Duration</span><strong><?= break_duration_html($r['last_start_time'], $r['last_end_time'], (int)$r['last_duration_minutes']) ?></strong></div><div><span>Allowed</span><strong>—</strong></div>
                        </div>
                        <div class="break-record-mobile__total"><span>Total Break</span><strong><?= break_total_live_html((int)$r['total_break_minutes'], empty($r['last_end_time']) ? (string)$r['last_start_time'] : null) ?></strong></div>
                        <a class="btn btn-sm btn-outline-secondary break-record-mobile__action" href="<?= e(url('/admin/break_detail.php?attendance_id=' . (int)$r['attendance_id'] . ($viewQs !== '' ? '&' . $viewQs : ''))) ?>"><?= e(__('view_details')) ?></a>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php echo pagination_links($total, $page, $perPage, array_filter(['from' => $from, 'to' => $to, 'exceeded' => $exceededOnly ? '1' : null])); ?>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
