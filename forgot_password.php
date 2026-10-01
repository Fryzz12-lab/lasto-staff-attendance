<?php
/**
 * Self-service password recovery via Security Questions
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    $u = current_user();
    redirect($u['role'] === 'admin' ? '/admin/' : '/staff/');
}

$step = 1;
$error = '';
$message = '';
$questions = [];

$recoveryUserId = (int) ($_SESSION['sec_reset_user_id'] ?? 0);
$recoveryUsername = (string) ($_SESSION['sec_reset_username'] ?? '');

// If user already initiated recovery in session and questions exist
if ($recoveryUserId > 0) {
    $questions = get_user_security_questions($recoveryUserId);
    if (count($questions) === 2) {
        $step = 2;
    } else {
        unset($_SESSION['sec_reset_user_id'], $_SESSION['sec_reset_username']);
        $step = 1;
    }
}

if (is_post()) {
    require_csrf();
    $formStep = (int) post('step', 1);

    if ($formStep === 1) {
        $username = normalize_username((string) post('username'));
        if ($username === '') {
            $error = __('msg_username_required');
        } else {
            $stmt = db()->prepare('SELECT id, username, full_name, is_active FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$username]);
            $account = $stmt->fetch();

            if (!$account) {
                $error = __('msg_invalid_credentials');
            } else {
                $uQuestions = get_user_security_questions((int) $account['id']);
                if (count($uQuestions) < 2) {
                    $error = __('msg_no_security_questions');
                } else {
                    $_SESSION['sec_reset_user_id'] = (int) $account['id'];
                    $_SESSION['sec_reset_username'] = (string) $account['username'];
                    $recoveryUserId = (int) $account['id'];
                    $recoveryUsername = (string) $account['username'];
                    $questions = $uQuestions;
                    $step = 2;
                }
            }
        }
    } elseif ($formStep === 2 && $recoveryUserId > 0) {
        $now = time();
        $attempts = array_values(array_filter($_SESSION['recovery_attempts'] ?? [], fn($at) => $at > $now - 3600));

        if (count($attempts) >= 5) {
            $error = 'Too many failed attempts. Please try again later or contact your administrator.';
        } else {
            $a1              = (string) post('answer_1');
            $a2              = (string) post('answer_2');
            $newPassword     = (string) post('new_password');
            $confirmPassword = (string) post('confirm_password');

            if (!verify_user_security_questions($recoveryUserId, $a1, $a2)) {
                $attempts[] = $now;
                $_SESSION['recovery_attempts'] = $attempts;
                $error = __('msg_incorrect_security_answers');
            } elseif (($policyError = password_policy_error($newPassword)) !== null) {
                $error = $policyError;
            } elseif ($newPassword !== $confirmPassword) {
                $error = __('msg_password_mismatch');
            } else {
                $newHash = hash_new_password($newPassword);
                db()->prepare('UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ?')
                    ->execute([$newHash, $recoveryUserId]);

                unset($_SESSION['sec_reset_user_id'], $_SESSION['sec_reset_username'], $_SESSION['recovery_attempts']);
                flash_set('success', __('msg_password_reset_success'));
                redirect('/');
            }
        }
    }
}

$pageTitle = __('forgot_password_title');
$bodyClass = 'auth-page';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="auth-card" style="max-width: 520px;">
    <div class="text-center mb-3">
        <div class="brand-mark mx-auto mb-2" style="width:56px;height:56px;">
            <img src="<?= e(asset('img/logo-dark.png')) ?>" alt="<?= e(company_name()) ?>" class="brand-logo-img logo-theme-dark">
                <img src="<?= e(asset(is_file(__DIR__ . '/assets/img/logo-light.png') ? 'img/logo-light.png' : 'img/logo-dark.png')) ?>" alt="<?= e(company_name()) ?>" class="brand-logo-img logo-theme-light">
        </div>
        <h1 class="h4 mb-1"><?= e(__('forgot_password_title')) ?></h1>
        <p class="text-muted small mb-0">
            <?= e($step === 1 ? __('forgot_password_step1_hint') : __('forgot_password_step2_hint')) ?>
        </p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert alert-info py-2"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
        <form method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="step" value="1">
            <div class="mb-3">
                <label class="form-label" for="recovery-username"><?= e(__('username')) ?> *</label>
                <input class="form-control form-control-lg" id="recovery-username" name="username"
                       value="<?= e((string) post('username', '')) ?>" autocomplete="username" required autofocus>
            </div>
            <button class="btn btn-accent w-100 py-2"><?= e(__('continue')) ?></button>
        </form>
    <?php else: ?>
        <form method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="step" value="2">

            <div class="card-soft p-3 mb-3 border">
                <div class="small text-muted mb-2">
                    <?= e(__('username')) ?>: <strong class="text-accent"><?= e($recoveryUsername) ?></strong>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold text-accent"><?= e($questions[0]['label'] ?? __('security_question_1')) ?></label>
                    <input type="text" name="answer_1" class="form-control"
                           placeholder="<?= e(__('answer_1')) ?>" required autocomplete="off" autofocus>
                </div>

                <div class="mb-2">
                    <label class="form-label fw-semibold text-accent"><?= e($questions[1]['label'] ?? __('security_question_2')) ?></label>
                    <input type="text" name="answer_2" class="form-control"
                           placeholder="<?= e(__('answer_2')) ?>" required autocomplete="off">
                </div>
            </div>

            <div class="card-soft p-3 mb-3 border">
                <h6 class="fw-bold mb-2 text-accent"><i class="bi bi-key me-1"></i> <?= e(__('new_password')) ?></h6>
                
                <div class="mb-2">
                    <label class="form-label" for="reset-new-password"><?= e(__('new_password')) ?> *</label>
                    <div class="password-field">
                        <input type="password" id="reset-new-password" name="new_password" class="form-control"
                               autocomplete="new-password" required minlength="8">
                        <button type="button" class="password-toggle" data-password-toggle aria-controls="reset-new-password"
                                aria-label="<?= e(__('show_password')) ?>" aria-pressed="false"
                                data-show-label="<?= e(__('show_password')) ?>" data-hide-label="<?= e(__('hide_password')) ?>" hidden>
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="form-hint"><?= e(__('password_guidance')) ?></div>
                </div>

                <div class="mb-1">
                    <label class="form-label" for="reset-confirm-password"><?= e(__('confirm_password')) ?> *</label>
                    <div class="password-field">
                        <input type="password" id="reset-confirm-password" name="confirm_password" class="form-control"
                               autocomplete="new-password" required>
                        <button type="button" class="password-toggle" data-password-toggle aria-controls="reset-confirm-password"
                                aria-label="<?= e(__('show_password')) ?>" aria-pressed="false"
                                data-show-label="<?= e(__('show_password')) ?>" data-hide-label="<?= e(__('hide_password')) ?>" hidden>
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </div>

            <button class="btn btn-accent w-100 py-2">
                <i class="bi bi-shield-check me-1"></i> <?= e(__('verify_and_reset_button')) ?>
            </button>
        </form>
    <?php endif; ?>

    <div class="text-center mt-3">
        <a href="<?= e(url('/')) ?>" class="text-muted small"><i class="bi bi-arrow-left"></i> <?= e(__('back_to_login')) ?></a>
    </div>
</div>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
