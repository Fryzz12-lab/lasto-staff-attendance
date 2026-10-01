<?php
/**
 * CSRF protection
 */

declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION[CSRF_TOKEN_KEY])) {
        $_SESSION[CSRF_TOKEN_KEY] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_KEY];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token = null): bool
{
    $token = $token ?? ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $sessionToken = $_SESSION[CSRF_TOKEN_KEY] ?? '';
    if ($sessionToken === '' || $token === '') {
        return false;
    }
    return hash_equals($sessionToken, $token);
}

function require_csrf(): void
{
    if (!csrf_verify()) {
        if (is_ajax()) {
            json_response(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.'], 403);
        }
        flash_set('danger', 'Invalid security token. Please try again.');
        redirect('/');
    }
}

function is_ajax(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
        || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));
}
