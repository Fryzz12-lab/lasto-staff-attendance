<?php
/**
 * Admin live workforce news ticker (today)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => __('msg_session_api')], 401);
}

if ((current_user()['role'] ?? '') !== 'admin') {
    json_response(['success' => false, 'message' => 'Forbidden'], 403);
}

// This endpoint only reads session data. Let other requests from this browser
// run while the ticker queries the workforce tables.
session_write_close();

$date = today_str();
$pdo = db();
$items = [];

$sql = "SELECT a.clock_in, a.clock_out, a.status, a.late_minutes, u.full_name,
               ob.start_time AS open_break_start
        FROM attendance a
        JOIN users u ON u.id = a.user_id
        LEFT JOIN break_records ob ON ob.attendance_id = a.id AND ob.end_time IS NULL
        WHERE a.work_date = ? AND u.role = 'staff' AND u.is_active = 1
        ORDER BY a.clock_in DESC, u.full_name";
$stmt = $pdo->prepare($sql);
$stmt->execute([$date]);
$rows = $stmt->fetchAll();

$working = 0;
$onBreak = 0;
$late = 0;
$notIn = 0;
$clockInShown = 0;

foreach ($rows as $r) {
    $name = (string) ($r['full_name'] ?? '');
    $st = (string) ($r['status'] ?? '');

    if ($st === 'not_clocked_in') {
        $notIn++;
    }

    if (!empty($r['open_break_start'])) {
        $onBreak++;
        if ($name !== '') {
            $items[] = [
                'kind' => 'on_break',
                'text' => __('ticker_on_break', [
                    'name' => $name,
                    'time' => format_time((string) $r['open_break_start']),
                ]),
            ];
        }
        continue;
    }

    if (!empty($r['clock_in']) && empty($r['clock_out'])) {
        $working++;
        if ($name !== '' && $clockInShown < 6) {
            $clockInShown++;
            $items[] = [
                'kind' => 'clock_in',
                'text' => __('ticker_clocked_in', [
                    'name' => $name,
                    'time' => format_time((string) $r['clock_in']),
                ]),
            ];
        }
    }

    if (((int) ($r['late_minutes'] ?? 0) > 0 || $st === 'late') && $name !== '' && !empty($r['clock_in'])) {
        $late++;
        $items[] = [
            'kind' => 'late',
            'text' => __('ticker_late', [
                'name' => $name,
                'mins' => (string) max(1, (int) $r['late_minutes']),
            ]),
        ];
    }
}

$items[] = [
    'kind' => 'summary',
    'text' => __('ticker_summary', [
        'work' => (string) $working,
        'break' => (string) $onBreak,
        'late' => (string) $late,
    ]),
];

if ($notIn > 0) {
    $items[] = [
        'kind' => 'alert',
        'text' => __('ticker_not_in', ['n' => (string) $notIn]),
    ];
}

if (setting_bool('enable_leave_module', true)) {
    $leaveCnt = (int) $pdo->query(
        "SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'"
    )->fetchColumn();
    if ($leaveCnt > 0) {
        $leaveStmt = $pdo->query(
            "SELECT u.full_name, lr.leave_type, lr.leave_date
             FROM leave_requests lr
             JOIN users u ON u.id = lr.user_id
             WHERE lr.status = 'pending'
             ORDER BY lr.created_at DESC
             LIMIT 5"
        );
        $typeMap = [
            'medical' => __('leave_medical'),
            'wfh' => __('leave_wfh'),
            'day_off' => __('leave_day_off'),
            'annual' => __('leave_annual'),
            'emergency' => __('leave_emergency'),
            'other' => __('leave_other'),
        ];
        foreach ($leaveStmt->fetchAll() as $lr) {
            $type = (string) $lr['leave_type'];
            $items[] = [
                'kind' => 'leave',
                'text' => __('ticker_leave_pending', [
                    'name' => (string) $lr['full_name'],
                    'type' => $typeMap[$type] ?? $type,
                    'date' => format_date((string) $lr['leave_date']),
                ]),
            ];
        }
        $items[] = [
            'kind' => 'alert',
            'text' => __('ticker_leave_count', ['n' => (string) $leaveCnt]),
        ];
    }
}

$incomplete = (int) $pdo->query(
    "SELECT COUNT(*) FROM attendance a
     JOIN users u ON u.id = a.user_id
     WHERE u.role = 'staff' AND u.is_active = 1 AND a.status = 'incomplete'"
)->fetchColumn();
if ($incomplete > 0) {
    $items[] = [
        'kind' => 'alert',
        'text' => __('ticker_incomplete', ['n' => (string) $incomplete]),
    ];
}

if (count($items) < 2) {
    $items[] = [
        'kind' => 'info',
        'text' => __('ticker_ops_online'),
    ];
}

// Keep the ticker short enough to refresh visibly. The summary is always kept.
$summaryItems = array_values(array_filter($items, static fn(array $item): bool => ($item['kind'] ?? '') === 'summary'));
$otherItems = array_values(array_filter($items, static fn(array $item): bool => ($item['kind'] ?? '') !== 'summary'));
$items = array_merge(array_slice($otherItems, 0, 5), array_slice($summaryItems, 0, 1));

json_response([
    'success' => true,
    'date' => $date,
    'items' => array_values($items),
]);
