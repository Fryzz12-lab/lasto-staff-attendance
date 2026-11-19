<?php
/**
 * Admin — My Profile
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

$user = current_user();
$userId = (int) $user['id'];

if (is_post()) {
    require_csrf();
    $action = (string) post('action', 'profile');

    if ($action === 'verify_email') {
        if (empty($user['email']) || !recovery_mail_ready()) {
            flash_set('warning', 'Set a real email address and configure mail delivery first.');
        } else {
            $token = recovery_issue_token($userId, 'verify_email');
            $sent = recovery_send_link((string) $user['email'], $token, 'verify_email', (string) $user['full_name']);
            flash_set($sent ? 'success' : 'danger', $sent ? 'Email verification sent.' : 'Email verification could not be sent.');
        }
        redirect('/admin/profile.php');
    }

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
            db()->prepare('UPDATE users SET password_hash = ?, auth_version=auth_version+1 WHERE id = ?')
                ->execute([hash_new_password($new), $userId]);
            $_SESSION['auth_version'] = (int) ($user['auth_version'] ?? 0) + 1;
            flash_set('success', __('msg_password_updated'));
        }
        redirect('/admin/profile.php');
    }

    if ($action === 'profile') {
        $fullName = normalize_full_name((string) post('full_name'));
        $email = strtolower(trim((string) post('email')));
        $phone = normalize_account_phone((string) post('phone'));
        $position = trim((string) post('job_position'));

        if (!valid_account_phone($phone)) {
            flash_set('danger', __('msg_phone_invalid'));
            redirect('/admin/profile.php');
        }

        if ($fullName === '') {
            flash_set('danger', 'Full name is required.');
            redirect('/admin/profile.php');
        }

        if ($email !== '' && !valid_account_email($email)) {
            flash_set('danger', 'Enter a valid email ending in .com, for example username@example.com.');
            redirect('/admin/profile.php');
        }

        if ($email !== '') {
            $eCheck = db()->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
            $eCheck->execute([$email, $userId]);
            if ($eCheck->fetch()) {
                flash_set('danger', 'This email address is already in use by another account.');
                redirect('/admin/profile.php');
            }
        }

        db()->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, job_position = ? WHERE id = ?')
            ->execute([
                $fullName,
                $email !== '' ? $email : null,
                $phone !== '' ? $phone : null,
                $position !== '' ? $position : null,
                $userId
            ]);

        flash_set('success', __('msg_profile_updated'));
        redirect('/admin/profile.php');
    }
}

$user = current_user();
$pageTitle = __('my_profile');
$activeMenu = 'profile';
$isAdminArea = true;
require __DIR__ . '/../includes/layout_header.php';
?>

<div class="row g-3">
    <div class="col-lg-7 d-flex">
        <div class="card-soft w-100 d-flex flex-column">
            <div class="card-header"><?= e(__('account_information')) ?></div>
            <div class="card-body d-flex flex-column flex-grow-1">
                <form method="post" class="d-flex flex-column flex-grow-1">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="profile">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label" for="admin-fullname"><?= e(__('name')) ?></label>
                            <input id="admin-fullname" name="full_name" class="form-control" required value="<?= e($user['full_name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= e(__('username')) ?></label>
                            <input class="form-control" value="<?= e($user['username']) ?>" readonly disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="admin-email"><?= e(__('email')) ?></label>
                            <input id="admin-email" type="email" name="email" class="form-control" value="<?= e((string)($user['email'] ?? '')) ?>" placeholder="admin@company.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="admin-phone"><?= e(__('phone')) ?></label>
                            <div class="input-group">
                                <span class="input-group-text" id="admin-phone-prefix">+60</span>
                                <input id="admin-phone" type="tel" name="phone" inputmode="numeric" maxlength="10" pattern="[1-9][0-9]{8,9}" aria-label="<?= e(__('phone')) ?>" aria-describedby="admin-phone-prefix" class="form-control" value="<?= e((string)($user['phone'] ?? '')) ?>" placeholder="123456789">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="admin-position"><?= e(__('job_position') ?: 'Job Position') ?></label>
                            <input id="admin-position" name="job_position" class="form-control" value="<?= e((string)($user['job_position'] ?? '')) ?>" placeholder="System Administrator">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= e(__('role') ?: 'Role') ?></label>
                            <div class="py-1">
                                <span class="badge admin-role-badge">
                                    <i class="bi bi-shield-check me-1" aria-hidden="true"></i>System Administrator
                                </span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= e(__('joining_date') ?: 'Joining Date') ?></label>
                            <div class="text-muted py-1" style="font-family: var(--font-ui); font-size: 0.95rem;">
                                <i class="bi bi-calendar-event me-2 text-info" aria-hidden="true"></i><?= e(!empty($user['joining_date']) ? format_date($user['joining_date']) : '-') ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= e(__('account_created') ?: 'Account Created') ?></label>
                            <div class="text-muted py-1" style="font-family: var(--font-ui); font-size: 0.95rem;">
                                <i class="bi bi-clock-history me-2 text-info" aria-hidden="true"></i><?= e(!empty($user['created_at']) ? format_date(substr($user['created_at'], 0, 10)) : '-') ?>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3"><button class="btn btn-accent"><?= e(__('save_changes')) ?></button></div>
                </form>
            </div>
        </div>

    </div>
    <div class="col-lg-5 d-flex">
        <div class="card-soft w-100 d-flex flex-column">
            <div class="card-header"><?= e(__('change_password')) ?></div>
            <div class="card-body d-flex flex-column flex-grow-1">
                <form method="post" class="d-flex flex-column flex-grow-1" data-password-form novalidate
                      data-required-message="<?= e(__('password_fields_required')) ?>"
                      data-short-message="<?= e(__('password_minimum_8')) ?>"
                      data-mismatch-message="<?= e(__('msg_password_mismatch')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="password">
                    <div class="alert alert-danger d-none" role="alert" data-password-error></div>
                    <div class="mb-2">
                        <label class="form-label" for="admin-current-password"><?= e(__('current_password')) ?></label>
                        <div class="password-field">
                            <input type="password" id="admin-current-password" name="current_password" class="form-control" autocomplete="current-password" required>
                            <button type="button" class="password-toggle" data-password-toggle aria-controls="admin-current-password" aria-label="<?= e(__('show_password')) ?>" aria-pressed="false" data-show-label="<?= e(__('show_password')) ?>" data-hide-label="<?= e(__('hide_password')) ?>" hidden><i class="bi bi-eye" aria-hidden="true"></i></button>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="admin-new-password"><?= e(__('new_password')) ?></label>
                        <div class="password-field">
                            <input type="password" id="admin-new-password" name="new_password" class="form-control" autocomplete="new-password" required minlength="8">
                            <button type="button" class="password-toggle" data-password-toggle aria-controls="admin-new-password" aria-label="<?= e(__('show_password')) ?>" aria-pressed="false" data-show-label="<?= e(__('show_password')) ?>" data-hide-label="<?= e(__('hide_password')) ?>" hidden><i class="bi bi-eye" aria-hidden="true"></i></button>
                        </div>
                        <div class="form-hint mt-1"><?= e(__('password_guidance')) ?></div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" for="admin-confirm-password"><?= e(__('confirm_password')) ?></label>
                        <div class="password-field">
                            <input type="password" id="admin-confirm-password" name="confirm_password" class="form-control" autocomplete="new-password" required>
                            <button type="button" class="password-toggle" data-password-toggle aria-controls="admin-confirm-password" aria-label="<?= e(__('show_password')) ?>" aria-pressed="false" data-show-label="<?= e(__('show_password')) ?>" data-hide-label="<?= e(__('hide_password')) ?>" hidden><i class="bi bi-eye" aria-hidden="true"></i></button>
                        </div>
                    </div>
                    <div class="mt-auto pt-3"><button class="btn btn-accent"><?= e(__('update_password')) ?></button></div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card-soft staff-profile-card mt-3 notification-settings-card">
    <div class="card-header" data-desktop-label="<?= e(__('notice_desktop')) ?>"><i class="bi bi-bell me-1"></i> <?= e(__('notice_admin')) ?></div>
    <div class="card-body">
        <p class="form-hint mb-3"><?= e(__('notice_admin_hint')) ?></p>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <label class="notification-preference notification-preference--all" data-push-label="<?= e(__('notice_push')) ?>"><span data-allow-label="<?= e(__('notice_allow_short')) ?>"><i class="bi bi-bell-fill me-1"></i> <?= e(__('notice_allow_all')) ?></span><input type="checkbox" id="allowAllNotifications" class="notification-toggle"><span class="notification-switch" aria-hidden="true"></span></label>
            <span class="form-hint" id="notificationStatus" role="status" data-off="<?= e(__('notice_off')) ?>" data-enabled="<?= e(__('notice_enabled')) ?>" data-not-enabled="<?= e(__('notice_not_enabled')) ?>" data-blocked="<?= e(__('notice_blocked')) ?>" data-unsupported="<?= e(__('notice_unsupported')) ?>"><?= e(__('notice_not_enabled')) ?></span>
        </div>
        <div class="notification-preferences mt-3" aria-label="<?= e(__('notice_admin')) ?>">
            <?php foreach (['new_leave_request' => 'New leave request', 'new_wfh_request' => 'New WFH request', 'new_correction' => 'New attendance correction', 'missing_clock_out' => 'Missing clock-out', 'staff_setup' => 'Staff account setup completed', 'job_failure' => 'Backup or scheduled job failure'] as $key => $notice): ?>
                <label class="notification-preference"><span><?= e(__('notice_' . $key)) ?></span><input type="checkbox" class="notification-option" data-notification-key="<?= e($key) ?>"><span class="notification-checkbox" aria-hidden="true"></span></label>
            <?php endforeach; ?>
        </div>
        <div class="mt-3 pt-3 border-top border-secondary-subtle d-flex align-items-center justify-content-between flex-wrap gap-2">
            <small class="text-muted"><?= e(__('notice_device_hint')) ?></small>
            <button type="button" class="btn btn-sm btn-outline-info" id="btnTestPushNotification">
                <i class="bi bi-send me-1"></i> <?= e(__('notice_test_button')) ?>
            </button>
        </div>
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
                    <input type="checkbox" id="globeProfileToggleAdmin" class="notification-toggle globe-profile-toggle" checked>
                    <span class="notification-switch" aria-hidden="true"></span>
                </label>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
