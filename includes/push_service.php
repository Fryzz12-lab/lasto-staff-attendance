<?php
/**
 * Web Push Notification Service (RFC 8291 / RFC 8292)
 * Staff Attendance & Workforce Management System
 */

declare(strict_types=1);

if (defined('SAS_PUSH_SERVICE_LOADED')) {
    return;
}
define('SAS_PUSH_SERVICE_LOADED', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

function push_is_valid_endpoint(string $endpoint): bool
{
    if (strlen($endpoint) > 4096 || !filter_var($endpoint, FILTER_VALIDATE_URL)) return false;
    $p = parse_url($endpoint); if (($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user'], $p['pass'])) return false;
    if (!empty($p['port']) && (int)$p['port'] !== 443) return false;
    $host = strtolower(rtrim($p['host'], '.'));
    foreach (['fcm.googleapis.com','push.services.mozilla.com','updates.push.services.mozilla.com','web.push.apple.com'] as $allowed) if ($host === $allowed || str_ends_with($host, '.' . $allowed)) return true;
    return false;
}

function push_safe_destination(?string $url): ?string
{
    $url = trim((string)$url); if ($url === '') return BASE_PATH . '/';
    if (str_starts_with($url, '/')) return BASE_PATH . '/' . ltrim($url, '/');
    $origin = rtrim((string)(getenv('LASTO_PUBLIC_ORIGIN') ?: ''), '/'); if (!$origin || !filter_var($url, FILTER_VALIDATE_URL)) return null;
    $a = parse_url($url); $b = parse_url($origin);
    if (($a['scheme'] ?? '') !== ($b['scheme'] ?? '') || strtolower((string)($a['host'] ?? '')) !== strtolower((string)($b['host'] ?? '')) || ($a['port'] ?? null) !== ($b['port'] ?? null) || isset($a['user'], $a['pass'])) return null;
    $path = (string)($a['path'] ?? '/'); if (BASE_PATH !== '' && !($path === BASE_PATH || str_starts_with($path, BASE_PATH . '/'))) return null;
    return $path . (isset($a['query']) ? '?' . $a['query'] : '');
}

function push_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function push_base64url_decode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Locate OpenSSL configuration file for Windows/XAMPP or Linux
 */
function push_get_openssl_conf(): ?string
{
    if (getenv('OPENSSL_CONF') && file_exists((string)getenv('OPENSSL_CONF'))) {
        return (string)getenv('OPENSSL_CONF');
    }
    $candidates = [
        'C:/xampp/php/extras/ssl/openssl.cnf',
        'C:/xampp/apache/bin/openssl.cnf',
        '/etc/ssl/openssl.cnf',
        '/etc/pki/tls/openssl.cnf',
    ];
    foreach ($candidates as $cand) {
        if (file_exists($cand)) {
            return $cand;
        }
    }
    return null;
}

/**
 * Retrieve or generate persistent VAPID keys
 */
function push_get_vapid_keys(): array
{
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }

    $keyFile = __DIR__ . '/../config/vapid.json';
    if (is_file($keyFile)) {
        $data = json_decode((string)file_get_contents($keyFile), true);
        if (!empty($data['publicKey']) && !empty($data['privateKey']) && !empty($data['privatePem'])) {
            $keys = $data;
            return $keys;
        }
    }

    $conf = push_get_openssl_conf();
    $args = [
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ];
    if ($conf) {
        $args['config'] = $conf;
    }

    $res = openssl_pkey_new($args);
    if (!$res) {
        throw new RuntimeException('Failed to generate EC P-256 keys for Web Push');
    }

    $exportArgs = $conf ? ['config' => $conf] : null;
    $privatePem = '';
    openssl_pkey_export($res, $privatePem, null, $exportArgs);

    $details = openssl_pkey_get_details($res);
    if (!$details || empty($details['ec'])) {
        throw new RuntimeException('Invalid EC key details generated');
    }

    $publicKey = push_base64url_encode("\x04" . $details['ec']['x'] . $details['ec']['y']);
    $privateKey = push_base64url_encode($details['ec']['d']);

    $keys = [
        'publicKey' => $publicKey,
        'privateKey' => $privateKey,
        'privatePem' => $privatePem,
    ];

    @file_put_contents($keyFile, json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $keys;
}

/**
 * Returns VAPID public key string for frontend registration
 */
function push_get_public_key(): string
{
    $keys = push_get_vapid_keys();
    return $keys['publicKey'];
}

/**
 * Convert DER signature from openssl_sign into raw 64-byte IEEE P1363 (ES256)
 */
function push_der_to_raw(string $der): string
{
    $offset = 2;
    if (ord($der[1]) & 0x80) {
        $offset += (ord($der[1]) & 0x7f);
    }
    $offset += 1;
    $rLen = ord($der[$offset++]);
    $r = substr($der, $offset, $rLen);
    $offset += $rLen + 1;
    $sLen = ord($der[$offset++]);
    $s = substr($der, $offset, $sLen);
    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");
    return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
}

/**
 * Generate a VAPID Authorization header (JWT)
 */
function push_create_vapid_header(string $endpoint, array $vapidKeys): string
{
    $parts = parse_url($endpoint);
    $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
    if (!empty($parts['port'])) {
        $origin .= ':' . $parts['port'];
    }

    $header = push_base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = push_base64url_encode(json_encode([
        'aud' => $origin,
        'exp' => time() + 43200, // 12 hours
        'sub' => 'mailto:attendance-admin@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
    ]));

    $unsigned = $header . '.' . $claims;
    $privKeyRes = openssl_pkey_get_private($vapidKeys['privatePem']);
    if (!$privKeyRes) {
        throw new RuntimeException('Failed to load private key resource');
    }

    openssl_sign($unsigned, $derSig, $privKeyRes, OPENSSL_ALGO_SHA256);
    $jwt = $unsigned . '.' . push_base64url_encode(push_der_to_raw($derSig));

    return 'vapid t=' . $jwt . ', k=' . $vapidKeys['publicKey'];
}

/**
 * Encrypt payload using RFC 8291 (aes128gcm)
 */
function push_encrypt_payload(string $plaintext, string $clientPubBase64, string $clientAuthBase64): array
{
    $clientPubRaw = push_base64url_decode($clientPubBase64);
    $clientAuthRaw = push_base64url_decode($clientAuthBase64);

    if (strlen($clientPubRaw) !== 65 || strlen($clientAuthRaw) !== 16) {
        throw new InvalidArgumentException('Invalid client push subscription keys');
    }

    $conf = push_get_openssl_conf();
    $args = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
    if ($conf) {
        $args['config'] = $conf;
    }
    $localKey = openssl_pkey_new($args);
    $localDetails = openssl_pkey_get_details($localKey);
    $localPubRaw = "\x04" . $localDetails['ec']['x'] . $localDetails['ec']['y'];

    // Convert client raw public key into OpenSSL PEM
    $derPrefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
    $clientPubPem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($derPrefix . $clientPubRaw), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $clientPubResource = openssl_pkey_get_public($clientPubPem);
    if (!$clientPubResource) {
        throw new RuntimeException('Cannot parse client public key PEM');
    }

    // ECDH shared secret
    $sharedSecret = openssl_pkey_derive($clientPubResource, $localKey);
    if (!$sharedSecret || strlen($sharedSecret) !== 32) {
        throw new RuntimeException('ECDH secret derivation failed');
    }

    // HKDF key derivation
    $authInfo = "WebPush: info\x00" . $clientPubRaw . $localPubRaw;
    $ikm = hash_hkdf('sha256', $sharedSecret, 32, $authInfo, $clientAuthRaw);

    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

    $record = $plaintext . "\x02"; // padding delimiter
    $ciphertext = openssl_encrypt($record, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) {
        throw new RuntimeException('AES-128-GCM encryption failed');
    }

    $body = $salt . pack('N', 4096) . chr(strlen($localPubRaw)) . $localPubRaw . $ciphertext . $tag;

    return [
        'body' => $body,
        'headers' => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'Content-Length: ' . strlen($body),
        ],
    ];
}

/**
 * Send raw push to a single endpoint
 */
function push_send_endpoint(string $endpoint, string $p256dh, string $auth, array $payloadData): array
{
    if (!push_is_valid_endpoint($endpoint)) return ['success' => false, 'status' => 0, 'error' => 'Invalid push endpoint'];
    $vapidKeys = push_get_vapid_keys();
    $vapidHeader = push_create_vapid_header($endpoint, $vapidKeys);

    $payloadJson = json_encode($payloadData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $encrypted = push_encrypt_payload($payloadJson, $p256dh, $auth);

    $headers = array_merge([
        'Authorization: ' . $vapidHeader,
        'TTL: 86400',
        'Urgency: high',
        'Topic: attendance',
    ], $encrypted['headers']);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $encrypted['body'],
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_TCP_NODELAY => 1,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    return [
        'success' => ($httpCode >= 200 && $httpCode < 300),
        'status' => $httpCode,
        'error' => $curlError,
        'response' => $response,
    ];
}

/**
 * Save or refresh a user's device subscription
 */
function push_subscribe_device(int $userId, string $endpoint, string $p256dh, string $auth, ?string $userAgent = null): bool
{
    if (!push_is_valid_endpoint($endpoint)) return false;
    $pdo = db();
    // Check if subscription for this endpoint already exists
    $stmt = $pdo->prepare('SELECT id FROM push_subscriptions WHERE endpoint = ? LIMIT 1');
    $stmt->execute([$endpoint]);
    $existing = $stmt->fetch();

    if ($existing) {
        $up = $pdo->prepare('UPDATE push_subscriptions SET user_id = ?, p256dh = ?, auth = ?, user_agent = ?, updated_at = NOW() WHERE id = ?');
        return $up->execute([$userId, $p256dh, $auth, $userAgent, (int)$existing['id']]);
    }

    $ins = $pdo->prepare('INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
    return $ins->execute([$userId, $endpoint, $p256dh, $auth, $userAgent]);
}

/**
 * Remove a device subscription
 */
function push_unsubscribe_device(int $userId, string $endpoint): bool
{
    $pdo = db();
    $stmt = $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?');
    return $stmt->execute([$userId, $endpoint]);
}

/**
 * Retrieve user's notification preferences
 */
function push_get_preferences(int $userId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT notification_preferences FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $raw = $stmt->fetchColumn();

    $defaults = [
        'all' => false,
        'break_ending' => true,
        'shift_ending' => true,
        'leave_decision' => true,
        'missing_clock_out' => true,
        'new_leave_request' => true,
        'new_wfh_request' => true,
        'new_correction' => true,
        'staff_setup' => true,
        'job_failure' => true,
    ];

    if ($raw && is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_merge($defaults, $decoded);
        }
    }

    return $defaults;
}

/**
 * Update user's notification preferences
 */
function push_update_preferences(int $userId, array $preferences): bool
{
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE users SET notification_preferences = ? WHERE id = ?');
    return $stmt->execute([json_encode($preferences), $userId]);
}

function push_get_device_preferences(array $sub, array $fallback): array
{
    $raw = $sub['notification_preferences'] ?? null;
    if (is_string($raw) && $raw !== '') { $decoded = json_decode($raw, true); if (is_array($decoded)) return array_merge($fallback, $decoded); }
    // Existing subscriptions predate per-device preferences and were enabled explicitly.
    return array_merge($fallback, ['all' => true]);
}

/**
 * Dispatch a push notification to all subscribed devices of a user
 */
function push_send_to_user(int $userId, string $title, string $body, ?string $url = null, ?string $tag = null, ?string $category = null): array
{
    $prefs = push_get_preferences($userId);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, endpoint, p256dh, auth, notification_preferences FROM push_subscriptions WHERE user_id = ?');
    $stmt->execute([$userId]);
    $subscriptions = $stmt->fetchAll();

    if (empty($subscriptions)) {
        return ['sent' => 0, 'failed' => 0, 'skipped' => 'No active device subscriptions'];
    }

    $payload = [
        'title' => $title,
        'body' => $body,
        'icon' => (BASE_PATH ?: '') . '/assets/img/icon-192.png',
        'badge' => (BASE_PATH ?: '') . '/assets/img/icon-192.png',
        'url' => push_safe_destination($url) ?? ((BASE_PATH ?: '') . '/'),
        'tag' => $tag ?: ('sas-notice-' . time()),
        'timestamp' => time() * 1000,
    ];
    $payload['data'] = ['url' => $payload['url']];

    $sent = 0;
    $failed = 0;
    $staleIds = [];

    foreach ($subscriptions as $sub) {
        $devicePrefs = push_get_device_preferences($sub, $prefs);
        if (!($devicePrefs['all'] ?? true) || ($category && isset($devicePrefs[$category]) && !$devicePrefs[$category])) continue;
        if (!push_is_valid_endpoint((string)$sub['endpoint'])) { $staleIds[] = (int)$sub['id']; continue; }
        try {
            $result = push_send_endpoint($sub['endpoint'], $sub['p256dh'], $sub['auth'], $payload);
            if ($result['success']) {
                $sent++;
            } else {
                $failed++;
                // If endpoint is 404 or 410 Gone, user unsubscribed or revoked in browser
                if (in_array((int)$result['status'], [404, 410], true)) {
                    $staleIds[] = (int)$sub['id'];
                }
            }
        } catch (Throwable $e) {
            $failed++;
            error_log('[WebPush] Error sending to subscription ' . $sub['id'] . ': ' . $e->getMessage());
        }
    }

    if (!empty($staleIds)) {
        $inQuery = implode(',', array_fill(0, count($staleIds), '?'));
        $del = $pdo->prepare("DELETE FROM push_subscriptions WHERE id IN ($inQuery)");
        $del->execute($staleIds);
    }

    return ['sent' => $sent, 'failed' => $failed];
}

/**
 * Dispatch notification to all active administrators
 */
function push_send_to_admins(string $title, string $body, ?string $url = null, ?string $tag = null, ?string $category = null): array
{
    $pdo = db();
    $stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1");
    $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $totalSent = 0;
    $totalFailed = 0;
    foreach ($admins as $adminId) {
        $res = push_send_to_user((int)$adminId, $title, $body, $url, $tag, $category);
        $totalSent += ($res['sent'] ?? 0);
        $totalFailed += ($res['failed'] ?? 0);
    }

    return ['sent' => $totalSent, 'failed' => $totalFailed];
}
