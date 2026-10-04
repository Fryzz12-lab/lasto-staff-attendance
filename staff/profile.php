<?php
/**
 * Staff — My Profile
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$user = current_user();
$userId = (int) $user['id'];
$balances = employee_balances($userId);

if (is_post()) {
    require_csrf();
    $action = (string) post('action', 'profile');

    if ($action === 'password') {
        $current = (string) post('current_password');
        $new = (string) post('new_password');
        $confirm = (string) post('confirm_password');
        if (!password_verify($current, $user['password_hash'])) {
            flash_set('danger', __('msg_password_incorrect'));
        } elseif (($policyError = password_policy_error($new)) !== null) {
            flash_set('danger', __($policyError));
        } elseif ($new !== $confirm) {
            flash_set('danger', __('msg_password_mismatch'));
        } else {
            $hash = hash_new_password($new);
            $stmt = db()->prepare('UPDATE users SET password_hash = ?, auth_version=auth_version+1 WHERE id = ?');
            $stmt->execute([$hash, $userId]);
            $_SESSION['auth_version'] = (int) ($user['auth_version'] ?? 0) + 1;
            flash_set('success', __('msg_password_updated'));
        }
        redirect('/staff/profile.php');
    }

    if ($action === 'verify_email') {
        if (empty($user['email']) || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            flash_set('danger', 'Your account does not have a valid email address. Contact an administrator.');
        } else {
            $token = recovery_issue_token($userId, 'verify_email');
            $sent = recovery_send_link((string) $user['email'], $token, 'verify_email', (string) $user['full_name']);
            flash_set($sent ? 'success' : 'danger', $sent ? 'Verification email sent. Check your inbox and spam folder.' : 'Verification email could not be sent. Contact an administrator.');
        }
        redirect('/staff/profile.php');
    }

    if ($action === 'profile') {
        $phone = normalize_account_phone((string) post('phone'));
        $jobPosition = trim((string) post('job_position'));
        $endDate = trim((string) post('employment_end_date'));
        if ($endDate !== '') {
            $parsedEndDate = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
            if (!$parsedEndDate || $parsedEndDate->format('Y-m-d') !== $endDate) {
                flash_set('danger', __('msg_end_date_invalid'));
                redirect('/staff/profile.php');
            }
            if (!empty($user['joining_date']) && $endDate < $user['joining_date']) {
                flash_set('danger', __('msg_end_before_joining'));
                redirect('/staff/profile.php');
            }
        }
        if (!valid_account_phone($phone)) {
            flash_set('danger', __('msg_phone_invalid'));
            redirect('/staff/profile.php');
        }
        if (mb_strlen($jobPosition) > 100) {
            flash_set('danger', __('msg_job_position_too_long'));
            redirect('/staff/profile.php');
        }
        db()->prepare('UPDATE users SET phone=?, job_position=?, employment_end_date=? WHERE id=?')
            ->execute([$phone !== '' ? $phone : null, $jobPosition !== '' ? $jobPosition : null, $endDate !== '' ? $endDate : null, $userId]);
        flash_set('success', __('msg_profile_updated'));
        redirect('/staff/profile.php');
    }
}

$user = current_user();
$pageTitle = __('my_profile');
$activeMenu = 'profile';
$isAdminArea = false;
require __DIR__ . '/../includes/layout_header.php';
?>

<h2 class="h4 mb-3 d-none d-lg-block"><?= e(__('my_profile')) ?></h2>

<div class="row g-3 mb-3">
    <div class="col-md-6"><div class="card-soft p-3"><div class="form-hint">Replacement Off available</div><div class="h3 mb-0"><?= e((string)$balances['replacement_off']) ?> <?= e($balances['replacement_off'] === 1 ? 'day' : 'days') ?></div></div></div>
    <div class="col-md-6"><div class="card-soft p-3"><div class="form-hint">Cuti Perlu Ganti</div><div class="h3 mb-0"><?= e((string)$balances['cuti_perlu_ganti']) ?> <?= e($balances['cuti_perlu_ganti'] === 1 ? 'day' : 'days') ?></div></div></div>
</div>

<div class="row g-3 staff-profile-row">
    <div class="col-lg-8 d-flex">
        <div class="card-soft staff-profile-card w-100 d-flex flex-column">
            <div class="card-header"><?= e(__('personal_info')) ?></div>
            <div class="card-body d-flex flex-column flex-grow-1">
                <form method="post" class="d-flex flex-column flex-grow-1">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="profile">
                    <div class="row g-2 g-md-2">
                        <div class="col-6">
                            <label class="form-label text-truncate d-block"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i><?= e(__('employee_id')) ?></label>
                            <input class="form-control" value="<?= e((string) $user['employee_id']) ?>" disabled>
                        </div>
                        <div class="col-6">
                            <label class="form-label"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i><?= e(__('username')) ?></label>
                            <input class="form-control" value="<?= e($user['username']) ?>" disabled>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i><?= e(__('full_name')) ?></label>
                            <div class="form-control staff-profile-name-field" role="textbox" aria-readonly="true"><?= e($user['full_name']) ?></div>
                        </div>
                        <div class="col-6">
                            <label class="form-label"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i><?= e(__('email')) ?></label>
                            <input type="email" class="form-control" value="<?= e((string) $user['email']) ?>" disabled>
                            <div class="form-hint mt-1">
                                <?= !empty($user['email_verified_at']) ? '<span class="email-status email-status--verified">Verified</span>' : '<span class="email-status email-status--pending">Not verified</span>' ?>
                            </div>
                            <?php if (empty($user['email_verified_at'])): ?>
                                <form method="post" class="mt-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="verify_email">
                                    <button class="btn btn-sm btn-outline-info" <?= empty($user['email']) ? 'disabled' : '' ?>>Send verification email</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div class="col-6">
                            <label class="form-label"><?= e(__('phone')) ?></label>
                            <div class="input-group">
                                <span class="input-group-text" id="staff-phone-prefix">+60</span>
                                <input type="tel" name="phone" inputmode="numeric" maxlength="10" pattern="[1-9][0-9]{8,9}" aria-label="<?= e(__('phone')) ?>" aria-describedby="staff-phone-prefix" class="form-control" value="<?= e((string) $user['phone']) ?>" placeholder="123456789">
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="profile-job-position"><?= e(__('job_position')) ?></label>
                            <input id="profile-job-position" name="job_position" class="form-control" maxlength="100" value="<?= e((string) $user['job_position']) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label text-truncate d-block"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i><?= e(__('joining_date')) ?></label>
                            <input class="form-control" value="<?= e(format_date($user['joining_date'])) ?>" disabled>
                        </div>
                        <div class="col-6">
                            <label class="form-label text-truncate d-block" for="profile-end-date"><?= e(__('employment_end_date')) ?></label>
                            <input type="date" id="profile-end-date" name="employment_end_date" class="form-control" value="<?= e((string) ($user['employment_end_date'] ?? '')) ?>" min="<?= e((string) ($user['joining_date'] ?? '')) ?>">
                        </div>
                    </div>
                    <div class="staff-profile-actions mt-auto pt-4">
                        <button class="btn btn-accent w-100 w-md-auto py-2 fw-semibold shadow-sm"><i class="bi bi-check2-circle me-1"></i><?= e(__('save')) ?></button>
                    </div>
                </form>
            </div>
        </div>

    </div>
    <div class="col-lg-4 d-flex">
        <div class="card-soft staff-profile-card w-100 d-flex flex-column">
            <div class="card-header"><?= e(__('change_password')) ?></div>
            <div class="card-body d-flex flex-column flex-grow-1">
                <form method="post" class="d-flex flex-column flex-grow-1" data-password-form novalidate
                      data-required-message="<?= e(__('password_fields_required')) ?>"
                      data-short-message="<?= e(__('password_minimum_8')) ?>"
                      data-mismatch-message="<?= e(__('msg_password_mismatch')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="password">
                    <div class="alert alert-danger d-none" role="alert" data-password-error></div>
                    <div class="mb-3">
                        <label class="form-label" for="current-password"><?= e(__('current_password')) ?></label>
                        <div class="password-field">
                            <input type="password" id="current-password" name="current_password" class="form-control" autocomplete="current-password" required>
                            <button type="button" class="password-toggle" data-password-toggle aria-controls="current-password" aria-label="<?= e(__('show_password')) ?>" aria-pressed="false" data-show-label="<?= e(__('show_password')) ?>" data-hide-label="<?= e(__('hide_password')) ?>" hidden><i class="bi bi-eye" aria-hidden="true"></i></button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="new-password"><?= e(__('new_password')) ?></label>
                        <div class="password-field">
                            <input type="password" id="new-password" name="new_password" class="form-control" autocomplete="new-password" aria-describedby="password-guidance" required>
                            <button type="button" class="password-toggle" data-password-toggle aria-controls="new-password" aria-label="<?= e(__('show_password')) ?>" aria-pressed="false" data-show-label="<?= e(__('show_password')) ?>" data-hide-label="<?= e(__('hide_password')) ?>" hidden><i class="bi bi-eye" aria-hidden="true"></i></button>
                        </div>
                        <div class="form-hint mt-1" id="password-guidance"><?= e(__('password_guidance')) ?></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="confirm-password"><?= e(__('confirm_password')) ?></label>
                        <div class="password-field">
                            <input type="password" id="confirm-password" name="confirm_password" class="form-control" autocomplete="new-password" required>
                            <button type="button" class="password-toggle" data-password-toggle aria-controls="confirm-password" aria-label="<?= e(__('show_password')) ?>" aria-pressed="false" data-show-label="<?= e(__('show_password')) ?>" data-hide-label="<?= e(__('hide_password')) ?>" hidden><i class="bi bi-eye" aria-hidden="true"></i></button>
                        </div>
                    </div>
                    <div class="staff-profile-actions mt-auto pt-4">
                        <button class="btn btn-accent w-100 w-md-auto py-2 fw-semibold shadow-sm"><i class="bi bi-shield-lock me-1"></i><?= e(__('update_password')) ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card-soft staff-profile-card mt-3 notification-settings-card">
    <div class="card-header" data-desktop-label="<?= e(__('notice_desktop')) ?>"><i class="bi bi-bell me-1"></i> <?= e(__('notice_phone')) ?></div>
    <div class="card-body">
        <p class="form-hint mb-3"><?= e(__('notice_staff_hint')) ?></p>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <label class="notification-preference notification-preference--all" data-push-label="<?= e(__('notice_push')) ?>"><span data-allow-label="<?= e(__('notice_allow_short')) ?>"><i class="bi bi-bell-fill me-1"></i> <?= e(__('notice_allow_all')) ?></span><input type="checkbox" id="allowAllNotifications" class="notification-toggle"><span class="notification-switch" aria-hidden="true"></span></label>
            <span class="form-hint" id="notificationStatus" role="status" data-off="<?= e(__('notice_off')) ?>" data-enabled="<?= e(__('notice_enabled')) ?>" data-not-enabled="<?= e(__('notice_not_enabled')) ?>" data-blocked="<?= e(__('notice_blocked')) ?>" data-unsupported="<?= e(__('notice_unsupported')) ?>"><?= e(__('notice_not_enabled')) ?></span>
        </div>
        <div class="notification-preferences">
            <?php foreach ([
                'break_ending' => 'Break ending soon',
                'shift_ending' => 'Shift ending soon',
                'leave_decision' => 'Leave / WFH decision',
                'missing_clock_out' => 'Missing clock-out reminder',
            ] as $key => $notice): ?>
                <label class="notification-preference"><span><?= e(__('notice_' . $key)) ?></span><input type="checkbox" class="notification-option" data-notification-key="<?= e($key) ?>"><span class="notification-checkbox" aria-hidden="true">✓</span></label>
            <?php endforeach; ?>
        </div>
    </div>
<div class="card-soft staff-profile-card mt-3">
    <div class="card-header"><i class="bi bi-sliders me-1"></i> <?= e(__('display_preferences')) ?></div>
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div>
                <strong class="d-block text-white-emphasis"><i class="bi bi-globe2 me-1 text-accent"></i> <?= e(__('globe_3d')) ?></strong>
                <p class="form-hint mb-0"><?= e(__('globe_3d_hint')) ?></p>
            </div>
            <div>
                <label class="notification-preference m-0" data-label-on="<?= e(__('globe_on')) ?>" data-label-off="<?= e(__('globe_off')) ?>">
                    <span class="globe-profile-label"><?= e(__('globe_on')) ?></span>
                    <input type="checkbox" id="globeProfileToggleStaff" class="notification-toggle globe-profile-toggle" checked>
                    <span class="notification-switch" aria-hidden="true"></span>
                </label>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
