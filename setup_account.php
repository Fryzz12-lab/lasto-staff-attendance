<?php
/**
 * Mandatory Account Setup
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

require_login();
$user = current_user();

// If user does not require setup, redirect home
if (empty($user['must_change_password'])) {
    redirect($user['role'] === 'admin' ? '/admin/' : '/staff/');
}

$error = '';
if (is_post()) {
    require_csrf();
    $newPassword     = (string) post('new_password');
    $confirmPassword = (string) post('confirm_password');

    if (($policyError = password_policy_error($newPassword)) !== null) {
        $error = __($policyError);
    } elseif ($newPassword !== $confirmPassword) {
        $error = __('msg_password_mismatch');
    } else {
        try {
        db()->beginTransaction();
            $newHash = hash_new_password($newPassword);
            $newAuthVersion = (int) ($user['auth_version'] ?? 0) + 1;

            $stmt = db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0, auth_version = ? WHERE id = ?');
            $stmt->execute([$newHash, $newAuthVersion, (int) $user['id']]);

            db()->commit();
            $language = $_SESSION['lang'] ?? 'en';
            $_SESSION = ['lang' => $language];
            session_regenerate_id(true);
            flash_set('success', __('msg_account_setup_success'));
            redirect('/');
        } catch (Throwable $exception) {
            if (db()->inTransaction()) db()->rollBack();
            error_log((string) $exception);
            $error = __('msg_process_fail');
        }
    }
}

$pageTitle = __('account_setup_title');
$bodyClass = 'auth-page setup-account-page';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="auth-card" style="max-width: 580px;">
    <div class="text-center mb-3">
        <div class="brand-mark mx-auto mb-2" style="width:56px;height:56px;">
            <img src="<?= e(asset('img/logo-dark.png')) ?>" alt="<?= e(company_name()) ?>" class="brand-logo-img logo-theme-dark">
                <img src="<?= e(asset(is_file(__DIR__ . '/assets/img/logo-light.png') ? 'img/logo-light.png' : 'img/logo-dark.png')) ?>" alt="<?= e(company_name()) ?>" class="brand-logo-img logo-theme-light">
        </div>
        <h1 class="h4 mb-1"><?= e(__('account_setup_title')) ?></h1>
        <p class="text-muted small mb-0"><?= e(__('account_setup_hint')) ?></p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger py-2" role="alert" tabindex="-1" data-setup-error><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
        <?= csrf_field() ?>

        <div class="card-soft p-3 mb-3 border">
            <h6 class="fw-bold mb-2 text-accent"><i class="bi bi-key me-1"></i> <?= e(__('change_password')) ?></h6>
            
            <div class="mb-2">
                <label class="form-label" for="setup-new-password"><?= e(__('new_password')) ?> *</label>
                <div class="password-field">
                    <input type="password" id="setup-new-password" name="new_password" class="form-control"
                           autocomplete="new-password" required minlength="8">
                    <button type="button" class="password-toggle" data-password-toggle aria-controls="setup-new-password"
                            aria-label="<?= e(__('show_password')) ?>" aria-pressed="false"
                            data-show-label="<?= e(__('show_password')) ?>" data-hide-label="<?= e(__('hide_password')) ?>" hidden>
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="form-hint"><?= e(__('password_guidance')) ?></div>
            </div>

            <div class="mb-1">
                <label class="form-label" for="setup-confirm-password"><?= e(__('confirm_password')) ?> *</label>
                <div class="password-field">
                    <input type="password" id="setup-confirm-password" name="confirm_password" class="form-control"
                           autocomplete="new-password" required>
                    <button type="button" class="password-toggle" data-password-toggle aria-controls="setup-confirm-password"
                            aria-label="<?= e(__('show_password')) ?>" aria-pressed="false"
                            data-show-label="<?= e(__('show_password')) ?>" data-hide-label="<?= e(__('hide_password')) ?>" hidden>
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-accent w-100 py-2">
            <i class="bi bi-check-circle me-1"></i> <?= e(__('complete_setup_button')) ?>
        </button>

        <div class="text-center mt-3">
            <a href="<?= e(url('/logout.php')) ?>" class="text-muted small"><i class="bi bi-box-arrow-right"></i> <?= e(__('logout')) ?></a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
