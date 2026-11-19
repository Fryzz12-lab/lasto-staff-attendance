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
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/notification_service.php';

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
    $url = trim((string)$url);
    if ($url === '') {
        return (BASE_PATH ?: '') . '/notifications.php';
    }

    // Reject non-http(s) schemes immediately (e.g. javascript:, data:, file:)
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) && !preg_match('#^https?://#i', $url)) {
        return null;
    }

    // Protocol-relative URLs (//evil.com) are unsafe
    if (str_starts_with($url, '//')) {
        return null;
    }

    $path = '';
    $query = '';

    if (preg_match('#^https?://#i', $url)) {
        $origin = rtrim((string)(push_get_config_var('LASTO_PUBLIC_ORIGIN') ?: ''), '/');
        $bHost = '';
        $bPort = null;
        $bScheme = '';
        if ($origin && filter_var($origin, FILTER_VALIDATE_URL)) {
            $b = parse_url($origin);
            $bHost = strtolower((string)($b['host'] ?? ''));
            $bPort = $b['port'] ?? null;
            $bScheme = $b['scheme'] ?? '';
        } elseif (!empty($_SERVER['HTTP_HOST'])) {
            $parts = explode(':', $_SERVER['HTTP_HOST']);
            $bHost = strtolower($parts[0]);
            $bPort = isset($parts[1]) ? (int)$parts[1] : null;
            $bScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        }

        $a = parse_url($url);
        if (!$a || empty($a['host'])) return null;
        if (isset($a['user']) || isset($a['pass'])) return null;
        if ($bHost === '' || strtolower((string)$a['host']) !== $bHost) return null;
        if ($bPort !== null && ($a['port'] ?? ($a['scheme'] === 'https' ? 443 : 80)) !== ($bPort ?? ($bScheme === 'https' ? 443 : 80))) return null;

        $path = (string)($a['path'] ?? '/');
        $query = isset($a['query']) ? '?' . $a['query'] : '';
    } else {
        $parts = parse_url($url);
        $path = (string)($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    }

    // Path sanitization: reject path traversal and backslashes
    if (str_contains($path, '..') || str_contains($path, '\\')) {
        return null;
    }

    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }

    // Normalize path by removing duplicate slashes
    $path = (string)preg_replace('#/{2,}#', '/', $path);

    // Strip BASE_PATH if already present at the start of $path
    $base = BASE_PATH;
    $route = $path;
    if ($base !== '' && ($route === $base || str_starts_with($route, $base . '/'))) {
        $route = substr($route, strlen($base));
        if ($route === '') $route = '/';
    }

    // Explicitly blocked destinations
    if ($route === '/logout.php' || str_ends_with($route, '/logout.php')) {
        return null;
    }
    foreach (['/api/', '/cli/', '/config/', '/storage/', '/tests/', '/uploads/'] as $blocked) {
        if (str_starts_with($route, $blocked) || str_contains($route, $blocked)) {
            return null;
        }
    }

    // Whitelist allowed routes
    $allowed = (
        $route === '/' ||
        $route === '/index.php' ||
        $route === '/login.php' ||
        $route === '/notifications.php' ||
        $route === '/notification_open.php' ||
        str_starts_with($route, '/staff/') ||
        str_starts_with($route, '/admin/')
    );

    if (!$allowed) {
        return null;
    }

    return ($base !== '' ? $base : '') . $route . $query;
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
 * Retrieve environment variable or configuration value across getenv, $_ENV, and $_SERVER
 */
function push_get_config_var(string $name): ?string
{
    $val = getenv($name);
    if ($val !== false && trim((string)$val) !== '') {
        return trim((string)$val);
    }
    if (isset($_ENV[$name]) && is_string($_ENV[$name]) && trim($_ENV[$name]) !== '') {
        return trim($_ENV[$name]);
    }
    if (isset($_SERVER[$name]) && is_string($_SERVER[$name]) && trim($_SERVER[$name]) !== '') {
        return trim($_SERVER[$name]);
    }
    return null;
}

/**
 * Reconstruct standard SEC1 EC Private Key PEM from raw scalar d (and optional public point)
 * conforming to RFC 5915 / SEC 1: Elliptic Curve Cryptography
 */
