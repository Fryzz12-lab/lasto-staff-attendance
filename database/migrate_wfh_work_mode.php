<?php
/** CLI upgrade: php database/migrate_wfh_work_mode.php. Keeps all punches and requests. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/attendance_service.php';
$pdo = db();
if (!$pdo->query("SHOW COLUMNS FROM attendance LIKE 'is_wfh'")->fetch()) {
    $pdo->exec("ALTER TABLE attendance ADD COLUMN is_wfh TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Approved work arrangement' AFTER is_day_off");
}
$pairs = $pdo->query("SELECT DISTINCT user_id,leave_date FROM leave_requests WHERE leave_type='wfh' AND status='approved'")->fetchAll();
$pdo->beginTransaction();
$reconciled = 0;
try {
    foreach ($pairs as $pair) {
        $userId = (int) $pair['user_id'];
        $date = $pair['leave_date'];
        if ($date > today_str()) continue;
        if (get_attendance($userId, $date)) {
            reconcile_attendance_day_status($userId, $date);
        } elseif (attendance_date_eligible($userId, $date)) {
            ensure_attendance_row($userId, $date);
        } else {
            continue;
        }
        $reconciled++;
    }
    $pdo->commit();
    echo 'WFH upgrade complete; reconciled ' . $reconciled . " approved dates.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
