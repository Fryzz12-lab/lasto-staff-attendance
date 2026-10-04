<?php
/**
 * Admin — Staff Create / Edit Form
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

$id    = (int) get('id', 0);
$staff = null;

if ($id > 0) {
    $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND role = 'staff' LIMIT 1");
    $stmt->execute([$id]);
    $staff = $stmt->fetch();
    if (!$staff) {
        flash_set('danger', __('msg_staff_not_found'));
        redirect('/admin/staff.php');
    }
}

$pageTitle = $staff ? __('edit_staff') : __('add_staff');
$activeMenu = 'staff';
$bodyClass = 'admin-staff-form-page';
$isAdminArea = true;

// Handle form submission
if (is_post()) {
    require_csrf();
    $action = (string) post('form_action', 'save');

    if ($action === 'delete' && $staff) {
        $hasAttendance = (bool) db()->prepare('SELECT 1 FROM attendance WHERE user_id = ? LIMIT 1')->execute([$id]);
        $count = (int) db()->prepare('SELECT COUNT(*) FROM attendance WHERE user_id = ?')->execute([$id]);
        $stmtC = db()->prepare('SELECT COUNT(*) FROM attendance WHERE user_id = ?');
        $stmtC->execute([$id]);
        if ((int) $stmtC->fetchColumn() > 0) {
            db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$id]);
            flash_set('warning', __('msg_staff_deactivated_history'));
        } else {
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            flash_set('success', __('msg_staff_deleted'));
        }
        redirect('/admin/staff.php');
    }

    if ($action === 'override_password' && $staff) {
        $newTemp = trim((string) post('new_temp_password'));
        if ($newTemp === '') {
            flash_set('danger', 'Temporary password cannot be empty.');
        } else {
            set_user_temporary_password($id, $newTemp, true);
            $_SESSION['staff_override_credentials'] = [
                'username'      => $staff['username'],
                'temp_password' => $newTemp,
                'full_name'     => $staff['full_name'],
            ];
            flash_set('success', 'Temporary password set successfully.');
        }
        redirect('/admin/staff_form.php?id=' . $id);
    }

    if ($action === 'reset_password' && $staff) {
        $token = recovery_issue_token($id, 'reset', (int) current_user()['id']);
        $sent = !empty($staff['email_verified_at']) && !empty($staff['email'])
            && recovery_send_link((string) $staff['email'], $token, 'reset', (string) $staff['full_name']);
        if ($sent) {
            flash_set('success', 'A one-time reset link was sent to the verified email address.');
        } else {
            $_SESSION['handoff_recovery_link'] = recovery_link($token);
            flash_set('warning', 'Mail is unavailable or the email is unverified. Give the one-time link to the staff member after confirming their identity.');
        }
        redirect('/admin/staff_form.php?id=' . $id);
    }

    if ($action === 'verify_email' && $staff) {
        if (empty($staff['email']) || !filter_var($staff['email'], FILTER_VALIDATE_EMAIL)) {
            flash_set('danger', 'Add a valid email address before sending verification.');
        } else {
            $token = recovery_issue_token($id, 'verify_email', (int) current_user()['id']);
            $sent = recovery_send_link((string) $staff['email'], $token, 'verify_email', (string) $staff['full_name']);
            if ($sent) {
                flash_set('success', 'Verification email sent to the staff member.');
            } else {
                $_SESSION['handoff_recovery_link'] = recovery_link($token);
                flash_set('warning', 'Verification email could not be sent. Use the one-time link shown below after confirming the staff member’s identity.');
            }
        }
        redirect('/admin/staff_form.php?id=' . $id);
    }

    $username     = normalize_username((string) post('username'));
    $fullName     = normalize_full_name((string) post('full_name'));
    $email        = strtolower(trim((string) post('email')));
    $phone        = normalize_account_phone((string) post('phone'));
    $position     = trim((string) post('job_position'));
    $joining      = trim((string) post('joining_date'));
    $endDate      = trim((string) post('employment_end_date'));
    $offDay       = (int) post('weekly_off_day', 0);
    $isActive     = (int) post('is_active', 1) === 1 ? 1 : 0;
    $tempPassword = trim((string) post('temporary_password'));

    if ($fullName === '') {
        flash_set('danger', 'Full name is required.');
    } else {
        // Auto-generate username from full name if blank (or on new staff if not provided)
        if ($username === '') {
            $username = generate_smart_username($fullName, $staff ? (int) $staff['id'] : null);
        }

        // Check if username is already taken by someone else
        $dupCheck = db()->prepare('SELECT id FROM users WHERE username = ?' . ($staff ? ' AND id != ?' : '') . ' LIMIT 1');
        $dupCheck->execute($staff ? [$username, $id] : [$username]);

        // A suggested username may already exist (for example, a second
        // employee with the same name). Resolve that collision automatically
        // instead of forcing the administrator to refresh or edit it.
        if (!$staff && $dupCheck->fetch()) {
            $username = generate_smart_username($fullName);
            $dupCheck = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $dupCheck->execute([$username]);
        }

        // Check if email is already taken by someone else
        $dupEmail = false;
        if ($email !== '') {
            $eCheck = db()->prepare('SELECT id FROM users WHERE email = ?' . ($staff ? ' AND id != ?' : '') . ' LIMIT 1');
            $eCheck->execute($staff ? [$email, $id] : [$email]);
            $dupEmail = (bool) $eCheck->fetch();
        }

        if ($dupCheck->fetch()) {
            flash_set('danger', 'This username (' . e($username) . ') is already taken.');
        } elseif ($dupEmail) {
            flash_set('danger', 'This email address (' . e($email) . ') is already in use by another account.');
        } elseif (!valid_account_phone($phone)) {
            flash_set('danger', __('msg_phone_invalid'));
        } elseif ($offDay < 0 || $offDay > 6) {
            flash_set('danger', 'Select a valid weekly off day.');
        } elseif ($email === '') {
            flash_set('danger', __('msg_email_required'));
        } elseif (!valid_account_email($email)) {
            flash_set('danger', 'Enter a valid email ending in .com, for example username@example.com.');
        } elseif ($endDate !== '' && $joining !== '' && $endDate < $joining) {
            flash_set('danger', 'Employment end date cannot be before the joining date.');
        } else {
            try {
                db()->beginTransaction();
                $scheduleId = standard_schedule_id();

                if ($staff) {
                    $lock = db()->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
                    $lock->execute([$id]);
                    $emailChanged = strcasecmp((string) ($staff['email'] ?? ''), $email) !== 0;
                    $sql = 'UPDATE users SET username=?, full_name=?, email=?, email_verified_at=CASE WHEN ?=1 THEN NULL ELSE email_verified_at END, phone=?, job_position=?, joining_date=?, employment_end_date=?, weekly_off_day=?, is_active=? WHERE id=?';
                    db()->prepare($sql)->execute([
                        $username, $fullName, $email ?: null, $emailChanged ? 1 : 0,
                        $phone ?: null, $position ?: null,
                        $joining ?: null, $endDate ?: null, $offDay, $isActive, $id,
                    ]);
                    if ($emailChanged) {
                        db()->prepare('UPDATE account_recovery_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$id]);
                    }
                    $userId = $id;
                } else {
                    $employeeId = generate_employee_id();
                    if ($tempPassword === '') {
                        $tempPassword = 'Temp@' . rand(1000, 9999);
                    }
                    $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
                    $sql = 'INSERT INTO users (employee_id, username, password_hash, full_name, email, phone, job_position, role, joining_date, employment_end_date, weekly_off_day, is_active, must_change_password)
                            VALUES (?, ?, ?, ?, ?, ?, ?, \'staff\', ?, ?, ?, ?, 1)';
                    db()->prepare($sql)->execute([
                        $employeeId, $username, $hash, $fullName, $email ?: null,
                        $phone ?: null, $position ?: null,
                        $joining ?: today_str(), $endDate ?: null, $offDay, $isActive,
                    ]);
                    $userId = (int) db()->lastInsertId();

                    $_SESSION['new_staff_credentials'] = [
                        'username'      => $username,
                        'temp_password' => $tempPassword,
                        'full_name'     => $fullName,
                    ];
                }

                assign_employee_schedule($userId, $scheduleId, $offDay);

                db()->commit();

                if ($staff) flash_set('success', __('msg_staff_updated'));
                redirect('/admin/staff_form.php?id=' . $userId);
            } catch (Throwable $ex) {
                if (db()->inTransaction()) db()->rollBack();
                if ($ex instanceof InvalidArgumentException) {
                    flash_set('danger', $ex->getMessage());
                } elseif ($ex instanceof PDOException && (int) ($ex->errorInfo[1] ?? 0) === 1062) {
                    if (str_contains($ex->getMessage(), 'email')) {
                        flash_set('danger', 'This email address is already in use by another account.');
                    } elseif (str_contains($ex->getMessage(), 'username')) {
                        flash_set('danger', 'This username is already taken. Please choose another username.');
                    } else {
                        flash_set('danger', __('msg_duplicate_user'));
                    }
                } else {
                    flash_set('danger', 'Error saving staff: ' . $ex->getMessage());
                }
            }
        }
    }
}

require __DIR__ . '/../includes/layout_header.php';

// Helper to prefill values
$v = function (string $key, $default = '') use ($staff) {
    if (is_post()) {
        return post($key, $default);
    }
    return $staff[$key] ?? $default;
};

$hasIssuedCredentials = !empty($_SESSION['new_staff_credentials']) || !empty($_SESSION['staff_override_credentials']);
$suggestedTempPassword = 'Temp@' . rand(1000, 9999);
$loginUrl = getenv('LASTO_PUBLIC_ORIGIN')
    ? rtrim((string) getenv('LASTO_PUBLIC_ORIGIN'), '/') . url('/')
    : absolute_url('/');
?>

<div class="mb-3">
    <a href="<?= e(url('/admin/staff.php')) ?>" class="btn btn-sm btn-outline-secondary">&larr; <?= e(__('back')) ?></a>
</div>

<?php if (!empty($_SESSION['new_staff_credentials'])): ?>
    <?php 
    $c = $_SESSION['new_staff_credentials']; 
    unset($_SESSION['new_staff_credentials']); 
    $templateText = __('staff_welcome_message', [
        'name' => $c['full_name'],
        'company' => company_name(),
        'url' => $loginUrl,
        'username' => $c['username'],
        'password' => $c['temp_password'],
    ]);
    ?>
    <div class="card-soft staff-credentials-handoff p-3 mb-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-check-circle-fill text-success fs-5"></i>
                <h6 class="mb-0 fw-bold">Account ready — share login details</h6>
            </div>
            <button type="button" class="btn btn-sm btn-accent d-inline-flex align-items-center gap-1"
                    onclick="copyCredsText(this, <?= e(json_encode($templateText)) ?>)">
                <i class="bi bi-clipboard"></i> <span><?= e(__('copy_employee_message')) ?></span>
            </button>
        </div>
        <p class="small text-muted mb-3">Share with <strong><?= e($c['full_name']) ?></strong>. They will set their password and security questions at first login.</p>
        
        <div class="row g-2 mb-2">
            <div class="col-sm-6">
                <div class="p-2 rounded border bg-dark bg-opacity-50 d-flex justify-content-between align-items-center">
                    <div>
                        <span class="small text-muted d-block"><?= e(__('username')) ?>:</span>
                        <code class="fs-6 user-select-all text-accent fw-bold"><?= e($c['username']) ?></code>
                    </div>
                    <button type="button" class="btn btn-sm btn-link text-muted p-1" title="Copy username only"
                            onclick="copyCredsText(this, <?= e(json_encode($c['username'])) ?>)">
                        <i class="bi bi-copy"></i>
                    </button>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="p-2 rounded border bg-dark bg-opacity-50 d-flex justify-content-between align-items-center">
                    <div>
                        <span class="small text-muted d-block"><?= e(__('temporary_password')) ?>:</span>
                        <code class="fs-6 user-select-all text-warning fw-bold"><?= e($c['temp_password']) ?></code>
                    </div>
                    <button type="button" class="btn btn-sm btn-link text-muted p-1" title="Copy password only"
                            onclick="copyCredsText(this, <?= e(json_encode($c['temp_password'])) ?>)">
                        <i class="bi bi-copy"></i>
                    </button>
                </div>
            </div>
        </div>

        <details class="mt-3">
            <summary class="small text-muted" style="cursor: pointer;"><?= e(__('preview_employee_message')) ?></summary>
            <div class="mt-2 p-3 rounded border small user-select-all" style="white-space: pre-wrap; overflow-wrap: anywhere;"><?= e($templateText) ?></div>
        </details>
        <div class="d-flex flex-wrap justify-content-between gap-2 mt-3"><span class="small text-muted"><?= e(__('employee_message_hint')) ?></span><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/staff.php')) ?>">Done — back to staff</a></div>
    </div>
<?php endif; ?>

<?php if (!empty($_SESSION['staff_override_credentials'])): ?>
    <?php 
    $c = $_SESSION['staff_override_credentials']; 
    unset($_SESSION['staff_override_credentials']); 
    $templateText = __('staff_password_reset_message', [
        'name' => $c['full_name'],
        'company' => company_name(),
        'url' => $loginUrl,
        'username' => $c['username'],
        'password' => $c['temp_password'],
    ]);
    ?>
    <div class="card-soft border border-warning p-3 mb-3" style="background: rgba(245, 158, 11, 0.08);">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-shield-exclamation text-warning fs-5"></i>
                <h6 class="mb-0 fw-bold text-warning">Temporary Password Issued!</h6>
            </div>
            <button type="button" class="btn btn-sm btn-outline-warning d-inline-flex align-items-center gap-1 shadow-sm"
                    data-is-warning="1" onclick="copyCredsText(this, <?= e(json_encode($templateText)) ?>)">
                <i class="bi bi-clipboard"></i> <span>Copy Credentials</span>
            </button>
        </div>
        <p class="small text-muted mb-2">Provide this temporary password to <strong><?= e($c['full_name']) ?></strong>. The employee will be forced to change their password and re-verify security questions upon logging in.</p>
        
        <div class="row g-2 mb-2">
            <div class="col-sm-6">
                <div class="p-2 rounded border bg-dark bg-opacity-50 d-flex justify-content-between align-items-center">
                    <div>
                        <span class="small text-muted d-block"><?= e(__('username')) ?>:</span>
                        <code class="fs-6 user-select-all text-accent fw-bold"><?= e($c['username']) ?></code>
                    </div>
                    <button type="button" class="btn btn-sm btn-link text-muted p-1" title="Copy username only"
                            onclick="copyCredsText(this, <?= e(json_encode($c['username'])) ?>)">
                        <i class="bi bi-copy"></i>
                    </button>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="p-2 rounded border bg-dark bg-opacity-50 d-flex justify-content-between align-items-center">
                    <div>
                        <span class="small text-muted d-block"><?= e(__('temporary_password')) ?>:</span>
                        <code class="fs-6 user-select-all text-warning fw-bold"><?= e($c['temp_password']) ?></code>
                    </div>
                    <button type="button" class="btn btn-sm btn-link text-muted p-1" title="Copy password only"
                            onclick="copyCredsText(this, <?= e(json_encode($c['temp_password'])) ?>)">
                        <i class="bi bi-copy"></i>
                    </button>
                </div>
            </div>
        </div>

        <div class="mt-2 p-2 rounded border bg-dark bg-opacity-35 small font-monospace text-muted user-select-all" style="white-space: pre-line; line-height: 1.5;"><?= e($templateText) ?></div>
    </div>
<?php endif; ?>

<?php if (!empty($_SESSION['handoff_recovery_link'])): ?>
    <?php
    $handoffLink = (string) $_SESSION['handoff_recovery_link'];
    $handoffMessage = 'Hello ' . (string) $staff['full_name'] . ",\n\n"
        . "A password reset link has been issued for your staff attendance account. Please open the link to set a new password:\n"
        . $handoffLink . "\n\n"
        . 'This reset link will expire in one hour.';
    ?>
    <div class="staff-reset-handoff mb-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
            <div>
                <strong>One-time reset message</strong>
                <div class="small">Confirm the staff member's identity before sharing. This message is shown only once.</div>
            </div>
            <button type="button" class="btn btn-outline-warning btn-sm" onclick="copyCredsText(this, <?= e(json_encode($handoffMessage)) ?>)">
                <i class="bi bi-clipboard" aria-hidden="true"></i> <span>Copy message</span>
            </button>
        </div>
        <div class="staff-reset-handoff__message user-select-all"><?= e($handoffMessage) ?></div>
    </div>
    <?php unset($_SESSION['handoff_recovery_link']); ?>
<?php endif; ?>

<?php if ($staff && (int) ($staff['must_change_password'] ?? 0) === 1 && !$hasIssuedCredentials): ?>
    <div class="card-soft border border-info p-3 mb-3" style="background: rgba(14, 165, 233, 0.08);">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-clock-history text-info fs-5"></i>
                <div>
                    <h6 class="mb-0 fw-bold text-info">First-Time Setup Pending</h6>
                    <span class="small text-muted">This staff member has not logged in to set their permanent password and security questions yet.</span>
                </div>
            </div>
            <a href="#sidebar_temp_pw" class="btn btn-sm btn-outline-info" onclick="document.getElementById('staff-account-recovery').open = true; document.getElementById('sidebar_temp_pw')?.focus(); document.getElementById('sidebar_temp_pw')?.scrollIntoView({behavior:'smooth', block:'center'}); return false;">
                <i class="bi bi-key me-1"></i> Reset temporary password
            </a>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-<?= $staff ? '8' : '12' ?>">
        <div class="card-soft">
            <div class="card-header"><?= e($pageTitle) ?></div>
            <div class="card-body">
                <?php if (!$staff): ?>
                    <p class="form-hint mb-3">Create the staff account with a temporary password. The employee will be prompted to set their permanent password and security questions on first login.</p>
                <?php endif; ?>
                <form method="post" id="staff-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_action" value="save">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="input_full_name"><?= e(__('full_name')) ?> *</label>
                            <input id="input_full_name" name="full_name" class="form-control" value="<?= e((string)$v('full_name')) ?>" required autofocus autocomplete="name">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="input_username"><?= e(__('username')) ?> *</label>
                            <input id="input_username" name="username" class="form-control font-monospace" required value="<?= e((string)$v('username')) ?>" autocomplete="username">
                            <?php if (!$staff): ?>
                                <div class="form-hint">Auto-suggested from full name. You can manually edit it.</div>
                            <?php endif; ?>
                        </div>

                        <?php if (!$staff): ?>
                        <div class="col-md-6">
                            <label class="form-label" for="input_temp_password"><?= e(__('temporary_password')) ?> *</label>
                            <div class="input-group">
                                <input type="text" id="input_temp_password" name="temporary_password" class="form-control font-monospace" value="<?= e((string) post('temporary_password', $suggestedTempPassword)) ?>" required>
                                <button type="button" class="btn btn-outline-secondary" id="btn_generate_temp_pw" title="Generate another password">
                                    <i class="bi bi-arrow-clockwise"></i> <?= e(__('generate_btn')) ?>
                                </button>
                            </div>
                            <div class="form-hint mb-2"><?= e(__('temporary_password_hint')) ?></div>
                        </div>
                        <?php endif; ?>

                        <div class="col-md-6">
                            <label class="form-label"><?= e(__('email')) ?> *</label>
                            <input type="email" name="email" class="form-control" value="<?= e((string) $v('email')) ?>" autocomplete="email" required>
                            <?php if ($staff): ?>
                                <div class="d-flex align-items-center gap-2 mt-1">
                                    <span class="form-hint">Managed by Admin</span>
                                    <?php if (!empty($staff['email_verified_at'])): ?><span class="email-status email-status--verified">Verified</span>
                                    <?php else: ?><span class="email-status email-status--pending">Not verified</span><?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label"><?= e(__('job_position')) ?></label>
                            <input name="job_position" class="form-control" value="<?= e((string)$v('job_position')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= e(__('phone')) ?></label>
                            <div class="input-group">
                                <span class="input-group-text" id="edit-phone-prefix">+60</span>
                                <input type="tel" name="phone" inputmode="numeric" maxlength="10" pattern="[1-9][0-9]{8,9}" aria-label="<?= e(__('phone')) ?>" aria-describedby="edit-phone-prefix edit-phone-hint" class="form-control" value="<?= e((string)$v('phone')) ?>" placeholder="123456789">
                            </div>
                            <div id="edit-phone-hint" class="form-hint">9 or 10 digits. Omit the leading 0.</div>
                        </div>
                        <?php if ($staff): ?>
                        <div class="col-md-6">
                            <label class="form-label"><?= e(__('account_status')) ?></label>
                            <select name="is_active" class="form-select">
                                <option value="1" <?= (int)$v('is_active', 1) === 1 ? 'selected' : '' ?>><?= e(__('active')) ?></option>
                                <option value="0" <?= (int)$v('is_active', 1) === 0 ? 'selected' : '' ?>><?= e(__('inactive')) ?></option>
                            </select>
                        </div>
                        <?php else: ?>
                            <input type="hidden" name="is_active" value="1">
                        <?php endif; ?>
                        <div class="col-md-4">
                            <label class="form-label"><?= e(__('joining_date')) ?></label>
                            <input type="date" name="joining_date" class="form-control" value="<?= e((string)$v('joining_date', today_str())) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Employment End Date</label>
                            <input type="date" name="employment_end_date" class="form-control" value="<?= e((string)$v('employment_end_date')) ?>">
                            <div class="form-hint">Leave blank if still employed.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?= e(__('weekly_off_day')) ?></label>
                            <select name="weekly_off_day" class="form-select">
                                <?php for ($d = 0; $d <= 6; $d++): ?>
                                    <option value="<?= $d ?>" <?= (int)$v('weekly_off_day', 0) === $d ? 'selected' : '' ?>>
                                        <?= e(weekday_name($d, lang_current())) ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>


                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-4">
                        <button class="btn btn-accent"><?= e(__('save')) ?></button>
                        <?php if ($staff): ?>
                            <button type="submit" form="delete-staff-form" class="btn btn-outline-danger" data-confirm="<?= e(__('confirm_delete_staff')) ?>"><?= e(__('delete')) ?></button>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if ($staff): ?>
                <form id="delete-staff-form" method="post" class="d-none">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_action" value="delete">
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($staff): ?>
    <div class="col-lg-4">
        <details id="staff-account-recovery" class="staff-account-recovery">
        <summary class="card-soft p-3 mb-3">Account recovery <i class="bi bi-chevron-down ms-2" aria-hidden="true"></i></summary>
        <!-- Direct Temporary Password Override Card -->
        <div class="card-soft mb-3">
            <div class="card-header"><i class="bi bi-key-fill text-warning me-1"></i> <?= e(__('direct_password_override')) ?></div>
            <div class="card-body">
                <p class="form-hint mb-3"><?= e(__('direct_override_hint')) ?></p>
                <form method="post" id="override-password-form" data-confirm="Override password with this temporary password?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_action" value="override_password">
                    <div class="mb-3">
                        <label class="form-label" for="sidebar_temp_pw"><?= e(__('temporary_password')) ?></label>
                        <div class="input-group">
                            <input type="text" name="new_temp_password" id="sidebar_temp_pw" class="form-control font-monospace" value="<?= e($suggestedTempPassword) ?>" required autocomplete="off">
                            <button type="button" class="btn btn-outline-secondary" id="btn_regen_sidebar_pw" title="Generate another password">
                                <i class="bi bi-arrow-clockwise"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Template to Send Box -->
                    <?php
                    $sidebarInitText = __('staff_password_reset_message', [
                        'name' => (string) ($staff['full_name'] ?? ''),
                        'company' => company_name(),
                        'url' => $loginUrl,
                        'username' => (string) ($staff['username'] ?? ''),
                        'password' => $suggestedTempPassword,
                    ]);
                    ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label mb-0 small text-warning fw-semibold"><i class="bi bi-card-text me-1"></i> Template to Send:</label>
                            <button type="button" class="btn btn-xs btn-outline-warning py-0 px-2" style="font-size: 0.75rem;" onclick="copySidebarTemplate(this)">
                                <i class="bi bi-clipboard"></i> <span>Copy Template</span>
                            </button>
                        </div>
                        <span id="sidebar_welcome_template" hidden><?= e(__('staff_password_reset_message')) ?></span>
                        <span id="sidebar_login_url" hidden><?= e($loginUrl) ?></span>
                        <span id="sidebar_company_name" hidden><?= e(company_name()) ?></span>
                        <div id="sidebar_template_preview" class="p-2 rounded border bg-dark bg-opacity-50 small font-monospace text-light user-select-all" style="white-space: pre-line; line-height: 1.45; font-size: 0.82rem;"><?= e($sidebarInitText) ?></div>
                        <div class="form-hint mt-1">Preview only. Set the temporary password before sharing this message.</div>
                    </div>

                    <button class="btn btn-warning w-100" id="btn_submit_temp_pw"><?= e(__('set_temp_password_btn')) ?></button>
                </form>
            </div>
        </div>

        <div class="card-soft mb-3">
            <div class="card-header">Issue one-time reset link</div>
            <div class="card-body">
                <p class="form-hint">Confirm staff identity. The link is single-use and expires in 1 hour.</p>
                <form method="post" data-confirm="Issue a one-time reset link for this staff member?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_action" value="reset_password">
                    <button class="btn btn-outline-secondary w-100">Issue reset link</button>
                </form>
            </div>
        </div>
        <div class="card-soft mb-3">
            <div class="card-header">Verify staff email</div>
            <div class="card-body">
                <p class="form-hint mb-2">Current status:
                    <?php if (!empty($staff['email_verified_at'])): ?><span class="text-success fw-semibold">Verified</span>
                    <?php else: ?><span class="text-warning fw-semibold">Not verified</span><?php endif; ?>
                </p>
                <form method="post" data-confirm="Send an email verification link to this staff member?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_action" value="verify_email">
                    <button class="btn btn-outline-info w-100" <?= empty($staff['email']) ? 'disabled' : '' ?>>Send verification email</button>
                </form>
            </div>
        </div>
        </details>
    </div>
    <?php endif; ?>
</div>

<script>
(function() {
    function initStaffFormScript() {
        const fullNameInput = document.getElementById('input_full_name');
        const usernameInput = document.getElementById('input_username');
        const isEditMode = <?= $staff ? 'true' : 'false' ?>;
        let usernameManuallyChanged = isEditMode && usernameInput && usernameInput.value.trim() !== '';

        if (usernameInput) {
            usernameInput.addEventListener('input', () => {
                usernameManuallyChanged = true;
            });
        }

        const genBtn = document.getElementById('btn_generate_temp_pw');
        const tempPwInput = document.getElementById('input_temp_password');
        const loginUrl = <?= json_encode($loginUrl) ?>;

        if (fullNameInput && usernameInput && !isEditMode) {
            fullNameInput.addEventListener('input', () => {
                if (!usernameManuallyChanged) {
                    const name = fullNameInput.value.trim().toLowerCase();
                    if (!name) {
                        usernameInput.value = '';
                        return;
                    }
                    // Strip common patronymics like bin, binti, a/l, a/p
                    let clean = name.replace(/\b(a\/l|a\/p|s\/o|d\/o|bin|binti|bte|bt|anak|ak)\b/gi, ' ');
                    clean = clean.replace(/['`]/g, '').replace(/[^a-z0-9\s]/g, ' ');
                    const tokens = clean.split(/\s+/).filter(t => t.length > 0 && !['a', 'l', 'p', 's', 'd'].includes(t));
                    if (tokens.length === 0) {
                        return;
                    }

                    let slug = '';
                    if (tokens.length === 1) {
                        slug = tokens[0];
                    } else if (tokens.length === 2) {
                        slug = tokens[0][0] + '_' + tokens[1];
                    } else if (tokens.length === 3) {
                        slug = tokens[0][0] + '_' + tokens[1] + '_' + tokens[2];
                    } else {
                        slug = tokens[0][0] + '_' + tokens[1] + '_' + tokens[tokens.length - 1];
                    }
                    usernameInput.value = slug.replace(/_+/g, '_').substring(0, 45);
                }
            });

            // SPA navigation and browser autofill can populate the name before
            // the input event is registered. Generate the required username
            // immediately so the form never remains blank on first submit.
            if (fullNameInput.value.trim() && !usernameInput.value.trim()) {
                fullNameInput.dispatchEvent(new Event('input', { bubbles: true }));
                if (!usernameInput.value.trim()) usernameInput.value = 'staff';
            }
        }

        if (genBtn && tempPwInput) {
            genBtn.addEventListener('click', () => {
                tempPwInput.value = 'Temp@' + Math.floor(1000 + Math.random() * 9000);
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initStaffFormScript);
    } else {
        initStaffFormScript();
    }
})();


</script>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
