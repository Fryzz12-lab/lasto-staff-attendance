<?php
/** One-time invitation, password reset, and email verification. */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$token = trim((string) get('token', post('token', '')));
$record = recovery_valid_token($token);
$error = '';
$done = false;
if (is_post()) {
    require_csrf();
    if (!$record) {
        $error = __('recovery_invalid_link');
    } elseif ($record['purpose'] === 'verify_email') {
        $done = recovery_consume_email_token($token);
        if (!$done) $error = __('recovery_invalid_link');
    } else {
        $password = (string) post('new_password');
        if (($policyError = password_policy_error($password)) !== null) {
            $error = __($policyError);
        } elseif ($password !== (string) post('confirm_password')) {
            $error = __('msg_password_mismatch');
        } else {
            $done = recovery_consume_password_token($token, $password);
            if (!$done) $error = __('recovery_invalid_link');
        }
    }
}
$pageTitle = $record && $record['purpose'] === 'verify_email' ? __('recovery_verify_email') : __('reset_password');
require __DIR__ . '/includes/layout_header.php';
?>
<div class="auth-card">
    <h1 class="h4 mb-3"><?= e($pageTitle) ?></h1>
    <?php if ($done): ?>
        <div class="alert alert-success"><?= e($record['purpose'] === 'verify_email' ? __('recovery_email_verified') : __('recovery_password_set')) ?></div>
    <?php elseif (!$record): ?>
        <div class="alert alert-danger"><?= e(__('recovery_invalid_link')) ?></div>
    <?php else: ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <?php if ($record['purpose'] !== 'verify_email'): ?>
                <div class="mb-3">
                    <label class="form-label" for="new-password"><?= e(__('new_password')) ?></label>
                    <div class="password-field">
                        <input type="password" name="new_password" id="new-password" class="form-control" minlength="8" autocomplete="new-password" required>
                        <button type="button" class="password-toggle" data-password-toggle aria-controls="new-password" aria-label="<?= e(__('show_password')) ?>" aria-pressed="false" data-show-label="<?= e(__('show_password')) ?>" data-hide-label="<?= e(__('hide_password')) ?>" hidden><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                    <div class="form-hint mt-1"><?= e(__('password_guidance')) ?></div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="confirm-password"><?= e(__('confirm_password')) ?></label>
                    <div class="password-field">
                        <input type="password" name="confirm_password" id="confirm-password" class="form-control" autocomplete="new-password" required>
                        <button type="button" class="password-toggle" data-password-toggle aria-controls="confirm-password" aria-label="<?= e(__('show_password')) ?>" aria-pressed="false" data-show-label="<?= e(__('show_password')) ?>" data-hide-label="<?= e(__('hide_password')) ?>" hidden><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                </div>
            <?php endif; ?>
            <button class="btn btn-accent w-100"><?= e($record['purpose'] === 'verify_email' ? __('recovery_verify_email') : __('recovery_set_password')) ?></button>
        </form>
    <?php endif; ?>
    <div class="text-center mt-3"><a href="<?= e(url('/')) ?>"><?= e(__('back_to_login')) ?></a></div>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
