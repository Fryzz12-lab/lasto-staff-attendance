<?php
/**
 * Shared helper functions
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function normalize_username(string $value): string
{
    return mb_strtolower((string) preg_replace('/\s/u', '_', trim($value)), 'UTF-8');
}

function normalize_full_name(string $value): string
{
    return mb_strtoupper(trim($value), 'UTF-8');
}

function valid_account_email(string $value): bool
{
    if (str_contains($value, ' ') || str_contains($value, '..')) {
        return false;
    }
    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

function normalize_account_phone(string $value): string
{
    return ltrim((string) preg_replace('/[^0-9]/', '', $value), '0');
}

function valid_account_phone(string $value): bool
{
    if ($value === '') return true;
    return preg_match('/\A[1-9][0-9]{8,9}\z/', $value) === 1;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): void
{
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        header('Location: ' . $path);
    } else {
        header('Location: ' . BASE_PATH . $path);
    }
    exit;
}

function url(string $path = ''): string
{
    if ($path === '' || $path === '/') {
        return BASE_PATH . '/';
    }
    return BASE_PATH . (str_starts_with($path, '/') ? $path : '/' . $path);
}

function absolute_url(string $path = ''): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . url($path);
}

function current_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? (BASE_PATH . '/');
    return $scheme . '://' . $host . $uri;
}

function asset(string $path): string
{
    $relative = '/assets/' . ltrim($path, '/');
    $file = __DIR__ . '/..' . $relative;
    $version = is_file($file) ? (string) filemtime($file) : APP_VERSION;
    return url($relative) . '?v=' . rawurlencode($version);
}

function flash_set(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function flash_get(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $messages;
}

function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur'));
}

function today_str(): string
{
    return now()->format('Y-m-d');
}

function format_time(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($datetime, new DateTimeZone('Asia/Kuala_Lumpur'));
        return $dt->format('g:i A');
    } catch (Exception $e) {
        return '—';
    }
}

function format_datetime(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($datetime, new DateTimeZone('Asia/Kuala_Lumpur'));
        return $dt->format('d M Y, g:i A');
    } catch (Exception $e) {
        return '—';
    }
}

function format_date(?string $date): string
{
    if ($date === null || $date === '') {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($date, new DateTimeZone('Asia/Kuala_Lumpur'));
        return $dt->format('d/m/Y');
    } catch (Exception $e) {
        return '—';
    }
}

/**
 * HTML lang for day/month/year locales (browser date UI fallback).
 */
function html_lang(): string
{
    return match (lang_current()) {
        'zh' => 'zh-HK',
        'ms' => 'ms-MY',
        default => 'en-GB',
    };
}

function format_minutes(int $minutes): string
{
    if ($minutes < 0) {
        $minutes = 0;
    }
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    $uh = function_exists('__') ? __('unit_h') : 'h';
    $um = function_exists('__') ? __('unit_m') : 'm';
    if ($h > 0) {
        return sprintf('%d%s %02d%s', $h, $uh, $m, $um);
    }
    return sprintf('%d%s', $m, $um);
}

/** Parse datetime in Asia/Kuala_Lumpur. */
function parse_kl_datetime(string $datetime): ?DateTimeImmutable
{
    try {
        return new DateTimeImmutable($datetime, new DateTimeZone('Asia/Kuala_Lumpur'));
    } catch (Exception $e) {
        return null;
    }
}

/** ISO-8601 with +08:00 for reliable browser Date.parse. */
function datetime_iso_kl(string $datetime): string
{
    $dt = parse_kl_datetime($datetime);
    return $dt ? $dt->format('c') : $datetime;
}

/** Elapsed whole minutes from start until now (or until $end). */
function elapsed_minutes_since(string $start, ?string $end = null): int
{
    $startDt = parse_kl_datetime($start);
    if (!$startDt) {
        return 0;
    }
    $endDt = $end ? parse_kl_datetime($end) : now();
    if (!$endDt) {
        $endDt = now();
    }
    $secs = $endDt->getTimestamp() - $startDt->getTimestamp();
    return max(0, intdiv($secs, 60));
}

/**
 * Duration cell: completed = static; open = live-updating span.
 */
