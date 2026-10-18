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

    $stmt = db()->prepare('SELECT * FROM attendance_correction_requests WHERE id = ?');
    $stmt->execute([$id]);
    $req = $stmt->fetch();

    if (!$req || $req['status'] !== 'pending') {
        flash_set('danger', __('msg_request_not_found'));
    } elseif (!in_array($decision, ['approved', 'rejected'], true)) {
        flash_set('danger', __('msg_invalid_decision'));
    } else {
        $adminId = (int) current_user()['id'];
        try {
            with_employee_lock((int) $req['user_id'], function () use ($id, $decision, $note, $adminId): void {
                $stmt = db()->prepare('SELECT * FROM attendance_correction_requests WHERE id=? FOR UPDATE');
                $stmt->execute([$id]);
                $request = $stmt->fetch();
                if (!$request || $request['status'] !== 'pending') {
                    throw new InvalidArgumentException(__('msg_request_not_found'));
                }
                if ($decision === 'approved') {
                    if (!in_array($request['field_name'], ['clock_in', 'clock_out', 'both'], true)) {
                        throw new InvalidArgumentException('Only clock-in and clock-out corrections can be approved here.');
                    }
                    $att = ensure_attendance_row((int) $request['user_id'], $request['work_date']);
                    $newIn = in_array($request['field_name'], ['clock_in', 'both'], true) ? $request['requested_clock_in'] : $att['clock_in'];
                    $newOut = in_array($request['field_name'], ['clock_out', 'both'], true) ? $request['requested_clock_out'] : $att['clock_out'];
                    correct_attendance((int) $att['id'], $newIn, $newOut, $adminId, $request['reason'], 'correction_approved');
                }
                db()->prepare('UPDATE attendance_correction_requests SET status=?, admin_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?')
                    ->execute([$decision, $note ?: null, $adminId, $id]);
            });
            flash_set('success', __('msg_correction_processed', ['status' => __('st_' . $decision)]));
        } catch (InvalidArgumentException $error) {
            flash_set('danger', $error->getMessage());
        } catch (Throwable $error) {
            error_log((string) $error);
            flash_set('danger', __('msg_process_fail'));
        }
    }
    redirect('/admin/corrections.php');
}

$status = (string) get('status', 'pending');
$where = " FROM attendance_correction_requests c
        JOIN users u ON u.id = c.user_id WHERE 1=1";
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

$sql = "SELECT c.*, u.full_name" . $where . " ORDER BY c.created_at DESC LIMIT {$perPage} OFFSET {$offset}";
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
                            <td><?= e($r['full_name']) ?></td>
                            <td><?= e(format_date($r['work_date'])) ?></td>
                            <td>
                                <div class="form-hint"><?= e($fieldLabels[$r['field_name']] ?? $r['field_name']) ?></div>
                                <?php if ($r['requested_clock_in']): ?><?= e(__('in_prefix')) ?>: <?= e(format_time($r['requested_clock_in'])) ?><br><?php endif; ?>
                                <?php if ($r['requested_clock_out']): ?><?= e(__('out_prefix')) ?>: <?= e(format_time($r['requested_clock_out'])) ?><?php endif; ?>
                            </td>
                            <td><?= e($r['reason']) ?></td>
                            <td><?= status_badge($r['status']) ?></td>
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
