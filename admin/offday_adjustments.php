<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();
if (is_post()) {
    require_csrf();
    try {
        $adminId = (int) current_user()['id']; $note = trim((string) post('admin_note'));
        if (post('action') === 'reverse') reverse_offday_adjustment((int) post('review_id'), $adminId, $note);
        else decide_offday_adjustment((int) post('review_id'), (string) post('decision'), $adminId, $note, post('override_negative') === '1');
        flash_set('success', __('offday_decision_saved'));
    } catch (Throwable $e) { flash_set('danger', $e instanceof InvalidArgumentException ? $e->getMessage() : __('msg_process_fail')); error_log((string) $e); }
    redirect('/admin/offday_adjustments.php');
}
$status = (string) get('status', 'pending'); if (!in_array($status, ['pending','decided','all'], true)) $status = 'pending'; $search = trim((string) get('search', ''));
$where = $status === 'all' ? '' : ' AND r.status = ?'; $params = $status === 'all' ? [] : [$status];
if ($search !== '') { $where .= ' AND (u.full_name LIKE ? OR u.employee_id LIKE ?)'; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }
$stmt = db()->prepare("SELECT r.*,a.work_date,a.clock_in,a.clock_out,a.total_break_minutes,a.shift_minutes,a.work_minutes,u.full_name,u.employee_id,
 (SELECT COALESCE(SUM(l.amount),0) FROM staff_balance_ledger l WHERE l.employee_id=u.id AND l.balance_type='replacement_off') replacement_balance,
 (SELECT COALESCE(SUM(l.amount),0) FROM staff_balance_ledger l WHERE l.employee_id=u.id AND l.balance_type='cuti_perlu_ganti') cuti_balance
 FROM offday_adjustment_reviews r JOIN attendance a ON a.id=r.attendance_id JOIN users u ON u.id=r.employee_id WHERE 1=1{$where} ORDER BY r.created_at DESC LIMIT 100");
$stmt->execute($params); $rows = $stmt->fetchAll(); $pageTitle = __('offday_adjustments'); $activeMenu = 'offday_adjustments'; $isAdminArea = true; $bodyClass = 'admin-offday-adjustments'; require __DIR__ . '/../includes/layout_header.php';
?>
<style>
.admin-offday-adjustments .app-content { background: rgba(248, 250, 252, .72); position: relative; z-index: 1; }
.admin-offday-adjustments #chronoAmbient canvas { opacity: .22; }
.offday-results-card { width: 100%; }
.offday-results-summary { color: #64748b; }
.offday-filter-toolbar { width: 100%; display: grid !important; grid-template-columns: minmax(0, 1fr) 220px auto; gap: .5rem; }
.offday-search { min-width: 0; }
.offday-filter-toolbar .btn { height: 38px; }
.offday-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 1rem; box-shadow: 0 1px 3px rgba(15, 23, 42, .06); }
.offday-card > .card-body { padding: 1.25rem 1.5rem; }
.offday-card table { width: 100%; border-collapse: collapse; }
.offday-card th { padding: .75rem 1rem; background: #f8fafc; color: #64748b; font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; text-align: left; }
.offday-card td { padding: .9rem 1rem; border-top: 1px solid #f1f5f9; vertical-align: middle; }
.offday-summary-row { height: 56px; }
.offday-badge { display: inline-flex; border-radius: 999px; padding: .2rem .5rem; background: #f1f5f9; color: #475569; font-size: .75rem; font-weight: 600; }
.offday-earned { background: #ecfdf5; color: #047857; }
.offday-owed { background: #fff7ed; color: #c2410c; }
.btn-review { border: 1px solid #0f766e; background: #fff; color: #0f766e; border-radius: .5rem; padding: .35rem .65rem; font-size: .8rem; font-weight: 600; }
.decision-row { background: #f8fafc; }
.decision-row.hidden { display: none; }
.decision-panel { padding: 1rem; }
.decision-grid { display: grid; grid-template-columns: 1fr 1.2fr; gap: 1rem; }
.decision-summary, .decision-form { background: #fff; border: 1px solid #e2e8f0; border-radius: .75rem; padding: 1rem; }
.decision-summary dl { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; margin: 0; }
.decision-summary dt { font-size: .7rem; color: #64748b; text-transform: uppercase; }
.decision-summary dd { margin: .15rem 0 0; font-weight: 600; color: #0f172a; }
.decision-form textarea { min-height: 4rem; resize: vertical; }

@media (min-width: 768px) {
  .offday-adjustments-mobile { display: none !important; }
}

@media (max-width: 767.98px) {
  .admin-offday-adjustments .offday-filter-toolbar { grid-template-columns: 1fr; }
  .admin-offday-adjustments .offday-filter-toolbar .btn { width: 100%; }
  .admin-offday-adjustments .table-responsive-card { display: none !important; }
  .admin-offday-adjustments .offday-results-card { background: transparent !important; border: none !important; box-shadow: none !important; }
  .admin-offday-adjustments .offday-results-card > .card-body { padding: 0 !important; }
  .admin-offday-adjustments .offday-adjustments-mobile { display: grid; gap: .75rem; }
  .admin-offday-adjustments .offday-adjustment-mobile { padding: .85rem; border: 1px solid var(--border); border-radius: var(--radius, 12px); background: var(--surface); box-shadow: 0 4px 14px rgba(15, 23, 42, .06); }
  .admin-offday-adjustments .offday-mobile__top { display: flex; align-items: flex-start; justify-content: space-between; gap: .65rem; }
  .admin-offday-adjustments .offday-mobile__top strong { display: block; font-size: 1rem; line-height: 1.3; overflow-wrap: anywhere; }
  .admin-offday-adjustments .offday-mobile__top span:not(.offday-mobile__status) { display: block; color: var(--muted); font-size: .78rem; margin-top: .15rem; }
  .admin-offday-adjustments .offday-mobile__status { flex: 0 0 auto; padding: .2rem .55rem; border-radius: 999px; font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
  .admin-offday-adjustments .status-pending { color: #f59e0b; background: rgba(245, 158, 11, .16); border: 1px solid rgba(245, 158, 11, .3); }
  .admin-offday-adjustments .status-decided { color: #10b981; background: rgba(16, 185, 129, .14); border: 1px solid rgba(16, 185, 129, .3); }
  .admin-offday-adjustments .offday-mobile__summary { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: .35rem .6rem; margin-top: .6rem; padding-top: .6rem; border-top: 1px solid var(--border); color: var(--muted); font-size: .78rem; line-height: 1.35; }
  .admin-offday-adjustments .offday-mobile__summary span:first-child { color: var(--text); font-weight: 500; }
  .admin-offday-adjustments .offday-mobile__summary span:nth-child(2) { grid-column: 1; color: var(--muted); font-size: .76rem; }
  .admin-offday-adjustments .offday-mobile__review { grid-column: 2; grid-row: 1 / span 2; align-self: center; min-width: 82px; min-height: 36px; }
  .admin-offday-adjustments .offday-adjustment-mobile section { display: none; margin-top: .8rem; padding-top: .75rem; border-top: 1px dashed var(--border); }
  .admin-offday-adjustments .offday-adjustment-mobile.is-expanded section { display: block; }
  .admin-offday-adjustments .offday-adjustment-mobile.is-expanded .offday-mobile__review i { transform: rotate(180deg); }
  .admin-offday-adjustments section h6 { margin: 0 0 .5rem; color: var(--muted); font-size: .7rem; letter-spacing: .1em; font-weight: 700; }
  .admin-offday-adjustments .offday-mobile__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .55rem .75rem; }
  .admin-offday-adjustments .offday-mobile__grid div { display: grid; gap: .12rem; }
  .admin-offday-adjustments .offday-mobile__grid span { color: var(--muted); font-size: .72rem; }
  .admin-offday-adjustments .offday-mobile__grid strong { font-size: .86rem; }
  .admin-offday-adjustments .offday-mobile__decision { display: grid; gap: .55rem; }
  .admin-offday-adjustments .offday-mobile__decision label { font-size: .8rem; line-height: 1.35; }
  .admin-offday-adjustments .offday-mobile__decision button { min-height: 38px; }
}

html[data-theme='night'] .admin-offday-adjustments .app-content { background: color-mix(in srgb, var(--surface-solid) 78%, transparent); }
html[data-theme='night'] .admin-offday-adjustments #chronoAmbient canvas { opacity: .16; }
html[data-theme='night'] .admin-offday-adjustments .offday-results-summary { color: var(--muted); }
html[data-theme='night'] .admin-offday-adjustments .offday-card { background: var(--surface-2); border-color: var(--border); }
html[data-theme='night'] .admin-offday-adjustments .offday-card th { background: color-mix(in srgb, var(--surface-solid) 82%, #14253a); color: var(--muted); }
html[data-theme='night'] .admin-offday-adjustments .offday-card td { border-color: var(--border); color: var(--text); }
html[data-theme='night'] .admin-offday-adjustments h1, html[data-theme='night'] .admin-offday-adjustments .offday-card strong { color: var(--text); }
html[data-theme='night'] .admin-offday-adjustments .form-hint, html[data-theme='night'] .admin-offday-adjustments small { color: var(--muted); }
html[data-theme='night'] .admin-offday-adjustments .offday-badge { background: color-mix(in srgb, var(--surface-solid) 78%, #294056); color: var(--text); }
html[data-theme='night'] .admin-offday-adjustments .offday-earned { background: rgba(16, 185, 129, .2); color: #6ee7b7; }
html[data-theme='night'] .admin-offday-adjustments .offday-owed { background: rgba(245, 158, 11, .2); color: #fcd34d; }
html[data-theme='night'] .admin-offday-adjustments input, html[data-theme='night'] .admin-offday-adjustments select, html[data-theme='night'] .admin-offday-adjustments textarea { background-color: var(--surface-solid); border-color: var(--border-strong); color: var(--text); }
html[data-theme='night'] .admin-offday-adjustments input::placeholder, html[data-theme='night'] .admin-offday-adjustments textarea::placeholder { color: var(--muted); }
html[data-theme='night'] .admin-offday-adjustments .decision-row { background: color-mix(in srgb, var(--surface-solid) 88%, #14253a); }
html[data-theme='night'] .admin-offday-adjustments .decision-summary, html[data-theme='night'] .admin-offday-adjustments .decision-form { background: var(--surface); border-color: var(--border); }
html[data-theme='night'] .admin-offday-adjustments .decision-summary h3, html[data-theme='night'] .admin-offday-adjustments .decision-summary dd { color: var(--text); }
html[data-theme='night'] .admin-offday-adjustments .decision-summary dt { color: var(--muted); }
html[data-theme='night'] .admin-offday-adjustments .btn-review { border-color: var(--accent); background: transparent; color: var(--accent); }
</style>

<div class="offday-card mb-3">
    <div class="card-body">
        <div><p class="form-hint mb-0">Review work completed on scheduled off days.</p></div>
        <form method="get" class="offday-filter-toolbar d-flex flex-wrap gap-2 mt-3">
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search staff name or ID..." class="form-control form-control-sm offday-search">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>><?= e(__('filter_pending')) ?></option>
                <option value="decided" <?= $status === 'decided' ? 'selected' : '' ?>><?= e(__('filter_decided')) ?></option>
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>><?= e(__('filter_all')) ?></option>
            </select>
            <button class="btn btn-sm btn-outline-secondary">Search</button>
        </form>
        <div class="offday-results-summary mt-2 small">Showing <?= count($rows) ?> request<?= count($rows) === 1 ? '' : 's' ?><?= $status === 'pending' ? ' pending review' : '' ?></div>
    </div>
</div>

<div class="offday-card offday-results-card">
    <div class="card-body p-0">
        <!-- Desktop Table View -->
        <div class="table-responsive-card">
            <table class="mb-0">
                <thead>
                    <tr>
                        <th>Staff / Date</th>
                        <th>Attendance Details</th>
                        <th>Current Balances</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="4">
                            <div class="offday-empty py-5 text-center text-muted">
                                <?= e(__('no_adjustments_found')) ?>
                                <div class="small mt-1"><?= e(__('no_adjustments_desc')) ?></div>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): $id = (int)$row['id']; $replacement = (int)$row['replacement_balance']; $cuti = (int)$row['cuti_balance']; ?>
                    <tr class="offday-summary-row">
                        <td>
                            <strong><?= e((string)$row['full_name']) ?></strong><br>
                            <span class="offday-badge"><?= e((string)$row['employee_id']) ?></span> <small><?= e(date('d M Y', strtotime((string)$row['work_date']))) ?></small>
                        </td>
                        <td>
                            <?= e(format_time($row['clock_in'])) ?> &ndash; <?= e(format_time($row['clock_out'])) ?><br>
                            <span class="offday-badge"><?= e(format_minutes((int)$row['work_minutes'])) ?></span>
                        </td>
                        <td>
                            <span class="offday-badge offday-earned">Earned <?= $replacement ?>d</span>
                            <span class="offday-badge offday-owed">Owed <?= $cuti ?>d</span>
                        </td>
                        <td>
                            <?php if ($row['status'] === 'pending'): ?>
                                <button type="button" class="btn-review" onclick="toggleDecision(<?= $id ?>)" aria-controls="decision-row-<?= $id ?>">Review</button>
                            <?php else: ?>
                                <span class="offday-badge"><?= e(__('decision_' . (string)$row['decision'])) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr id="decision-row-<?= $id ?>" class="hidden decision-row border-t border-slate-200">
                        <td colspan="4">
                            <div class="decision-panel">
                                <div class="decision-grid">
                                    <section class="decision-summary">
                                        <h3 class="h6">Shift Summary</h3>
                                        <dl>
                                            <div><dt>Clock in</dt><dd><?= e(format_datetime($row['clock_in'])) ?></dd></div>
                                            <div><dt>Clock out</dt><dd><?= e(format_datetime($row['clock_out'])) ?></dd></div>
                                            <div><dt>Shift duration</dt><dd><?= e(format_minutes((int)$row['shift_minutes'])) ?></dd></div>
                                            <div><dt>Breaks</dt><dd><?= e(format_minutes((int)$row['total_break_minutes'])) ?></dd></div>
                                            <div><dt>Actual worked</dt><dd><?= e(format_minutes((int)$row['work_minutes'])) ?></dd></div>
                                        </dl>
                                    </section>
                                    <form method="post" class="decision-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="review_id" value="<?= $id ?>">
                                        <input type="hidden" name="adjustment_id" value="<?= $id ?>">
                                        <label class="form-label" for="decision-<?= $id ?>">Decision</label>
                                        <select id="decision-<?= $id ?>" name="decision" class="form-select mb-2" required>
                                            <option value="replacement_off">Company owes 1 replacement leave day</option>
                                            <option value="settle_cuti_ganti">Employee must replace 1 leave day</option>
                                            <option value="no_adjustment">No adjustment (mistaken clock-in)</option>
                                        </select>
                                        <label class="form-label" for="note-<?= $id ?>">Admin note</label>
                                        <textarea id="note-<?= $id ?>" name="admin_note" class="form-control mb-2" placeholder="Add remarks or justification..."></textarea>
                                        <label class="form-check mb-3">
                                            <input class="form-check-input" type="checkbox" name="override_negative" value="1"> Allow a negative owed balance
                                        </label>
                                        <div>
                                            <button class="btn btn-primary">Save decision</button>
                                            <button type="button" class="btn btn-light border" onclick="toggleDecision(<?= $id ?>)">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Cards View -->
        <div class="offday-adjustments-mobile" aria-label="Off-day adjustments">
            <?php if (!$rows): ?>
                <div class="offday-empty py-5 text-center text-muted">
                    <?= e(__('no_adjustments_found')) ?>
                    <div class="small mt-1"><?= e(__('no_adjustments_desc')) ?></div>
                </div>
            <?php endif; ?>
            <?php foreach ($rows as $row): $id = (int)$row['id']; $replacement = (int)$row['replacement_balance']; $cuti = (int)$row['cuti_balance']; ?>
                <article class="offday-adjustment-mobile" id="offday-mobile-<?= $id ?>">
                    <div class="offday-mobile__top">
                        <div>
                            <strong><?= e((string)$row['full_name']) ?></strong>
                            <span><span class="offday-badge me-1"><?= e((string)$row['employee_id']) ?></span><?= e(date('d M Y', strtotime((string)$row['work_date']))) ?></span>
                        </div>
                        <span class="offday-mobile__status <?= $row['status'] === 'pending' ? 'status-pending' : 'status-decided' ?>">
                            <?= $row['status'] === 'pending' ? 'Pending' : e(__('decision_' . (string)$row['decision'])) ?>
                        </span>
                    </div>

                    <div class="offday-mobile__summary">
                        <span><i class="bi bi-clock me-1"></i><?= e(format_time($row['clock_in'])) ?> &ndash; <?= e(format_time($row['clock_out'])) ?> &middot; <strong><?= e(format_minutes((int)$row['work_minutes'])) ?></strong></span>
                        <span>Earned <strong><?= $replacement ?>d</strong> &middot; Owed <strong><?= $cuti ?>d</strong></span>
                        <?php if ($row['status'] === 'pending'): ?>
                            <button type="button" class="btn btn-sm btn-outline-info offday-mobile__review" onclick="toggleMobileDecision(<?= $id ?>)" id="mobile-rev-btn-<?= $id ?>" aria-expanded="false">
                                Review <i class="bi bi-chevron-down ms-1"></i>
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary offday-mobile__review" onclick="toggleMobileDecision(<?= $id ?>)" id="mobile-rev-btn-<?= $id ?>" aria-expanded="false">
                                Details <i class="bi bi-chevron-down ms-1"></i>
                            </button>
                        <?php endif; ?>
                    </div>

                    <section id="mobile-section-<?= $id ?>">
                        <h6>SHIFT SUMMARY</h6>
                        <div class="offday-mobile__grid mb-3">
                            <div><span>Clock in</span><strong><?= e(format_datetime($row['clock_in'])) ?></strong></div>
                            <div><span>Clock out</span><strong><?= e(format_datetime($row['clock_out'])) ?></strong></div>
                            <div><span>Shift duration</span><strong><?= e(format_minutes((int)$row['shift_minutes'])) ?></strong></div>
                            <div><span>Breaks</span><strong><?= e(format_minutes((int)$row['total_break_minutes'])) ?></strong></div>
                            <div style="grid-column: 1 / -1;"><span>Actual worked</span><strong><?= e(format_minutes((int)$row['work_minutes'])) ?></strong></div>
                        </div>

                        <?php if ($row['status'] === 'pending'): ?>
                            <h6>DECISION</h6>
                            <form method="post" class="offday-mobile__decision">
                                <?= csrf_field() ?>
                                <input type="hidden" name="review_id" value="<?= $id ?>">
                                <input type="hidden" name="adjustment_id" value="<?= $id ?>">
                                <label for="mob-decision-<?= $id ?>" class="form-label mb-0">Select action</label>
                                <select id="mob-decision-<?= $id ?>" name="decision" class="form-select form-select-sm" required>
                                    <option value="replacement_off">Company owes 1 replacement leave day</option>
                                    <option value="settle_cuti_ganti">Employee must replace 1 leave day</option>
                                    <option value="no_adjustment">No adjustment (mistaken clock-in)</option>
                                </select>
                                <label for="mob-note-<?= $id ?>" class="form-label mb-0">Admin note</label>
                                <textarea id="mob-note-<?= $id ?>" name="admin_note" class="form-control form-control-sm" rows="2" placeholder="Add remarks or justification..."></textarea>
                                <label class="form-check small">
                                    <input class="form-check-input" type="checkbox" name="override_negative" value="1"> Allow a negative owed balance
                                </label>
                                <div class="d-flex gap-2 mt-1">
                                    <button class="btn btn-primary btn-sm flex-grow-1">Save decision</button>
                                    <button type="button" class="btn btn-light btn-sm border" onclick="toggleMobileDecision(<?= $id ?>)">Cancel</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <h6>DECISION DETAILS</h6>
                            <div class="offday-mobile__decision-details">
                                <div class="mb-1">
                                    <span class="text-muted small">Decision:</span> <strong><?= e(__('decision_' . (string)$row['decision'])) ?></strong>
                                </div>
                                <?php if (!empty($row['admin_note'])): ?>
                                    <div class="mb-2">
                                        <span class="text-muted small">Note:</span> <div class="small fst-italic text-break"><?= e((string)$row['admin_note']) ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($row['decision'] !== 'no_adjustment'): ?>
                                    <form method="post" class="mt-2">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reverse">
                                        <input type="hidden" name="review_id" value="<?= $id ?>">
                                        <textarea name="admin_note" class="form-control form-control-sm mb-2" rows="2" required placeholder="<?= e(__('reversal_note')) ?>"></textarea>
                                        <button class="btn btn-outline-danger btn-sm w-100"><?= e(__('reverse_decision')) ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
function toggleDecision(id) {
    const row = document.getElementById('decision-row-' + id);
    if (row) row.classList.toggle('hidden');
}
function toggleMobileDecision(id) {
    const card = document.getElementById('offday-mobile-' + id);
    if (!card) return;
    card.classList.toggle('is-expanded');
    const btn = document.getElementById('mobile-rev-btn-' + id);
    if (btn) {
        const isExp = card.classList.contains('is-expanded');
        btn.setAttribute('aria-expanded', isExp ? 'true' : 'false');
    }
}
</script>
<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