function break_duration_html(?string $startTime, ?string $endTime, int $storedDuration): string
{
    if ($endTime !== null && $endTime !== '') {
        return e(format_minutes($storedDuration));
    }
    if ($startTime === null || $startTime === '') {
        return e(format_minutes(0));
    }
    $mins = elapsed_minutes_since($startTime);
    return '<span data-live-start="' . e(datetime_iso_kl($startTime)) . '">'
        . e(format_minutes($mins))
        . '</span>';
}

/**
 * Total minutes that may include an open break (live).
 */
function break_total_live_html(int $completedMinutes, ?string $openStart): string
{
    if ($openStart === null || $openStart === '') {
        return e(format_minutes($completedMinutes));
    }
    $mins = $completedMinutes + elapsed_minutes_since($openStart);
    return '<span data-live-start="' . e(datetime_iso_kl($openStart)) . '" data-live-base="' . (int) $completedMinutes . '">'
        . e(format_minutes($mins))
        . '</span>';
}

/**
 * Live net working minutes while still clocked in:
 * (now - clock_in) - (completed breaks + open break elapsed).
 * Finalized after clock out. Base minutes can include other days in a weekly total.
 */
function attendance_live_work_html(
    ?string $clockIn,
    ?string $clockOut,
    int $storedWorkMinutes,
    int $completedBreakMinutes,
    ?string $openBreakStart,
    int $baseWorkMinutes = 0
): string {
    if ($clockIn === null || $clockIn === '') {
        return e(format_minutes($baseWorkMinutes));
    }
    if ($clockOut !== null && $clockOut !== '') {
        return e(format_minutes($baseWorkMinutes + $storedWorkMinutes));
    }

    $elapsed = elapsed_minutes_since($clockIn);
    $breaks = $completedBreakMinutes + ($openBreakStart ? elapsed_minutes_since($openBreakStart) : 0);
    $work = $baseWorkMinutes + max(0, $elapsed - $breaks);

    $attrs = 'data-live-work'
        . ' data-work-base="' . (int) $baseWorkMinutes . '"'
        . ' data-clock-in="' . e(datetime_iso_kl($clockIn)) . '"'
        . ' data-break-base="' . (int) $completedBreakMinutes . '"';
    if ($openBreakStart) {
        $attrs .= ' data-break-open="' . e(datetime_iso_kl($openBreakStart)) . '"';
    }

    return '<span ' . $attrs . '>' . e(format_minutes($work)) . '</span>';
}

/**
 * Breaks cell for attendance lists (includes open break live).
 */
function attendance_live_breaks_html(int $completedBreakMinutes, ?string $openBreakStart): string
{
    return break_total_live_html($completedBreakMinutes, $openBreakStart);
}

function weekday_name(int $day, string $lang = 'en'): string
{
    $names = [
        'en' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        'zh' => ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'],
        'ms' => ['Ahad', 'Isnin', 'Selasa', 'Rabu', 'Khamis', 'Jumaat', 'Sabtu'],
    ];
    $list = $names[$lang] ?? $names['en'];
    return $list[$day] ?? '';
}

function status_badge(string $status): string
{
    $classMap = [
        'present'        => 'badge-present',
        'late'           => 'badge-late',
        'on_break'       => 'badge-break',
        'completed'      => 'badge-completed',
        'absent'         => 'badge-absent',
        'day_off'        => 'badge-off',
        'wfh'            => 'badge-wfh',
        'not_clocked_in' => 'badge-notclocked',
        'incomplete'     => 'badge-incomplete',
        'pending'        => 'badge-pending',
        'approved'       => 'badge-completed',
        'rejected'       => 'badge-absent',
    ];
    $label = status_label($status);
    $class = $classMap[$status] ?? 'badge-pending';
    return '<span class="status-badge ' . e($class) . '">' . e($label) . '</span>';
}

function status_label(string $status): string
{
    $labelKey = 'st_' . $status;
    $label = function_exists('__') ? __($labelKey) : ucfirst(str_replace('_', ' ', $status));
    if ($label === $labelKey) {
        $label = ucfirst(str_replace('_', ' ', $status));
    }
    return $label;
}

function attendance_status_badge(array $attendance): string
{
    $html = status_badge($attendance['status']);
    if (!empty($attendance['is_wfh']) && $attendance['status'] !== 'wfh') {
        $html .= ' <span class="status-badge badge-wfh" title="' . e(__('leave_wfh')) . '">' . e(__('st_wfh')) . '</span>';
    }
    return $html;
}

