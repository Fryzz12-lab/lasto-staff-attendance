<?php
/**
 * Persistent Login Rate Limiting & Brute-Force Protection
 * Staff Attendance & Workforce Management System
 */

declare(strict_types=1);

if (!defined('AUTH_RATE_LIMIT_ENABLED')) {
    define('AUTH_RATE_LIMIT_ENABLED', true);
}
if (!defined('AUTH_MAX_FAILED_PER_IP_USER')) {
    define('AUTH_MAX_FAILED_PER_IP_USER', 5);
}
if (!defined('AUTH_MAX_FAILED_PER_IP')) {
    define('AUTH_MAX_FAILED_PER_IP', 15);
}
if (!defined('AUTH_MAX_FAILED_PER_ACCOUNT')) {
    define('AUTH_MAX_FAILED_PER_ACCOUNT', 15);
}
if (!defined('AUTH_RATE_WINDOW_MINUTES')) {
    define('AUTH_RATE_WINDOW_MINUTES', 15);
}
if (!defined('AUTH_COOLDOWN_MINUTES')) {
    define('AUTH_COOLDOWN_MINUTES', 15);
}
if (!defined('TRUSTED_PROXIES')) {
    define('TRUSTED_PROXIES', getenv('LASTO_TRUSTED_PROXIES') ?: '');
}

/**
 * Resolve client network source IP address with strict proxy validation.
 * Arbitrary X-Forwarded-For headers are ignored unless REMOTE_ADDR is an explicitly trusted proxy.
 */
function auth_client_ip(): string
{
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
    if (!filter_var($remote, FILTER_VALIDATE_IP)) {
        $remote = '127.0.0.1';
    }

    $trustedConfig = defined('TRUSTED_PROXIES') && is_string(TRUSTED_PROXIES) && TRUSTED_PROXIES !== ''
        ? TRUSTED_PROXIES
        : (getenv('LASTO_TRUSTED_PROXIES') ?: '');
    $trustedProxies = $trustedConfig !== ''
        ? array_filter(array_map('trim', explode(',', $trustedConfig)))
        : [];

    if (!empty($trustedProxies) && in_array($remote, $trustedProxies, true)) {
        $forwarded = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            $ips = array_map('trim', explode(',', $forwarded));
            for ($i = count($ips) - 1; $i >= 0; $i--) {
                $candidate = $ips[$i];
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    if (!in_array($candidate, $trustedProxies, true)) {
                        return $candidate;
                    }
                }
            }
        }
    }

    return $remote;
}

/**
 * Check if the persistent rate limits table exists in the database.
 */
function auth_rate_limit_table_exists(): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $stmt = db()->query("SHOW TABLES LIKE 'login_rate_limits'");
        $exists = (bool)$stmt->fetchColumn();
    } catch (Throwable) {
        $exists = false;
    }
    return $exists;
}

/**
 * Check whether a login attempt is currently throttled.
 *
 * @return array{throttled: bool, retry_after: int, minutes: int, reason?: string}
 */
