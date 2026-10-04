<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

if (is_post()) {
    require_csrf();
    try {
        if (post('action') === 'reverse') {
            reverse_offday_adjustment((int)post('review_id'), (int)current_user()['id'], trim((string)post('admin_note')));
        } else {
            decide_offday_adjustment((int)post('review_id'), (string)post('decision'), (float)post('amount'), (int)current_user()['id'], trim((string)post('admin_note')), post('override_negative') === '1');
        }
        flash_set('success', 'Off-day adjustment decision saved.');
    } catch (Throwable $e) { flash_set('danger', $e instanceof InvalidArgumentException ? $e->getMessage() : __('msg_process_fail')); error_log((string)$e); }
    redirect('/admin/offday_adjustments.php');
}

$status = (string)get('status', 'pending');
$where = $status === 'all' ? '' : ' AND r.status = ?';
$params = $status === 'all' ? [] : [$status];
$stmt = db()->prepare("SELECT r.*, a.work_date,a.clock_in,a.clock_out,a.total_break_minutes,a.shift_minutes,a.work_minutes,u.full_name,u.employee_id,
    (SELECT COALESCE(SUM(l.amount),0) FROM staff_balance_ledger l WHERE l.employee_id=u.id AND l.balance_type='replacement_off') replacement_balance,
    (SELECT COALESCE(SUM(l.amount),0) FROM staff_balance_ledger l WHERE l.employee_id=u.id AND l.balance_type='cuti_perlu_ganti') cuti_balance
    FROM offday_adjustment_reviews r JOIN attendance a ON a.id=r.attendance_id JOIN users u ON u.id=r.employee_id WHERE 1=1{$where} ORDER BY r.created_at DESC LIMIT 100");
$stmt->execute($params); $rows = $stmt->fetchAll();
$pageTitle = 'Off-day Adjustments'; $activeMenu = 'offday_adjustments'; $isAdminArea = true;
require __DIR__ . '/../includes/layout_header.php';
?>
<div class="card-soft mb-3"><div class="card-body d-flex justify-content-between align-items-center"><div><h1 class="h4 mb-1">Off-day Adjustments</h1><p class="form-hint mb-0">Review completed work on scheduled off days. No balance changes happen automatically.</p></div><form method="get"><select name="status" class="form-select" onchange="this.form.submit()"><option value="pending" <?= $status==='pending'?'selected':'' ?>>Pending</option><option value="decided" <?= $status==='decided'?'selected':'' ?>>Decided</option><option value="all" <?= $status==='all'?'selected':'' ?>>All</option></select></form></div></div>
<div class="card-soft"><div class="table-responsive-card"><table class="table mb-0"><thead><tr><th>Employee / date</th><th>Attendance details</th><th>Balances</th><th>Decision</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="4" class="text-muted">No adjustment reviews found.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?><tr><td><strong><?= e($r['full_name']) ?></strong><br><span class="form-hint"><?= e($r['employee_id']) ?> · <?= e(format_date($r['work_date'])) ?></span></td><td><?= e(format_datetime($r['clock_in'])) ?> → <?= e(format_datetime($r['clock_out'])) ?><br><span class="form-hint">Break <?= e(format_minutes((int)$r['total_break_minutes'])) ?> · Shift <?= e(format_minutes((int)$r['shift_minutes'])) ?> · Work <?= e(format_minutes((int)$r['work_minutes'])) ?></span></td><td>Replacement Off: <strong><?= e(number_format((float)$r['replacement_balance'],2)) ?></strong><br>Cuti Ganti: <strong><?= e(number_format((float)$r['cuti_balance'],2)) ?></strong></td><td><?php if ($r['status']==='pending'): ?><form method="post" class="d-grid gap-1" style="min-width:220px"><?= csrf_field() ?><input type="hidden" name="review_id" value="<?= (int)$r['id'] ?>"><select name="decision" class="form-select form-select-sm" required><option value="replacement_off">Earn Replacement Off</option><option value="settle_cuti_ganti">Settle Cuti Ganti</option><option value="no_adjustment">No Adjustment</option></select><input name="amount" type="number" min="0" max="99.99" step="0.01" value="1.00" class="form-control form-control-sm" required><textarea name="admin_note" class="form-control form-control-sm" rows="2" placeholder="Admin note"></textarea><label class="small"><input type="checkbox" name="override_negative" value="1"> Explicit negative-balance override</label><button class="btn btn-sm btn-accent">Save decision</button></form><?php else: ?><span class="badge text-bg-secondary"><?= e((string)$r['decision']) ?></span><br><?= e(number_format((float)($r['amount'] ?? 0),2)) ?> days<br><span class="form-hint"><?= e((string)($r['admin_note'] ?? '')) ?></span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