function attendance_work_arrangement(array $attendance): string
{
    if (!empty($attendance['is_day_off'])) return '-';
    return !empty($attendance['is_wfh']) ? 'WFH' : '-';
}

/** Allocate a stable sequential employee ID within the staff creation transaction. */
function generate_employee_id(): string
{
    $pdo = db();
    if (!$pdo->inTransaction()) {
        throw new LogicException('Employee IDs must be allocated inside a transaction.');
    }

    // The unique settings row serializes concurrent staff creations.
    $pdo->exec("INSERT INTO system_settings (setting_key, setting_value, setting_group)
        VALUES ('employee_id_sequence', '0', 'internal')
        ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)");
    $counter = $pdo->query("SELECT setting_value FROM system_settings
        WHERE setting_key='employee_id_sequence' FOR UPDATE")->fetchColumn();
    $existingMax = $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(employee_id, 4) AS UNSIGNED)), 0)
        FROM users WHERE employee_id REGEXP '^EMP[0-9]+$'")->fetchColumn();
    $last = max((int) $counter, (int) $existingMax);
    if ($last === PHP_INT_MAX) {
        throw new OverflowException('Employee ID sequence exhausted.');
    }
    $next = $last + 1;
    $pdo->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key='employee_id_sequence'")
        ->execute([(string) $next]);

    return 'EMP' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
}

function get_setting(string $key, ?string $default = null, bool $fresh = false): ?string
{
    static $cache = null;
    if ($fresh) {
        $cache = null;
    }
    if ($cache === null) {
        $cache = [];
        try {
            $stmt = db()->query('SELECT setting_key, setting_value FROM system_settings');
            foreach ($stmt->fetchAll() as $row) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $e) {
            return $default;
        }
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $value]);
    get_setting('', null, true);
}

function setting_bool(string $key, bool $default = false): bool
{
    $val = get_setting($key);
    if ($val === null) {
        return $default;
    }
    return in_array(strtolower($val), ['1', 'true', 'yes', 'on'], true);
}

function setting_int(string $key, int $default = 0): int
{
    $val = get_setting($key);
    return $val === null ? $default : (int) $val;
}

function post(string $key, mixed $default = null): mixed
{
    return $_POST[$key] ?? $default;
}

function get(string $key, mixed $default = null): mixed
{
    return $_GET[$key] ?? $default;
}

function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function company_name(): string
{
    return get_setting('company_name', APP_NAME) ?? APP_NAME;
}

/**
 * Render pagination links matching the Reports page style.
 *
 * @param int   $total   Total number of records matching the current filters.
 * @param int   $page    Current page number (1-indexed).
 * @param int   $perPage Records shown per page.
 * @param array $params  Query string params to carry forward (filters, date range, etc.).
 * @return string        HTML string. Empty string when there is only one page.
 */
