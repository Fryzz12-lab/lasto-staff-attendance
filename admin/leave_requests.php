<?php
/**
 * Admin — Leave / Day Off Requests
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

if (!setting_bool('enable_leave_module', true)) {
    flash_set('warning', __('msg_leave_disabled_admin'));
    redirect('/admin/');
}

if (is_post()) {
    require_csrf();
    $id = (int) post('id');
    $decision = (string) post('decision');
    $note = trim((string) post('admin_note'));
    $requiresReplacement = post('requires_replacement') === '1';
    $replacementAmount = (float) post('replacement_amount', 0);

    try {
        review_leave_request($id, $decision, (int) current_user()['id'], $note ?: null, $requiresReplacement, $replacementAmount);
        flash_set('success', __('msg_request_reviewed', ['status' => __('st_' . $decision)]));
    } catch (InvalidArgumentException $error) {
        flash_set('danger', $error->getMessage());
    } catch (Throwable $error) {
        error_log((string) $error);
        flash_set('danger', __('msg_process_fail'));
    }
    redirect('/admin/leave_requests.php');
}


$status = (string) get('status', 'pending');
$from   = trim((string) get('from', ''));
$to     = trim((string) get('to', ''));
$page    = max(1, (int) get('page', 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

$where = " WHERE 1=1";
$params = [];
if ($status !== 'all') {
    $where .= ' AND l.status = ?';
    $params[] = $status;
}
if ($from !== '') {
    $where .= ' AND l.leave_date >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $where .= ' AND l.leave_date <= ?';
    $params[] = $to;
}

$countSql = "SELECT COUNT(*) FROM leave_requests l" . $where;
$cLeave = db()->prepare($countSql);
$cLeave->execute($params);
$total = (int) $cLeave->fetchColumn();

$sql = "SELECT l.*, u.full_name FROM leave_requests l JOIN users u ON u.id = l.user_id" . $where;
$sql .= " ORDER BY l.created_at DESC LIMIT {$perPage} OFFSET {$offset}";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = __('leave_requests');
$activeMenu = 'leave';
$isAdminArea = true;
require __DIR__ . '/../includes/layout_header.php';
?>

<div class="card-soft mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-12">
                <?php render_date_presets('leave', ['from' => $from, 'to' => $to]); ?>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="filter-status"><?= e(__('filter_request_status')) ?></label>
                <select id="filter-status" name="status" class="form-select">
                    <?php foreach (['pending','approved','rejected','all'] as $s): ?>
                        <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e($s === 'all' ? __('all') : __('st_' . $s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="filter-from"><?= e(__('filter_from')) ?></label>
                <input id="filter-from" type="date" name="from" class="form-control" value="<?= e($from) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="filter-to"><?= e(__('filter_to')) ?></label>
                <input id="filter-to" type="date" name="to" class="form-control" value="<?= e($to) ?>">
            </div>
            <div class="col-md-3">
                <button class="btn btn-accent w-100"><?= e(__('filter')) ?></button>
            </div>
            <div class="col-12 d-flex flex-wrap align-items-center gap-3" style="flex-basis:100%">
                <span class="form-hint" data-auto-filter-hint hidden><?= e(__('filters_auto_hint')) ?></span>
                <a href="<?= e(url('/admin/leave_requests.php')) ?>" class="small"><?= e(__('filters_reset')) ?></a>
            </div>
        </form>
    </div>
</div>

<div class="card-soft">
    <div class="card-body p-0">
        <?php if (!$rows): ?>
            <div class="empty-state"><?= e(__('no_leave_admin')) ?></div>
        <?php else: ?>
            <div class="table-responsive-card">
                <table class="table mb-0 leave-requests-table" style="table-layout: fixed; width: 100%;">
                    <colgroup>
                        <col style="width: 25%;">
                        <col style="width: 8%;">
                        <col style="width: 12%;">
                        <col style="width: 32%;">
                        <col style="width: 8%;">
                        <col style="width: 15%;">
                    </colgroup>
                    <thead>
                    <tr>
                        <th><?= e(__('employee')) ?></th>
                        <th><?= e(__('date')) ?></th>
                        <th><?= e(__('type')) ?></th>
                        <th><?= e(__('reason')) ?></th>
                        <th><?= e(__('status')) ?></th>
                        <th><?= e(__('action')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="fw-semibold text-break"><?= e($r['full_name']) ?></td>
                            <td class="text-nowrap"><?= e(format_date($r['leave_date'])) ?></td>
                            <td class="text-break"><?= e(__('leave_' . $r['leave_type'])) ?></td>
                            <td class="text-break" style="line-height: 1.35; white-space: normal;"><?= e($r['reason']) ?></td>
                            <td class="text-nowrap"><?= status_badge($r['status']) ?></td>
                            <td>
                                <?php if ($r['status'] === 'pending'): ?>
                                    <div class="d-inline-flex align-items-center justify-content-start gap-1 flex-nowrap mb-0">
                                        <button type="button"
                                                class="btn btn-sm btn-success text-nowrap px-2 py-1"
                                                data-leave-modal="approve"
                                                data-id="<?= (int)$r['id'] ?>"
                                                data-name="<?= e($r['full_name']) ?>"
                                                data-date="<?= e(format_date($r['leave_date'])) ?>"
                                                data-type="<?= e(__('leave_' . $r['leave_type'])) ?>"
                                                data-reason="<?= e($r['reason']) ?>">
                                            <?= e(__('approve')) ?>
                                        </button>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-danger text-nowrap px-2 py-1"
                                                data-leave-modal="reject"
                                                data-id="<?= (int)$r['id'] ?>"
                                                data-name="<?= e($r['full_name']) ?>"
                                                data-date="<?= e(format_date($r['leave_date'])) ?>"
                                                data-type="<?= e(__('leave_' . $r['leave_type'])) ?>"
                                                data-reason="<?= e($r['reason']) ?>">
                                            <?= e(__('reject')) ?>
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php echo pagination_links($total, $page, $perPage, array_filter([
                'status' => $status !== 'pending' ? $status : null,
                'from'   => $from !== '' ? $from : null,
                'to'     => $to !== '' ? $to : null,
            ])); ?>
        <?php endif; ?>
    </div>
</div>

<!-- Approve Leave Modal -->
<div class="modal fade leave-action-modal" id="approveLeaveModal" tabindex="-1" aria-labelledby="approveLeaveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="approveLeaveForm">
                <?= csrf_field() ?>
                <input type="hidden" name="id" id="approveLeaveId">
                <input type="hidden" name="decision" value="approved">
                <div class="modal-header">
                    <h5 class="modal-title" id="approveLeaveModalLabel">
                        <i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>Approve Leave Request
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Are you sure you want to approve this request?</p>
                    
                    <div class="leave-action-card p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small">Employee:</span>
                            <strong id="approveStaffName" class="text-break"></strong>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small">Date:</span>
                            <span id="approveLeaveDate" class="fw-semibold"></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small">Type:</span>
                            <span id="approveLeaveType" class="badge bg-secondary-subtle text-dark border"></span>
                        </div>
                        <div class="pt-2 border-top">
                            <span class="text-muted small d-block mb-1">Reason:</span>
                            <div id="approveLeaveReason" class="small text-break"></div>
                        </div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label small fw-semibold" for="requiresReplacement">Does this leave require replacement?</label>
                        <select id="requiresReplacement" name="requires_replacement" class="form-select mb-2">
                            <option value="0">No replacement required</option>
                            <option value="1">Yes, add Cuti Perlu Ganti</option>
                        </select>
                        <label class="form-label small fw-semibold" for="replacementAmount">Replacement days</label>
                        <input id="replacementAmount" type="number" name="replacement_amount" class="form-control mb-2" min="0" max="99.99" step="0.01" value="1.00">
                        <label for="approveAdminNote" class="form-label small fw-semibold">Admin Note (optional)</label>
                        <input type="text" id="approveAdminNote" name="admin_note" class="form-control" placeholder="e.g. Approved as discussed / Cover duty arranged">
                        <div class="form-hint mt-1 small text-muted">Will be recorded in request history and audit logs.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Approve</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Leave Modal -->
<div class="modal fade leave-action-modal" id="rejectLeaveModal" tabindex="-1" aria-labelledby="rejectLeaveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="rejectLeaveForm">
                <?= csrf_field() ?>
                <input type="hidden" name="id" id="rejectLeaveId">
                <input type="hidden" name="decision" value="rejected">
                <div class="modal-header">
                    <h5 class="modal-title" id="rejectLeaveModalLabel">
                        <i class="bi bi-x-circle-fill text-danger me-2" aria-hidden="true"></i>Reject Leave Request
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Are you sure you want to reject this request?</p>
                    
                    <div class="leave-action-card p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small">Employee:</span>
                            <strong id="rejectStaffName" class="text-break"></strong>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small">Date:</span>
                            <span id="rejectLeaveDate" class="fw-semibold"></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small">Type:</span>
                            <span id="rejectLeaveType" class="badge bg-secondary-subtle text-dark border"></span>
                        </div>
                        <div class="pt-2 border-top">
                            <span class="text-muted small d-block mb-1">Reason:</span>
                            <div id="rejectLeaveReason" class="small text-break"></div>
                        </div>
                    </div>

                    <div class="mb-0">
                        <label for="rejectAdminNote" class="form-label small fw-semibold">Reason for Rejection (optional)</label>
                        <textarea id="rejectAdminNote" name="admin_note" class="form-control" rows="2" placeholder="e.g. Insufficient team coverage on this date. Please reschedule."></textarea>
                        <div class="form-hint mt-1 small text-muted">Explains to the employee why their request was rejected.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Reject</button>
                </div>
            </form>
        </div>
    </div>
</div>



<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
