<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/attendance_service.php';

$pdo = db();
$today = today_str();
$ids = $pdo->query("SELECT id FROM users WHERE email LIKE '%@local.invalid' ORDER BY id DESC LIMIT 1000")
    ->fetchAll(PDO::FETCH_COLUMN);
$select = $pdo->prepare('SELECT id, user_id, is_day_off FROM attendance WHERE user_id=? AND work_date=?');
$clearBreaks = $pdo->prepare('DELETE FROM break_records WHERE attendance_id=?');
$update = $pdo->prepare('UPDATE attendance SET clock_in=?,clock_out=NULL WHERE id=?');
$pdo->beginTransaction();
try {
    foreach ($ids as $index => $id) {
        $select->execute([(int)$id,$today]);
        $att = $select->fetch(PDO::FETCH_ASSOC);
        if (!$att || (int)$att['is_day_off']) continue;
        $clearBreaks->execute([(int)$att['id']]);
        $minutes = 20 + ($index % 90);
        $in = now()->format('H:i') >= '10:00' && $index % 11 !== 1
            ? now()->modify('-'.$minutes.' minutes')->format('Y-m-d H:i:s') : null;
        $update->execute([$in,(int)$att['id']]);
        reconcile_attendance_day_status((int)$id,$today);
        recalculate_attendance((int)$att['id']);
    }
    $pdo->commit();
    echo 'Updated current-day fixture attendance for '.count($ids).' accounts.'.PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
