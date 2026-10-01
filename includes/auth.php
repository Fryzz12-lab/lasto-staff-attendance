<?php
/**
 * Authentication & authorization
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/csrf.php';

function is_request_secure(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(SESSION_NAME);
    $isSecure = is_request_secure();
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $timeout = setting_int('session_timeout_minutes', SESSION_TIMEOUT_MINUTES) * 60;
    if (empty($_SESSION['remember_me']) && !empty($_SESSION['last_activity']) && (time() - (int) $_SESSION['last_activity']) > $timeout) {
        logout_user();
        flash_set('warning', __('msg_session_expired'));
        redirect('/');
    }
    $_SESSION['last_activity'] = time();
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        $remembered = check_remember_token();
        if (!$remembered) {
            return null;
        }
    }
    static $user = null;
    if ($user !== null && (int) $user['id'] === (int) $_SESSION['user_id']) {
        return $user;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    if (!$user || !(int) $user['is_active']) {
        logout_user();
        return null;
    }
    if (!isset($_SESSION['auth_version']) || (int) $_SESSION['auth_version'] !== (int) ($user['auth_version'] ?? 0)) {
        logout_user();
        return null;
    }
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    $user = current_user();
    return $user && $user['role'] === 'admin';
}

function is_staff(): bool
{
    $user = current_user();
    return $user && $user['role'] === 'staff';
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash_set('warning', __('msg_please_login'));
        redirect('/');
    }
    if (!empty(current_user()['must_change_password'])
        && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'setup_account.php') {
        redirect('/setup_account.php');
    }
}

function require_admin(): void
{
    require_login();
    $user = current_user();
    if (!empty($user['must_change_password'])) {
        redirect('/setup_account.php');
    }
    if (!is_admin()) {
        flash_set('danger', __('msg_no_permission'));
        redirect('/staff/');
    }
}

function require_staff(): void
{
    require_login();
    $user = current_user();
    if (!empty($user['must_change_password'])) {
        redirect('/setup_account.php');
    }
    if (!is_staff() && !is_admin()) {
        flash_set('danger', __('msg_access_denied'));
        redirect('/');
    }
}

function attempt_login(string $username, string $password, bool $remember = false): array
{
    $username = normalize_username($username);
    if ($username === '' || $password === '') {
        return ['success' => false, 'message' => __('msg_login_required_fields')];
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'message' => __('msg_invalid_credentials')];
    }
    if (!(int) $user['is_active']) {
        return ['success' => false, 'message' => __('msg_account_inactive')];
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['auth_version'] = (int) ($user['auth_version'] ?? 0);
    $_SESSION['last_activity'] = time();

    if ($remember) {
        $_SESSION['remember_me'] = true;
        create_remember_token((int) $user['id']);
    } else {
        unset($_SESSION['remember_me']);
        clear_remember_cookie();
    }

    return ['success' => true, 'user' => $user];
}

function create_remember_token(int $userId): void
{
    try {
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $validator);
        $expiresAt = date('Y-m-d H:i:s', time() + (30 * 86400));

        $stmt = db()->prepare('DELETE FROM auth_remember_tokens WHERE user_id = ? OR expires_at < NOW()');
        $stmt->execute([$userId]);

        $stmt = db()->prepare('INSERT INTO auth_remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $selector, $tokenHash, $expiresAt]);

        $cookieVal = $selector . ':' . $validator;
        $isSecure = is_request_secure();
        setcookie('sas_remember', $cookieVal, [
            'expires'  => time() + (30 * 86400),
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['sas_remember'] = $cookieVal;
    } catch (Throwable) {
        // Fallback silently if tokens table unavailable
    }
}

function check_remember_token(): ?array
{
    if (empty($_COOKIE['sas_remember']) || !is_string($_COOKIE['sas_remember'])) {
        return null;
    }

    $parts = explode(':', $_COOKIE['sas_remember'], 2);
    if (count($parts) !== 2) {
        clear_remember_cookie();
        return null;
    }

    [$selector, $validator] = $parts;
    if (strlen($selector) !== 32 || strlen($validator) !== 64) {
        clear_remember_cookie();
        return null;
    }

    try {
        $stmt = db()->prepare('
            SELECT t.id AS token_id, t.user_id, t.token_hash, t.expires_at, u.*
            FROM auth_remember_tokens t
            JOIN users u ON u.id = t.user_id
            WHERE t.selector = ? AND t.expires_at > NOW()
            LIMIT 1
        ');
        $stmt->execute([$selector]);
        $row = $stmt->fetch();

        if (!$row || !hash_equals($row['token_hash'], hash('sha256', $validator))) {
            clear_remember_cookie();
            return null;
        }

        if (!(int) $row['is_active']) {
            clear_remember_cookie();
            return null;
        }

        // Initialize session
        $_SESSION['user_id'] = (int) $row['user_id'];
        $_SESSION['user_role'] = $row['role'];
        $_SESSION['auth_version'] = (int) ($row['auth_version'] ?? 0);
        $_SESSION['last_activity'] = time();
        $_SESSION['remember_me'] = true;

        // Rotate token validator to prevent replay
        $newValidator = bin2hex(random_bytes(32));
        $newTokenHash = hash('sha256', $newValidator);
        $upd = db()->prepare('UPDATE auth_remember_tokens SET token_hash = ? WHERE id = ?');
        $upd->execute([$newTokenHash, (int) $row['token_id']]);

        $newCookieVal = $selector . ':' . $newValidator;
        $isSecure = is_request_secure();
        setcookie('sas_remember', $newCookieVal, [
            'expires'  => time() + (30 * 86400),
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['sas_remember'] = $newCookieVal;

        return $row;
    } catch (Throwable) {
        return null;
    }
}

function clear_remember_cookie(): void
{
    if (!empty($_COOKIE['sas_remember']) && is_string($_COOKIE['sas_remember'])) {
        $parts = explode(':', $_COOKIE['sas_remember'], 2);
        if (!empty($parts[0])) {
            try {
                $stmt = db()->prepare('DELETE FROM auth_remember_tokens WHERE selector = ?');
                $stmt->execute([$parts[0]]);
            } catch (Throwable) {}
        }
    }
    $isSecure = is_request_secure();
    setcookie('sas_remember', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE['sas_remember']);
}

function logout_user(): void
{
    clear_remember_cookie();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

function require_own_or_admin(int $userId): void
{
    require_login();
    $user = current_user();
    if (!$user) {
        redirect('/');
    }
    if ((int) $user['id'] !== $userId && $user['role'] !== 'admin') {
        flash_set('danger', 'You do not have permission to view this record.');
        redirect($user['role'] === 'admin' ? '/admin/' : '/staff/');
    }
}
