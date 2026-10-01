<?php
/** Account invitation, email verification, and password recovery. */
declare(strict_types=1);
require_once __DIR__ . '/password_policy.php';

function recovery_mail_ready(): bool
{
    $origin = (string) getenv('LASTO_PUBLIC_ORIGIN');
    $parts = parse_url($origin);
    $secureOrigin = is_array($parts) && isset($parts['host'])
        && !isset($parts['user']) && !isset($parts['pass'])
        && !isset($parts['query']) && !isset($parts['fragment'])
        && (!isset($parts['path']) || $parts['path'] === '' || $parts['path'] === '/')
        && (($parts['scheme'] ?? '') === 'https'
            || (($parts['scheme'] ?? '') === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1'], true)));
    return function_exists('mail') && getenv('LASTO_MAIL_TRANSPORT') === 'php_mail'
        && filter_var(getenv('LASTO_MAIL_FROM'), FILTER_VALIDATE_EMAIL) !== false
        && $secureOrigin;
}

function recovery_link(string $token): string
{
    $origin = rtrim((string) (getenv('LASTO_PUBLIC_ORIGIN') ?: 'http://localhost'), '/');
    return $origin . url('/reset_password.php?token=' . rawurlencode($token));
}

function recovery_issue_token(int $userId, string $purpose, ?int $createdBy = null): string
{
    if (!in_array($purpose, ['invite', 'reset', 'verify_email'], true)) {
        throw new InvalidArgumentException('Invalid recovery purpose.');
    }
    $token = bin2hex(random_bytes(32));
    $expiryHours = $purpose === 'invite' ? 48 : 1;
    db()->prepare('UPDATE account_recovery_tokens SET used_at = NOW() WHERE user_id = ? AND purpose = ? AND used_at IS NULL')
        ->execute([$userId, $purpose]);
    db()->prepare('INSERT INTO account_recovery_tokens (user_id, token_hash, purpose, expires_at, created_by) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . $expiryHours . ' HOUR), ?)')
        ->execute([$userId, hash('sha256', $token), $purpose, $createdBy]);
    return $token;
}

function recovery_send_link(string $email, string $token, string $purpose): bool
{
    if (!recovery_mail_ready() || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $subject = match ($purpose) {
        'invite' => 'LASTO TECHNOLOGY account invitation',
        'verify_email' => 'LASTO TECHNOLOGY email verification',
        default => 'LASTO TECHNOLOGY password reset',
    };
    $body = ($purpose === 'verify_email' ? "Use this one-time link to verify your email address:\r\n" : "Use this one-time link to choose your password:\r\n") . recovery_link($token)
        . "\r\n\r\nThe link expires " . ($purpose === 'invite' ? 'in 48 hours' : 'in 1 hour')
        . ". If you did not request this, contact LASTO TECHNOLOGY.\r\n";
    $from = (string) getenv('LASTO_MAIL_FROM');
    $sent = mail($email, $subject, $body, [
        'From' => $from,
        'Reply-To' => $from,
        'Content-Type' => 'text/plain; charset=UTF-8',
    ]);
    if ($sent) {
        db()->prepare('UPDATE account_recovery_tokens SET email_delivery=1 WHERE token_hash=?')->execute([hash('sha256', $token)]);
    }
    return $sent;
}

function recovery_valid_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return null;
    $stmt = db()->prepare('SELECT t.*, u.email, u.email_verified_at, u.is_active, u.role FROM account_recovery_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > NOW() LIMIT 1');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    return $row && (int) $row['is_active'] === 1 ? $row : null;
}

function recovery_consume_password_token(string $token, string $newPassword): bool
{
    if (password_policy_error($newPassword) !== null || !preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
    db()->beginTransaction();
    try {
        $stmt = db()->prepare('SELECT t.*, u.is_active FROM account_recovery_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? FOR UPDATE');
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();
        if (!$row || $row['used_at'] !== null || strtotime($row['expires_at']) <= time() || !(int) $row['is_active'] || !in_array($row['purpose'], ['invite', 'reset'], true)) {
            db()->rollBack();
            return false;
        }
        db()->prepare('UPDATE users SET password_hash=?, auth_version=auth_version+1, email_verified_at=CASE WHEN ? = 1 THEN NOW() ELSE email_verified_at END WHERE id=?')
            ->execute([hash_new_password($newPassword), (int) $row['email_delivery'], $row['user_id']]);
        db()->prepare('UPDATE account_recovery_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$row['user_id']]);
        db()->commit();
        return true;
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
}

function recovery_consume_email_token(string $token): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
    db()->beginTransaction();
    try {
        $stmt = db()->prepare('SELECT t.*, u.is_active FROM account_recovery_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? FOR UPDATE');
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();
        if (!$row || $row['purpose'] !== 'verify_email' || !(int) $row['email_delivery'] || $row['used_at'] !== null || strtotime($row['expires_at']) <= time() || !(int) $row['is_active']) {
            db()->rollBack();
            return false;
        }
        db()->prepare('UPDATE users SET email_verified_at=NOW() WHERE id=?')->execute([$row['user_id']]);
        db()->prepare('UPDATE account_recovery_tokens SET used_at=NOW() WHERE id=?')->execute([$row['id']]);
        db()->commit();
        return true;
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
}
