<?php
/**
 * Automated test suite for Phase 6B.5: Release Hardening & Remaining Security Findings
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/push_service.php';

$totalChecks = 0;
$passedChecks = 0;

function assertHardening(bool $condition, string $description): void
{
    global $totalChecks, $passedChecks;
    $totalChecks++;
    if ($condition) {
        $passedChecks++;
        echo "  [PASS] {$description}\n";
    } else {
        echo "  [FAIL] {$description}\n";
    }
}

echo "=== PHASE 6B.5: RELEASE HARDENING & REMAINING SECURITY AUDIT ===\n\n";

// -------------------------------------------------------------
// Section 1: push_safe_destination & Route Whitelisting (M2)
// -------------------------------------------------------------
echo "1. Push Notification Navigation & Route Whitelist Verification:\n";

// Safe routes
$dest = push_safe_destination('');
assertHardening($dest === BASE_PATH . '/notifications.php', 'Empty destination defaults safely to /notifications.php');

$dest = push_safe_destination('/staff/leaves.php');
assertHardening($dest === BASE_PATH . '/staff/leaves.php', 'Valid staff path /staff/leaves.php is allowed and prefixed with BASE_PATH');

$dest = push_safe_destination('staff/leaves.php');
assertHardening($dest === BASE_PATH . '/staff/leaves.php', 'Relative staff path staff/leaves.php without leading slash is normalized');

$dest = push_safe_destination('/admin/attendance.php');
assertHardening($dest === BASE_PATH . '/admin/attendance.php', 'Valid admin path /admin/attendance.php is allowed');

$dest = push_safe_destination('/notifications.php?highlight=42');
assertHardening($dest === BASE_PATH . '/notifications.php?highlight=42', 'Query parameters on allowed routes are preserved');

$dest = push_safe_destination(BASE_PATH . '/notifications.php');
assertHardening($dest === BASE_PATH . '/notifications.php', 'Paths already prefixed with BASE_PATH do not duplicate prefix');

// Dangerous / blocked routes
assertHardening(push_safe_destination('/logout.php') === null, 'Dangerous route /logout.php is strictly blocked');
assertHardening(push_safe_destination('logout.php') === null, 'Dangerous route logout.php without leading slash is strictly blocked');
assertHardening(push_safe_destination(BASE_PATH . '/logout.php') === null, 'Dangerous route with BASE_PATH prefix /logout.php is strictly blocked');
assertHardening(push_safe_destination('/api/push_subscription.php') === null, 'Internal /api/ endpoints are strictly blocked from push navigation');
assertHardening(push_safe_destination('/cli/create_admin.php') === null, 'Internal /cli/ scripts are strictly blocked from push navigation');
assertHardening(push_safe_destination('/config/database.php') === null, 'Internal /config/ files are strictly blocked from push navigation');
assertHardening(push_safe_destination('/storage/sessions/sess_123') === null, 'Internal /storage/ files are strictly blocked from push navigation');

// Malicious schemes & URLs
assertHardening(push_safe_destination('javascript:alert(1)') === null, 'JavaScript pseudo-protocol is strictly rejected');
assertHardening(push_safe_destination('data:text/html,<b>evil</b>') === null, 'Data URLs are strictly rejected');
assertHardening(push_safe_destination('//evil.test/phish') === null, 'Protocol-relative URLs are strictly rejected');
assertHardening(push_safe_destination('https://evil.test/notifications.php') === null, 'Cross-origin URLs are strictly rejected');
assertHardening(push_safe_destination('/staff/../../etc/passwd') === null, 'Path traversal sequences (..) are strictly rejected');
assertHardening(push_safe_destination('/staff\\profile.php') === null, 'Backslashes in paths are strictly rejected');

// -------------------------------------------------------------
// Section 2: Unified Environment Resolution (M3)
// -------------------------------------------------------------
echo "\n2. Unified Environment Resolution (lasto_env):\n";

assertHardening(function_exists('lasto_env'), 'lasto_env helper function exists in config/config.php');
assertHardening(lasto_env('NON_EXISTENT_VAR_' . mt_rand(), 'default_val') === 'default_val', 'lasto_env returns fallback default when unset');

putenv('LASTO_TEST_M3_VAR=alpha');
assertHardening(lasto_env('LASTO_TEST_M3_VAR') === 'alpha', 'lasto_env resolves values set via putenv/getenv');
putenv('LASTO_TEST_M3_VAR'); // unset

$_ENV['LASTO_TEST_M3_ENV'] = 'beta';
assertHardening(lasto_env('LASTO_TEST_M3_ENV') === 'beta', 'lasto_env resolves values present in $_ENV');
unset($_ENV['LASTO_TEST_M3_ENV']);

$_SERVER['LASTO_TEST_M3_SERVER'] = 'gamma';
assertHardening(lasto_env('LASTO_TEST_M3_SERVER') === 'gamma', 'lasto_env resolves values present in $_SERVER');
unset($_SERVER['LASTO_TEST_M3_SERVER']);

// -------------------------------------------------------------
// Section 3: CLI Administrator Creation Security (Item 7)
// -------------------------------------------------------------
echo "\n3. CLI Admin Creation Security:\n";

// Invocation with --password argument must be rejected
$cmdPasswordArg = 'php ' . escapeshellarg(__DIR__ . '/../cli/create_admin.php') . ' --username=badadmin --password=plaintext123 2>&1';
$output = shell_exec($cmdPasswordArg) ?? '';
assertHardening(
    str_contains($output, 'Security Error: Do not pass passwords via command-line arguments'),
    'CLI create_admin.php strictly rejects --password argument'
);

// Invocation without required args
$cmdNoArgs = 'php ' . escapeshellarg(__DIR__ . '/../cli/create_admin.php') . ' 2>&1';
$outputNoArgs = shell_exec($cmdNoArgs) ?? '';
assertHardening(
    str_contains($outputNoArgs, 'Usage: php cli/create_admin.php'),
    'CLI create_admin.php shows proper usage message when arguments are missing'
);

// -------------------------------------------------------------
// Section 4: Service Worker Version & Clean Code (M4)
// -------------------------------------------------------------
echo "\n4. Service Worker Cache Version & Security:\n";

$swContent = file_get_contents(__DIR__ . '/../sw.js') ?: '';
assertHardening(str_contains($swContent, "CACHE_NAME = CACHE_PREFIX + 'v24'"), 'Dev sw.js uses aligned cache version v24');
assertHardening(str_contains($swContent, 'isBlocked'), 'Dev sw.js contains route blacklist filter');
assertHardening(str_contains($swContent, 'alreadyOnDestination'), 'sw.js checks alreadyOnDestination');

// -------------------------------------------------------------
// Section 5: Deployment Build Script Safety
// -------------------------------------------------------------
echo "\n5. Deployment Artifact Exclusion Verification:\n";

$buildScript = file_get_contents(__DIR__ . '/../cli/build_cpanel_artifact.ps1') ?: '';
assertHardening(str_contains($buildScript, '*vapid*.json'), 'Build script prohibits packaging VAPID JSON keyfiles');
assertHardening(str_contains($buildScript, 'uat-manifest*.json'), 'Build script prohibits packaging UAT manifests');
assertHardening(str_contains($buildScript, 'config/local.php must not be packaged'), 'Build script strictly guards local.php from artifact inclusion');

// -------------------------------------------------------------
// Summary
// -------------------------------------------------------------
echo "\nPhase 6B.5 Hardening Suite: {$passedChecks}/{$totalChecks} passed.\n";

if ($passedChecks !== $totalChecks) {
    exit(1);
}
