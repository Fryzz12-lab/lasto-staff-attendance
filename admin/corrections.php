<?php
/**
 * Admin — Attendance Correction Requests
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

if (is_post()) {
    require_csrf();
    $id = (int) post('id');
    $decision = (string) post('decision');
    $note = trim((string) post('admin_note'));

    $adminId = (int) current_user()['id'];
    try {
        review_attendance_correction_request($id, $decision, $adminId, $note);
        flash_set('success', __('msg_correction_processed', ['status' => __('st_' . $decision)]));
    } catch (InvalidArgumentException $error) {
        flash_set('danger', $error->getMessage());
    } catch (Throwable $error) {
        error_log((string) $error);
        flash_set('danger', __('msg_process_fail'));
    }
    redirect('/admin/corrections.php');
}

$status = (string) get('status', 'pending');
$where = " FROM attendance_correction_requests c
        JOIN users u ON u.id = c.user_id
        LEFT JOIN attendance a ON a.id = c.attendance_id WHERE 1=1";
$params = [];
if ($status !== 'all') {
    $where .= ' AND c.status = ?';
    $params[] = $status;
}

$page = max(1, (int) get('page', 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$countStmt = db()->prepare("SELECT COUNT(*)" . $where);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$sql = "SELECT c.*, u.full_name, a.clock_in AS current_clock_in, a.clock_out AS current_clock_out, a.version AS current_version,
               br.start_time AS current_break_start, br.end_time AS current_break_end, br.break_type AS current_break_type,
               br.duration_minutes AS current_break_duration, br.is_auto_closed AS current_break_is_auto_closed,
               br.closure_reason AS current_break_closure_reason, br.system_closed_at AS current_break_system_closed_at
        FROM attendance_correction_requests c
        JOIN users u ON u.id = c.user_id
        LEFT JOIN attendance a ON a.id = c.attendance_id
        LEFT JOIN break_records br ON br.id = c.break_id
        WHERE 1=1" . ($status !== 'all' ? " AND c.status = ?" : "") . " ORDER BY c.created_at DESC LIMIT {$perPage} OFFSET {$offset}";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = __('correction_requests');
$activeMenu = 'corrections';
$isAdminArea = true;
require __DIR__ . '/../includes/layout_header.php';

$fieldLabels = [
    'clock_in' => __('clock_in_only'),
    'clock_out' => __('clock_out_only'),
    'both' => __('both_clock'),
];
?>

<div class="card-soft mb-3">
    <div class="card-body">
        <form method="get" class="d-flex flex-wrap align-items-end gap-2">
            <div><label class="form-label" for="filter-status"><?= e(__('filter_request_status')) ?></label><select id="filter-status" name="status" class="form-select" style="max-width:220px">
                <?php foreach (['pending','approved','rejected','all'] as $s): ?>
                    <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($s === 'all' ? __('all') : __('st_' . $s)) ?></option>
                <?php endforeach; ?>
            </select></div>
            <button class="btn btn-outline-secondary"><?= e(__('filter')) ?></button>
        <div class="col-12 d-flex flex-wrap align-items-center gap-3" style="flex-basis:100%">
                <span class="form-hint" data-auto-filter-hint hidden><?= e(__('filters_auto_hint')) ?></span>
                <a href="<?= e(url('/admin/corrections.php')) ?>" class="small staff-filter-reset-link"><?= e(__('filters_reset')) ?></a>
            </div>
        </form>
    </div>
</div>

<div class="card-soft">
    <div class="card-body p-0">
        <?php if (!$rows): ?>
            <div class="empty-state"><?= e(__('no_corrections_admin')) ?></div>
        <?php else: ?>
            <div class="table-responsive-card">
                <table class="table mb-0">
                    <thead>
                    <tr>
                        <th><?= e(__('employee')) ?></th>
                        <th><?= e(__('date')) ?></th>
                        <th><?= e(__('requested_change')) ?></th>
                        <th><?= e(__('reason')) ?></th>
                        <th><?= e(__('status')) ?></th>
                        <th><?= e(__('action')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>
                                <strong><?= e($r['full_name']) ?></strong>
                            </td>
                            <td>
                                <div><?= e(format_date($r['work_date'])) ?></div>
                                <?php if (!empty($r['is_late_submission'])): ?>
                                    <span class="badge bg-warning text-dark mt-1" style="font-size:0.72rem;"><i class="bi bi-clock-history me-1"></i>Late Submission</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($r['field_name'] === 'break'): ?>
                                    <div class="badge bg-warning text-dark mb-1">
                                        <i class="bi bi-cup-hot me-1"></i>Break: <?= e(ucfirst((string)($r['break_type'] ?? 'break'))) ?> (<?= e(ucfirst(str_replace('_', ' ', (string)($r['break_correction_type'] ?? 'correction')))) ?>)
                                    </div>
                                    <?php if (!empty($r['current_break_is_auto_closed'])): ?>
                                        <div class="mb-1">
                                            <span class="badge bg-secondary-subtle text-secondary-emphasis" style="font-size:0.7rem;">
                                                <i class="bi bi-robot me-1"></i>Auto-Closed (<?= e(ucfirst(str_replace('_', ' ', (string)($r['current_break_closure_reason'] ?? 'system')))) ?><?php if (!empty($r['current_break_system_closed_at'])): ?> @ <?= e(format_time($r['current_break_system_closed_at'])) ?><?php endif; ?>)
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="small text-muted font-monospace">
                                        <?php if (!empty($r['break_id'])): ?>
                                            Original: <?= e(format_time($r['current_break_start']) ?: '-') ?> &ndash; <?= e(format_time($r['current_break_end']) ?: (!empty($r['current_break_is_auto_closed']) ? 'Auto-Closed' : '-')) ?>
                                            <span class="fw-normal">(<?= e(format_minutes((int)($r['current_break_duration'] ?? 0))) ?>)</span>
                                        <?php else: ?>
                                            Original: <span class="fst-italic text-secondary">Unrecorded session (0m)</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php
                                    $reqDur = max(0, (int) floor((strtotime($r['requested_break_end']) - strtotime($r['requested_break_start'])) / 60));
                                    $origDur = !empty($r['break_id']) ? (int) ($r['current_break_duration'] ?? 0) : 0;
                                    $diff = $origDur - $reqDur;
                                    ?>
                                    <div class="small text-accent font-monospace fw-semibold">
                                        Requested: <?= e(format_time($r['requested_break_start'])) ?> &ndash; <?= e(format_time($r['requested_break_end'])) ?>
                                        <span class="fw-semibold">(<?= e(format_minutes($reqDur)) ?>)</span>
                                    </div>
                                    <?php if ($diff > 0): ?>
                                        <div class="small text-success mt-1"><i class="bi bi-graph-up-arrow me-1"></i>Impact: <strong>+<?= e(format_minutes($diff)) ?></strong> work time</div>
                                    <?php elseif ($diff < 0): ?>
                                        <div class="small text-warning-emphasis mt-1"><i class="bi bi-graph-down-arrow me-1"></i>Impact: <strong>-<?= e(format_minutes(abs($diff))) ?></strong> work time</div>
                                    <?php else: ?>
                                        <div class="small text-muted mt-1"><i class="bi bi-dash me-1"></i>Impact: No change to work time</div>
                                    <?php endif; ?>
                                    <?php
                                    $overlapWarning = null;
                                    if ($r['status'] === 'pending' && !empty($r['attendance_id'])) {
                                        $chkOverlap = db()->prepare("
                                            SELECT b.break_type, b.start_time, b.end_time 
                                            FROM break_records b 
                                            WHERE b.attendance_id = ? 
                                              AND (b.id != ? OR ? IS NULL) 
                                              AND b.end_time IS NOT NULL 
                                              AND GREATEST(?, b.start_time) < LEAST(?, b.end_time) 
                                            LIMIT 1
                                        ");
                                        $chkOverlap->execute([(int)$r['attendance_id'], $r['break_id'], $r['break_id'], $r['requested_break_start'], $r['requested_break_end']]);
                                        $overlapWarning = $chkOverlap->fetch();
                                    }
                                    ?>
                                    <?php if ($overlapWarning): ?>
                                        <div class="badge bg-danger text-white mt-1" style="font-size:0.7rem;">
                                            <i class="bi bi-exclamation-triangle-fill me-1"></i>Overlaps with <?= e(break_type_name($overlapWarning['break_type'])) ?> (<?= e(format_time($overlapWarning['start_time'])) ?> &ndash; <?= e(format_time($overlapWarning['end_time'])) ?>)
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="badge bg-secondary-subtle text-secondary-emphasis mb-1"><?= e($fieldLabels[$r['field_name']] ?? $r['field_name']) ?></div>
                                    <div class="small text-muted font-monospace">
                                        Original: In <?= e(format_time($r['current_clock_in']) ?: '-') ?> | Out <?= e(format_time($r['current_clock_out']) ?: '-') ?>
                                    </div>
                                    <div class="small text-accent font-monospace fw-semibold">
                                        Requested:
                                        <?php if ($r['requested_clock_in']): ?>In <?= e(format_time($r['requested_clock_in'])) ?><?php endif; ?>
                                        <?php if ($r['requested_clock_in'] && $r['requested_clock_out']): ?> | <?php endif; ?>
                                        <?php if ($r['requested_clock_out']): ?>Out <?= e(format_time($r['requested_clock_out'])) ?><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?= e($r['reason']) ?></div>
                                <?php if (!empty($r['late_reason'])): ?>
                                    <div class="small text-warning-emphasis mt-1 bg-warning-subtle p-1 rounded" style="font-size:0.8rem;">
                                        <strong>Late reason:</strong> <?= e($r['late_reason']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= status_badge($r['status']) ?>
                                <?php if ($r['status'] === 'pending'): ?>
                                    <?php if ($r['target_attendance_version'] === null): ?>
                                        <div class="mt-1"><span class="badge bg-secondary" title="Legacy unversioned request">Legacy Baseline</span></div>
                                    <?php elseif ((int)($r['current_version'] ?? 1) !== (int)$r['target_attendance_version']): ?>
                                        <div class="mt-1"><span class="badge bg-danger" title="Record modified since request submission">Version Conflict</span></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($r['status'] === 'pending'): ?>
                                    <form method="post" class="d-inline-flex gap-1 flex-wrap">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <input type="text" name="admin_note" class="form-control form-control-sm" placeholder="<?= e(__('admin_note')) ?>" style="width:120px">
                                        <button name="decision" value="approved" class="btn btn-sm btn-success" data-confirm="<?= e(__('confirm_approve_correction')) ?>"><?= e(__('approve')) ?></button>
                                        <button name="decision" value="rejected" class="btn btn-sm btn-outline-danger" data-confirm="<?= e(__('confirm_reject')) ?>"><?= e(__('reject')) ?></button>
                                    </form>
                                <?php else: ?>
                                    <span class="form-hint"><?= e((string)$r['admin_note']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
            $filterParams = array_filter([
                'status' => $status !== 'pending' ? $status : null,
            ]);
            echo pagination_links($total, $page, $perPage, $filterParams);
            ?>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>