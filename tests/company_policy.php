<?php
/** Run: C:\xampp\php\php.exe tests/company_policy.php
 * Uses an isolated, uniquely named database; never resets the application database.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$testSessions = __DIR__ . '/../storage/test-sessions';
if (!is_dir($testSessions)) mkdir($testSessions, 0700, true);
session_save_path($testSessions);
require_once __DIR__ . '/../includes/attendance_service.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/report_export.php';
require_once __DIR__ . '/../includes/account_recovery.php';
require_once __DIR__ . '/../includes/password_policy.php';
require_once __DIR__ . '/../includes/security_questions.php';
$_SESSION = ['lang' => 'en'];

// Separate processes exercise the same row-lock protocol as concurrent requests.
if (in_array($argv[1] ?? '', ['worker', 'render'], true)) {
    $database = $argv[2] ?? '';
    if (!preg_match('/^staff_attendance_test_[a-f0-9]{12}$/D', $database)) {
        throw new RuntimeException('Invalid test database.');
    }
    db()->exec('USE `' . $database . '`');
    $userId = (int) $argv[3];
    if ($argv[1] === 'render') {
        $page = $argv[4];
        $allowedPages = ['admin/reports.php', 'staff/attendance.php', 'staff/leave.php', 'admin/index.php', 'admin/attendance.php', 'admin/attendance_today.php',
            'admin/attendance_detail.php', 'admin/settings.php', 'admin/schedules.php', 'admin/staff_form.php',
            'admin/corrections.php', 'admin/leave_requests.php', 'admin/offday_adjustments.php', 'staff/index.php', 'staff/schedule.php', 'staff/profile.php', 'admin/profile.php'];
        if (!in_array($page, $allowedPages, true)) throw new RuntimeException('Invalid page');
        require_once __DIR__ . '/../includes/bootstrap.php';
        $_SESSION['user_id'] = $userId;
        $_SESSION['auth_version'] = (int) db()->query('SELECT auth_version FROM users WHERE id=' . $userId)->fetchColumn();
        $_SESSION['lang'] = $argv[6] ?? 'en';
        $_GET['id'] = (int) ($argv[5] ?? 0);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = BASE_PATH . '/' . $page;
        require __DIR__ . '/../' . $page;
        exit;
    }
    $operation = $argv[4];
    if ($operation === 'employee_id') {
        db()->beginTransaction();
        $employeeId = generate_employee_id();
        $newUser = test_user(0);
        db()->prepare('UPDATE users SET employee_id=? WHERE id=?')->execute([$employeeId, $newUser]);
        db()->commit();
        echo json_encode(['success' => true, 'employee_id' => $employeeId]);
        exit;
    }
    $result = match ($operation) {
        'clock' => clock_in($userId),
        'break' => start_break($userId, 'solat'),
        'out' => clock_out($userId),
        default => throw new RuntimeException('Unknown operation'),
    };
    echo json_encode($result);
    exit;
}

$checks = 0;
foreach (['', '123456789', '1112345678'] as $phone) {
    check(valid_account_phone($phone), 'Valid optional phone format accepted: ' . $phone);
}
foreach (['012-0GG00001', 'abcdefg', '123', '0000000000', '+60123456789', '0123456789', '12345678', '12345678901', '12-3456789', '12 3456789'] as $phone) {
    check(!valid_account_phone($phone), 'Invalid phone rejected: ' . $phone);
}
check(normalize_username('Nurul Aisyah') === 'nurul_aisyah', 'Username lowercase and space normalization');
check(normalize_full_name('Nurul 李伟') === 'NURUL 李伟', 'Full name uppercase preserves Chinese characters');
check(valid_account_email('nurul.aisyah+test@example.com'), 'Valid .com email accepted');
check(valid_account_email('admin@lastotechnology.my'), 'Valid .my email accepted');
foreach (['nurul.example.com', 'nurul@example', 'nurul@@example.com', 'nurul @example.com', 'nurul..aisyah@example.com'] as $invalidEmail) {
    check(!valid_account_email($invalidEmail), 'Invalid account email rejected: ' . $invalidEmail);
}
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function rejected(callable $action, string $message): void
{
    try {
        $action();
    } catch (InvalidArgumentException $error) {
        check(true, $message);
        return;
    }
    throw new RuntimeException($message);
}
function render_offday_review(string $database, int $adminId): string
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, 'render', $database, (string) $adminId, 'admin/offday_adjustments.php'],
        [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot render off-day review.');
    fclose($pipes[0]);
    $html = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || $errors !== '') throw new RuntimeException('Off-day review render failed: ' . $errors);
    return $html;
}
check(password_policy_error('seven77') === 'password_minimum_8', 'Password policy rejects fewer than 8 characters');
check(password_policy_error('Eight888') === null, 'Password policy accepts 8 characters');
check(password_policy_error('password123456789') === 'password_too_common', 'Password policy rejects common passwords');
check(password_policy_error('a longer private passphrase') === null, 'Password policy accepts a long passphrase');
function test_user(int $offDay): int
{
    $name = 'test_' . bin2hex(random_bytes(4));
    db()->prepare("INSERT INTO users (employee_id,username,password_hash,full_name,weekly_off_day) VALUES (?,?,?,?,?)")
        ->execute([$name, $name, 'unused', $name, $offDay]);
    return (int) db()->lastInsertId();
}
function race(string $database, int $userId, string $operation): array
{
    $processes = [];
    for ($i = 0; $i < 2; $i++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $database, (string) $userId, $operation],
            [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start concurrency worker.');
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException('Worker failed: ' . $errors . $output);
        $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
    return $results;
}

$database = 'staff_attendance_test_' . bin2hex(random_bytes(6));
$pdo = db();
$pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4');
try {
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    $schema = str_replace('`staff_attendance`', '`' . $database . '`', $schema);
    $pdo->exec($schema);
    $resetUser = test_user(0);
    $pdo->prepare('INSERT INTO user_security_questions (user_id,question_key,answer_hash) VALUES (?,?,?)')
        ->execute([$resetUser, 'sq_first_school', password_hash('test answer', PASSWORD_DEFAULT)]);
    $pdo->exec($schema);
    check((int) $pdo->query('SELECT COUNT(*) FROM user_security_questions')->fetchColumn() === 0, 'Fresh schema reset removes old recovery answers');
    $firstIdUser = test_user(0);
    check(count(get_available_security_questions()) === 9, 'Nine requested security questions available');
    check(__('security_questions') === 'Security Questions', 'Security questions heading is translated');
    check(!save_user_security_questions($firstIdUser, 'sq_birth_city', 'secret place', 'sq_birth_city', 'another place')['success'], 'Duplicate security prompts rejected');
    check(!save_user_security_questions($firstIdUser, 'invalid', 'secret place', 'sq_favorite_food', 'secret food')['success'], 'Unknown security prompts rejected');
    check(save_user_security_questions($firstIdUser, 'sq_birth_city', 'secret place', 'sq_favorite_food', 'secret food')['success'], 'Distinct new security prompts saved');
    check(verify_user_security_questions($firstIdUser, 'secret place', 'secret food'), 'New security answers verify');
    $pdo->beginTransaction();
    check(save_user_security_questions($firstIdUser, 'sq_favorite_movie', 'new object', 'sq_childhood_nickname', 'new activity')['success'], 'Security questions participate in setup transaction');
    $pdo->rollBack();
    check(verify_user_security_questions($firstIdUser, 'secret place', 'secret food'), 'Failed setup transaction preserves original questions');
    $secondIdUser = test_user(0);
    $pdo->prepare('UPDATE users SET employee_id=? WHERE id=?')->execute(['EMP001', $firstIdUser]);
    $pdo->prepare('UPDATE users SET employee_id=? WHERE id=?')->execute(['EMP002', $secondIdUser]);
    $pdo->beginTransaction();
    check(generate_employee_id() === 'EMP003', 'Sequential employee IDs follow existing seeded IDs');
    $pdo->rollBack();
    $idResults = race($database, 0, 'employee_id');
    $allocated = array_column($idResults, 'employee_id');
    sort($allocated);
    check($allocated === ['EMP003', 'EMP004'], 'Concurrent staff creation allocates distinct sequential IDs');
    $pdo->exec("DELETE FROM users WHERE employee_id='EMP004'");
    $pdo->beginTransaction();
    check(generate_employee_id() === 'EMP005', 'Deleted employee IDs are not reused');
    $pdo->commit();
    $legacyIdUser = test_user(0);
    $pdo->prepare('UPDATE users SET employee_id=? WHERE id=?')->execute(['EMP999', $legacyIdUser]);
    $pdo->beginTransaction();
    check(generate_employee_id() === 'EMP1000', 'Employee ID sequence expands past three digits and skips existing IDs');
    $pdo->rollBack();
    check($pdo->query("SELECT employee_id FROM users WHERE id=" . $legacyIdUser)->fetchColumn() === 'EMP999', 'Generating employee IDs preserves existing identifiers');
    $liveIn = now()->modify('-90 minutes')->format('Y-m-d H:i:s');
    $liveBreak = now()->modify('-10 minutes')->format('Y-m-d H:i:s');
    $weeklyLive = attendance_live_work_html($liveIn, null, 999, 20, null, 450);
    check(str_contains($weeklyLive, '>8h 40m</span>') && str_contains($weeklyLive, 'data-work-base="450"'), 'Weekly live total adds saved days once and deducts completed breaks');
    $weeklyPaused = attendance_live_work_html($liveIn, null, 0, 20, $liveBreak, 450);
    check(str_contains($weeklyPaused, '>8h 30m</span>') && str_contains($weeklyPaused, 'data-break-open='), 'Weekly live total deducts the active break');
    check(attendance_live_work_html($liveIn, now()->format('Y-m-d H:i:s'), 70, 20, null, 450) === '8h 40m', 'Finalized weekly total uses saved working time without a live timer');
    check(attendance_live_work_html(null, null, 0, 0, null, 450) === '7h 30m', 'Weekly total without an active shift preserves saved days');
    $recoveryUser = test_user(0);
    $inviteToken = recovery_issue_token($recoveryUser, 'invite');
    check(recovery_valid_token($inviteToken) !== null, 'Invitation token is valid before use');
    check(!recovery_consume_password_token($inviteToken, 'short7!'), 'Short recovery password rejected');
    check(recovery_consume_password_token($inviteToken, 'a-long-test-password-2026'), 'Invitation sets a staff password');
    check(!recovery_consume_password_token($inviteToken, 'another-long-test-password'), 'Invitation cannot be replayed');
    $recoveryRow = $pdo->query('SELECT email_verified_at, auth_version FROM users WHERE id=' . $recoveryUser)->fetch();
    check($recoveryRow['email_verified_at'] === null && (int) $recoveryRow['auth_version'] === 1, 'In-person invitation does not falsely verify email and invalidates sessions');
    $resetToken = recovery_issue_token($recoveryUser, 'reset');
    check(recovery_consume_password_token($resetToken, 'another-long-test-password'), 'Reset token sets a new password');
    check(password_verify('another-long-test-password', (string) $pdo->query('SELECT password_hash FROM users WHERE id=' . $recoveryUser)->fetchColumn()), 'Reset password is usable for login');
    check(recovery_valid_token($resetToken) === null, 'Used reset token is invalid');
    $verifyToken = recovery_issue_token($recoveryUser, 'verify_email');
    check(!recovery_consume_email_token($verifyToken), 'Undelivered verification token cannot verify an email');
    $pdo->prepare('UPDATE account_recovery_tokens SET email_delivery=1 WHERE token_hash=?')->execute([hash('sha256', $verifyToken)]);
    check(recovery_consume_email_token($verifyToken), 'Delivered verification token verifies email');
    check(!recovery_consume_email_token($verifyToken), 'Verification token cannot be replayed');
    $expiredToken = recovery_issue_token($recoveryUser, 'reset');
    $pdo->prepare('UPDATE account_recovery_tokens SET expires_at=DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE token_hash=?')->execute([hash('sha256', $expiredToken)]);
    check(!recovery_consume_password_token($expiredToken, 'another-long-test-password'), 'Expired reset token cannot change password');
    $pdo->exec("INSERT INTO work_schedules (name,start_time,end_time,is_default) VALUES ('Policy','10:00:00','20:00:00',1)");
    // Legacy settings must not override company policy.
    $pdo->exec("INSERT INTO system_settings (setting_key,setting_value) VALUES ('late_grace_minutes','15'),('enable_other_break','1'),('break_pool_total_minutes','999')");
    foreach (['09:59:59' => 0, '10:00:00' => 0, '10:00:01' => 0, '10:00:59' => 0, '10:01:00' => 1, '10:01:01' => 1, '10:01:59' => 1, '10:02:00' => 2] as $time => $expected) {
        check(attendance_late_minutes('2026-09-21', '2026-09-21 ' . $time) === $expected, 'Punctuality boundary ' . $time);
    }
    for ($off = 0; $off <= 6; $off++) {
        check(count(working_weekdays($off)) === 6, 'Six workdays');
        for ($dow = 0; $dow <= 6; $dow++) {
            $date = (new DateTimeImmutable('2026-09-20'))->modify('+' . $dow . ' days')->format('Y-m-d');
            check(is_scheduled_off(['weekly_off_day' => $off, 'work_days' => '1,2,3,4,5,6'], $date) === ($dow === $off), 'Arranged off day including Sunday');
        }
    }
    rejected(fn() => working_weekdays(7), 'Reject invalid off day');
    rejected(fn() => normalize_attendance_time('2026-02-30T10:00'), 'Reject invalid calendar date');
    $today = today_str();
    $off = ((int) now()->format('w') + 1) % 7;
    $user = test_user($off);
    check(!start_break($user, 'lunch')['success'], 'Break requires clock-in');
    check(!clock_out($user)['success'], 'Clock-out requires clock-in');
    check(clock_in($user)['success'], 'Clock-in succeeds');
    check(!clock_in($user)['success'], 'Duplicate clock-in rejected');
    check(!start_break($user, 'other')['success'], 'Other break rejected despite legacy setting');
    $attendance = get_attendance($user, $today);
    foreach (['lunch' => [1,60], 'solat' => [3,20], 'short' => [3,10]] as $type => [$count,$allowed]) {
        for ($i=0; $i<$count; $i++) {
            $result = start_break($user, $type);
            check($result['success'], 'Allowed session ' . $type);
            $active = get_active_break($user);
            check((int) $active['id'] === $result['break_id'], 'Returned break id is not the audit id');
            check((int) $active['allowed_minutes'] === $allowed, 'Per-session allowance');
            check(!start_break($user, 'short')['success'], 'Overlapping break rejected');
            check(!clock_out($user)['success'], 'Clock-out during break rejected');
            if ($type === 'lunch') {
                $pdo->prepare('UPDATE break_records SET start_time=? WHERE id=?')->execute([now()->modify('-3601 seconds')->format('Y-m-d H:i:s'), $active['id']]);
            }
            $ended = end_break($user);
            check($ended['success'], 'Can end break');
            if ($type === 'lunch') check($ended['exceeded'], 'One-second overrun flagged');
            check(!end_break($user)['success'], 'Duplicate end rejected');
        }
        check(!start_break($user, $type)['success'], 'Daily session cap ' . $type);
        check(!break_usage_summary((int) $attendance['id'])[$type]['can_start'], 'UI availability matches API');
    }
    check(clock_out($user)['success'], 'Clock-out succeeds');
    check(!clock_out($user)['success'], 'Duplicate clock-out rejected');
    check(!start_break($user, 'solat')['success'], 'Break after clock-out rejected');

    $restUser = test_user((int) now()->format('w'));
    check(clock_in($restUser)['success'], 'Off-day clock-in allowed');
    check(start_break($restUser, 'solat')['success'], 'Off-day break start allowed');
    check(end_break($restUser)['success'], 'Off-day break end allowed');
    check(clock_out($restUser)['success'], 'Off-day clock-out allowed');
    $offReview = $pdo->query('SELECT * FROM offday_adjustment_reviews WHERE employee_id=' . $restUser . ' ORDER BY id DESC LIMIT 1')->fetch();
    check($offReview && $offReview['status'] === 'pending', 'Completed off-day creates pending review');
    assign_employee_schedule($restUser, 1, $off);
    check((int) get_attendance($restUser, $today)['is_day_off'] === 0, 'Today reconciles after off-day change');
    assign_employee_schedule($restUser, 1, ($off + 1) % 7);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM schedule_assignments WHERE user_id=?'); $stmt->execute([$restUser]);
    check((int) $stmt->fetchColumn() === 1, 'Same-day assignment edits do not overlap');
    $pdo->prepare('INSERT INTO schedule_assignments (user_id,schedule_id,effective_from) VALUES (?,1,?)')->execute([$restUser, now()->modify('+2 days')->format('Y-m-d')]);
    rejected(fn() => assign_employee_schedule($restUser, 1, $off), 'Future assignment conflict rejected');
    check(!$pdo->inTransaction(), 'Rejected assignment releases transaction');

    $historical = test_user(0);
    $att = ensure_attendance_row($historical, '2026-09-21');
    correct_attendance((int) $att['id'], '2026-09-21 10:00:01', '2026-09-21 20:00:00', $historical, 'Test correction');
    $att = get_attendance($historical, '2026-09-21');
    check((int) $att['late_minutes'] === 0 && $att['status'] === 'completed', 'Correction treats 10:00 seconds as on time');
    correct_attendance((int) $att['id'], '2026-09-21 10:01:00', '2026-09-21 20:00:00', $historical, 'Late boundary');
    check((int) get_attendance($historical, '2026-09-21')['late_minutes'] === 1, 'Correction marks 10:01 as one minute late');
    try {
        correct_attendance((int) $att['id'], '2026-09-20 10:00:00', '2026-09-20 20:00:00', $historical, 'Wrong record date');
        throw new RuntimeException('Expected wrong-date correction to be rejected');
    } catch (InvalidArgumentException $error) {
        check(str_contains($error->getMessage(), '21/09/2026'), 'Wrong-date correction names the record date');
    }
    rejected(fn() => correct_attendance((int) $att['id'], '2026-09-21 11:00:00', '2026-09-21 10:00:00', $historical, 'Invalid'), 'Out before in rejected');
    check(get_attendance($historical, '2026-09-21')['clock_in'] === '2026-09-21 10:01:00', 'Invalid correction does not mutate record');
    correct_attendance((int) $att['id'], null, null, $historical, 'Clear punches');
    $att = get_attendance($historical, '2026-09-21');
    check($att['status'] === 'absent' && (int) $att['work_minutes'] === 0, 'Clearing punches clears totals');
    $pdo->prepare('INSERT INTO break_records (attendance_id,user_id,break_type,start_time,end_time,duration_minutes,allowed_minutes) VALUES (?,?,\'lunch\',\'2026-09-21 12:00:00\',\'2026-09-21 14:30:00\',150,60)')->execute([$att['id'],$historical]);
    correct_attendance((int) $att['id'], '2026-09-21 10:00:00', '2026-09-21 20:00:00', $historical, 'Full shift');
    $att = get_attendance($historical, '2026-09-21');
    check((int) $att['shift_minutes'] === 600 && (int) $att['work_minutes'] === 450, 'Ten hours less actual breaks equals 450 minutes');
    rejected(fn() => correct_attendance((int) $att['id'], null, null, $historical, 'Invalid clear'), 'Cannot orphan breaks');

    // Force audit insertion to fail: the attendance mutation must roll back with it.
    $rollbackUser = test_user($off);
    $pdo->exec("CREATE TRIGGER fail_test_audit BEFORE INSERT ON attendance_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test audit failure'");
    try { clock_in($rollbackUser); throw new RuntimeException('Expected audit failure'); }
    catch (PDOException $error) { check(get_attendance($rollbackUser, $today) === null, 'Audit failure rolls back attendance creation and clock-in'); }
    $pdo->exec('DROP TRIGGER fail_test_audit');

    $concurrent = test_user($off);
    foreach (['clock', 'break'] as $operation) {
        $results = race($database, $concurrent, $operation);
        check(count(array_filter($results, fn($r) => $r['success'])) === 1, 'Only one concurrent ' . $operation . ' succeeds');
    }
    end_break($concurrent);
    $results = race($database, $concurrent, 'out');
    check(count(array_filter($results, fn($r) => $r['success'])) === 1, 'Only one concurrent clock-out succeeds');

    $pdo->exec('UPDATE work_schedules SET is_default=0');
    check(get_schedule_for_date($restUser, $today)['start_time'] === '10:00:00', 'Assigned schedule uses fixed policy');
    $fallback = test_user(3);
    $schedule = get_schedule_for_date($fallback, '2026-09-20');
    check($schedule['schedule_id'] === null && !is_scheduled_off($schedule, '2026-09-20'), 'Sunday remains a workday without a default template');
    check(is_scheduled_off($schedule, '2026-09-23'), 'Fallback observes arranged off day');
    $pdo->exec('UPDATE work_schedules SET is_default=1');

    // Upgrade is repeatable and does not rewrite completed breaks.
    $upgradeUser = test_user($off);
    clock_in($upgradeUser);
    start_break($upgradeUser, 'lunch');
    $openBreak = get_active_break($upgradeUser);
    $pdo->prepare('UPDATE break_records SET allowed_minutes=150 WHERE id=?')->execute([$openBreak['id']]);
    $migration = str_replace('USE staff_attendance;', 'USE `' . $database . '`;', file_get_contents(__DIR__ . '/../database/migration_company_policy.sql'));
    $pdo->exec($migration);
    $pdo->exec($migration);
    check((int) get_active_break($upgradeUser)['allowed_minutes'] === 60, 'Upgrade fixes open legacy break allowance');
    check((int) $pdo->query("SELECT COUNT(*) FROM system_settings WHERE setting_key='break_pool_total_minutes'")->fetchColumn() === 0, 'Upgrade removes obsolete pool override');

    $admin = test_user($off);
    $pdo->prepare("UPDATE users SET role='admin' WHERE id=?")->execute([$admin]);

    $futureDate = now()->modify('+7 days')->format('Y-m-d');
    foreach (['medical', 'wfh', 'day_off', 'annual', 'emergency', 'other'] as $leaveType) {
        $futureUser = test_user($off);
        submit_leave_request($futureUser, $futureDate, $leaveType, 'Future approval regression');
        $futureRequest = (int) $pdo->query('SELECT MAX(id) FROM leave_requests')->fetchColumn();
        review_leave_request($futureRequest, 'approved', $admin, 'Approved in advance');
        $reviewed = $pdo->query('SELECT * FROM leave_requests WHERE id=' . $futureRequest)->fetch();
        check($reviewed['status'] === 'approved' && (int) $reviewed['reviewed_by'] === $admin
            && $reviewed['reviewed_at'] !== null && $reviewed['admin_note'] === 'Approved in advance', 'Future approval retains review history: ' . $leaveType);
        check(get_attendance($futureUser, $futureDate) === null, 'Future approval does not create attendance: ' . $leaveType);
        rejected(fn() => ensure_attendance_row($futureUser, $futureDate), 'Future attendance generation remains blocked');
    }
    $endedUser = test_user($off);
    $pdo->prepare('UPDATE users SET employment_end_date=? WHERE id=?')->execute([$today, $endedUser]);
    rejected(fn() => submit_leave_request($endedUser, $futureDate, 'annual', 'After employment'), 'Request after employment end rejected');
    $changedUser = test_user($off);
    submit_leave_request($changedUser, $futureDate, 'annual', 'Employment changes before review');
    $changedRequest = (int) $pdo->query('SELECT MAX(id) FROM leave_requests')->fetchColumn();
    $pdo->prepare('UPDATE users SET employment_end_date=? WHERE id=?')->execute([$today, $changedUser]);
    rejected(fn() => review_leave_request($changedRequest, 'approved', $admin, null), 'Approval rechecks employment dates');
    check($pdo->query('SELECT status FROM leave_requests WHERE id=' . $changedRequest)->fetchColumn() === 'pending', 'Invalid approval leaves request pending');
    review_leave_request($changedRequest, 'rejected', $admin, 'Employment ended');
    check($pdo->query('SELECT status FROM leave_requests WHERE id=' . $changedRequest)->fetchColumn() === 'rejected', 'Invalid employment request can still be rejected');

    // WFH is a work arrangement, independent of attendance status.
    $remote = test_user($off);
    submit_leave_request($remote, $today, 'wfh', 'Remote work test');
    $requestId = (int) $pdo->query('SELECT MAX(id) FROM leave_requests')->fetchColumn();
    check(!has_approved_wfh($remote, $today), 'Pending WFH is not approved');
    check((int) ensure_attendance_row($remote, $today)['is_wfh'] === 0, 'Pending request has no WFH badge');
    rejected(fn() => submit_leave_request($remote, $today, 'medical', 'Conflict'), 'Duplicate pending request blocked');
    rejected(fn() => review_leave_request($requestId, 'approved', $remote, null), 'Only an admin can approve');
    review_leave_request($requestId, 'approved', $admin, 'Approved test');
    $remoteAtt = ensure_attendance_row($remote, $today);
    check((int) $remoteAtt['is_wfh'] === 1 && !(int) $remoteAtt['is_day_off'] && $remoteAtt['status'] === 'not_clocked_in', 'Approved WFH is an unclocked working day');
    check(!has_approved_leave($remote, $today), 'WFH excluded from approved absence');
    check(clock_in($remote)['success'], 'Approved WFH can clock in');
    // Historical fixture keeps boundary checks valid even before 10:01 today.
    $boundaryDate = now()->modify('-1 day')->format('Y-m-d');
    $pdo->prepare("INSERT INTO leave_requests (user_id,leave_date,leave_type,reason,status) VALUES (?,?,'wfh','Boundary fixture','approved')")->execute([$remote,$boundaryDate]);
    $boundaryAtt = ensure_attendance_row($remote, $boundaryDate);
    correct_attendance((int) $boundaryAtt['id'], $boundaryDate . ' 10:00:59', $boundaryDate . ' 20:00:00', $admin, 'WFH on-time boundary');
    check((int) get_attendance($remote, $boundaryDate)['late_minutes'] === 0, 'WFH 10:00:59 is on time');
    correct_attendance((int) $boundaryAtt['id'], $boundaryDate . ' 10:01:00', $boundaryDate . ' 20:00:00', $admin, 'WFH late boundary');
    $boundaryAtt = get_attendance($remote, $boundaryDate);
    check((int) $boundaryAtt['late_minutes'] === 1 && (int) $boundaryAtt['is_wfh'] === 1, 'WFH and lateness coexist');
    check(start_break($remote, 'lunch')['success'], 'WFH lunch allowed');
    $remoteAtt = get_attendance($remote, $today);
    check($remoteAtt['status'] === 'on_break' && (int) $remoteAtt['is_wfh'] === 1, 'WFH visible during break');
    check(!clock_out($remote)['success'], 'WFH active break blocks clock-out');
    check(end_break($remote)['success'], 'WFH break can end');
    check(!start_break($remote, 'lunch')['success'], 'WFH uses normal lunch session limit');
    check(clock_out($remote)['success'], 'WFH can clock out');
    $remoteAtt = get_attendance($remote, $today);
    check($remoteAtt['status'] === 'completed' && (int) $remoteAtt['is_wfh'] === 1, 'Completed WFH retains arrangement');
    check(count(report_filter_rows([$remoteAtt], 'wfh')) === 1, 'WFH report includes completed shifts');
    check(count(report_filter_rows([$remoteAtt], 'leave')) === 0, 'Leave report excludes WFH');
    $remoteAtt['full_name'] = 'WFH Test';
    $matrix = report_attendance_export_matrix([$remoteAtt]);
    check(count($matrix[0]) === count(report_attendance_headers()) && end($matrix[0]) === 'WFH', 'Export retains short WFH label separately from status');
    check(attendance_work_arrangement(['is_wfh' => 0, 'is_day_off' => 0]) === '-', 'On-site export arrangement is a dash');
    check(attendance_work_arrangement(['is_wfh' => 0, 'is_day_off' => 1]) === '-', 'Day-off export arrangement is a dash');
    rejected(fn() => review_leave_request($requestId, 'approved', $admin, null), 'Request cannot be approved twice');

    $declined = test_user($off);
    submit_leave_request($declined, $today, 'wfh', 'Reject test');
    $declinedId = (int) $pdo->query('SELECT MAX(id) FROM leave_requests')->fetchColumn();
    review_leave_request($declinedId, 'rejected', $admin, 'Rejected test');
    check((int) ensure_attendance_row($declined, $today)['is_wfh'] === 0, 'Rejected WFH does not change work arrangement');
    submit_leave_request($declined, $today, 'medical', 'Medical test');
    $medicalId = (int) $pdo->query('SELECT MAX(id) FROM leave_requests')->fetchColumn();
    review_leave_request($medicalId, 'approved', $admin, null);
    check(!clock_in($declined)['success'], 'Ordinary leave still blocks clock-in');
    // Simulate a conflicting request left over from the older application.
    $pdo->prepare("INSERT INTO leave_requests (user_id,leave_date,leave_type,reason) VALUES (?,?,'wfh','Legacy duplicate')")->execute([$declined,$today]);
    $conflictId = (int) $pdo->lastInsertId();
    rejected(fn() => review_leave_request($conflictId, 'approved', $admin, null), 'WFH cannot override approved leave');

    $restRemote = test_user((int) now()->format('w'));
    rejected(fn() => submit_leave_request($restRemote, $today, 'wfh', 'Off-day test'), 'WFH submission cannot override arranged rest day');
    $pdo->prepare("INSERT INTO leave_requests (user_id,leave_date,leave_type,reason) VALUES (?,?,'wfh','Legacy off-day request')")->execute([$restRemote,$today]);
    $offRequest = (int) $pdo->lastInsertId();
    rejected(fn() => review_leave_request($offRequest, 'approved', $admin, null), 'Admin approval rechecks off day');

    $alreadyWorking = test_user($off);
    clock_in($alreadyWorking);
    submit_leave_request($alreadyWorking, $today, 'wfh', 'Already working test');
    $workingRequest = (int) $pdo->query('SELECT MAX(id) FROM leave_requests')->fetchColumn();
    $beforePunch = get_attendance($alreadyWorking,$today)['clock_in'];
    review_leave_request($workingRequest, 'approved', $admin, null);
    check(get_attendance($alreadyWorking,$today)['clock_in'] === $beforePunch && (int) get_attendance($alreadyWorking,$today)['is_wfh'] === 1, 'Approval after clock-in preserves punch');

    $legacyRemote = test_user(0);
    $pdo->prepare("INSERT INTO leave_requests (user_id,leave_date,leave_type,reason,status) VALUES (?,'2026-09-21','wfh','Legacy approved','approved')")->execute([$legacyRemote]);
    $legacyAtt = ensure_attendance_row($legacyRemote,'2026-09-21');
    check($legacyAtt['status'] === 'absent' && (int) $legacyAtt['is_wfh'] === 1, 'Missed WFH day counts as absent');
    $pdo->prepare("UPDATE attendance SET is_day_off=1,is_wfh=0,status='day_off' WHERE id=?")->execute([$legacyAtt['id']]);
    $legacyAtt = ensure_attendance_row($legacyRemote,'2026-09-21');
    check(!(int) $legacyAtt['is_day_off'] && (int) $legacyAtt['is_wfh'] === 1, 'Legacy WFH day-off record repaired');
    correct_attendance((int)$legacyAtt['id'],'2026-09-21 10:00:59',null,$admin,'Missing out test');
    check(get_attendance($legacyRemote,'2026-09-21')['status'] === 'incomplete', 'WFH missing clock-out is incomplete');

    $rollbackRemote = test_user($off);
    submit_leave_request($rollbackRemote, $today, 'wfh', 'Approval rollback test');
    $rollbackRequest = (int) $pdo->query('SELECT MAX(id) FROM leave_requests')->fetchColumn();
    $pdo->exec("CREATE TRIGGER fail_test_audit BEFORE INSERT ON attendance_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test audit failure'");
    try {
        review_leave_request($rollbackRequest, 'approved', $admin, null);
        throw new RuntimeException('Expected approval audit failure');
    } catch (PDOException $error) {
        $stmt = $pdo->prepare('SELECT status FROM leave_requests WHERE id=?');
        $stmt->execute([$rollbackRequest]);
        check($stmt->fetchColumn() === 'pending' && get_attendance($rollbackRemote,$today) === null, 'Failed WFH approval rolls back request and attendance together');
    }
    $pdo->exec('DROP TRIGGER fail_test_audit');

    // Exercise the CLI upgrade against the isolated DB with an old-schema record.
    $pdo->prepare("UPDATE attendance SET status='wfh',is_day_off=1 WHERE id=?")->execute([$legacyAtt['id']]);
    $pdo->exec('ALTER TABLE attendance DROP COLUMN is_wfh');
    ob_start();
    require __DIR__ . '/../database/migrate_wfh_work_mode.php';
    ob_end_clean();
    $repaired = get_attendance($legacyRemote,'2026-09-21');
    check((int) $repaired['is_wfh'] === 1 && !(int) $repaired['is_day_off'] && $repaired['status'] === 'incomplete', 'CLI migration repairs legacy WFH and preserves clock-in');
    ob_start();
    require __DIR__ . '/../database/migrate_wfh_work_mode.php';
    ob_end_clean();
    check(get_attendance($legacyRemote,'2026-09-21')['clock_in'] === '2026-09-21 10:00:59', 'WFH upgrade is safe to rerun');

    // Consolidating templates must preserve history, future off days and snapshots.
    $scheduledUser = test_user(3);
    $yesterday = now()->modify('-1 day')->format('Y-m-d');
    $tomorrow = now()->modify('+1 day')->format('Y-m-d');
    $pdo->prepare('INSERT INTO schedule_assignments (user_id,schedule_id,effective_from,effective_to,weekly_off_day) VALUES (?,1,?,?,3), (?,1,?,NULL,5)')
        ->execute([$scheduledUser, $yesterday, $today, $scheduledUser, $tomorrow]);
    $beforeAttendance = $pdo->query('SELECT * FROM attendance ORDER BY id')->fetchAll();
    $standardMigration = str_replace('USE staff_attendance;', 'USE `' . $database . '`;', file_get_contents(__DIR__ . '/../database/migration_standard_schedule.sql'));
    $pdo->exec($standardMigration);
    $standardId = standard_schedule_id();
    $afterAssignments = $pdo->query('SELECT * FROM schedule_assignments ORDER BY id')->fetchAll();
    $pdo->exec($standardMigration);
    check($afterAssignments === $pdo->query('SELECT * FROM schedule_assignments ORDER BY id')->fetchAll(), 'Standard migration is repeatable without extra assignments');
    check($beforeAttendance === $pdo->query('SELECT * FROM attendance ORDER BY id')->fetchAll(), 'Standard migration leaves every attendance snapshot unchanged');
    check((int) $pdo->query('SELECT COUNT(*) FROM work_schedules WHERE is_active=1')->fetchColumn() === 1, 'Only one active standard schedule');
    check(get_schedule_for_date($scheduledUser, $yesterday)['schedule_id'] === 1, 'Previous assignment preserved');
    check(get_schedule_for_date($scheduledUser, $today)['schedule_id'] === $standardId, 'Current assignment uses standard schedule');
    check(get_schedule_for_date($scheduledUser, $today)['weekly_off_day'] === 3, 'Current off day preserved');
    check(get_schedule_for_date($scheduledUser, $tomorrow)['weekly_off_day'] === 5, 'Future off day preserved');
    check(get_schedule_for_date($scheduledUser, $tomorrow)['schedule_id'] === $standardId, 'Future assignment uses standard schedule');
    $newStaff = test_user(2);
    assign_employee_schedule($newStaff, standard_schedule_id(), 2);
    check(get_schedule_for_date($newStaff, $today)['schedule_id'] === $standardId, 'New staff receives standard schedule');
    $seed = file_get_contents(__DIR__ . '/../database/seed.sql');
    preg_match('/INSERT INTO `work_schedules`.*?;\r?\n/s', $seed, $seedSchedule);
    $pdo->exec($seedSchedule[0]);
    $pdo->exec($seedSchedule[0]);
    check((int) $pdo->query("SELECT COUNT(*) FROM work_schedules WHERE name='LASTO Standard Schedule'")->fetchColumn() === 1, 'Repeating schedule setup does not create duplicates');

    foreach (['admin/reports.php','staff/attendance.php','staff/leave.php','admin/index.php','admin/attendance.php','admin/attendance_today.php','admin/attendance_detail.php',
        'admin/settings.php','admin/schedules.php','admin/staff_form.php','admin/corrections.php','admin/leave_requests.php',
        'staff/index.php','staff/schedule.php','staff/profile.php','admin/profile.php'] as $page) {
        $pageUser = str_starts_with($page, 'admin/') ? $admin : $remote;
        $recordId = $page === 'admin/staff_form.php' ? $concurrent : (int) $attendance['id'];
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, 'render', $database, (string) $pageUser, $page, (string) $recordId],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        fclose($pipes[0]);
        $html = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        check($exit === 0 && $errors === '' && !preg_match('/(?:Warning|Fatal error|Deprecated):/', $html), 'Clean page render: ' . $page . ' ' . $errors);
        check(str_contains($html, '</html>'), 'Complete page: ' . $page);
        check(!str_contains($html, 'punch-geo') && !str_contains($html, 'clock_location_hint'), 'No location UI: ' . $page);
    }
    $reviewId = (int) $offReview['id'];
    foreach ([0, 1, 2] as $owedDays) {
        if ($owedDays > 0) {
            $pdo->prepare("INSERT INTO staff_balance_ledger (employee_id,balance_type,amount,source_type,admin_id,admin_note) VALUES (?,'cuti_perlu_ganti',1,'manual',?,'Review scenario')")
                ->execute([$restUser, $admin]);
        }
        $html = render_offday_review($database, $admin);
        check(preg_match('/<tr id="decision-row-' . $reviewId . '".*?<\/tr>/s', $html, $matches) === 1, 'Pending off-day review renders at ' . $owedDays . ' owed');
        $reviewHtml = $matches[0];
        $recommended = $owedDays > 0 ? 'Settle 1 day owed' : 'Grant 1 day of Replacement Leave';
        check(str_contains($reviewHtml, 'Recommended: ' . $recommended), 'Recommendation follows ' . $owedDays . ' owed balance');
        check(str_contains($reviewHtml, 'data-owed="' . $owedDays . '"'), 'Balance preview uses current owed balance');
        check(str_contains($reviewHtml, 'Days to Replace: ' . $owedDays . ' to ' . ($owedDays - 1)) === ($owedDays > 0), 'Settlement option eligibility at ' . $owedDays . ' owed');
        check(str_contains($reviewHtml, 'name="decision" value="no_adjustment"'), 'No adjustment remains available');
        check(!preg_match('/name="decision" value="[^"]+" checked/', $reviewHtml), 'No adjustment is preselected');
    }
    rejected(fn() => decide_offday_adjustment($reviewId, 'no_adjustment', $admin, ''), 'No adjustment requires a reason');
    decide_offday_adjustment($reviewId, 'no_adjustment', $admin, 'Reviewed attendance; no balance change applies.');
    check(balance_total($restUser, 'cuti_perlu_ganti') === 2 && balance_total($restUser, 'replacement_off') === 0, 'No adjustment preserves both balances');
    check((string) $pdo->query('SELECT status FROM offday_adjustment_reviews WHERE id=' . $reviewId)->fetchColumn() === 'decided', 'No adjustment records admin decision');
    echo "PASS: {$checks} policy, integrity and concurrency checks.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec('USE `' . DB_NAME . '`');
    $pdo->exec('DROP DATABASE `' . $database . '`');
}
