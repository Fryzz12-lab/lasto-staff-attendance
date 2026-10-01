<?php
/**
 * Admin — Break day detail (all breaks for one employee on one day)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

$attendanceId = (int) get('attendance_id', 0);
if ($attendanceId <= 0) {
    flash_set('danger', __('msg_not_found'));
    redirect('/admin/breaks.php');
}

$stmt = db()->prepare(
    "SELECT a.*, u.full_name
     FROM attendance a
     JOIN users u ON u.id = a.user_id
     WHERE a.id = ?"
);
$stmt->execute([$attendanceId]);
$att = $stmt->fetch();
if (!$att) {
    flash_set('danger', __('msg_not_found'));
    redirect('/admin/breaks.php');
}

$breaks = db()->prepare(
    'SELECT * FROM break_records WHERE attendance_id = ? ORDER BY start_time ASC'
);
$breaks->execute([$attendanceId]);
$breakRows = $breaks->fetchAll();

$openStart = null;
foreach ($breakRows as $b) {
    if (empty($b['end_time'])) {
        $openStart = (string) $b['start_time'];
        break;
    }
}

$from = (string) get('from', '');
$to = (string) get('to', '');
$exceeded = (string) get('exceeded', '');
$backQs = http_build_query(array_filter([
    'from' => $from !== '' ? $from : null,
    'to' => $to !== '' ? $to : null,
    'exceeded' => $exceeded === '1' ? '1' : null,
]));

$pageTitle = __('break_day_detail');
$activeMenu = 'breaks';
$isAdminArea = true;
require __DIR__ . '/../includes/layout_header.php';
?>

<div class="mb-3">
    <a href="<?= e(url('/admin/breaks.php' . ($backQs !== '' ? '?' . $backQs : ''))) ?>"
       class="btn btn-sm btn-outline-secondary">&larr; <?= e(__('back')) ?></a>
</div>

<div class="card-soft mb-3">
    <div class="card-header">
        <?= e($att['full_name']) ?> · <span class="fw-normal"><?= e(format_date($att['work_date'])) ?></span>
    </div>
    <div class="card-body">
        <div class="meta-grid">
            <div class="meta-item">
                <div class="label"><?= e(__('total_break_time')) ?></div>
                <div class="value"><?= break_total_live_html((int)$att['total_break_minutes'], $openStart) ?></div>
            </div>
            <div class="meta-item">
                <div class="label">Number of Breaks</div>
                <div class="value"><?= e((string) count($breakRows)) ?></div>
            </div>
            <div class="meta-item">
                <div class="label"><?= e(__('status')) ?></div>
                <div class="value"><?= attendance_status_badge($att) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card-soft">
    <div class="card-header"><?= e(__('break_records')) ?></div>
    <div class="card-body p-0">
        <?php if (!$breakRows): ?>
            <div class="empty-state"><?= e(__('no_breaks')) ?></div>
        <?php else: ?>
            <div class="table-responsive-card">
                <table class="table mb-0">
                    <thead>
                    <tr>
                        <th><?= e(__('type')) ?></th>
                        <th><?= e(__('start')) ?></th>
                        <th><?= e(__('end')) ?></th>
                        <th><?= e(__('duration')) ?></th>
                        <th><?= e(__('allowed')) ?></th>
                        <th><?= e(__('action')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($breakRows as $b): ?>
                        <tr class="<?= (int)$b['exceeded'] ? 'table-warning' : '' ?>">
                            <td>
                                <?= e(break_label((string)$b['break_type'], (int)$b['sequence_no'])) ?>
                            </td>
                            <td><?= e(format_time($b['start_time'])) ?></td>
                            <td><?= $b['end_time'] ? e(format_time($b['end_time'])) : '<em>' . e(__('open')) . '</em>' ?></td>
                            <td>
                                <?= break_duration_html($b['start_time'], $b['end_time'], (int)$b['duration_minutes']) ?>
                            </td>
                            <td><?= e(format_minutes((int)$b['allowed_minutes'])) ?></td>
                            <td>
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?= e(url('/admin/break_edit.php?id=' . (int)$b['id'] . '&attendance_id=' . $attendanceId)) ?>">
                                    Edit
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
