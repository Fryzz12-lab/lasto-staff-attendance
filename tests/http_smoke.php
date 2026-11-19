<?php
/**
 * Isolated HTTP Smoke Test Suite
 * AGY Staff Attendance & Workforce Management System
 *
 * Verifies HTTP routing, authentication redirects, session lifecycle, authorization guards,
 * and CSRF enforcement across roles over local HTTP against a disposable test database.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/test_database_safety.php';

if (!extension_loaded('curl')) {
    throw new RuntimeException('PHP cURL extension is required.');
}

$docRoot = realpath(__DIR__ . '/..');
$phpBin = PHP_BINARY ?: 'C:/xampp/php/php.exe';

// 1. Create disposable database
$database = 'staff_attendance_test_smoke_' . bin2hex(random_bytes(5));
TestDatabaseSafety::guardDropDatabase($database);

$rootPdo = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '');
$rootPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rootPdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$testPdo = new PDO("mysql:host=127.0.0.1;dbname=$database;charset=utf8mb4", 'root', '');
$testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$routerFile = sys_get_temp_dir() . '/lasto_smoke_router_' . bin2hex(random_bytes(4)) . '.php';
$proc = null;

try {
    // 2. Load schema into disposable database
    $schemaSql = file_get_contents($docRoot . '/database/cpanel_schema.sql');
    if ($schemaSql === false) {
        throw new RuntimeException('Failed to read database/cpanel_schema.sql');
    }
    $testPdo->exec($schemaSql);

    // 3. Seed isolated fixtures
    $testPdo->exec("INSERT INTO offices (id, name, radius_meters, is_active) VALUES (1, 'Main Branch', 100, 1)");
    $testPdo->exec("INSERT INTO work_schedules (id, name, start_time, end_time, is_default, is_active) VALUES (1, 'Standard 10-8', '10:00:00', '20:00:00', 1, 1)");

    $password = 'SmokePass!123';
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $accounts = [
        'admin' => ['emp' => 'EMP_SMK_01', 'user' => 'smoke_admin', 'name' => 'Smoke Admin', 'role' => 'admin', 'active' => 1, 'must_change' => 0],
        'nurul' => ['emp' => 'EMP_SMK_02', 'user' => 'smoke_staff', 'name' => 'Smoke Staff', 'role' => 'staff', 'active' => 1, 'must_change' => 0],
        'yuting' => ['emp' => 'EMP_SMK_03', 'user' => 'smoke_setup', 'name' => 'Smoke Setup', 'role' => 'staff', 'active' => 1, 'must_change' => 1],
        'azlan' => ['emp' => 'EMP_SMK_04', 'user' => 'smoke_inactive', 'name' => 'Smoke Inactive', 'role' => 'staff', 'active' => 0, 'must_change' => 0],
    ];

    $insUser = $testPdo->prepare("
        INSERT INTO users (employee_id, username, password_hash, full_name, email, office_id, role, is_active, must_change_password)
        VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)
    ");
    foreach ($accounts as $key => $acc) {
        $insUser->execute([
            $acc['emp'],
            $acc['user'],
            $passwordHash,
            $acc['name'],
            $acc['user'] . '@example.test',
            $acc['role'],
            $acc['active'],
            $acc['must_change']
        ]);
        $userId = (int)$testPdo->lastInsertId();
        if ($acc['active']) {
            $testPdo->exec("INSERT INTO schedule_assignments (user_id, schedule_id, effective_from) VALUES ($userId, 1, '2026-01-01')");
        }
    }

    // 4. Create ephemeral router pointing exclusively to disposable database
    $routerCode = <<<CODE
<?php
putenv('LASTO_DB_NAME=$database');
putenv('LASTO_BASE_PATH=');
\$_SERVER['LASTO_DB_NAME'] = '$database';
\$_SERVER['LASTO_BASE_PATH'] = '';

\$uri = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Protect private paths (mirroring Apache .htaccess rules)
if (preg_match('#^/(?:config|database|docs|tests|storage|cli|\\.ht|\\.git)#i', \$uri)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}
return false;
CODE;
    file_put_contents($routerFile, $routerCode);

    // 5. Spawn local ephemeral PHP web server on loopback
    $port = random_int(19000, 19999);
    $cmd = "$phpBin -S 127.0.0.1:$port -t " . escapeshellarg($docRoot) . " " . escapeshellarg($routerFile);
    $descriptors = [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException("Failed to launch local test web server on port $port.");
    }
    usleep(500000); // 500ms startup grace

    $checks = 0;
    $check = static function (bool $passed, string $message) use (&$checks): void {
        if (!$passed) throw new RuntimeException($message);
        $checks++;
    };

    $base = "http://127.0.0.1:$port";
    $request = static function (CurlHandle $client, string $path, ?array $data = null) use ($base, $check): array {
        curl_setopt_array($client, [
            CURLOPT_URL => $base . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_COOKIEFILE => '',
            CURLOPT_POST => $data !== null,
            CURLOPT_HTTPHEADER => ['Accept: application/json']
        ]);
        if ($data !== null) {
            curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $body = curl_exec($client);
        $check($body !== false, 'HTTP request failed: ' . curl_error($client));
        $check(!preg_match('/(?:Fatal error|Warning|Deprecated):/', (string)$body), 'PHP error on ' . $path . ': ' . substr((string)$body, 0, 300));
        return [
            'status' => (int)curl_getinfo($client, CURLINFO_RESPONSE_CODE),
            'redirect' => (string)curl_getinfo($client, CURLINFO_REDIRECT_URL),
            'body' => (string)$body
        ];
    };

    // 6. Execute smoke scenarios
    // Scenario A: Private path protection
    $client = curl_init();
    foreach (['/storage/', '/config/config.php', '/database/schema.sql', '/docs/', '/tests/http_smoke.php'] as $path) {
        $response = $request($client, $path);
        $check(in_array($response['status'], [403, 404], true), 'Private path accessible: ' . $path);
    }
    $response = $request($client, '/api/clock.php');
    $check($response['status'] === 401, 'Anonymous clock API must return 401');
    curl_close($client);

    // Scenario B: Role-based authentication, authorization, and route verification
    foreach (['admin', 'nurul', 'yuting', 'azlan'] as $key) {
        $client = curl_init();
        $response = $request($client, '/');
        $check($response['status'] === 200, 'Login page loads');
        $check((bool)preg_match('/name="csrf_token" value="([^"]+)"/', $response['body'], $matches), 'Login CSRF token exists');

        $response = $request($client, '/', [
            'username' => $accounts[$key]['user'],
            'password' => $password,
            'csrf_token' => $matches[1]
        ]);

        if ($key === 'azlan') {
            $check($response['status'] === 200 && empty($response['redirect']), 'Inactive account refused');
            $response = $request($client, '/api/clock.php');
            $check($response['status'] === 401, 'Inactive account has no authenticated session');
        } else {
            $destination = $key === 'admin' ? '/admin/' : ($key === 'yuting' ? '/setup_account.php' : '/staff/');
            $check($response['status'] === 302 && str_ends_with($response['redirect'], $destination), 'Correct login redirect for ' . $key);

            $pages = $key === 'admin'
                ? ['/admin/', '/admin/staff.php', '/admin/attendance.php', '/admin/reports.php', '/admin/corrections.php', '/admin/leave_requests.php']
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

    echo "PASS: {$checks} HTTP checks; isolated disposable DB test passed cleanly.\n";
} finally {
    if (is_resource($proc)) {
        proc_terminate($proc);
    }
    @unlink($routerFile);
    try {
        $rootPdo->exec("DROP DATABASE IF EXISTS `$database`");
    } catch (Throwable $e) {}
}
