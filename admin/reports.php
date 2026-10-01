<?php
/**
 * Admin — Reports + CSV / Excel / PDF export
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/report_export.php';
require_admin();

$report = (string) get('report', 'daily');
$from = (string) get('from', now()->modify('-7 days')->format('Y-m-d'));
$to = (string) get('to', today_str());
$userId = (int) get('user_id', 0);
$export = strtolower((string) get('export', ''));
if (!in_array($export, ['csv', 'excel', 'pdf', 'print', ''], true)) {
    $export = '';
}

$staffList = db()->query("SELECT id, full_name FROM users WHERE role='staff' ORDER BY full_name")->fetchAll();

$pageSize = 25;
$page = max(1, (int) get('page', 1));
$userFilter = '';
$userParams = [];
if ($userId > 0) {
    $userFilter = ' AND a.user_id = ?';
    $userParams[] = $userId;
}

ensure_attendance_rows_for_range($from, $to);
$reportCondition = match ($report) {
    'late' => " AND (a.late_minutes > 0 OR a.status = 'late')",
    'incomplete' => " AND (a.status = 'incomplete' OR (a.clock_in IS NOT NULL AND a.clock_out IS NULL AND a.work_date < CURDATE()))",
    'leave' => " AND (a.is_day_off = 1 OR a.status = 'day_off')",
    'wfh' => ' AND a.is_wfh = 1',
    default => '',
};
$baseParams = [$from, $to, ...$userParams];
$baseWhere = "a.work_date BETWEEN ? AND ? AND u.role='staff'
    AND (u.joining_date IS NULL OR u.joining_date <= a.work_date)
    AND (u.is_active = 1 OR a.work_date < CURDATE()) {$userFilter}{$reportCondition}";

$countStmt = db()->prepare("SELECT COUNT(*) FROM attendance a JOIN users u ON u.id=a.user_id WHERE {$baseWhere}");
$countStmt->execute($baseParams);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $pageSize));
$page = min($page, $totalPages);

if ($report === 'late') {
    $lateStmt = db()->prepare("SELECT
        COUNT(*) AS total_late,
        COALESCE(SUM(a.late_minutes), 0) AS total_late_minutes,
        COUNT(DISTINCT a.user_id) AS unique_users,
        COALESCE(AVG(NULLIF(a.late_minutes, 0)), 0) AS avg_late_minutes
        FROM attendance a JOIN users u ON u.id=a.user_id WHERE {$baseWhere}");
    $lateStmt->execute($baseParams);
    $lateData = $lateStmt->fetch() ?: [];
    $summaryCardsList = [
        ['label' => __('late_incidents'), 'value' => number_format((int)($lateData['total_late'] ?? 0)), 'mod' => 'report-summary-card--late'],
        ['label' => __('total_late_time'), 'value' => format_minutes((int)($lateData['total_late_minutes'] ?? 0)), 'mod' => 'report-summary-card--hours'],
        ['label' => __('employees_late'), 'value' => number_format((int)($lateData['unique_users'] ?? 0)), 'mod' => 'report-summary-card--present'],
        ['label' => __('avg_late_delay'), 'value' => format_minutes((int)round((float)($lateData['avg_late_minutes'] ?? 0))), 'mod' => 'report-summary-card--records'],
    ];
} elseif ($report === 'incomplete') {
    $incompStmt = db()->prepare("SELECT
        COUNT(*) AS total_incomp,
        SUM(CASE WHEN a.clock_in IS NOT NULL AND a.clock_out IS NULL THEN 1 ELSE 0 END) AS missing_out,
        SUM(CASE WHEN a.clock_in IS NULL THEN 1 ELSE 0 END) AS absent,
        COUNT(DISTINCT a.user_id) AS unique_users
        FROM attendance a JOIN users u ON u.id=a.user_id WHERE {$baseWhere}");
    $incompStmt->execute($baseParams);
    $incompData = $incompStmt->fetch() ?: [];
    $summaryCardsList = [
        ['label' => __('incomplete_records'), 'value' => number_format((int)($incompData['total_incomp'] ?? 0)), 'mod' => 'report-summary-card--records'],
        ['label' => __('missing_clockout_count'), 'value' => number_format((int)($incompData['missing_out'] ?? 0)), 'mod' => 'report-summary-card--late'],
        ['label' => __('absent_unrecorded'), 'value' => number_format((int)($incompData['absent'] ?? 0)), 'mod' => 'report-summary-card--present'],
        ['label' => __('affected_employees'), 'value' => number_format((int)($incompData['unique_users'] ?? 0)), 'mod' => 'report-summary-card--hours'],
    ];
} elseif ($report === 'leave') {
    $approvedLeaveSql = "EXISTS (SELECT 1 FROM leave_requests lr WHERE lr.user_id=a.user_id
        AND lr.leave_date=a.work_date AND lr.status='approved' AND lr.leave_type<>'wfh')";
    $leaveStmt = db()->prepare("SELECT
        COUNT(*) AS total_leave,
        SUM(CASE WHEN NOT {$approvedLeaveSql} THEN 1 ELSE 0 END) AS days_off,
        SUM(CASE WHEN {$approvedLeaveSql} THEN 1 ELSE 0 END) AS leaves,
        COUNT(DISTINCT a.user_id) AS unique_users
        FROM attendance a JOIN users u ON u.id=a.user_id WHERE {$baseWhere}");
    $leaveStmt->execute($baseParams);
    $leaveData = $leaveStmt->fetch() ?: [];
    $summaryCardsList = [
        ['label' => __('total_leave_days'), 'value' => number_format((int)($leaveData['total_leave'] ?? 0)), 'mod' => 'report-summary-card--records'],
        ['label' => __('scheduled_days_off'), 'value' => number_format((int)($leaveData['days_off'] ?? 0)), 'mod' => 'report-summary-card--present'],
        ['label' => __('approved_leaves'), 'value' => number_format((int)($leaveData['leaves'] ?? 0)), 'mod' => 'report-summary-card--late'],
        ['label' => __('employees_on_leave'), 'value' => number_format((int)($leaveData['unique_users'] ?? 0)), 'mod' => 'report-summary-card--hours'],
    ];
} elseif ($report === 'wfh') {
    $wfhStmt = db()->prepare("SELECT
        COUNT(*) AS total_wfh,
        SUM(CASE WHEN (a.status IN ('present','late','completed','on_break') OR a.clock_in IS NOT NULL) THEN 1 ELSE 0 END) AS worked,
        COUNT(DISTINCT a.user_id) AS unique_users,
        COALESCE(SUM(a.work_minutes), 0) AS total_work_minutes
        FROM attendance a JOIN users u ON u.id=a.user_id WHERE {$baseWhere}");
    $wfhStmt->execute($baseParams);
    $wfhData = $wfhStmt->fetch() ?: [];
    $summaryCardsList = [
        ['label' => __('wfh_days'), 'value' => number_format((int)($wfhData['total_wfh'] ?? 0)), 'mod' => 'report-summary-card--records'],
        ['label' => __('wfh_worked_days'), 'value' => number_format((int)($wfhData['worked'] ?? 0)), 'mod' => 'report-summary-card--present'],
        ['label' => __('wfh_employees'), 'value' => number_format((int)($wfhData['unique_users'] ?? 0)), 'mod' => 'report-summary-card--late'],
        ['label' => __('wfh_total_hours'), 'value' => format_minutes((int)($wfhData['total_work_minutes'] ?? 0)), 'mod' => 'report-summary-card--hours'],
    ];
} else {
    // Default: daily attendance details
    $summaryStmt = db()->prepare("SELECT COUNT(*) AS scheduled_days,
        SUM(CASE WHEN a.clock_in IS NOT NULL THEN 1 ELSE 0 END) AS present_days,
        SUM(CASE WHEN a.late_minutes > 0 OR a.status='late' THEN 1 ELSE 0 END) AS late_count,
        COALESCE(SUM(a.work_minutes),0) AS total_work_minutes,
        COALESCE(SUM(a.total_break_minutes),0) AS total_break_minutes
        FROM attendance a JOIN users u ON u.id=a.user_id WHERE {$baseWhere}");
    $summaryStmt->execute($baseParams);
    $summaryRow = $summaryStmt->fetch() ?: [];
    $summaryCardsList = [
        ['label' => __('total_attendance_records'), 'value' => number_format((int)($summaryRow['scheduled_days'] ?? 0)), 'mod' => 'report-summary-card--records'],
        ['label' => __('report_clocked_records'), 'value' => number_format((int)($summaryRow['present_days'] ?? 0)), 'mod' => 'report-summary-card--present'],
        ['label' => __('late_arrivals'), 'value' => number_format((int)($summaryRow['late_count'] ?? 0)), 'mod' => 'report-summary-card--late'],
        ['label' => __('total_working_time'), 'value' => format_minutes((int)($summaryRow['total_work_minutes'] ?? 0)), 'mod' => 'report-summary-card--hours'],
    ];
}

$rows = [];
$filtered = [];
if ($export !== '') {
    $rowsStmt = db()->prepare("SELECT a.*, u.full_name, u.employee_id FROM attendance a JOIN users u ON u.id=a.user_id WHERE {$baseWhere} ORDER BY a.work_date, u.full_name");
    $rowsStmt->execute($baseParams);
    $rows = $rowsStmt->fetchAll();
    $filtered = $rows;
} else {
    $offset = ($page - 1) * $pageSize;
    $rowsStmt = db()->prepare("SELECT a.*, u.full_name, u.employee_id FROM attendance a JOIN users u ON u.id=a.user_id WHERE {$baseWhere} ORDER BY a.work_date, u.full_name LIMIT {$pageSize} OFFSET {$offset}");
    $rowsStmt->execute($baseParams);
    $rows = $rowsStmt->fetchAll();
    $filtered = $rows;
}

// Break usage summary (only needed when viewing break report)
$breakSummary = [];
if ($report === 'breaks') {
    $breakSql = "SELECT b.break_type, COUNT(*) AS cnt, SUM(b.duration_minutes) AS mins, SUM(b.exceeded) AS exceeded
                 FROM break_records b
                 JOIN attendance a ON a.id = b.attendance_id
                 JOIN users u ON u.id = a.user_id
                 WHERE {$baseWhere}
                 GROUP BY b.break_type";
    $breakStmt = db()->prepare($breakSql);
    $breakStmt->execute($baseParams);
    $breakSummary = $breakStmt->fetchAll();
}

$reportTitles = [
    'late' => __('report_late_summary'),
    'incomplete' => __('report_missing_clockout'),
    'leave' => __('report_day_off_leave'),
    'wfh' => __('leave_wfh'),
    'breaks' => __('report_break_usage'),
    'daily' => __('report_attendance_detail'),
];
$reportTitle = $reportTitles[$report] ?? __('report_attendance_detail');
$periodLabel = format_date($from) . ' – ' . format_date($to);
$summaryNotes = match ($report) {
    'late' => [
        __('late_incidents') => __('report_late_note'),
        __('total_late_time') => __('report_late_time_note'),
        __('employees_late') => __('report_late_staff_note'),
        __('avg_late_delay') => __('report_late_average_note'),
    ],
    'incomplete' => [
        __('incomplete_records') => __('report_incomplete_note'),
        __('missing_clockout_count') => __('report_missing_out_note'),
        __('absent_unrecorded') => __('report_missing_in_note'),
        __('affected_employees') => __('report_incomplete_staff_note'),
    ],
    'leave' => [
        __('total_leave_days') => __('report_leave_days_note'),
        __('scheduled_days_off') => __('report_days_off_note'),
        __('approved_leaves') => __('report_leave_marked_note'),
        __('employees_on_leave') => __('report_leave_staff_note'),
    ],
    'wfh' => [
        __('wfh_days') => __('report_wfh_days_note'),
        __('wfh_worked_days') => __('report_wfh_worked_note'),
        __('wfh_employees') => __('report_wfh_staff_note'),
        __('wfh_total_hours') => __('report_work_note'),
    ],
    'breaks' => [
        __('total_breaks') => __('report_break_count_note'),
        __('total_break_time') => __('report_break_time_note'),
        __('exceeded_breaks') => __('report_break_exceeded_note'),
        __('avg_break_duration') => __('report_break_average_note'),
    ],
    default => [
        __('total_attendance_records') => __('report_records_note'),
        __('report_clocked_records') => __('report_clocked_note'),
        __('late_arrivals') => __('report_late_note'),
        __('total_working_time') => __('report_work_note'),
    ],
};

if ($export !== '') {
    $empName = '';
    if ($userId > 0) {
        foreach ($staffList as $s) {
            if ((int) $s['id'] === $userId) {
                $empName = (string) $s['full_name'];
                break;
            }
        }
    }

    $meta = [
        [__('report'), $reportTitle],
        [__('report_period'), $periodLabel],
        [__('generated_at'), format_datetime(now()->format('Y-m-d H:i:s'))],
    ];
    if ($userId > 0) {
        $meta[] = [__('employee'), $empName !== '' ? $empName : ('#' . $userId)];
    }

    // Build clean, collision-free export filename
    $compWords = preg_split('/\s+/', trim(company_name())) ?: ['Report'];
    $companyPrefix = ucfirst(strtolower($compWords[0])); // e.g. "Lasto"

    $reportTypeLabels = [
        'daily' => 'Attendance',
        'late' => 'Late_Summary',
        'incomplete' => 'Missing_Clockout',
        'leave' => 'Leave_Records',
        'wfh' => 'WFH_Records',
        'breaks' => 'Break_Usage',
    ];
    $typeLabel = $reportTypeLabels[$report] ?? ucfirst(preg_replace('/[^a-zA-Z0-9]+/', '_', $report));

    $empSlug = '';
    if ($empName !== '') {
        $cleanEmp = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $empName);
        $empSlug = trim($cleanEmp, '_');
    }

    if ($from !== '' && $to !== '') {
        $dateSlug = ($from === $to) ? $from : ($from . '_to_' . $to);
    } elseif ($from !== '') {
        $dateSlug = 'from_' . $from;
    } elseif ($to !== '') {
        $dateSlug = 'up_to_' . $to;
    } else {
        $dateSlug = 'all_time_' . now()->format('Ymd');
    }

    $fileParts = array_filter([$companyPrefix, $typeLabel, $empSlug, $dateSlug]);
    $baseName = preg_replace('/_+/', '_', implode('_', $fileParts));

    if ($report === 'breaks') {
        $headers = report_break_headers();
        $matrix = report_break_export_matrix($breakSummary);
        $sheet = __('report_break_usage');
    } else {
        $headers = report_attendance_headers();
        $matrix = report_attendance_export_matrix($filtered);
        $sheet = $reportTitle;
    }

    if ($report === 'breaks') {
        $totBreakCount = 0;
        $totBreakMins = 0;
        $totBreakExceeded = 0;
        foreach ($breakSummary as $b) {
            $totBreakCount += (int) $b['cnt'];
            $totBreakMins += (int) $b['mins'];
            $totBreakExceeded += (int) $b['exceeded'];
        }
        $avgBreakMins = $totBreakCount > 0 ? (int) round($totBreakMins / $totBreakCount) : 0;
        $summaryCardsList = [
            ['label' => __('total_breaks'), 'value' => number_format($totBreakCount), 'mod' => 'report-summary-card--records'],
            ['label' => __('total_break_time'), 'value' => format_minutes($totBreakMins), 'mod' => 'report-summary-card--hours'],
            ['label' => __('exceeded_breaks'), 'value' => number_format($totBreakExceeded), 'mod' => 'report-summary-card--late'],
            ['label' => __('avg_break_duration'), 'value' => format_minutes($avgBreakMins), 'mod' => 'report-summary-card--present'],
        ];
    }
    $summaryCards = [];
    foreach ($summaryCardsList as $c) {
        $summaryCards[$c['label']] = (string) $c['value'];
    }

    $employeeInfo = null;
    if ($userId > 0) {
        $uStmt = db()->prepare("SELECT id, full_name, employee_id, job_position, weekly_off_day, email, joining_date FROM users WHERE id = ?");
        $uStmt->execute([$userId]);
        $employeeInfo = $uStmt->fetch() ?: null;
    }

    if ($export === 'csv') {
        report_emit_csv($baseName . '.csv', $headers, $matrix);
    }
    if ($export === 'excel') {
        report_emit_excel($baseName . '.xls', $sheet, $headers, $matrix, $meta);
    }
    if ($export === 'pdf' || $export === 'print') {
        $scope = $userId > 0 ? ($empName !== '' ? $empName : '#' . $userId) : __('all_staff');
        $exportContext = $report === 'breaks'
            ? ['scope' => $scope, 'record_count' => count($matrix), 'basis' => __('report_break_basis')]
            : report_attendance_context($filtered, $scope);
        $exportContext['summary_notes'] = $summaryNotes;
        report_emit_pdf_html(
            $reportTitle,
            $periodLabel,
            $headers,
            $matrix,
            $summaryCards,
            $report === 'breaks' ? __('break_usage_summary') : '',
            $baseName . '.pdf',
            $export === 'print',
            $employeeInfo,
            $exportContext
        );
    }
}

$pageTitle = __('reports');
$activeMenu = 'reports';
$isAdminArea = true;
$bodyClass = 'reports-page';
require __DIR__ . '/../includes/layout_header.php';

$qs = http_build_query(['report' => $report, 'from' => $from, 'to' => $to, 'user_id' => $userId]);
$pageQs = ['report' => $report, 'from' => $from, 'to' => $to, 'user_id' => $userId];
?>

<div class="card-soft card-filters mb-3 no-print">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-12">
                <?php render_date_presets('range', ['from' => $from, 'to' => $to]); ?>
            </div>
            <div class="col-12 col-md-6 col-xl-2">
                <label class="form-label" for="filter-report"><?= e(__('filter_report')) ?></label>
                <select id="filter-report" name="report" class="form-select">
                    <option value="daily" <?= $report === 'daily' ? 'selected' : '' ?>><?= e(__('report_attendance_detail')) ?></option>
                    <option value="late" <?= $report === 'late' ? 'selected' : '' ?>><?= e(__('report_late_summary')) ?></option>
                    <option value="breaks" <?= $report === 'breaks' ? 'selected' : '' ?>><?= e(__('report_break_usage')) ?></option>
                    <option value="incomplete" <?= $report === 'incomplete' ? 'selected' : '' ?>><?= e(__('report_missing_clockout')) ?></option>
                    <option value="leave" <?= $report === 'leave' ? 'selected' : '' ?>><?= e(__('report_day_off_leave')) ?></option>
                    <option value="wfh" <?= $report === 'wfh' ? 'selected' : '' ?>><?= e(__('leave_wfh')) ?></option>
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-2"><label class="form-label" for="filter-from"><?= e(__('filter_from')) ?></label><input id="filter-from" type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
            <div class="col-12 col-md-6 col-xl-2"><label class="form-label" for="filter-to"><?= e(__('filter_to')) ?></label><input id="filter-to" type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
            <div class="col-12 col-md-6 col-xl-2">
                <label class="form-label" for="filter-user_id"><?= e(__('employee')) ?></label>
                <select id="filter-user_id" name="user_id" class="form-select">
                    <option value="0"><?= e(__('all_staff')) ?></option>
                    <?php foreach ($staffList as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= $userId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-xl-4 d-flex flex-wrap gap-2 align-items-end justify-content-end report-actions">
                <button class="btn btn-accent"><?= e(__('filter')) ?></button>
                <div class="dropdown report-export-dropdown">
                    <button
                        class="btn btn-outline-secondary dropdown-toggle"
                        type="button"
                        data-bs-toggle="dropdown"
                        data-bs-display="static"
                        aria-expanded="false"
                    >
                        <i class="bi bi-download me-1"></i><?= e(__('export_as')) ?>
                    </button>
                    <ul class="dropdown-menu shadow">
                        <li>
                            <a class="dropdown-item" data-spa="0" href="?<?= e($qs) ?>&export=csv">
                                <i class="bi bi-filetype-csv me-1"></i> <?= e(__('export_csv')) ?>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" data-spa="0" href="?<?= e($qs) ?>&export=excel">
                                <i class="bi bi-filetype-xlsx me-1"></i> <?= e(__('export_excel')) ?>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" data-spa="0" target="_blank" rel="noopener" href="?<?= e($qs) ?>&export=pdf">
                                <i class="bi bi-filetype-pdf me-1"></i> <?= e(__('export_pdf')) ?>
                            </a>
                        </li>
                    </ul>
                </div>
                <a class="btn btn-outline-secondary" target="_blank" rel="noopener" data-spa="0" href="?<?= e($qs) ?>&export=print">
                    <i class="bi bi-printer me-1"></i><?= e(__('print')) ?>
                </a>
            </div>
        <div class="col-12 d-flex flex-wrap align-items-center gap-3" style="flex-basis:100%">
                <span class="form-hint" data-auto-filter-hint hidden><?= e(__('filters_auto_hint')) ?></span>
                <a href="<?= e(url('/admin/reports.php')) ?>" class="small"><?= e(__('filters_reset')) ?></a>
            </div>
        </form>
    </div>
</div>

<?php
if ($report === 'breaks') {
    $totBreakCount = 0;
    $totBreakMins = 0;
    $totBreakExceeded = 0;
    foreach ($breakSummary as $b) {
        $totBreakCount += (int) $b['cnt'];
        $totBreakMins += (int) $b['mins'];
        $totBreakExceeded += (int) $b['exceeded'];
    }
    $avgBreakMins = $totBreakCount > 0 ? (int) round($totBreakMins / $totBreakCount) : 0;
    $summaryCardsList = [
        ['label' => __('total_breaks'), 'value' => number_format($totBreakCount), 'mod' => 'report-summary-card--records'],
        ['label' => __('total_break_time'), 'value' => format_minutes($totBreakMins), 'mod' => 'report-summary-card--hours'],
        ['label' => __('exceeded_breaks'), 'value' => number_format($totBreakExceeded), 'mod' => 'report-summary-card--late'],
        ['label' => __('avg_break_duration'), 'value' => format_minutes($avgBreakMins), 'mod' => 'report-summary-card--present'],
    ];
}
?>
<div class="report-content-wrapper" id="reportContentWrapper">
    <div class="report-loading-overlay" id="reportLoadingOverlay" aria-hidden="true">
        <div class="report-loading-spinner-box">
            <div class="spinner-border" role="status">
                <span class="visually-hidden"><?= e(__('loading')) ?></span>
            </div>
            <div class="report-loading-text"><?= e(__('loading')) ?></div>
        </div>
    </div>
<div class="row g-3 mb-3 report-summary">
    <?php foreach ($summaryCardsList as $c): ?>
        <div class="col-6 col-md-3">
            <div class="stat-card report-summary-card <?= e($c['mod']) ?>">
                <div class="stat-label"><?= e($c['label']) ?></div>
                <div class="stat-value <?= (str_contains($c['value'], 'h') || str_contains($c['value'], 'm')) && !str_contains($c['value'], ' ') ? 'report-summary-card__hours' : '' ?>"><?= e($c['value']) ?></div>
                <?php if (isset($summaryNotes[$c['label']])): ?>
                    <div class="form-hint mt-2"><?= e($summaryNotes[$c['label']]) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($report === 'breaks'): ?>
<div class="card-soft mb-3">
    <div class="card-header"><?= e(__('break_usage_summary')) ?></div>
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead><tr><th><?= e(__('type')) ?></th><th><?= e(__('count')) ?></th><th><?= e(__('total_duration')) ?></th><th><?= e(__('exceeded')) ?></th></tr></thead>
            <tbody>
            <?php if (!$breakSummary): ?>
                <tr><td colspan="4" class="text-center text-muted"><?= e(__('no_break_data')) ?></td></tr>
            <?php else: foreach ($breakSummary as $b): ?>
                <tr>
                    <td><?= e(break_label((string) $b['break_type'])) ?></td>
                    <td><?= (int) $b['cnt'] ?></td>
                    <td><?= e(format_minutes((int) $b['mins'])) ?></td>
                    <td><?= (int) $b['exceeded'] ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card-soft report-results-card">
    <div class="card-header report-results-header">
        <span><?= e($reportTitle) ?></span>
        <span class="report-period" aria-label="<?= e(__('report_period') . ': ' . $periodLabel) ?>"><span class="report-period-label"><?= e(__('report_period')) ?>:</span> <?= e($periodLabel) ?></span>
    </div>
    <div class="card-body p-0">
        <?php if ($report === 'breaks'): ?>
            <?php
            // For breaks view, still show attendance detail underneath (unfiltered period)
            $tableRows = report_filter_rows($rows, 'daily');
            ?>
        <?php else: ?>
            <?php $tableRows = $filtered; ?>
        <?php endif; ?>

        <?php if (!$tableRows): ?>
            <div class="empty-state"><?= e(__('no_report_records')) ?></div>
        <?php else: ?>
            <div class="table-responsive-card">
                <table class="table mb-0 report-results-table">
                    <thead>
                    <tr>
                        <th><?= e(__('date')) ?></th>
                        <th><?= e(__('employee')) ?></th>
                        <th><?= e(__('clock_in_col')) ?></th>
                        <th><?= e(__('clock_out_col')) ?></th>
                        <th><?= e(__('late')) ?></th>
                        <th><?= e(__('total_break_time')) ?></th>
                        <th><?= e(__('total_working_time')) ?></th>
                        <th><?= e(__('status')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tableRows as $r): ?>
                        <tr>
                            <td><?= e(format_date($r['work_date'])) ?></td>
                            <td><?= e($r['full_name']) ?></td>
                            <td><?= e(format_time($r['clock_in'])) ?></td>
                            <td><?= e(format_time($r['clock_out'])) ?></td>
                            <td><?= e(format_minutes((int) $r['late_minutes'])) ?></td>
                            <td><?= e(format_minutes((int) $r['total_break_minutes'])) ?></td>
                            <td><?= e(format_minutes((int) $r['work_minutes'])) ?></td>
                            <td><div class="report-status-badges"><?= attendance_status_badge($r) ?></div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($export === ''): ?>
                <?= pagination_links($totalRows, $page, $pageSize, $pageQs, url('/admin/reports.php')) ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</div><!-- /.report-content-wrapper -->

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
