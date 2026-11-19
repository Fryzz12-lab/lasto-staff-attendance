<?php
/**
 * Create the first production administrator. CLI only.
 *
 * php cli/create_admin.php --username=admin --name='Full Name' --email=admin@lastotechnology.my
 * The password is read from standard input to keep it out of shell history and process arguments.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--password')) {
        fwrite(STDERR, "Security Error: Do not pass passwords via command-line arguments. Passwords must be entered securely via the interactive prompt.\n");
        exit(1);
    }
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/password_policy.php';

$opts = getopt('', ['username:', 'name:', 'email:', 'employee-id:']);
$username = normalize_username((string) ($opts['username'] ?? ''));
$password = '';
$name = normalize_full_name((string) ($opts['name'] ?? ''));
$email = trim((string) ($opts['email'] ?? ''));
$employeeId = trim((string) ($opts['employee-id'] ?? 'EMP001'));

if ($username === '' || $name === '') {
    fwrite(STDERR, "Usage: php cli/create_admin.php --username=admin --name='Full Name' [--email=admin@lastotechnology.my] [--employee-id=EMP001]\nPassword is read from standard input.\n");
    exit(1);
}
$hasTty = function_exists('posix_isatty') && posix_isatty(STDIN);
if ($hasTty) { system('stty -echo'); }
fwrite(STDERR, "Administrator password: ");
$password = rtrim((string) fgets(STDIN), "\r\n");
if ($hasTty) { system('stty echo'); fwrite(STDERR, PHP_EOL); }
if (($policy = password_policy_error($password)) !== null) {
    fwrite(STDERR, "Password rejected: {$policy}\n");
    exit(1);
}
if ($email !== '' && !valid_account_email($email)) {
    fwrite(STDERR, "Email is not valid.\n");
    exit(1);
}

$existing = (int) db()->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
if ($existing > 0) {
    fwrite(STDERR, "An administrator already exists. Refusing to create another from CLI.\n");
    exit(1);
}

try {
    db()->prepare(
        'INSERT INTO users (employee_id, username, password_hash, full_name, email, role, joining_date, is_active, must_change_password, weekly_off_day)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0, 0)'
    )->execute([
        $employeeId,
        $username,
        hash_new_password($password),
        $name,
        $email !== '' ? $email : null,
        'admin',
        today_str(),
    ]);
} catch (PDOException $error) {
    fwrite(STDERR, "Could not create administrator (duplicate username, email, or employee ID).\n");
    exit(1);
}

echo 'Administrator created: ' . $username . PHP_EOL;
exit(0);
