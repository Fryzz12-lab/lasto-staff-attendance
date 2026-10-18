<?php
/** Self-service password recovery via verified email. */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    $u = current_user();
    redirect($u['role'] === 'admin' ? '/admin/' : '/staff/');
}

$error = '';
$message = '';
if (is_post()) {
    require_csrf();
    $username = normalize_username((string) post('username'));
    $generic = 'If the account is eligible, a password reset link will be sent to its verified email address.';
    $now = time();
    $lastRequest = (int) ($_SESSION['password_reset_request_at'] ?? 0);
    if ($lastRequest > $now - 60) {
        $message = $generic;
    } else {
        $_SESSION['password_reset_request_at'] = $now;
        if ($username !== '' && recovery_mail_ready()) {
            $stmt = db()->prepare('SELECT id, full_name, email, email_verified_at FROM users WHERE username=? AND is_active=1 LIMIT 1');
            $stmt->execute([$username]);
            $account = $stmt->fetch();
            if ($account && !empty($account['email_verified_at']) && filter_var($account['email'], FILTER_VALIDATE_EMAIL)) {
                $recent = db()->prepare("SELECT id FROM account_recovery_tokens WHERE user_id=? AND purpose='reset' AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) LIMIT 1");
                $recent->execute([(int) $account['id']]);
                if (!$recent->fetch()) {
                    $token = recovery_issue_token((int) $account['id'], 'reset');
                    recovery_send_link((string) $account['email'], $token, 'reset', (string) $account['full_name']);
                }
            }
        }
        $message = $generic;
    }
}
$pageTitle = __('forgot_password_title');
$bodyClass = 'auth-page';
require __DIR__ . '/includes/layout_header.php';
?>
<div class="auth-card" style="max-width: 520px;">
    <div class="text-center mb-3">
        <div class="brand-mark mx-auto mb-2" style="width:56px;height:56px;"><img src="<?= e(asset('img/logo-dark.png')) ?>" alt="<?= e(company_name()) ?>" class="brand-logo-img logo-theme-dark"><img src="<?= e(asset(is_file(__DIR__ . '/assets/img/logo-light.png') ? 'img/logo-light.png' : 'img/logo-dark.png')) ?>" alt="<?= e(company_name()) ?>" class="brand-logo-img logo-theme-light"></div>
        <h1 class="h4 mb-1"><?= e($pageTitle) ?></h1>
        <p class="text-muted small mb-0">Enter your username. If its email is verified, we will send a secure reset link.</p>
    </div>
    <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="alert alert-info py-2"><?= e($message) ?></div><?php endif; ?>
    <form method="post" novalidate>
        <?= csrf_field() ?><div class="mb-3"><label class="form-label" for="recovery-username"><?= e(__('username')) ?> *</label><input class="form-control form-control-lg" id="recovery-username" name="username" autocomplete="username" required autofocus></div>
        <button class="btn btn-accent w-100 py-2"><?= e(__('continue')) ?></button>
    </form>
    <div class="text-center mt-3"><a href="<?= e(url('/')) ?>" class="text-muted small"><i class="bi bi-arrow-left"></i> <?= e(__('back_to_login')) ?></a></div>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
