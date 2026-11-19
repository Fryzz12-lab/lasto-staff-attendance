<?php
/**
 * Daily attendance catch-up — CLI only.
 *
 * Usage:
 *   php cli/daily_attendance.php                         # processes yesterday
 *   php cli/daily_attendance.php --date 2026-09-20       # processes one specific date
 *   php cli/daily_attendance.php --from 2026-09-01 --to 2026-09-25  # catch-up range
 *
 * Schedule (Windows Task Scheduler):
 *   Program : C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\agy-staff-attendance\cli\daily_attendance.php
 *   Trigger : Daily at 00:05 AM
 *
 * This script is idempotent — running it multiple times for the same date
 * is safe and will not create duplicate records.
 */

declare(strict_types=1);

// Must be run from CLI, not via a web request.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line.' . PHP_EOL);
}

// Bootstrap — loads DB, helpers, attendance_service, etc.
require_once __DIR__ . '/../includes/bootstrap.php';

// ── Parse CLI arguments ──────────────────────────────────────────────────────

$opts = getopt('', ['date:', 'from:', 'to:']);

$today     = today_str();
$yesterday = now()->modify('-1 day')->format('Y-m-d');

$singleDate = isset($opts['date']) ? trim((string) $opts['date']) : null;
$fromDate   = isset($opts['from']) ? trim((string) $opts['from']) : null;
$toDate     = isset($opts['to'])   ? trim((string) $opts['to'])   : null;

// Validate date helper
$parseDate = function (string $value, string $label): string {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) {
        fwrite(STDERR, "Error: {$label} must be in YYYY-MM-DD format. Got: {$value}" . PHP_EOL);
        exit(1);
    }
    return $value;
};

// Determine the date range to process
if ($singleDate !== null) {
    $from = $to = $parseDate($singleDate, '--date');
} elseif ($fromDate !== null || $toDate !== null) {
    if ($fromDate === null || $toDate === null) {
        fwrite(STDERR, 'Error: --from and --to must be used together.' . PHP_EOL);
        exit(1);
    }
    $from = $parseDate($fromDate, '--from');
    $to   = $parseDate($toDate,   '--to');
    if ($to < $from) {
        fwrite(STDERR, 'Error: --to cannot be before --from.' . PHP_EOL);
        exit(1);
    }
} else {
    // Default: process yesterday (the previous calendar day)
    $from = $to = $yesterday;
}

// Safety: never process future dates
if ($to > $today) {
    $to = $today;
}
if ($from > $today) {
    echo 'Nothing to process — all requested dates are in the future.' . PHP_EOL;
    exit(0);
}

// ── Processing ───────────────────────────────────────────────────────────────

$start     = new DateTimeImmutable($from, new DateTimeZone('Asia/Kuala_Lumpur'));
$end       = new DateTimeImmutable($to,   new DateTimeZone('Asia/Kuala_Lumpur'));
$totalDays = 0;
$totalRows = 0;
$errors    = 0;

echo sprintf(
    '[%s] daily_attendance — processing %s to %s' . PHP_EOL,
    now()->format('Y-m-d H:i:s'),
    $from,
    $to
);

for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
    $dateStr = $date->format('Y-m-d');
    try {
        $created = ensure_attendance_rows_for_date($dateStr);
        $totalRows += $created;
        $totalDays++;
        if ($created > 0) {
            echo "  {$dateStr}: {$created} row(s) created." . PHP_EOL;
        } else {
            echo "  {$dateStr}: no new rows (already up to date)." . PHP_EOL;
        }
    } catch (Throwable $e) {
        $errors++;
        fwrite(STDERR, "  {$dateStr}: ERROR — " . $e->getMessage() . PHP_EOL);
    }
}

// Mark any past records that have a clock-in but no clock-out as incomplete
try {
    $maintenanceErrors = mark_incomplete_attendances();
    $errors += $maintenanceErrors;
    echo 'Incomplete attendances marked.' . PHP_EOL;
    if ($maintenanceErrors > 0) {
        fwrite(STDERR, "  {$maintenanceErrors} per-record maintenance failure(s) were logged." . PHP_EOL);
    }
} catch (Throwable $e) {
    $errors++;
    fwrite(STDERR, 'mark_incomplete_attendances failed: ' . $e->getMessage() . PHP_EOL);
}

// ── Summary ──────────────────────────────────────────────────────────────────

echo sprintf(
    '[%s] Done — %d day(s) processed, %d row(s) created, %d error(s).' . PHP_EOL,
    now()->format('Y-m-d H:i:s'),
    $totalDays,
    $totalRows,
    $errors
);

exit($errors > 0 ? 1 : 0);