function push_raw_to_sec1_pem(string $rawD, ?string $rawPub = null): string
{
    if (strlen($rawD) !== 32) {
        throw new InvalidArgumentException('Invalid EC private key scalar length');
    }
    // prime256v1 OID: 1.2.840.10045.3.1.7 -> 06 08 2a 86 48 ce 3d 03 01 07
    $oid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
    $params = "\xa0" . chr(strlen($oid)) . $oid;
    $privOctet = "\x04" . chr(strlen($rawD)) . $rawD;
    $seqBody = "\x02\x01\x01" . $privOctet . $params;

    if ($rawPub !== null && strlen($rawPub) === 65 && $rawPub[0] === "\x04") {
        $pubBitString = "\x00" . $rawPub;
        $pubTagged = "\xa1" . chr(strlen($pubBitString) + 2) . "\x03" . chr(strlen($pubBitString)) . $pubBitString;
        $seqBody .= $pubTagged;
    }

    $der = "\x30" . chr(strlen($seqBody)) . $seqBody;
    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}

/**
 * Validate VAPID key structure, consistency, and cryptographic pair
 */
function push_validate_vapid_keys(array $keys): array
{
    $pubKey = trim((string)($keys['publicKey'] ?? ''));
    if ($pubKey === '') {
        throw new RuntimeException('VAPID key validation failed: public key is empty.');
    }

    $pubRaw = push_base64url_decode($pubKey);
    if (strlen($pubRaw) !== 65 || $pubRaw[0] !== "\x04") {
        throw new RuntimeException('VAPID key validation failed: invalid public key format (must be 65-byte uncompressed EC point).');
    }

    $privPem = trim((string)($keys['privatePem'] ?? ''));
    $privKey = trim((string)($keys['privateKey'] ?? ''));

    if ($privPem === '' && $privKey === '') {
        throw new RuntimeException('VAPID key validation failed: missing private key.');
    }

    if ($privPem === '') {
        $privRaw = push_base64url_decode($privKey);
        if (strlen($privRaw) !== 32) {
            throw new RuntimeException('VAPID key validation failed: invalid private key format (must be 32-byte scalar).');
        }
        // Do not embed public point in SEC1 DER so OpenSSL derives the authentic EC point from scalar d
        $privPem = push_raw_to_sec1_pem($privRaw);
    }

    $privRes = openssl_pkey_get_private($privPem);
    if (!$privRes) {
        throw new RuntimeException('VAPID key validation failed: could not parse private key resource.');
    }

    $details = openssl_pkey_get_details($privRes);
    if (!$details || empty($details['ec'])) {
        throw new RuntimeException('VAPID key validation failed: key is not a valid EC key.');
    }

    $derivedPub = push_base64url_encode("\x04" . $details['ec']['x'] . $details['ec']['y']);
    if ($derivedPub !== $pubKey) {
        throw new RuntimeException('VAPID key validation failed: public key does not match private key.');
    }

    if ($privKey === '') {
        $privKey = push_base64url_encode($details['ec']['d']);
    }

    return [
        'publicKey' => $pubKey,
        'privateKey' => $privKey,
        'privatePem' => $privPem,
        'subject' => !empty($keys['subject']) ? trim((string)$keys['subject']) : null,
        'source' => (string)($keys['source'] ?? 'custom'),
    ];
}

/**
 * Generate a new persistent EC P-256 VAPID keypair
 */
function push_generate_vapid_keypair(): array
{
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

    return push_validate_vapid_keys([
        'publicKey' => $publicKey,
        'privateKey' => $privateKey,
        'privatePem' => $privatePem,
        'source' => 'generated',
    ]);
}

/**
 * Retrieve persistent VAPID keys with strict precedence, validation, and zero ephemeral fallback
 */
