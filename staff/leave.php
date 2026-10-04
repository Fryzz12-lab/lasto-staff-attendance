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
require __DIR__ . '/../includes/layout_header.php';
?>

<h2 class="h4 mb-3 d-none d-lg-block"><?= e(__('leave_requests')) ?></h2>

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
                        <div class="mb-3">
                            <label class="form-label"><?= e(__('date')) ?></label>
                            <input type="date" name="leave_date" class="form-control" required min="<?= e(today_str()) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?= e(__('type')) ?></label>
                            <select name="leave_type" class="form-select" required>
                                <option value="medical"><?= e(__('leave_medical')) ?></option>
                                <option value="wfh"><?= e(__('leave_wfh')) ?></option>
                                <option value="annual"><?= e(__('leave_annual')) ?></option>
                                <option value="emergency"><?= e(__('leave_emergency')) ?></option>
                                <option value="other"><?= e(__('leave_other')) ?></option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?= e(__('reason')) ?></label>
                            <textarea name="reason" class="form-control" rows="3" required placeholder="<?= e(__('leave_reason_placeholder')) ?>"></textarea>
                        </div>
                        <button class="btn btn-accent w-100 w-md-auto"><i class="bi bi-send me-1"></i><?= e(__('submit_request')) ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 staff-leave-requests-column">
        <div class="card-soft staff-leave-requests-card">
            <div class="card-header"><?= e(__('my_requests')) ?></div>
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
                                            <div class="small text-info mt-1"><i class="bi bi-chat-left-dots me-1"></i><strong>Admin:</strong> <?= e($r['admin_note']) ?></div>
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