function auth_is_throttled(string $username, ?string $ip = null): array
{
    if (!AUTH_RATE_LIMIT_ENABLED) {
        return ['throttled' => false, 'retry_after' => 0, 'minutes' => 0];
    }

    $username = normalize_username($username);
    $ip = $ip ?? auth_client_ip();

    if (!auth_rate_limit_table_exists()) {
        return auth_session_is_throttled($username, $ip);
    }

    $userHash = hash('sha256', strtolower($username));
    $keyUserIp = 'user_ip:' . $userHash . ':' . $ip;
    $keyIp = 'ip:' . $ip;
    $keyAccount = 'account:' . $userHash;

    try {
        $stmt = db()->prepare('
            SELECT rate_key, identifier_type, throttled_until,
                   TIMESTAMPDIFF(SECOND, NOW(), throttled_until) AS seconds_left
            FROM login_rate_limits
            WHERE rate_key IN (?, ?, ?) AND throttled_until > NOW()
            ORDER BY throttled_until DESC
            LIMIT 1
        ');
        $stmt->execute([$keyUserIp, $keyIp, $keyAccount]);
        $row = $stmt->fetch();

        if ($row && isset($row['seconds_left']) && (int)$row['seconds_left'] > 0) {
            $seconds = max(1, (int)$row['seconds_left']);
            $minutes = (int)ceil($seconds / 60);
            return [
                'throttled' => true,
                'retry_after' => $seconds,
                'minutes' => $minutes,
                'reason' => $row['identifier_type'],
            ];
        }
    } catch (Throwable $e) {
        error_log('[RateLimiter] Check error: ' . $e->getMessage());
    }

    return ['throttled' => false, 'retry_after' => 0, 'minutes' => 0];
}

/**
 * Record a failed login attempt and apply temporary throttling if thresholds are reached.
 * Concurrency protected with database row-level locking.
 *
 * @return array{throttled: bool, retry_after: int, minutes: int, reason?: string}
 */
function auth_record_failed_attempt(string $username, ?string $ip = null): array
{
    if (!AUTH_RATE_LIMIT_ENABLED) {
        return ['throttled' => false, 'retry_after' => 0, 'minutes' => 0];
    }

    $username = normalize_username($username);
    $ip = $ip ?? auth_client_ip();

    if (!auth_rate_limit_table_exists()) {
        return auth_session_record_failed($username, $ip);
    }

    $userHash = hash('sha256', strtolower($username));
    $targets = [
        [
            'key' => 'user_ip:' . $userHash . ':' . $ip,
            'type' => 'ip_account',
            'id' => $username . '@' . $ip,
            'max' => (int)AUTH_MAX_FAILED_PER_IP_USER,
        ],
        [
            'key' => 'ip:' . $ip,
            'type' => 'ip',
            'id' => $ip,
            'max' => (int)AUTH_MAX_FAILED_PER_IP,
        ],
        [
            'key' => 'account:' . $userHash,
            'type' => 'account',
            'id' => $username,
            'max' => (int)AUTH_MAX_FAILED_PER_ACCOUNT,
        ],
    ];

    $pdo = db();
    $throttledTriggered = null;

    try {
        $pdo->beginTransaction();

        foreach ($targets as $target) {
            $key = $target['key'];
            $type = $target['type'];
            $idVal = $target['id'];
            $maxAttempts = $target['max'];

            $sel = $pdo->prepare('SELECT id, failed_attempts, first_failed_at, throttled_until FROM login_rate_limits WHERE rate_key = ? FOR UPDATE');
            $sel->execute([$key]);
            $row = $sel->fetch();

            $now = time();
            $windowSeconds = AUTH_RATE_WINDOW_MINUTES * 60;
            $cooldownSeconds = AUTH_COOLDOWN_MINUTES * 60;

            if ($row) {
                $firstFailedTime = strtotime((string)$row['first_failed_at']);
                $throttledUntilTime = !empty($row['throttled_until']) ? strtotime((string)$row['throttled_until']) : null;
                $isCurrentlyThrottled = $throttledUntilTime !== null && $throttledUntilTime > $now;

                if (!$isCurrentlyThrottled && ($now - $firstFailedTime) > $windowSeconds) {
                    $attempts = 1;
                    $upd = $pdo->prepare('UPDATE login_rate_limits SET failed_attempts = 1, first_failed_at = NOW(), last_failed_at = NOW(), throttled_until = NULL WHERE id = ?');
                    $upd->execute([(int)$row['id']]);
                } else {
                    $attempts = (int)$row['failed_attempts'] + 1;
                    $newThrottledUntil = null;
                    if ($attempts >= $maxAttempts) {
                        $newThrottledUntil = date('Y-m-d H:i:s', $now + $cooldownSeconds);
                        if ($throttledTriggered === null) {
                            $throttledTriggered = [
                                'throttled' => true,
                                'retry_after' => $cooldownSeconds,
                                'minutes' => AUTH_COOLDOWN_MINUTES,
                                'reason' => $type,
                            ];
                        }
                    }
                    $upd = $pdo->prepare('UPDATE login_rate_limits SET failed_attempts = ?, last_failed_at = NOW(), throttled_until = COALESCE(?, throttled_until) WHERE id = ?');
                    $upd->execute([$attempts, $newThrottledUntil, (int)$row['id']]);
                }
            } else {
                $attempts = 1;
                $newThrottledUntil = $attempts >= $maxAttempts ? date('Y-m-d H:i:s', $now + $cooldownSeconds) : null;
                $ins = $pdo->prepare('
                    INSERT INTO login_rate_limits (rate_key, identifier_type, identifier, failed_attempts, first_failed_at, last_failed_at, throttled_until, created_at, updated_at)
                    VALUES (?, ?, ?, ?, NOW(), NOW(), ?, NOW(), NOW())
                ');
                $ins->execute([$key, $type, $idVal, $attempts, $newThrottledUntil]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[RateLimiter] Failed record error: ' . $e->getMessage());
    }

    return $throttledTriggered ?? ['throttled' => false, 'retry_after' => 0, 'minutes' => 0];
}

/**
 * Clear failed login attempts after a successful authentication.
 */
function auth_clear_failed_attempts(string $username, ?string $ip = null): void
{
    if (!AUTH_RATE_LIMIT_ENABLED) {
        return;
    }

    $username = normalize_username($username);
    $ip = $ip ?? auth_client_ip();

    if (!auth_rate_limit_table_exists()) {
        unset($_SESSION['_login_rate_limits']);
        return;
    }

    $userHash = hash('sha256', strtolower($username));
    $keyUserIp = 'user_ip:' . $userHash . ':' . $ip;
    $keyAccount = 'account:' . $userHash;

    try {
        $pdo = db();
        $stmt = $pdo->prepare('DELETE FROM login_rate_limits WHERE rate_key = ?');
        $stmt->execute([$keyUserIp]);

        $stmt = $pdo->prepare('DELETE FROM login_rate_limits WHERE rate_key = ? AND (throttled_until IS NULL OR throttled_until <= NOW())');
        $stmt->execute([$keyAccount]);
    } catch (Throwable $e) {
        error_log('[RateLimiter] Clear error: ' . $e->getMessage());
    }
}

/**
 * Reset rate limits for administrative testing or recovery.
 */
function auth_reset_rate_limits(?string $username = null, ?string $ip = null): void
{
    if (!auth_rate_limit_table_exists()) {
        unset($_SESSION['_login_rate_limits']);
        return;
    }

    try {
        $pdo = db();
        if ($username === null && $ip === null) {
            $pdo->exec('DELETE FROM login_rate_limits');
            return;
        }

        if ($username !== null) {
            $userHash = hash('sha256', strtolower(normalize_username($username)));
            $pdo->prepare('DELETE FROM login_rate_limits WHERE rate_key LIKE ? OR rate_key LIKE ?')
                ->execute(['user_ip:' . $userHash . '%', 'account:' . $userHash . '%']);
        }

        if ($ip !== null) {
            $pdo->prepare('DELETE FROM login_rate_limits WHERE rate_key LIKE ? OR rate_key LIKE ?')
                ->execute(['%:' . $ip, 'ip:' . $ip]);
        }
    } catch (Throwable $e) {
        error_log('[RateLimiter] Reset error: ' . $e->getMessage());
    }
}

/**
 * Prune stale rate-limit records older than the retention period.
 */
function auth_prune_rate_limits(int $retentionDays = 7): int
{
    if (!auth_rate_limit_table_exists()) {
        return 0;
    }
    try {
        $stmt = db()->prepare('
            DELETE FROM login_rate_limits
            WHERE last_failed_at < (NOW() - INTERVAL ? DAY)
              AND (throttled_until IS NULL OR throttled_until <= NOW())
        ');
        $stmt->execute([$retentionDays]);
        return $stmt->rowCount();
    } catch (Throwable) {
        return 0;
    }
}

/**
 * In-memory fallback if database table is unavailable.
 */
function auth_session_is_throttled(string $username, string $ip): array
{
    $key = hash('sha256', $username) . ':' . $ip;
    $limits = $_SESSION['_login_rate_limits'][$key] ?? null;
    if ($limits && !empty($limits['throttled_until']) && $limits['throttled_until'] > time()) {
        $seconds = $limits['throttled_until'] - time();
        return [
            'throttled' => true,
            'retry_after' => $seconds,
            'minutes' => (int)ceil($seconds / 60),
            'reason' => 'ip_account',
        ];
    }
    return ['throttled' => false, 'retry_after' => 0, 'minutes' => 0];
}

function auth_session_record_failed(string $username, string $ip): array
{
    $key = hash('sha256', $username) . ':' . $ip;
    $now = time();
    $data = $_SESSION['_login_rate_limits'][$key] ?? ['attempts' => 0, 'first_failed_at' => $now];

    if (($now - $data['first_failed_at']) > (AUTH_RATE_WINDOW_MINUTES * 60)) {
        $data = ['attempts' => 1, 'first_failed_at' => $now];
    } else {
        $data['attempts']++;
    }

    $throttled = false;
    $seconds = 0;
    if ($data['attempts'] >= AUTH_MAX_FAILED_PER_IP_USER) {
        $data['throttled_until'] = $now + (AUTH_COOLDOWN_MINUTES * 60);
        $throttled = true;
        $seconds = AUTH_COOLDOWN_MINUTES * 60;
    }

    $_SESSION['_login_rate_limits'][$key] = $data;
    return [
        'throttled' => $throttled,
        'retry_after' => $seconds,
        'minutes' => AUTH_COOLDOWN_MINUTES,
        'reason' => 'ip_account',
    ];
}
