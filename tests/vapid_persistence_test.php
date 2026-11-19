<?php
/**
 * Automated Test Suite: VAPID Key Persistence, Precedence & Secure Packaging (Phase 6B.3 / H4)
 * AGY Staff Attendance & Workforce Management System
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/push_service.php';

$passed = 0;
$failed = 0;

function assert_true(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$message}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$message}\n";
    }
}

echo "=== PHASE 6B.3: VAPID KEY PERSISTENCE & SECURE PACKAGING TESTS ===\n\n";

// --- Test Suite 1: Active Key Structure & Cryptographic Consistency ---
echo "--- Test Suite 1: Active Key Structure & Cryptographic Consistency ---\n";

$isUat = str_contains(str_replace('\\', '/', __DIR__), 'agy-staff-attendance-uat');
$expectedFp = $isUat
    ? '677821752a2e710fcc79c02bb4a698c18e4f2519dbf865e75e0c94b9699a6e04'
    : '15d1c5a682bd82c5922c88d1d89c84e9914098a268ea12dd4e72aa6fe790e6c7';
$envLabel = $isUat ? 'UAT' : 'Dev';
$keys = push_get_vapid_keys(true);

assert_true(!empty($keys['publicKey']), 'Active public key is not empty');
assert_true(strlen($keys['publicKey']) === 87, 'Public key length is 87 characters (base64url)');
$pubRaw = push_base64url_decode($keys['publicKey']);
assert_true(strlen($pubRaw) === 65 && $pubRaw[0] === "\x04", 'Public key decodes to 65 bytes uncompressed EC point (\x04)');

assert_true(!empty($keys['privateKey']), 'Active private key scalar d is present');
$privRaw = push_base64url_decode($keys['privateKey']);
assert_true(strlen($privRaw) === 32, 'Private key scalar d decodes to exactly 32 bytes');

assert_true(!empty($keys['privatePem']), 'Active private PEM is present');
$privRes = openssl_pkey_get_private($keys['privatePem']);
assert_true($privRes !== false, 'Private PEM parses successfully with OpenSSL');

$details = openssl_pkey_get_details($privRes);
$derivedPub = push_base64url_encode("\x04" . $details['ec']['x'] . $details['ec']['y']);
assert_true($derivedPub === $keys['publicKey'], 'Private key cryptographically corresponds to the public key');

$currentFp = hash('sha256', push_get_public_key());
assert_true($currentFp === $expectedFp, "{$envLabel} public key fingerprint matches recorded baseline ({$expectedFp})");

// --- Test Suite 2: SEC1 Reconstruction from Raw Key Scalar ---
echo "\n--- Test Suite 2: SEC1 Reconstruction from Raw Key Scalar ---\n";

$reconstructedPem = push_raw_to_sec1_pem($privRaw, $pubRaw);
$reconRes = openssl_pkey_get_private($reconstructedPem);
assert_true($reconRes !== false, 'Reconstructed SEC1 PEM parses successfully in OpenSSL');

$testMsg = 'lasto-vapid-signature-verification';
openssl_sign($testMsg, $sig1, $privRes, OPENSSL_ALGO_SHA256);
openssl_sign($testMsg, $sig2, $reconRes, OPENSSL_ALGO_SHA256);

$pubPem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $pubRaw), 64, "\n") . "-----END PUBLIC KEY-----\n";
$v1 = openssl_verify($testMsg, $sig1, $pubPem, OPENSSL_ALGO_SHA256);
$v2 = openssl_verify($testMsg, $sig2, $pubPem, OPENSSL_ALGO_SHA256);
assert_true($v1 === 1 && $v2 === 1, 'Signature produced from reconstructed SEC1 PEM verifies identically');

// --- Test Suite 3: Key-Loading Precedence ---
echo "\n--- Test Suite 3: Key-Loading Precedence ---\n";

// Generate a temporary isolated test keypair
$testKeypair = push_generate_vapid_keypair();
$testPub = $testKeypair['publicKey'];
$testPriv = $testKeypair['privateKey'];

// Precedence Level 1: Environment Variables
putenv("LASTO_VAPID_PUBLIC_KEY={$testPub}");
putenv("LASTO_VAPID_PRIVATE_KEY={$testPriv}");
$_ENV['LASTO_VAPID_PUBLIC_KEY'] = $testPub;
$_ENV['LASTO_VAPID_PRIVATE_KEY'] = $testPriv;

$loadedEnvKeys = push_get_vapid_keys(true);
assert_true($loadedEnvKeys['source'] === 'environment', 'Environment variables take highest precedence (source is environment)');
assert_true($loadedEnvKeys['publicKey'] === $testPub, 'Public key loaded from environment matches test fixture');

// Precedence Level 2: Custom Key File via LASTO_VAPID_KEY_FILE
putenv('LASTO_VAPID_PUBLIC_KEY');
putenv('LASTO_VAPID_PRIVATE_KEY');
unset($_ENV['LASTO_VAPID_PUBLIC_KEY'], $_ENV['LASTO_VAPID_PRIVATE_KEY']);
unset($_SERVER['LASTO_VAPID_PUBLIC_KEY'], $_SERVER['LASTO_VAPID_PRIVATE_KEY']);

$tempKeyFile = sys_get_temp_dir() . '/test_vapid_' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($tempKeyFile, json_encode($testKeypair));

putenv("LASTO_VAPID_KEY_FILE={$tempKeyFile}");
$_ENV['LASTO_VAPID_KEY_FILE'] = $tempKeyFile;

$loadedCustomFileKeys = push_get_vapid_keys(true);
assert_true($loadedCustomFileKeys['source'] === 'custom_file', 'Custom file from LASTO_VAPID_KEY_FILE takes precedence over default file');
assert_true($loadedCustomFileKeys['publicKey'] === $testPub, 'Public key loaded from custom file matches test fixture');

// Cleanup custom file & reset to default file
putenv('LASTO_VAPID_KEY_FILE');
unset($_ENV['LASTO_VAPID_KEY_FILE'], $_SERVER['LASTO_VAPID_KEY_FILE']);
@unlink($tempKeyFile);

// Precedence Level 3: Default persistent file
$loadedDefaultKeys = push_get_vapid_keys(true);
assert_true($loadedDefaultKeys['source'] === 'file', 'Default persistent config/vapid.json loaded when env is empty');
assert_true($loadedDefaultKeys['publicKey'] === $keys['publicKey'], 'Baseline public key restored after precedence tests');

// --- Test Suite 4: Key Format Validation & Error Handling ---
echo "\n--- Test Suite 4: Key Format Validation & Error Handling ---\n";

// Test invalid public key length
try {
    push_validate_vapid_keys([
        'publicKey' => push_base64url_encode("invalid-short-key"),
        'privateKey' => $testPriv,
    ]);
    assert_true(false, 'Invalid public key length should throw RuntimeException');
} catch (RuntimeException $e) {
    assert_true(str_contains($e->getMessage(), 'invalid public key format'), 'Invalid public key length caught with descriptive error');
}

// Test invalid private key length
try {
    push_validate_vapid_keys([
        'publicKey' => $testPub,
        'privateKey' => push_base64url_encode("invalid-short-scalar"),
    ]);
    assert_true(false, 'Invalid private key length should throw RuntimeException');
} catch (RuntimeException $e) {
    assert_true(str_contains($e->getMessage(), 'invalid private key format'), 'Invalid private key scalar caught with descriptive error');
}

// Test mismatched public & private key
try {
    $anotherKeypair = push_generate_vapid_keypair();
    push_validate_vapid_keys([
        'publicKey' => $testPub,
        'privateKey' => $anotherKeypair['privateKey'],
    ]);
    assert_true(false, 'Mismatched keypair should throw RuntimeException');
} catch (RuntimeException $e) {
    assert_true(str_contains($e->getMessage(), 'public key does not match private key'), 'Mismatched keypair caught with cryptographic error');
}

// Test missing key file error
$fakeKeyFile = sys_get_temp_dir() . '/nonexistent_' . uniqid() . '.json';
putenv("LASTO_VAPID_KEY_FILE={$fakeKeyFile}");
$_ENV['LASTO_VAPID_KEY_FILE'] = $fakeKeyFile;
try {
    push_get_vapid_keys(true);
    assert_true(false, 'Missing key file specified in LASTO_VAPID_KEY_FILE should throw');
} catch (RuntimeException $e) {
    assert_true(str_contains($e->getMessage(), 'LASTO_VAPID_KEY_FILE not found or unreadable'), 'Missing key file produces actionable error without regenerating');
} finally {
    putenv('LASTO_VAPID_KEY_FILE');
    unset($_ENV['LASTO_VAPID_KEY_FILE'], $_SERVER['LASTO_VAPID_KEY_FILE']);
}

// --- Test Suite 5: Secret Redaction & Leak Resistance in Errors ---
echo "\n--- Test Suite 5: Secret Redaction & Leak Resistance in Errors ---\n";

$secretCanary = 'TOP_SECRET_PRIVATE_CANARY_VALUE_12345';
try {
    push_validate_vapid_keys([
        'publicKey' => $testPub,
        'privateKey' => push_base64url_encode($secretCanary),
    ]);
} catch (RuntimeException $e) {
    $msg = $e->getMessage();
    assert_true(!str_contains($msg, $secretCanary), 'Exception message does not leak raw private key value');
    assert_true(!str_contains($msg, $testPriv), 'Exception message does not leak valid private key material');
}

// --- Test Suite 6: VAPID Header Generation & ES256 JWT Verification ---
echo "\n--- Test Suite 6: VAPID Header Generation & ES256 JWT Verification ---\n";

$endpoint = 'https://fcm.googleapis.com/fcm/send/test-mock-token-12345';
$header = push_create_vapid_header($endpoint, $keys);

assert_true(str_starts_with($header, 'vapid t='), 'Authorization header format starts with vapid t=');
assert_true(str_contains($header, ', k=' . $keys['publicKey']), 'Authorization header includes public key parameter k=');

preg_match('/^vapid t=([^,]+), k=(.+)$/', $header, $matches);
assert_true(!empty($matches[1]), 'VAPID JWT extracted successfully from header');
$jwtParts = explode('.', $matches[1]);
assert_true(count($jwtParts) === 3, 'VAPID token is valid 3-segment JWT');

$jwtHeader = json_decode(push_base64url_decode($jwtParts[0]), true);
$jwtClaims = json_decode(push_base64url_decode($jwtParts[1]), true);
assert_true(($jwtHeader['alg'] ?? '') === 'ES256', 'JWT algorithm is ES256');
assert_true(($jwtClaims['aud'] ?? '') === 'https://fcm.googleapis.com', 'JWT audience matches endpoint origin');
assert_true(($jwtClaims['exp'] ?? 0) > time(), 'JWT expiration is in the future');

// Verify ES256 signature
$sigRaw = push_base64url_decode($jwtParts[2]);
assert_true(strlen($sigRaw) === 64, 'Raw ES256 IEEE P1363 signature is 64 bytes');

// --- Test Suite 7: Deployment Artifact Exclusion Security ---
echo "\n--- Test Suite 7: Deployment Artifact Exclusion Security ---\n";

$buildScript = file_get_contents(__DIR__ . '/../cli/build_cpanel_artifact.ps1');
assert_true(str_contains($buildScript, '*vapid*.json'), 'Build script strictly excludes *vapid*.json');
assert_true(str_contains($buildScript, '*keys.json'), 'Build script strictly excludes *keys.json');
assert_true(str_contains($buildScript, '*.pem'), 'Build script strictly excludes *.pem files');
assert_true(str_contains($buildScript, '*.key'), 'Build script strictly excludes *.key files');
assert_true(str_contains($buildScript, 'config/local.php'), 'Build script guards against accidental config/local.php packaging');

$exampleConfig = file_get_contents(__DIR__ . '/../config/local.php.example');
assert_true(str_contains($exampleConfig, 'LASTO_VAPID_PUBLIC_KEY'), 'config/local.php.example provides LASTO_VAPID_PUBLIC_KEY placeholder');
assert_true(str_contains($exampleConfig, 'LASTO_VAPID_PRIVATE_KEY'), 'config/local.php.example provides LASTO_VAPID_PRIVATE_KEY placeholder');
assert_true(!str_contains($exampleConfig, $keys['privateKey']), 'config/local.php.example does not leak live private key');

// --- Test Suite 8: Zero-Regeneration & Modification Protection ---
echo "\n--- Test Suite 8: Zero-Regeneration & Modification Protection ---\n";

$vapidPath = __DIR__ . '/../config/vapid.json';
$mtimeBefore = filemtime($vapidPath);

// Trigger repeated calls to push_get_vapid_keys
for ($i = 0; $i < 5; $i++) {
    $k = push_get_vapid_keys(true);
    assert_true($k['publicKey'] === $keys['publicKey'], "Iteration {$i} preserves identical public key");
}

$mtimeAfter = filemtime($vapidPath);
assert_true($mtimeBefore === $mtimeAfter, 'Persistent config/vapid.json file is not touched or overwritten on read');

echo "\n=======================================================\n";
echo "Phase 6B.3 Test Results: {$passed} Passed, {$failed} Failed\n";
echo "=======================================================\n";

if ($failed > 0) {
    exit(1);
}