function pagination_links(int $total, int $page, int $perPage, array $params = [], string $baseUrl = ''): string
{
    if ($perPage <= 0 || $total <= $perPage) {
        return '';
    }
    $pages = (int) ceil($total / $perPage);
    if ($pages <= 1) {
        return '';
    }
    $page = max(1, min($page, $pages));

    $buildUrl = function (int $p) use ($params, $baseUrl): string {
        $path = $baseUrl !== '' ? $baseUrl : (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
        $query = http_build_query(array_merge($params, ['page' => $p]));
        return $path . ($query !== '' ? '?' . $query : '');
    };

    // Calculate record range
    $fromRecord = ($page - 1) * $perPage + 1;
    $toRecord   = min($total, $page * $perPage);
    $fromFmt    = number_format($fromRecord);
    $toFmt      = number_format($toRecord);
    $totalFmt   = number_format($total);

    // Compute visible page numbers (standard algorithm)
    $visiblePages = $pages <= 7
        ? range(1, $pages)
        : array_values(array_unique(array_merge(
            [1, $pages],
            range(max(1, $page - 2), min($pages, $page + 2))
        )));
    sort($visiblePages);

    $summaryDesktop = e(__('showing_records_range', ['from' => $fromFmt, 'to' => $toFmt, 'total' => $totalFmt]));
    $summaryMobile  = e(__('showing_records_range_short', ['from' => $fromFmt, 'to' => $toFmt, 'total' => $totalFmt]));

    $html  = '<nav class="report-pagination no-print" aria-label="' . e(__('pagination')) . '">';
    $html .= '<span class="form-hint pagination-summary">';
    $html .= '<span class="d-none d-sm-inline">' . $summaryDesktop . '</span>';
    $html .= '<span class="d-sm-none">' . $summaryMobile . '</span>';
    $html .= '</span>';

    // Desktop controls (>= 576px)
    $html .= '<div class="report-pagination-links d-none d-sm-flex">';

    // Previous button
    if ($page > 1) {
        $html .= '<a class="btn btn-sm btn-outline-secondary pagination-btn-nav" href="' . e($buildUrl($page - 1)) . '" aria-label="' . e(__('previous')) . '">' . e(__('previous')) . '</a>';
    } else {
        $html .= '<span class="btn btn-sm btn-outline-secondary disabled pagination-btn-nav" aria-disabled="true" aria-label="' . e(__('previous')) . '">' . e(__('previous')) . '</span>';
    }

    // Page number buttons with ellipsis
    $priorPage = 0;
    foreach ($visiblePages as $vp) {
        if ($priorPage > 0 && $vp > $priorPage + 1) {
            $html .= '<span class="report-pagination-ellipsis" aria-hidden="true">&hellip;</span>';
        }
        if ($vp === $page) {
            $html .= '<span class="btn btn-sm btn-accent report-pagination-current" aria-current="page">' . $vp . '</span>';
        } else {
            $html .= '<a class="btn btn-sm btn-outline-secondary" href="' . e($buildUrl($vp)) . '" aria-label="' . e(__('page_number', ['page' => $vp])) . '">' . $vp . '</a>';
        }
        $priorPage = $vp;
    }

    // Next button
    if ($page < $pages) {
        $html .= '<a class="btn btn-sm btn-outline-secondary pagination-btn-nav" href="' . e($buildUrl($page + 1)) . '" aria-label="' . e(__('next')) . '">' . e(__('next')) . '</a>';
    } else {
        $html .= '<span class="btn btn-sm btn-outline-secondary disabled pagination-btn-nav" aria-disabled="true" aria-label="' . e(__('next')) . '">' . e(__('next')) . '</span>';
    }

    $html .= '</div>';

    // Mobile controls (< 576px)
    $html .= '<div class="report-pagination-mobile d-flex d-sm-none align-items-center justify-content-between gap-2 w-100">';

    if ($page > 1) {
        $html .= '<a class="btn btn-sm btn-outline-secondary pagination-mobile-btn" href="' . e($buildUrl($page - 1)) . '" aria-label="' . e(__('previous')) . '">&larr; ' . e(__('previous')) . '</a>';
    } else {
        $html .= '<span class="btn btn-sm btn-outline-secondary disabled pagination-mobile-btn" aria-disabled="true" aria-label="' . e(__('previous')) . '">&larr; ' . e(__('previous')) . '</span>';
    }

    $html .= '<span class="pagination-mobile-indicator font-mono fw-bold text-light-emphasis">' . $page . ' / ' . $pages . '</span>';

    if ($page < $pages) {
        $html .= '<a class="btn btn-sm btn-outline-secondary pagination-mobile-btn" href="' . e($buildUrl($page + 1)) . '" aria-label="' . e(__('next')) . '">' . e(__('next')) . ' &rarr;</a>';
    } else {
        $html .= '<span class="btn btn-sm btn-outline-secondary disabled pagination-mobile-btn" aria-disabled="true" aria-label="' . e(__('next')) . ' &rarr;">' . e(__('next')) . ' &rarr;</span>';
    }

    $html .= '</div>';

    $html .= '</nav>';

    return $html;
}

if (!function_exists('greeting_display_name')) {
    function greeting_display_name(string $fullName): string
    {
        $clean = trim($fullName);
        if ($clean === '') return 'there';
        if (mb_strtoupper($clean, 'UTF-8') === 'ADMIN') {
            return 'Admin';
        }
        $title = mb_convert_case($clean, MB_CASE_TITLE, 'UTF-8');
        $parts = preg_split('/\s+/u', $title);
        if (count($parts) >= 2) {
            return $parts[0] . ' ' . $parts[1];
        }
        return $parts[0];
    }
}
