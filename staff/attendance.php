<?php
/**
 * Staff — My Attendance history
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$user = current_user();
$userId = (int) $user['id'];

$from = (string) get('from', now()->modify('-30 days')->format('Y-m-d'));
$to = (string) get('to', today_str());

// Create/fix rows so rest days show as Day Off (never "Not Clocked In")
sync_attendance_range($userId, $from, $to);

$page = max(1, (int) get('page', 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$countStmt = db()->prepare(
    'SELECT COUNT(*) FROM attendance WHERE user_id = ? AND work_date BETWEEN ? AND ?'
);
$countStmt->execute([$userId, $from, $to]);
$total = (int) $countStmt->fetchColumn();

$stmt = db()->prepare(
    "SELECT * FROM attendance WHERE user_id = ? AND work_date BETWEEN ? AND ? ORDER BY work_date DESC LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute([$userId, $from, $to]);
$rows = $stmt->fetchAll();

$pageTitle = __('my_attendance');
$activeMenu = 'attendance';
$isAdminArea = false;
$bodyClass = 'staff-history-page';
require __DIR__ . '/../includes/layout_header.php';
?>

<h2 class="h4 mb-3"><?= e(__('my_attendance')) ?></h2>

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
                <a href="<?= e(url('/staff/attendance.php')) ?>" class="small"><?= e(__('filters_reset')) ?></a>
            </div>
        </form>
    </div>
</div>

<div class="card-soft">
    <div class="card-header"><?= e(__('attendance_records')) ?></div>
    <div class="card-body p-0">
        <?php if (!$rows): ?>
            <div class="empty-state"><i class="bi bi-inbox"></i><?= e(__('no_records')) ?></div>
        <?php else: ?>
            <!-- Desktop Table (≥ 768px): 100% untouched -->
            <div class="table-responsive-card d-none d-md-block">
                <table class="table mb-0 staff-attendance-table">
                    <thead>
                    <tr>
                        <th><?= e(__('date')) ?></th>
                        <th><?= e(__('clock_in_col')) ?></th>
                        <th><?= e(__('clock_out_col')) ?></th>
                        <th><?= e(__('breaks')) ?></th>
                        <th><?= e(__('working_time')) ?></th>
                        <th><?= e(__('status')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= e(format_date($r['work_date'])) ?></td>
                            <td><?= e(format_time($r['clock_in'])) ?><?php if ((int)$r['late_minutes'] > 0): ?> <small class="text-danger">(+<?= e(format_minutes((int) $r['late_minutes'])) ?>)</small><?php endif; ?></td>
                            <td><?= e(format_time($r['clock_out'])) ?></td>
                            <td><?= e(format_minutes((int) $r['total_break_minutes'])) ?></td>
                            <td><?= e(format_minutes((int) $r['work_minutes'])) ?></td>
                            <td><?= attendance_status_badge($r) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Stacked Card Feed (< 768px): Only affects mobile -->
            <div class="staff-attendance-mobile-feed d-md-none p-3">
                <?php foreach ($rows as $r): ?>
                    <?php $isToday = ($r['work_date'] ?? '') === today_str(); ?>
                    <div class="attendance-mobile-record-card p-3 mb-2 rounded border <?= !empty($r['is_day_off']) ? 'record-day-off' : '' ?><?= $isToday ? ' record-today' : '' ?>">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="fw-bold font-monospace text-white-emphasis">
                                <?= e(format_date($r['work_date'])) ?><?php if ($isToday): ?> <span class="attendance-today-label"><?= e(__('today')) ?></span><?php endif; ?>
                            </span>
                            <div>
                                <?= attendance_status_badge($r) ?>
                            </div>
                        </div>
                        <?php if (!empty($r['is_day_off'])): ?>
                            <div class="text-muted small">
                                <i class="bi bi-calendar-x me-1"></i><?= e(__('st_day_off')) ?>
                            </div>
                        <?php elseif (empty($r['clock_in'])): ?>
                            <div class="text-muted small">
                                <i class="bi bi-dash-circle me-1"></i><?= e(__('not_clocked_in')) ?>
                            </div>
                        <?php else: ?>
                            <div class="d-flex align-items-baseline justify-content-between font-monospace small mb-2">
                                <span class="text-white-emphasis fw-semibold">
                                    <?= e(format_time($r['clock_in'])) ?>
                                    <span class="text-muted">→</span>
                                    <?php if (!empty($r['clock_out'])): ?>
                                        <?= e(format_time($r['clock_out'])) ?>
                                    <?php elseif (($r['status'] ?? '') === 'incomplete'): ?>
                                        <span class="text-muted">-</span>
                                    <?php else: ?>
                                        <span class="text-warning"><?= e(__('badge_in_progress')) ?></span>
                                    <?php endif; ?>
                                </span>
                                <?php if ((int)$r['late_minutes'] > 0): ?>
                                    <span class="text-danger fw-semibold">
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i>+<?= e(format_minutes((int) $r['late_minutes'])) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-success">
                                        <i class="bi bi-check-circle-fill me-1"></i><?= e(__('on_time')) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex align-items-center justify-content-between pt-2 border-top border-secondary-subtle small font-monospace">
                                <div>
                                    <span class="text-muted"><?= e(__('working_time')) ?>:</span>
                                    <span class="text-accent fw-bold"><?= e(format_minutes((int) $r['work_minutes'])) ?></span>
                                </div>
                                <div>
                                    <span class="text-muted"><?= e(__('breaks')) ?>:</span>
                                    <span class="text-light-emphasis fw-semibold"><?= e(format_minutes((int) $r['total_break_minutes'])) ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
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