function push_get_vapid_keys(bool $forceReload = false): array
{
    static $keys = null;
    if ($keys !== null && !$forceReload) {
        return $keys;
    }

    // 1. Check environment variables or config/local.php
    $envPub = push_get_config_var('LASTO_VAPID_PUBLIC_KEY');
    $envPriv = push_get_config_var('LASTO_VAPID_PRIVATE_KEY');
    $envPem = push_get_config_var('LASTO_VAPID_PRIVATE_PEM');
    $envSubject = push_get_config_var('LASTO_VAPID_SUBJECT');

    if ($envPub !== null && ($envPriv !== null || $envPem !== null)) {
        $keys = push_validate_vapid_keys([
            'publicKey' => $envPub,
            'privateKey' => $envPriv ?? '',
            'privatePem' => $envPem ?? '',
            'subject' => $envSubject,
            'source' => 'environment',
        ]);
        return $keys;
    }

    // 2. Check explicit key file from environment variable (outside web root)
    $envFile = push_get_config_var('LASTO_VAPID_KEY_FILE');
    if ($envFile !== null) {
        if (!is_file($envFile) || !is_readable($envFile)) {
            throw new RuntimeException('VAPID key file specified in LASTO_VAPID_KEY_FILE not found or unreadable.');
        }
        $data = json_decode((string)file_get_contents($envFile), true);
        if (!is_array($data)) {
            throw new RuntimeException('VAPID key file specified in LASTO_VAPID_KEY_FILE contains malformed JSON.');
        }
        $data['source'] = 'custom_file';
        $keys = push_validate_vapid_keys($data);
        return $keys;
    }

    // 3. Check persistent protected files
    $candidates = [
        __DIR__ . '/../config/vapid.json',
        __DIR__ . '/../storage/vapid/keys.json',
    ];
    foreach ($candidates as $cand) {
        if (is_file($cand)) {
            $data = json_decode((string)file_get_contents($cand), true);
            if (!is_array($data)) {
                throw new RuntimeException('Persistent VAPID key file contains malformed JSON.');
            }
            $data['source'] = 'file';
            $keys = push_validate_vapid_keys($data);
            return $keys;
        }
    }

    // 4. Missing credentials handling
    // In production, automatic generation is strictly forbidden to prevent key rotation
    if (defined('APP_ENV') && (APP_ENV === 'production' || APP_ENV === 'staging')) {
        throw new RuntimeException('VAPID configuration missing. Configure LASTO_VAPID_PUBLIC_KEY and LASTO_VAPID_PRIVATE_KEY in config/local.php or provide a secure key file.');
    }

    // In development ONLY: generate once and persist to config/vapid.json
    $keyFile = __DIR__ . '/../config/vapid.json';
    $dir = dirname($keyFile);
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('Cannot generate VAPID keys: target directory is missing or read-only.');
    }

    $keys = push_generate_vapid_keypair();
    $payload = json_encode([
        'publicKey' => $keys['publicKey'],
        'privateKey' => $keys['privateKey'],
        'privatePem' => $keys['privatePem'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    $written = @file_put_contents($keyFile, $payload);
    if ($written === false) {
        throw new RuntimeException('Failed to write generated VAPID keys to disk. In-memory keys are prohibited.');
    }

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

    $subClaim = !empty($vapidKeys['subject'])
        ? $vapidKeys['subject']
        : ('mailto:attendance-admin@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

    $header = push_base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = push_base64url_encode(json_encode([
        'aud' => $origin,
        'exp' => time() + 43200, // 12 hours
        'sub' => $subClaim,
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
function push_request_headers(string $endpoint, array $vapidKeys, array $encryptedHeaders): array
{
    $vapidHeader = push_create_vapid_header($endpoint, $vapidKeys);
    return array_merge([
        'Authorization: ' . $vapidHeader,
        'TTL: 86400',
        'Urgency: high',
    ], $encryptedHeaders);
}

function push_send_endpoint(string $endpoint, string $p256dh, string $auth, array $payloadData): array
{
    // Centralized Staging Outbound Policy: Web push disabled by default in staging
    $isStaging = (function_exists('lasto_env') && lasto_env('LASTO_APP_ENV') === 'staging') || (getenv('LASTO_APP_ENV') === 'staging') || (defined('APP_ENV') && APP_ENV === 'staging');
    if ($isStaging) {
        $stagingPushEnabled = in_array(strtolower((string) (function_exists('lasto_env') ? lasto_env('LASTO_STAGING_PUSH_ENABLED', '0') : getenv('LASTO_STAGING_PUSH_ENABLED'))), ['1', 'true', 'yes'], true);
        if (!$stagingPushEnabled) {
            return ['success' => false, 'status' => 0, 'error' => 'Web push disabled by default in staging'];
        }
    }
    if (!push_is_valid_endpoint($endpoint)) return ['success' => false, 'status' => 0, 'error' => 'Invalid push endpoint'];
    $vapidKeys = push_get_vapid_keys();
    $payloadJson = json_encode($payloadData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $encrypted = push_encrypt_payload($payloadJson, $p256dh, $auth);

    $headers = push_request_headers($endpoint, $vapidKeys, $encrypted['headers']);

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
    $stmt = $pdo->prepare('SELECT id, user_id FROM push_subscriptions WHERE endpoint = ? LIMIT 1');
    $stmt->execute([$endpoint]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Retain this device's choices on key refresh, but never carry them to another account.
        $preferences = (int)$existing['user_id'] === $userId ? null : json_encode(push_new_device_preferences($userId));
        $up = $pdo->prepare('UPDATE push_subscriptions SET user_id = ?, p256dh = ?, auth = ?, user_agent = ?, notification_preferences = COALESCE(?, notification_preferences), updated_at = NOW() WHERE id = ?');
        return $up->execute([$userId, $p256dh, $auth, $userAgent, $preferences, (int)$existing['id']]);
    }

    $ins = $pdo->prepare('INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent, notification_preferences, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())');
    return $ins->execute([$userId, $endpoint, $p256dh, $auth, $userAgent, json_encode(push_new_device_preferences($userId))]);
}

function push_new_device_preferences(int $userId): array
{
    return array_merge(push_get_preferences($userId), ['all' => true]);
}

function push_find_device(int $userId, string $endpoint): ?array
{
    if ($endpoint === '') return null;
    $stmt = db()->prepare('SELECT id, notification_preferences FROM push_subscriptions WHERE user_id = ? AND endpoint = ? LIMIT 1');
    $stmt->execute([$userId, $endpoint]);
    return $stmt->fetch() ?: null;
}

function push_set_device_preferences(int $userId, string $endpoint, array $changes): ?array
{
    $device = push_find_device($userId, $endpoint);
    if (!$device) return null;
    $preferences = push_get_device_preferences($device, push_get_preferences($userId));
    foreach ($changes as $key => $value) {
        if (!array_key_exists($key, $preferences) || !is_bool($value)) return null;
        $preferences[$key] = $value;
    }
    $stmt = db()->prepare('UPDATE push_subscriptions SET notification_preferences = ?, updated_at = NOW() WHERE id = ? AND user_id = ?');
    $stmt->execute([json_encode($preferences), (int)$device['id'], $userId]);
    return $preferences;
}

function push_set_device_language(int $userId, string $endpoint, string $language): bool
{
    if (!in_array($language, ['en', 'ms', 'zh'], true)) return false;
    $device = push_find_device($userId, $endpoint);
    if (!$device) return false;
    $preferences = push_get_device_preferences($device, push_get_preferences($userId));
    $preferences['language'] = $language;
    return db()->prepare('UPDATE push_subscriptions SET notification_preferences=?,updated_at=NOW() WHERE id=? AND user_id=?')
        ->execute([json_encode($preferences), (int)$device['id'], $userId]);
}

function push_device_language(array $device, string $fallback): string
{
    $decoded = json_decode((string)($device['notification_preferences'] ?? ''), true);
    $language = is_array($decoded) ? ($decoded['language'] ?? null) : null;
    return in_array($language, ['en', 'ms', 'zh'], true) ? $language : $fallback;
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
    // Centralized Staging Outbound Policy: Web push disabled by default in staging
    $isStaging = (function_exists('lasto_env') && lasto_env('LASTO_APP_ENV') === 'staging') || (getenv('LASTO_APP_ENV') === 'staging') || (defined('APP_ENV') && APP_ENV === 'staging');
    if ($isStaging) {
        $stagingPushEnabled = in_array(strtolower((string) (function_exists('lasto_env') ? lasto_env('LASTO_STAGING_PUSH_ENABLED', '0') : getenv('LASTO_STAGING_PUSH_ENABLED'))), ['1', 'true', 'yes'], true);
        if (!$stagingPushEnabled) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 'Web push disabled by default in staging (set LASTO_STAGING_PUSH_ENABLED=1 to enable)'];
        }
    }
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
        'url' => push_safe_destination($url) ?? ((BASE_PATH ?: '') . '/notifications.php'),
        'tag' => $tag ?: ('sas-notice-' . time()),
        'category' => $category,
        'timestamp' => time() * 1000,
    ];
    $payload['data'] = ['url' => $payload['url'], 'category' => $category];

    $sent = 0;
    $failed = 0;
    $staleIds = [];
    $defaultLanguage = function_exists('get_setting') ? (string)(get_setting('default_language', 'en') ?: 'en') : 'en';

    foreach ($subscriptions as $sub) {
        $devicePrefs = push_get_device_preferences($sub, $prefs);
        if (!($devicePrefs['all'] ?? true) || ($category && isset($devicePrefs[$category]) && !$devicePrefs[$category])) continue;
        if (!push_is_valid_endpoint((string)$sub['endpoint'])) { $staleIds[] = (int)$sub['id']; continue; }
        try {
            $language = push_device_language($sub, $defaultLanguage);
            try {
                $localized = notification_localized_push_content($category, $tag, $title, $body, $language);
            } catch (Throwable $localizationError) {
                error_log('[WebPush] Localization fallback: ' . $localizationError->getMessage());
                $localized = ['title' => $title, 'message' => $body];
            }
            $devicePayload = $payload;
            $devicePayload['title'] = $localized['title'];
            $devicePayload['body'] = $localized['message'];
            $result = push_send_endpoint($sub['endpoint'], $sub['p256dh'], $sub['auth'], $devicePayload);
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
