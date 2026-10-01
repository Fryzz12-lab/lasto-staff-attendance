<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/config.php';
if (APP_ENV === 'production') throw new RuntimeException('Run HTTP UAT only in development.');
if (!extension_loaded('curl')) throw new RuntimeException('PHP cURL is required.');
$manifest = json_decode(file_get_contents(__DIR__ . '/../storage/uat-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$base = 'http://localhost' . BASE_PATH;
$checks = 0;
$check = static function (bool $passed, string $message) use (&$checks): void {
    if (!$passed) throw new RuntimeException($message);
    $checks++;
};
$request = static function (CurlHandle $client, string $path, ?array $data = null) use ($base, $check): array {
    curl_setopt_array($client, [CURLOPT_URL => $base . $path, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIEFILE => '',
        CURLOPT_POST => $data !== null, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    if ($data !== null) curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($data));
    $body = curl_exec($client);
    $check($body !== false, 'HTTP request failed: ' . curl_error($client));
    $check(!preg_match('/(?:Fatal error|Warning|Deprecated):/', $body), 'PHP error on ' . $path);
    return ['status' => curl_getinfo($client, CURLINFO_RESPONSE_CODE), 'redirect' => curl_getinfo($client, CURLINFO_REDIRECT_URL), 'body' => $body];
};
$client = curl_init();
foreach (['/storage/uat-manifest.json', '/config/config.php', '/database/schema.sql', '/docs/UAT_SCENARIOS.md', '/tests/http_smoke.php'] as $path) {
    $response = $request($client, $path);
    $check(in_array($response['status'], [403, 404], true), 'Private path accessible: ' . $path);
}
$response = $request($client, '/api/clock.php');
$check($response['status'] === 401, 'Anonymous clock API must return 401');
curl_close($client);
foreach (['admin', 'nurul', 'yuting', 'azlan'] as $key) {
    $client = curl_init();
    $response = $request($client, '/');
    $check($response['status'] === 200, 'Login page loads');
    $check((bool) preg_match('/name="csrf_token" value="([^"]+)"/', $response['body'], $matches), 'Login CSRF token exists');
    $response = $request($client, '/', ['username' => $manifest['accounts'][$key]['username'], 'password' => $manifest['password'], 'csrf_token' => $matches[1]]);
    if ($key === 'azlan') {
        $check($response['status'] === 200 && empty($response['redirect']), 'Inactive account refused');
        $response = $request($client, '/api/clock.php');
        $check($response['status'] === 401, 'Inactive account has no authenticated session');
    } else {
        $destination = $key === 'admin' ? '/admin/' : ($key === 'yuting' ? '/setup_account.php' : '/staff/');
        $check($response['status'] === 302 && str_ends_with($response['redirect'], $destination), 'Correct login redirect for ' . $key);
        $pages = $key === 'admin' ? ['/admin/', '/admin/staff.php', '/admin/attendance.php', '/admin/reports.php', '/admin/corrections.php', '/admin/leave_requests.php']
            : ($key === 'nurul' ? ['/staff/', '/staff/attendance.php', '/staff/profile.php', '/staff/schedule.php', '/staff/leave.php'] : ['/setup_account.php']);
        foreach ($pages as $path) {
            $response = $request($client, $path);
            $check($response['status'] === 200 && str_contains($response['body'], '</html>'), 'Complete authenticated page: ' . $path);
        }
        if ($key === 'nurul') {
            $response = $request($client, '/admin/staff.php');
            $check($response['status'] === 302 && str_ends_with($response['redirect'], '/staff/'), 'Staff cannot access admin');
            foreach (['clock', 'break'] as $endpoint) {
                $response = $request($client, '/api/' . $endpoint . '.php', ['action' => 'clock_in']);
                $check($response['status'] === 403, 'Missing CSRF rejected by ' . $endpoint);
            }
        }
        if ($key === 'yuting') {
            $response = $request($client, '/staff/');
            $check($response['status'] === 302 && str_ends_with($response['redirect'], '/setup_account.php'), 'Setup cannot be bypassed');
        }
    }
    curl_close($client);
}
echo "PASS: {$checks} HTTP checks; no attendance actions submitted with a valid CSRF token.\n";
