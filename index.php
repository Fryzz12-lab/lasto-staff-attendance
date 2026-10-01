<?php
/**
 * Login / entry point
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    $u = current_user();
    redirect($u['role'] === 'admin' ? '/admin/' : '/staff/');
}

$error = '';

if (is_post()) {
    require_csrf();
    $remember = (bool) post('remember_me');
    $result = attempt_login((string) post('username'), (string) post('password'), $remember);
    if ($result['success']) {
        if (!empty($result['user']['must_change_password'])) {
            redirect('/setup_account.php');
        }
        redirect($result['user']['role'] === 'admin' ? '/admin/' : '/staff/');
    }
    $error = $result['message'];
}

$pageTitle = __('login');
require __DIR__ . '/includes/layout_header.php';
?>

<div class="auth-card">
    <div class="text-center mb-3">
        <div class="brand-mark mx-auto mb-3" style="width:64px;height:64px;">
            <img src="<?= e(asset('img/logo-dark.png')) ?>" alt="<?= e(company_name()) ?>" class="brand-logo-img logo-theme-dark">
                <img src="<?= e(asset(is_file(__DIR__ . '/assets/img/logo-light.png') ? 'img/logo-light.png' : 'img/logo-dark.png')) ?>" alt="<?= e(company_name()) ?>" class="brand-logo-img logo-theme-light">
        </div>
        <?php $companyName = company_name(); ?>
        <h1><?= e($companyName) ?></h1>
        <?php if (strcasecmp(trim($companyName), trim(__('staff_attendance_system'))) !== 0): ?>
            <p class="subtitle mb-0"><?= e(__('staff_attendance_system')) ?></p>
        <?php endif; ?>
    </div>

    <?php foreach (flash_get() as $flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="username"><?= e(__('username')) ?></label>
            <input type="text" class="form-control form-control-lg" id="username" name="username"
                   value="<?= e((string) post('username', '')) ?>" autocomplete="username" required autofocus>
        </div>
        <div class="mb-3">
            <label class="form-label" for="password"><?= e(__('password')) ?></label>
            <div class="password-field">
                <input type="password" class="form-control form-control-lg" id="password" name="password" autocomplete="current-password" required>
                <button type="button" class="password-toggle" data-password-toggle aria-controls="password"
                        aria-label="<?= e(__('show_password')) ?>" aria-pressed="false"
                        data-show-label="<?= e(__('show_password')) ?>" data-hide-label="<?= e(__('hide_password')) ?>" hidden>
                    <i class="bi bi-eye" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <div class="mb-3 form-check">
            <input type="checkbox" class="form-check-input" id="remember_me" name="remember_me" value="1" <?= is_post() ? (post('remember_me') ? 'checked' : '') : 'checked' ?>>
            <label class="form-check-label text-muted small user-select-none" for="remember_me">
                <?= e(__('remember_me_device')) ?>
            </label>
        </div>
        <button type="submit" class="btn btn-accent btn-lg w-100"><?= e(__('login')) ?></button>
    </form>

        <div class="text-center mt-3">
            <a href="<?= e(url('/forgot_password.php')) ?>"><?= e(__('forgot_password')) ?></a>
        </div>

    </div>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
