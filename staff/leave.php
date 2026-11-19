<?php
/**
 * Staff — Leave / Work From Home requests
 * (Replaces clock-time correction requests for staff)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

if (!setting_bool('enable_leave_module', true)) {
    flash_set('warning', __('msg_leave_disabled'));
    redirect('/staff/');
}

$userId = (int) current_user()['id'];

if (is_post()) {
    require_csrf();
    $date = trim((string) post('leave_date'));
    $type = (string) post('leave_type', 'medical');
    $reason = trim((string) post('reason'));
    try {
        submit_leave_request($userId, $date, $type, $reason);
        flash_set('success', __('msg_leave_submitted'));
        redirect('/staff/leave.php');
    } catch (InvalidArgumentException $error) {
        flash_set('danger', $error->getMessage());
    } catch (Throwable $error) {
        error_log((string) $error);
        flash_set('danger', __('msg_process_fail'));
    }
}

$stmt = db()->prepare('SELECT * FROM leave_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
$stmt->execute([$userId]);
$rows = $stmt->fetchAll();

// Clear "decision ready" sidebar badge for this staff member
chrono_mark_leave_updates_seen();

$pendingCount = 0;
$approvedCount = 0;
$rejectedCount = 0;
foreach ($rows as $r) {
    if ($r['status'] === 'pending') {
        $pendingCount++;
    } elseif ($r['status'] === 'approved') {
        $approvedCount++;
    } elseif ($r['status'] === 'rejected') {
        $rejectedCount++;
    }
}
$isFormOpen = empty($rows) || is_post();

$pageTitle = __('leave_requests');
$activeMenu = 'leave';
$isAdminArea = false;
$bodyClass = 'staff-history-page staff-leave-page';
require __DIR__ . '/../includes/layout_header.php';
?>

<h2 class="h4 mb-3 d-none d-lg-block"><?= e(__('leave_requests')) ?></h2>

<style>
.profile-balance-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0;background:#fff;border:1px solid rgba(226,232,240,.8);border-radius:1rem;box-shadow:0 1px 3px rgba(15,23,42,.06);padding:1.25rem}
.profile-balance-card{min-width:0;padding:0 .9rem}
.profile-balance-card + .profile-balance-card{border-left:1px solid rgba(148,163,184,.35)}
.profile-balance-card__header{display:flex;align-items:center;gap:.65rem;color:#475569;font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
.profile-balance-card__icon{display:inline-flex;width:2rem;height:2rem;align-items:center;justify-content:center;border-radius:.65rem;font-size:1rem}
.profile-balance-card__value{display:flex;align-items:baseline;gap:.55rem;margin-top:1rem}.profile-balance-card__number{font-size:2.25rem;font-weight:700;line-height:1;color:#0f172a}.profile-balance-card__unit{font-size:.875rem;font-weight:600;color:#64748b}.profile-balance-card__description{margin:.65rem 0 0;color:#64748b;font-size:.875rem}
</style>
<?php $balances = employee_balances($userId); ?>
<?php $earnedDays = (int)($balances['replacement_off'] ?? 0); $owedDays = (int)($balances['cuti_perlu_ganti'] ?? 0); ?>
<section class="profile-balance-grid mb-3" aria-label="Leave balance">
    <article class="profile-balance-card">
        <div class="profile-balance-card__header"><span class="profile-balance-card__icon" style="background:#ecfdf5;color:#059669"><i class="bi bi-calendar-check" aria-hidden="true"></i></span><span><?= e(__('leave_earned_title')) ?></span></div>
        <div class="profile-balance-card__value"><span class="profile-balance-card__number"><?= e((string)$earnedDays) ?></span><span class="profile-balance-card__unit"><?= e(__('days_available')) ?></span></div>
        <p class="profile-balance-card__description mb-0"><?= e(__('leave_earned_desc')) ?></p>
    </article>
    <article class="profile-balance-card">
        <div class="profile-balance-card__header"><span class="profile-balance-card__icon" style="background:#fffbeb;color:#d97706"><i class="bi bi-arrow-repeat" aria-hidden="true"></i></span><span><?= e(__('leave_replace_title')) ?></span></div>
        <div class="profile-balance-card__value"><span class="profile-balance-card__number"><?= e((string)$owedDays) ?></span><span class="profile-balance-card__unit"><?= e(__('days_unit')) ?></span></div>
        <p class="profile-balance-card__description mb-0"><?= e($owedDays > 0 ? __('leave_replace_desc') : __('leave_replace_clear')) ?></p>
    </article>
</section>


<!-- Mobile Summary Chips (< 768px) -->
<div class="d-md-none mb-3">
    <div class="d-flex gap-2">
        <div class="flex-fill p-2 rounded text-center leave-mobile-stat-card leave-mobile-stat-card--pending">
            <span class="d-block text-muted" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em;"><?= e(__('st_pending')) ?></span>
            <span class="fw-bold font-mono fs-5 leave-mobile-stat-card__pending-count"><?= $pendingCount ?></span>
        </div>
        <div class="flex-fill p-2 rounded text-center leave-mobile-stat-card">
            <span class="d-block text-muted" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em;"><?= e(__('st_approved')) ?></span>
            <span class="fw-bold font-mono text-success fs-5"><?= $approvedCount ?></span>
        </div>
        <div class="flex-fill p-2 rounded text-center leave-mobile-stat-card">
            <span class="d-block text-muted" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em;"><?= e(__('st_rejected')) ?></span>
            <span class="fw-bold font-mono text-danger fs-5"><?= $rejectedCount ?></span>
        </div>
    </div>
</div>

<!-- Mobile Toggle Button for New Request Form (< 768px) -->
<?php if (!empty($rows)): ?>
    <div class="d-md-none mb-3">
        <button class="btn btn-accent w-100 py-2 fw-semibold shadow-sm d-flex align-items-center justify-content-center gap-2" type="button" data-bs-toggle="collapse" data-bs-target="#newRequestCard" aria-expanded="<?= $isFormOpen ? 'true' : 'false' ?>" aria-controls="newRequestCard">
            <i class="bi bi-calendar-plus"></i>
            <span><?= e(__('new_request')) ?></span>
            <i class="bi bi-chevron-down staff-leave-toggle-chevron" aria-hidden="true"></i>
        </button>
    </div>
<?php endif; ?>

<div class="row g-3 staff-leave-layout">
    <div class="col-12">
        <button class="staff-leave-new-request-toggle d-none d-md-inline-flex align-items-center gap-3" type="button" data-bs-toggle="collapse" data-bs-target="#newRequestCard" aria-expanded="<?= $isFormOpen ? 'true' : 'false' ?>" aria-controls="newRequestCard">
            <span><i class="bi bi-plus-lg me-2" aria-hidden="true"></i><?= e(__('new_request')) ?></span>
            <i class="bi bi-chevron-down" aria-hidden="true"></i>
        </button>
        <div class="collapse <?= $isFormOpen ? 'show' : '' ?>" id="newRequestCard">
            <div class="card-soft mb-3 mb-md-0">
                <div class="card-header"><?= e(__('new_request')) ?></div>
                <div class="card-body">
                    <p class="form-hint mb-3"><?= e(__('leave_wfh_hint')) ?></p>
                    <form method="post">
                        <?= csrf_field() ?>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                            <label class="form-label" for="leave_date"><?= e(__('date')) ?></label>
                            <input type="date" id="leave_date" name="leave_date" class="form-control" required min="<?= e(today_str()) ?>">
                            </div>
                            <div class="col-12 col-md-6">
                            <label class="form-label" for="leave_type"><?= e(__('type')) ?></label>
                            <select id="leave_type" name="leave_type" class="form-select" required>
                                <option value="medical"><?= e(__('leave_medical')) ?></option>
                                <option value="wfh"><?= e(__('leave_wfh')) ?></option>
                                <option value="annual"><?= e(__('leave_annual')) ?></option>
                                <option value="emergency"><?= e(__('leave_emergency')) ?></option>
                                <option value="other"><?= e(__('leave_other')) ?></option>
                            </select>
                            </div>
                            <div class="col-12">
                            <label class="form-label" for="reason"><?= e(__('reason')) ?></label>
                            <textarea id="reason" name="reason" class="form-control" rows="3" required placeholder="<?= e(__('leave_reason_placeholder')) ?>"></textarea>
                            </div>
                        </div>
                        <button class="btn btn-accent w-100 w-md-auto mt-3"><i class="bi bi-send me-1"></i><?= e(__('submit_request')) ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 staff-leave-requests-column">
        <div class="card-soft staff-leave-requests-card">
            <div class="card-header staff-leave-requests-header">
                <span><?= e(__('my_requests')) ?></span>
                <div class="staff-leave-summary-stats d-none d-md-flex" aria-label="Request summary">
                    <span class="staff-leave-summary-pill staff-leave-summary-pill--pending"><?= e(__('st_pending')) ?> <strong><?= $pendingCount ?></strong></span>
                    <span class="staff-leave-summary-pill staff-leave-summary-pill--approved"><?= e(__('st_approved')) ?> <strong><?= $approvedCount ?></strong></span>
                    <span class="staff-leave-summary-pill staff-leave-summary-pill--rejected"><?= e(__('st_rejected')) ?> <strong><?= $rejectedCount ?></strong></span>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (!$rows): ?>
                    <div class="empty-state"><?= e(__('no_leave')) ?></div>
                <?php else: ?>
                    <!-- Desktop request table -->
                    <div class="table-responsive-card d-none d-md-block">
                        <table class="table mb-0 staff-leave-history-table">
                            <thead>
                            <tr>
                                <th><?= e(__('date')) ?></th>
                                <th><?= e(__('type')) ?></th>
                                <th><?= e(__('reason')) ?></th>
                                <th><?= e(__('status')) ?></th>
                                <th><?= e(__('submitted')) ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><?= e(format_date($r['leave_date'])) ?></td>
                                    <td><?= e(__('leave_' . $r['leave_type'])) ?></td>
                                    <td>
                                        <div><?= e($r['reason']) ?></div>
                                        <?php if (!empty($r['admin_note'])): ?>
                                            <div class="staff-leave-admin-feedback small mt-2"><div class="staff-leave-admin-feedback__label"><i class="bi bi-chat-left-dots me-1"></i>Admin Feedback</div><div><?= e($r['admin_note']) ?></div></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= status_badge($r['status']) ?></td>
                                    <td><?= e(format_datetime($r['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile Cards List (< 768px: Clean, touch-friendly, zero horizontal scroll) -->
                    <div class="leave-mobile-list d-md-none p-2 d-flex flex-column gap-2">
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $typeIcon = match($r['leave_type']) {
                                'wfh' => 'bi-house-door text-info',
                                'medical' => 'bi-heart-pulse text-danger',
                                'annual' => 'bi-sun text-warning',
                                'emergency' => 'bi-exclamation-triangle text-warning',
                                'day_off' => 'bi-moon-stars text-secondary',
                                default => 'bi-calendar-event text-primary',
                            };
                            ?>
                            <div class="leave-mobile-card p-3 rounded">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="leave-mobile-icon-box rounded d-flex align-items-center justify-content-center">
                                            <i class="bi <?= $typeIcon ?> fs-5"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-white-emphasis"><?= e(format_date($r['leave_date'])) ?></div>
                                            <div class="small text-muted"><?= e(__('leave_' . $r['leave_type'])) ?></div>
                                        </div>
                                    </div>
                                    <div>
                                        <?= status_badge($r['status']) ?>
                                    </div>
                                </div>
                                <div class="leave-mobile-reason small p-2 rounded mb-2">
                                    <span class="fst-italic text-secondary">"<?= e($r['reason']) ?>"</span>
                                </div>
                                <?php if (!empty($r['admin_note'])): ?>
                                    <div class="leave-mobile-admin-note small p-2 rounded mb-2">
                                        <div class="d-flex align-items-center gap-1 text-info fw-semibold mb-1" style="font-size: 0.72rem; text-transform: uppercase;">
                                            <i class="bi bi-chat-left-dots"></i> Admin Feedback
                                        </div>
                                        <div class="text-white-emphasis"><?= e($r['admin_note']) ?></div>
                                    </div>
                                <?php endif; ?>
                                <div class="text-muted d-flex align-items-center justify-content-between" style="font-size: 0.72rem;">
                                    <span><i class="bi bi-clock-history me-1"></i><?= e(__('submitted')) ?>: <?= e(format_datetime($r['created_at'])) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
