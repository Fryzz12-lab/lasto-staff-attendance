<?php
/** Run: C:\xampp\php\php.exe tests/attendance_api_idempotency_test.php */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/test_database_safety.php';
if (!extension_loaded('curl')) {
    throw new RuntimeException('PHP cURL extension is required for the API integration tests.');
}

function api_idem_assert(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function api_idem_user(PDO $pdo, string $label): int
{
    $suffix = bin2hex(random_bytes(3));
    $username = 'api_idem_' . $label . '_' . $suffix;
    $pdo->prepare('INSERT INTO users (employee_id,username,password_hash,full_name,role,is_active) VALUES (?,?,?,?,\'staff\',1)')
        ->execute(['E' . strtoupper($suffix), $username, password_hash('TestPass!234', PASSWORD_DEFAULT), 'API Idempotency ' . $label]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO schedule_assignments (user_id,schedule_id,effective_from) VALUES (?,1,\'2020-01-01\')')->execute([$id]);
    return $id;
}

function api_idem_cookie_path(string $label): string
{
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'attendance-api-idem-' . $label . '-' . bin2hex(random_bytes(6)) . '.cookies';
}

function api_idem_login(int $port, string $username, string $jar): string
{
    $base = 'http://127.0.0.1:' . $port;
    $client = curl_init();
    curl_setopt_array($client, [
        CURLOPT_URL => $base . '/', CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: text/html'],
    ]);
    $html = curl_exec($client);
    if ($html === false || !preg_match('/name="csrf_token" value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('Could not get a login CSRF token: ' . curl_error($client));
    }
    $token = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    curl_setopt_array($client, [
        CURLOPT_URL => $base . '/', CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['username' => $username, 'password' => 'TestPass!234', 'csrf_token' => $token]),
        CURLOPT_HTTPHEADER => ['Accept: text/html', 'Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = curl_exec($client);
    $status = (int) curl_getinfo($client, CURLINFO_RESPONSE_CODE);
    $error = curl_error($client);
    curl_close($client);
    if ($response === false || $status !== 302) {
        throw new RuntimeException('Disposable API test login failed with HTTP ' . $status . ': ' . $error . ' ' . (string) $response);
    }
    return $token;
}

function api_idem_request(int $port, string $jar, string $path, array $data, ?string $key, ?string $csrf = null): array
{
    $headers = ['Accept: application/json', 'Content-Type: application/json', 'X-Requested-With: XMLHttpRequest'];
    if ($key !== null) {
        $headers[] = 'Idempotency-Key: ' . $key;
    }
    $data['csrf_token'] = $csrf ?? (string) ($data['csrf_token'] ?? '');
    $client = curl_init();
    curl_setopt_array($client, [
        CURLOPT_URL => 'http://127.0.0.1:' . $port . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($data, JSON_THROW_ON_ERROR),
    ]);
    $body = curl_exec($client);
    $error = curl_error($client);
    $status = (int) curl_getinfo($client, CURLINFO_RESPONSE_CODE);
    curl_close($client);
    if ($body === false) {
        throw new RuntimeException('API HTTP request failed: ' . $error);
    }
    $json = json_decode($body, true);
    return ['status' => $status, 'body' => $body, 'json' => is_array($json) ? $json : null];
}

function api_idem_get(int $port, string $jar, string $path): array
{
    $client = curl_init();
    curl_setopt_array($client, [
        CURLOPT_URL => 'http://127.0.0.1:' . $port . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($client);
    $error = curl_error($client);
    $status = (int) curl_getinfo($client, CURLINFO_RESPONSE_CODE);
    curl_close($client);
    if ($body === false) {
        throw new RuntimeException('API GET request failed: ' . $error);
    }
    $json = json_decode($body, true);
    return ['status' => $status, 'body' => $body, 'json' => is_array($json) ? $json : null];
}

function api_idem_start_server(string $php, string $root, string $router, int $port): mixed
{
    $pipes = [];
    $process = proc_open([$php, '-S', '127.0.0.1:' . $port, '-t', $root, $router], [
        0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start isolated PHP API server.');
    }
    usleep(400000);
    return $process;
}

function api_idem_multi_request(array $requests): array
{
    $multi = curl_multi_init();
    $handles = [];
    foreach ($requests as $request) {
        $headers = ['Accept: application/json', 'Content-Type: application/json', 'X-Requested-With: XMLHttpRequest', 'Idempotency-Key: ' . $request['key']];
        $data = $request['data'];
        $data['csrf_token'] = $request['csrf'];
        $client = curl_init();
        curl_setopt_array($client, [
            CURLOPT_URL => 'http://127.0.0.1:' . $request['port'] . $request['path'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_COOKIEFILE => $request['jar'],
            CURLOPT_COOKIEJAR => $request['jar'],
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($data, JSON_THROW_ON_ERROR),
        ]);
        curl_multi_add_handle($multi, $client);
        $handles[] = $client;
    }
    do {
        $code = curl_multi_exec($multi, $running);
        if ($running) curl_multi_select($multi, 1.0);
    } while ($running && $code === CURLM_OK);
    $results = [];
    foreach ($handles as $client) {
        $body = curl_multi_getcontent($client);
        $status = (int) curl_getinfo($client, CURLINFO_RESPONSE_CODE);
        $results[] = ['status' => $status, 'json' => is_string($body) ? json_decode($body, true) : null, 'body' => $body];
        curl_multi_remove_handle($multi, $client);
        curl_close($client);
    }
    curl_multi_close($multi);
    return $results;
}

$checks = 0;
$database = 'staff_attendance_test_api_idem_' . bin2hex(random_bytes(5));
TestDatabaseSafety::guardDropDatabase($database);
$rootPdo = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '');
$rootPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rootPdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = new PDO("mysql:host=127.0.0.1;dbname={$database};charset=utf8mb4", 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$root = realpath(__DIR__ . '/..');
$php = PHP_BINARY ?: 'C:/xampp/php/php.exe';
$router = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'attendance_api_idem_router_' . bin2hex(random_bytes(5)) . '.php';
$sessionDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'attendance-api-idem-sessions-' . bin2hex(random_bytes(5));
if (!mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
    throw new RuntimeException('Could not create isolated API test session directory.');
}
$servers = [];
$cookieJars = [];

try {
    $pdo->exec(file_get_contents($root . '/database/cpanel_schema.sql'));
    $pdo->exec("INSERT INTO offices (id,name,radius_meters,is_active) VALUES (1,'API Idempotency Office',100,1)");
    $pdo->exec("INSERT INTO work_schedules (id,name,start_time,end_time,is_default,is_active) VALUES (1,'LASTO Standard Schedule','10:00:00','20:00:00',1,1)");
    $pdo->exec(file_get_contents($root . '/database/migration_phase3b_idempotency.sql'));

    $users = [];
    foreach (['main', 'unkeyed', 'cross', 'active_break', 'audit_failure', 'concurrent_same', 'concurrent_new', 'expired'] as $label) {
        $users[$label] = api_idem_user($pdo, $label);
    }
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur')))->format('Y-m-d');
    $yesterday = (new DateTimeImmutable($today, new DateTimeZone('Asia/Kuala_Lumpur')))->modify('-1 day')->format('Y-m-d');
    $pdo->prepare("INSERT INTO attendance (user_id,work_date,schedule_id,scheduled_start,scheduled_end,clock_in,clock_out,status,shift_minutes,work_minutes,version) VALUES (?, ?, 1,'10:00:00','20:00:00',? ,?,'completed',600,600,1)")
        ->execute([$users['main'], $yesterday, $yesterday . ' 10:00:00', $yesterday . ' 20:00:00']);
    $historicalBefore = $pdo->query('SELECT id,work_date,clock_in,clock_out,status,shift_minutes,work_minutes,version FROM attendance WHERE user_id=' . (int) $users['main'] . ' AND work_date=' . $pdo->quote($yesterday))->fetch();

    $basePath = "putenv('LASTO_DB_NAME={$database}'); putenv('LASTO_BASE_PATH=/'); \$_SERVER['LASTO_DB_NAME']='{$database}'; \$_SERVER['LASTO_BASE_PATH']='/';";
    $routerCode = "<?php\n{$basePath}\nsession_save_path(" . var_export($sessionDir, true) . ");\n\$uri=parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);\nif (\$uri === '/__test_expire') { require '{$root}/includes/bootstrap.php'; \$_SESSION['last_activity']=time()-9*60*60; session_write_close(); header('Content-Type: application/json'); echo '{\"ok\":true}'; exit; }\nreturn false;\n";
    file_put_contents($router, $routerCode);

    $portA = random_int(20000, 24999);
    $portB = random_int(25000, 29999);
    $servers[] = api_idem_start_server($php, $root, $router, $portA);
    $servers[] = api_idem_start_server($php, $root, $router, $portB);

    $sessions = [];
    $tokens = [];
    foreach ($users as $label => $userId) {
        $username = $pdo->query('SELECT username FROM users WHERE id=' . (int) $userId)->fetchColumn();
        $cookieJars[$label] = api_idem_cookie_path($label);
        $sessions[$label] = $cookieJars[$label];
        $tokens[$label] = api_idem_login($portA, (string) $username, $sessions[$label]);
    }
    $emptyBefore = (int) $pdo->query('SELECT COUNT(*) FROM attendance WHERE user_id=' . (int) $users['unkeyed'])->fetchColumn();
    $emptyState = api_idem_get($portA, $sessions['unkeyed'], '/api/attendance_operation.php');
    $emptyAfter = (int) $pdo->query('SELECT COUNT(*) FROM attendance WHERE user_id=' . (int) $users['unkeyed'])->fetchColumn();
    api_idem_assert($emptyState['status'] === 200 && ($emptyState['json']['state']['derived']['classification'] ?? '') === 'no_attendance' && $emptyBefore === 0 && $emptyAfter === 0, 'Read-only state lookup does not create an attendance row');
    $clockInKey = 'api-clock-in-' . bin2hex(random_bytes(10));
    $clockIn = api_idem_request($portA, $sessions['main'], '/api/clock.php', ['action' => 'clock_in'], $clockInKey, $tokens['main']);
    api_idem_assert($clockIn['status'] === 200 && ($clockIn['json']['outcome'] ?? '') === 'confirmed_success', 'Keyed clock-in succeeds: HTTP ' . $clockIn['status'] . ' ' . $clockIn['body']);
    api_idem_assert(!empty($clockIn['json']['attendance_id']) && !empty($clockIn['json']['operation_id']), 'Clock-in response identifies attendance and operation');
    $clockInReplay = api_idem_request($portA, $sessions['main'], '/api/clock.php', ['action' => 'clock_in'], $clockInKey, $tokens['main']);
    api_idem_assert($clockInReplay['status'] === 200 && !empty($clockInReplay['json']['replayed']) && $clockInReplay['json']['operation_id'] === $clockIn['json']['operation_id'], 'Keyed clock-in replay returns the original operation');
    $capabilities = api_idem_get($portA, $sessions['main'], '/api/attendance_operation.php');
    api_idem_assert($capabilities['status'] === 200 && ($capabilities['json']['outcome'] ?? '') === 'ready' && !empty($capabilities['json']['idempotency_supported']), 'Authenticated capability probe enables keyed actions when the migration is present');
    $reconciledClockIn = api_idem_get($portA, $sessions['main'], '/api/attendance_operation.php?request_id=' . rawurlencode($clockInKey));
    api_idem_assert($reconciledClockIn['status'] === 200 && ($reconciledClockIn['json']['outcome'] ?? '') === 'confirmed_success' && ($reconciledClockIn['json']['result']['operation_id'] ?? null) === $clockIn['json']['operation_id'], 'Owner reconciliation returns the persisted terminal operation result');
    $crossOwnerReconciliation = api_idem_get($portA, $sessions['cross'], '/api/attendance_operation.php?request_id=' . rawurlencode($clockInKey) . '&user_id=' . (int) $users['main']);
    api_idem_assert($crossOwnerReconciliation['status'] === 200 && ($crossOwnerReconciliation['json']['outcome'] ?? '') === 'unknown' && !isset($crossOwnerReconciliation['json']['result']), 'Reconciliation does not disclose another employee operation');
    $anonymousReconciliation = api_idem_get($portA, api_idem_cookie_path('anonymous_reconcile'), '/api/attendance_operation.php');
    api_idem_assert($anonymousReconciliation['status'] === 401, 'Unauthenticated reconciliation is rejected');
    api_idem_assert((int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id=" . (int) $clockIn['json']['attendance_id'] . " AND action='clock_in'")->fetchColumn() === 1, 'Clock-in replay creates no second audit event');
    $sameKeyDifferentAction = api_idem_request($portA, $sessions['main'], '/api/clock.php', ['action' => 'clock_out'], $clockInKey, $tokens['main']);
    api_idem_assert($sameKeyDifferentAction['status'] === 409 && ($sameKeyDifferentAction['json']['outcome'] ?? '') === 'key_conflict', 'Same key with a different clock action conflicts');

    $startKey = 'api-break-start-' . bin2hex(random_bytes(8));
    $breakStart = api_idem_request($portA, $sessions['main'], '/api/break.php', ['action' => 'start', 'type' => 'lunch'], $startKey, $tokens['main']);
    api_idem_assert($breakStart['status'] === 200 && ($breakStart['json']['outcome'] ?? '') === 'confirmed_success', 'Keyed break-start succeeds');
    $breakStartReplay = api_idem_request($portA, $sessions['main'], '/api/break.php', ['action' => 'start', 'type' => 'lunch'], $startKey, $tokens['main']);
    api_idem_assert(!empty($breakStartReplay['json']['replayed']) && $breakStartReplay['json']['break_id'] === $breakStart['json']['break_id'], 'Break-start replay returns the original break');
    api_idem_assert((int) $pdo->query('SELECT COUNT(*) FROM break_records WHERE attendance_id=' . (int) $breakStart['json']['attendance_id'] . " AND break_type='lunch'")->fetchColumn() === 1, 'Break-start replay creates no duplicate break');
    $sameStartKeyDifferentType = api_idem_request($portA, $sessions['main'], '/api/break.php', ['action' => 'start', 'type' => 'short'], $startKey, $tokens['main']);
    api_idem_assert($sameStartKeyDifferentType['status'] === 409, 'Same break key with a different type conflicts');
    $sameStartKeyDifferentOperation = api_idem_request($portA, $sessions['main'], '/api/break.php', ['action' => 'end'], $startKey, $tokens['main']);
    api_idem_assert($sameStartKeyDifferentOperation['status'] === 409, 'Same key with a different break operation conflicts');

    $endKey = 'api-break-end-' . bin2hex(random_bytes(8));
    $breakEnd = api_idem_request($portA, $sessions['main'], '/api/break.php', ['action' => 'end'], $endKey, $tokens['main']);
    api_idem_assert($breakEnd['status'] === 200 && ($breakEnd['json']['outcome'] ?? '') === 'confirmed_success' && !empty($breakEnd['json']['break_id']), 'Keyed break-end succeeds with its break reference');
    $breakEndAuditCount = (int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id=" . (int) $breakEnd['json']['attendance_id'] . " AND action='break_end'")->fetchColumn();
    $breakEndReplay = api_idem_request($portA, $sessions['main'], '/api/break.php', ['action' => 'end'], $endKey, $tokens['main']);
    api_idem_assert(!empty($breakEndReplay['json']['replayed']), 'Break-end retry replays the terminal result');
    api_idem_assert((int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id=" . (int) $breakEnd['json']['attendance_id'] . " AND action='break_end'")->fetchColumn() === $breakEndAuditCount, 'Break-end replay creates no second audit event');

    $outKey = 'api-clock-out-' . bin2hex(random_bytes(8));
    $clockOut = api_idem_request($portA, $sessions['main'], '/api/clock.php', ['action' => 'clock_out'], $outKey, $tokens['main']);
    api_idem_assert($clockOut['status'] === 200 && ($clockOut['json']['outcome'] ?? '') === 'confirmed_success', 'Keyed clock-out succeeds');
    $clockOutAuditCount = (int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id=" . (int) $clockOut['json']['attendance_id'] . " AND action='clock_out'")->fetchColumn();
    $clockOutReplay = api_idem_request($portA, $sessions['main'], '/api/clock.php', ['action' => 'clock_out'], $outKey, $tokens['main']);
    api_idem_assert(!empty($clockOutReplay['json']['replayed']), 'Clock-out retry replays the terminal result');
    api_idem_assert((int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id=" . (int) $clockOut['json']['attendance_id'] . " AND action='clock_out'")->fetchColumn() === $clockOutAuditCount, 'Clock-out replay creates no duplicate audit event');
    $duplicateClockInKey = 'new-clock-in-' . bin2hex(random_bytes(8));
    $newKeyDuplicateAction = api_idem_request($portA, $sessions['main'], '/api/clock.php', ['action' => 'clock_in'], $duplicateClockInKey, $tokens['main']);
    api_idem_assert($newKeyDuplicateAction['status'] === 200 && empty($newKeyDuplicateAction['json']['success']) && ($newKeyDuplicateAction['json']['outcome'] ?? '') === 'confirmed_rejection', 'New key does not bypass duplicate clock-in rules');
    $duplicateClockInReplay = api_idem_request($portA, $sessions['main'], '/api/clock.php', ['action' => 'clock_in'], $duplicateClockInKey, $tokens['main']);
    api_idem_assert(!empty($duplicateClockInReplay['json']['replayed']) && empty($duplicateClockInReplay['json']['success']), 'Confirmed business rejection replays without re-running mutation');

    $noKeyClockIn = api_idem_request($portA, $sessions['unkeyed'], '/api/clock.php', ['action' => 'clock_in'], null, $tokens['unkeyed']);
    $noKeyDuplicate = api_idem_request($portA, $sessions['unkeyed'], '/api/clock.php', ['action' => 'clock_in'], null, $tokens['unkeyed']);
    api_idem_assert($noKeyClockIn['status'] === 200 && !array_key_exists('operation_id', $noKeyClockIn['json']), 'Unkeyed clock-in keeps the existing response path');
    api_idem_assert($noKeyDuplicate['status'] === 200 && empty($noKeyDuplicate['json']['success']), 'Unkeyed duplicate action keeps existing rejection behavior');

    $malformed = api_idem_request($portA, $sessions['cross'], '/api/clock.php', ['action' => 'clock_in'], 'bad key', $tokens['cross']);
    api_idem_assert($malformed['status'] === 400, 'Malformed supplied key is rejected');
    $badCsrfKey = 'api-bad-csrf-' . bin2hex(random_bytes(8));
    $badCsrf = api_idem_request($portA, $sessions['cross'], '/api/clock.php', ['action' => 'clock_in'], $badCsrfKey, 'invalid-token');
    api_idem_assert($badCsrf['status'] === 403, 'Invalid CSRF is rejected before idempotency reservation');
    $csrfOperation = $pdo->prepare('SELECT COUNT(*) FROM attendance_idempotency_operations WHERE user_id=? AND request_id=?');
    $csrfOperation->execute([$users['cross'], $badCsrfKey]);
    api_idem_assert((int) $csrfOperation->fetchColumn() === 0, 'Invalid CSRF creates no operation record');

    $anonymous = api_idem_request($portA, api_idem_cookie_path('anonymous'), '/api/clock.php', ['action' => 'clock_in'], 'anonymous-key-' . bin2hex(random_bytes(6)), 'no-token');
    api_idem_assert($anonymous['status'] === 401, 'Unauthenticated keyed request is rejected');

    $crossOwnerA = api_idem_request($portA, $sessions['main'], '/api/clock.php', ['action' => 'clock_in'], $clockInKey, $tokens['main']);
    $crossOwnerB = api_idem_request($portA, $sessions['cross'], '/api/clock.php', ['action' => 'clock_in'], $clockInKey, $tokens['cross']);
    api_idem_assert(!empty($crossOwnerA['json']['success']) && !empty($crossOwnerB['json']['success']), 'Same request identifier is scoped independently per user');
    api_idem_assert($crossOwnerA['json']['operation_id'] !== $crossOwnerB['json']['operation_id'] && $crossOwnerA['json']['attendance_id'] !== $crossOwnerB['json']['attendance_id'], 'Cross-user request does not replay another employee result');

    $activeInKey = 'active-clock-in-' . bin2hex(random_bytes(8));
    $activeIn = api_idem_request($portA, $sessions['active_break'], '/api/clock.php', ['action' => 'clock_in'], $activeInKey, $tokens['active_break']);
    $activeBreakKey = 'active-break-start-' . bin2hex(random_bytes(8));
    $activeBreak = api_idem_request($portA, $sessions['active_break'], '/api/break.php', ['action' => 'start', 'type' => 'solat'], $activeBreakKey, $tokens['active_break']);
    $activeOutKey = 'active-clock-out-' . bin2hex(random_bytes(8));
    $activeOut = api_idem_request($portA, $sessions['active_break'], '/api/clock.php', ['action' => 'clock_out'], $activeOutKey, $tokens['active_break']);
    api_idem_assert(!empty($activeIn['json']['success']) && !empty($activeBreak['json']['success']) && !empty($activeOut['json']['success']), 'Clock-out with an active break keeps current behavior');
    $activeOutReplay = api_idem_request($portA, $sessions['active_break'], '/api/clock.php', ['action' => 'clock_out'], $activeOutKey, $tokens['active_break']);
    api_idem_assert(!empty($activeOutReplay['json']['replayed']) && $activeOutReplay['json']['had_open_break'] === true, 'Active-break clock-out result replays accurately');
    $activeReview = $pdo->prepare('SELECT break_review_status FROM attendance WHERE id=?');
    $activeReview->execute([$activeOut['json']['attendance_id']]);
    api_idem_assert($activeReview->fetchColumn() === 'under_review', 'Active-break clock-out retains review policy');

    $failureKey = 'audit-failure-' . bin2hex(random_bytes(8));
    $pdo->exec("CREATE TRIGGER fail_api_idem_audit BEFORE INSERT ON attendance_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test audit failure'");
    try {
        $failure = api_idem_request($portA, $sessions['audit_failure'], '/api/clock.php', ['action' => 'clock_in'], $failureKey, $tokens['audit_failure']);
    } finally {
        $pdo->exec('DROP TRIGGER fail_api_idem_audit');
    }
    api_idem_assert($failure['status'] === 500 && ($failure['json']['outcome'] ?? '') === 'server_failure', 'Audit failure returns a sanitized server error');
    $todayAttendance = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE user_id=? AND work_date=?');
    $todayAttendance->execute([$users['audit_failure'], $today]);
    api_idem_assert((int) $todayAttendance->fetchColumn() === 0, 'Audit failure rolls back attendance creation');
    $failedOperation = $pdo->prepare('SELECT COUNT(*) FROM attendance_idempotency_operations WHERE user_id=? AND request_id=?');
    $failedOperation->execute([$users['audit_failure'], $failureKey]);
    api_idem_assert((int) $failedOperation->fetchColumn() === 0, 'Audit failure rolls back idempotency reservation');

    $expireKey = 'expired-session-' . bin2hex(random_bytes(8));
    $expireClient = curl_init();
    curl_setopt_array($expireClient, [CURLOPT_URL => 'http://127.0.0.1:' . $portA . '/__test_expire', CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $sessions['expired'], CURLOPT_COOKIEJAR => $sessions['expired'], CURLOPT_TIMEOUT => 10]);
    curl_exec($expireClient);
    curl_close($expireClient);
    $expiredSession = api_idem_request($portA, $sessions['expired'], '/api/clock.php', ['action' => 'clock_in'], $expireKey, $tokens['expired']);
    api_idem_assert($expiredSession['status'] === 401 && ($expiredSession['json']['outcome'] ?? '') === 'authentication_required', 'Expired session is rejected with an API JSON response');
    $expiredOperation = $pdo->prepare('SELECT COUNT(*) FROM attendance_idempotency_operations WHERE user_id=? AND request_id=?');
    $expiredOperation->execute([$users['expired'], $expireKey]);
    api_idem_assert((int) $expiredOperation->fetchColumn() === 0, 'Expired session creates no operation record');

    $concurrentKey = 'parallel-same-' . bin2hex(random_bytes(8));
    $concurrentSameUsername = (string) $pdo->query('SELECT username FROM users WHERE id=' . (int) $users['concurrent_same'])->fetchColumn();
    $cookieJars['concurrent_same_second'] = api_idem_cookie_path('concurrent_same_second');
    $concurrentSameSecondToken = api_idem_login($portB, $concurrentSameUsername, $cookieJars['concurrent_same_second']);
    $sameResults = api_idem_multi_request([
        ['port' => $portA, 'jar' => $sessions['concurrent_same'], 'path' => '/api/clock.php', 'data' => ['action' => 'clock_in'], 'key' => $concurrentKey, 'csrf' => $tokens['concurrent_same']],
        ['port' => $portB, 'jar' => $cookieJars['concurrent_same_second'], 'path' => '/api/clock.php', 'data' => ['action' => 'clock_in'], 'key' => $concurrentKey, 'csrf' => $concurrentSameSecondToken],
    ]);
    api_idem_assert(count(array_filter($sameResults, static fn(array $r): bool => !empty($r['json']['success']))) === 2, 'Concurrent same-key API calls both receive success semantics');
    api_idem_assert(count(array_filter($sameResults, static fn(array $r): bool => !empty($r['json']['replayed']))) === 1, 'Concurrent same-key API calls yield one original and one replay');
    $concurrentAttendance = $pdo->prepare('SELECT id FROM attendance WHERE user_id=? AND work_date=?');
    $concurrentAttendance->execute([$users['concurrent_same'], $today]);
    $concurrentAttendanceId = (int) $concurrentAttendance->fetchColumn();
    api_idem_assert((int) $pdo->query("SELECT COUNT(*) FROM attendance_audit_logs WHERE attendance_id={$concurrentAttendanceId} AND action='clock_in'")->fetchColumn() === 1, 'Concurrent same-key API calls create one punch and audit');

    // Two sessions and two independent HTTP server/PDO connections use distinct keys for one business action.
    $concurrentNewSecondJar = api_idem_cookie_path('concurrent_new_second');
    $cookieJars['concurrent_new_second'] = $concurrentNewSecondJar;
    $newUsername = (string) $pdo->query('SELECT username FROM users WHERE id=' . (int) $users['concurrent_new'])->fetchColumn();
    $newSecondToken = api_idem_login($portB, $newUsername, $concurrentNewSecondJar);
    $differentResults = api_idem_multi_request([
        ['port' => $portA, 'jar' => $sessions['concurrent_new'], 'path' => '/api/clock.php', 'data' => ['action' => 'clock_in'], 'key' => 'parallel-a-' . bin2hex(random_bytes(8)), 'csrf' => $tokens['concurrent_new']],
        ['port' => $portB, 'jar' => $concurrentNewSecondJar, 'path' => '/api/clock.php', 'data' => ['action' => 'clock_in'], 'key' => 'parallel-b-' . bin2hex(random_bytes(8)), 'csrf' => $newSecondToken],
    ]);
    api_idem_assert(count(array_filter($differentResults, static fn(array $r): bool => !empty($r['json']['success']))) === 1, 'Concurrent different-key duplicate actions allow exactly one success');
    api_idem_assert(count(array_filter($differentResults, static fn(array $r): bool => ($r['json']['outcome'] ?? '') === 'confirmed_rejection')) === 1, 'Concurrent different-key duplicate action is rejected by business rules');

    $historicalAfter = $pdo->query('SELECT id,work_date,clock_in,clock_out,status,shift_minutes,work_minutes,version FROM attendance WHERE user_id=' . (int) $users['main'] . ' AND work_date=' . $pdo->quote($yesterday))->fetch();
    api_idem_assert($historicalBefore === $historicalAfter, 'Historical attendance remains unchanged');

    $pdo->exec('RENAME TABLE attendance_idempotency_operations TO attendance_idempotency_operations_held');
    try {
        $missingMigration = api_idem_get($portA, $sessions['main'], '/api/attendance_operation.php');
        api_idem_assert($missingMigration['status'] === 503 && ($missingMigration['json']['outcome'] ?? '') === 'unavailable' && empty($missingMigration['json']['idempotency_supported']), 'Capability probe disables keyed actions when the migration is absent');
    } finally {
        $pdo->exec('RENAME TABLE attendance_idempotency_operations_held TO attendance_idempotency_operations');
    }
    echo "PASS: {$checks} real HTTP attendance API idempotency and reconciliation checks.\n";
} finally {
    foreach ($servers as $server) {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
    }
    foreach ($cookieJars as $jar) {
        if (is_file($jar)) @unlink($jar);
    }
    foreach (glob($sessionDir . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $sessionFile) {
        if (is_file($sessionFile)) @unlink($sessionFile);
    }
    if (is_dir($sessionDir)) @rmdir($sessionDir);
    if (is_file($router)) @unlink($router);
    $rootPdo->exec("DROP DATABASE IF EXISTS `{$database}`");
}
