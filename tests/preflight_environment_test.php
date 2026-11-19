<?php
declare(strict_types=1);

/**
 * Isolated unit and functional test suite for cli/preflight.php
 * Validates environment handling, origin validation, fail-closed behavior,
 * credential requirements, and security barriers.
 */

$preflightFile = __DIR__ . '/../cli/preflight.php';

function runPreflightWithEnv(array $envVars): array {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    // Build environment array inheriting system env plus overrides
    $defaultDb = [
        'LASTO_DB_HOST' => '127.0.0.1',
        'LASTO_DB_PORT' => '3306',
        'LASTO_DB_USER' => 'root',
        'LASTO_DB_PASS' => '',
        'LASTO_DB_NAME' => 'staff_attendance',
    ];
    $env = array_merge(getenv(), $defaultDb, $envVars);

    $process = proc_open('php ' . escapeshellarg(__DIR__ . '/../cli/preflight.php'), $descriptors, $pipes, null, $env);
    if (!is_resource($process)) {
        throw new RuntimeException("Failed to spawn preflight process");
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'exitCode' => $exitCode,
        'stdout' => (string)$stdout,
        'stderr' => (string)$stderr,
    ];
}

echo "Starting Preflight Environment & Security Verification Test...\n\n";

$passCount = 0;
$failCount = 0;

$assertCheck = static function (bool $condition, string $description) use (&$passCount, &$failCount): void {
    if ($condition) {
        echo " [PASS] {$description}\n";
        $passCount++;
    } else {
        echo " [FAIL] {$description}\n";
        $failCount++;
    }
};

// -----------------------------------------------------------------------------
// Test 1: Unknown environment must fail closed
// -----------------------------------------------------------------------------
echo "1. Testing Unknown Environment Rejection...\n";
$res = runPreflightWithEnv([
    'LASTO_APP_ENV' => 'sandbox_test_unknown',
]);
$assertCheck($res['exitCode'] === 1, 'Unknown environment exits with code 1');
$assertCheck(str_contains($res['stdout'], "Unknown LASTO_APP_ENV='sandbox_test_unknown'"), 'Unknown environment explicitly reported in output');

// -----------------------------------------------------------------------------
// Test 2: Local development cannot pass deployment readiness
// -----------------------------------------------------------------------------
echo "\n2. Testing Local Development Rejection...\n";
$res = runPreflightWithEnv([
    'LASTO_APP_ENV' => 'development',
]);
$assertCheck($res['exitCode'] === 1, 'Development environment exits with code 1');
$assertCheck(str_contains($res['stdout'], "LASTO_APP_ENV is 'development' - deployment preflight requires 'staging' or 'production'"), 'Development preflight rejection message displayed');

// -----------------------------------------------------------------------------
// Test 3: Staging Environment Origin Validation
// -----------------------------------------------------------------------------
echo "\n3. Testing Staging Environment Origin Validation...\n";
// 3a. Staging with correct HTTPS staging origin
$res = runPreflightWithEnv([
    'LASTO_APP_ENV' => 'staging',
    'LASTO_PUBLIC_ORIGIN' => 'https://staging-attendance.lastotechnology.my',
]);
$assertCheck(str_contains($res['stdout'], "TARGET ENVIRONMENT: STAGING"), 'Target environment identified as STAGING');
$assertCheck(str_contains($res['stdout'], "PASS: Staging origin host must match configured staging host 'staging-attendance.lastotechnology.my'"), 'Staging origin matches expected host');

// 3b. Staging with production origin (Misconfiguration / cross-talk)
$res = runPreflightWithEnv([
    'LASTO_APP_ENV' => 'staging',
    'LASTO_PUBLIC_ORIGIN' => 'https://attendance.lastotechnology.my',
]);
$assertCheck(str_contains($res['stdout'], "FAIL: Staging origin host must match configured staging host"), 'Staging rejects production origin');

// 3c. Staging with HTTP origin (Insecure)
$res = runPreflightWithEnv([
    'LASTO_APP_ENV' => 'staging',
    'LASTO_PUBLIC_ORIGIN' => 'http://staging-attendance.lastotechnology.my',
]);
$assertCheck(str_contains($res['stdout'], "FAIL: Public HTTPS origin configured"), 'Staging rejects unencrypted HTTP origin');

// -----------------------------------------------------------------------------
// Test 4: Production Environment Origin Validation
// -----------------------------------------------------------------------------
echo "\n4. Testing Production Environment Origin Validation...\n";
// 4a. Production with correct HTTPS production origin
$res = runPreflightWithEnv([
    'LASTO_APP_ENV' => 'production',
    'LASTO_PUBLIC_ORIGIN' => 'https://attendance.lastotechnology.my',
]);
$assertCheck(str_contains($res['stdout'], "TARGET ENVIRONMENT: PRODUCTION"), 'Target environment identified as PRODUCTION');
$assertCheck(str_contains($res['stdout'], "PASS: Production origin host must be 'attendance.lastotechnology.my'"), 'Production origin matches production host');

// 4b. Production with staging origin (Accidental staging leak)
$res = runPreflightWithEnv([
    'LASTO_APP_ENV' => 'production',
    'LASTO_PUBLIC_ORIGIN' => 'https://staging-attendance.lastotechnology.my',
]);
$assertCheck(str_contains($res['stdout'], "FAIL: Production origin host must be 'attendance.lastotechnology.my'"), 'Production rejects staging origin');

// 4c. Production with HTTP origin
$res = runPreflightWithEnv([
    'LASTO_APP_ENV' => 'production',
    'LASTO_PUBLIC_ORIGIN' => 'http://attendance.lastotechnology.my',
]);
$assertCheck(str_contains($res['stdout'], "FAIL: Public HTTPS origin configured"), 'Production rejects unencrypted HTTP origin');

// -----------------------------------------------------------------------------
// Test 5: Credential and Demo Password Detection
// -----------------------------------------------------------------------------
echo "\n5. Testing Security Barriers (Credentials & Demo Passwords)...\n";
$res = runPreflightWithEnv([
    'LASTO_APP_ENV' => 'production',
    'LASTO_DB_USER' => 'root',
    'LASTO_DB_PASS' => '',
]);
$assertCheck(str_contains($res['stdout'], "FAIL: Dedicated database account with a non-empty password"), 'Root database user rejected');

echo "\n================================================================================\n";
echo "PREFLIGHT TEST RESULTS: {$passCount} Passed, {$failCount} Failed\n";
echo "================================================================================\n";

if ($failCount > 0) {
    exit(1);
}
