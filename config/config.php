<?php
/**
 * Application configuration
 * Staff Attendance & Workforce Management System
 */

declare(strict_types=1);

$lastoLocalFile = __DIR__ . '/local.php';
$lastoConfigPath = strtolower(str_replace('\\', '/', __DIR__));
$lastoRunningInXampp = PHP_OS_FAMILY === 'Windows' && str_contains($lastoConfigPath, '/xampp/htdocs/');
// A copied cPanel local.php must not replace the local XAMPP database and URL.
// Keep the file in place so the same project archive can still be uploaded.
if (!$lastoRunningInXampp && is_file($lastoLocalFile)) {
    $lastoLocal = require $lastoLocalFile;
    if (is_array($lastoLocal)) {
        foreach ($lastoLocal as $name => $value) {
            if (!is_string($name) || (!is_string($value) && !is_int($value) && !is_bool($value))) {
                continue;
            }
            $stringValue = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            putenv($name . '=' . $stringValue);
            $_ENV[$name] = $stringValue;
            $_SERVER[$name] = $stringValue;
        }
    }
}

// Environment: 'development' | 'production'
define('APP_ENV', getenv('LASTO_APP_ENV') ?: 'development');
define('APP_NAME', 'Staff Attendance System');
define('APP_VERSION', '1.0.0');

// Company policy: fixed hours, six days with one individually arranged off day.
define('POLICY_START_TIME', '10:00:00');
define('POLICY_END_TIME', '20:00:00');
define('POLICY_TARGET_MINUTES', 450);

// Base URL path under the web root (no trailing slash). Empty string = domain root.
$lastoBasePath = getenv('LASTO_BASE_PATH');
if ($lastoBasePath === false) {
    $lastoBasePath = APP_ENV === 'production' ? '' : '/agy-staff-attendance';
}
define('BASE_PATH', rtrim((string) $lastoBasePath, '/'));

// Timezone — Malaysia
date_default_timezone_set('Asia/Kuala_Lumpur');

// Database
define('DB_HOST', getenv('LASTO_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('LASTO_DB_PORT') ?: '3306');
define('DB_NAME', getenv('LASTO_DB_NAME') ?: 'staff_attendance');
define('DB_USER', getenv('LASTO_DB_USER') ?: 'root');
define('DB_PASS', getenv('LASTO_DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// Sessions
define('SESSION_NAME', 'SAS_SESSION');
define('SESSION_TIMEOUT_MINUTES', 480);

// Uploads
define('UPLOAD_DIR', __DIR__ . '/../uploads/profiles');
define('UPLOAD_URL', BASE_PATH . '/uploads/profiles');
define('MAX_UPLOAD_BYTES', 2 * 1024 * 1024); // 2MB

// Security
define('CSRF_TOKEN_KEY', '_csrf_token');

// Error display
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
