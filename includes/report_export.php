<?php
/**
 * Admin report export helpers (CSV / Excel XML / printable PDF)
 */

declare(strict_types=1);

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function report_filter_rows(array $rows, string $report): array
{
    return array_values(array_filter($rows, static function ($r) use ($report) {
        return match ($report) {
            'late' => (int) $r['late_minutes'] > 0 || $r['status'] === 'late',
            'incomplete' => $r['status'] === 'incomplete'
                || ($r['clock_in'] && !$r['clock_out'] && $r['work_date'] < today_str()),
            'leave' => (int) $r['is_day_off'] === 1 || $r['status'] === 'day_off',
            'wfh' => !empty($r['is_wfh']),
            default => true,
        };
    }));
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<list<string>>
 */
function report_attendance_export_matrix(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            format_date((string) $r['work_date']),
            (string) $r['full_name'],
            format_time($r['clock_in'] ? (string) $r['clock_in'] : null),
            format_time($r['clock_out'] ? (string) $r['clock_out'] : null),
            format_minutes((int) $r['late_minutes']),
            format_minutes((int) $r['total_break_minutes']),
            format_minutes((int) $r['work_minutes']),
            status_label((string) $r['status']),
            attendance_work_arrangement($r),
        ];
    }
    return $out;
}

/**
 * @return list<string>
 */
function report_attendance_headers(): array
{
    return [
        __('date'),
        __('employee'),
        __('clock_in_col'),
        __('clock_out_col'),
        __('late'),
        __('total_break_time'),
        __('total_working_time'),
        __('status'),
        __('work_arrangement'),
    ];
}

/** Coverage and review counts from the complete filtered export, never a page of rows. */
function report_attendance_context(array $rows, string $scope): array
{
    $staff = [];
    $counts = ['late' => 0, 'absent' => 0, 'incomplete' => 0, 'missing' => 0, 'open' => 0];
    foreach ($rows as $row) {
        $staff[(int) $row['user_id']] = true;
        $counts['late'] += (int) ((int) $row['late_minutes'] > 0 || $row['status'] === 'late');
        $counts['absent'] += (int) ($row['status'] === 'absent');
        $counts['incomplete'] += (int) ($row['status'] === 'incomplete');
        if (!empty($row['clock_in']) && empty($row['clock_out'])) {
            $counts[$row['work_date'] < today_str() ? 'missing' : 'open']++;
        }
    }
    $review = [];
    foreach (['late' => 'late_arrivals', 'absent' => 'report_recorded_absences', 'incomplete' => 'incomplete_records',
        'missing' => 'missing_clockout_count', 'open' => 'report_open_shifts'] as $type => $key) {
        if ($counts[$type] > 0) $review[__($key)] = number_format($counts[$type]);
    }
    return [
        'scope' => $scope,
        'staff_count' => count($staff),
        'record_count' => count($rows),
        'review' => $review,
        'basis' => __('report_attendance_basis'),
        'records' => $rows,
    ];
}

/**
 * @param list<array<string, mixed>> $breakSummary
 * @return list<list<string>>
 */
function report_break_export_matrix(array $breakSummary): array
{
    $out = [];
    foreach ($breakSummary as $b) {
        $out[] = [
            break_type_name((string) $b['break_type']),
            (string) (int) $b['cnt'],
            format_minutes((int) $b['mins']),
            (string) (int) $b['exceeded'],
        ];
    }
    return $out;
}

/**
 * @return list<string>
 */
function report_break_headers(): array
{
    return [__('type'), __('count'), __('total_duration'), __('exceeded')];
}

function report_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * @param list<string> $headers
 * @param list<list<string>> $matrix
 */
function report_emit_csv(string $filename, array $headers, array $matrix): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM for Excel
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($matrix as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

/**
 * Excel 2003 XML Spreadsheet (opens in Excel / LibreOffice) — Unicode safe
 *
 * @param list<string> $headers
 * @param list<list<string>> $matrix
 * @param list<array{0:string,1:string}> $meta optional label/value pairs
 */
function report_emit_excel(string $filename, string $sheetName, array $headers, array $matrix, array $meta = []): void
{
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $sheetName = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $sheetName) ?: 'Report', 0, 31);

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
        . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
        . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
        . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"'
        . ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
    echo '<Styles>'
        . '<Style ss:ID="hdr"><Font ss:Bold="1"/><Interior ss:Color="#D9EAF7" ss:Pattern="Solid"/></Style>'
        . '<Style ss:ID="meta"><Font ss:Italic="1" ss:Color="#555555"/></Style>'
        . '</Styles>' . "\n";
    echo '<Worksheet ss:Name="' . report_xml_escape($sheetName) . '"><Table>' . "\n";

    foreach ($meta as $pair) {
        echo '<Row>';
        echo '<Cell ss:StyleID="meta"><Data ss:Type="String">' . report_xml_escape($pair[0]) . '</Data></Cell>';
        echo '<Cell ss:StyleID="meta"><Data ss:Type="String">' . report_xml_escape($pair[1]) . '</Data></Cell>';
        echo '</Row>' . "\n";
    }
    if ($meta) {
        echo '<Row></Row>' . "\n";
    }

    echo '<Row>';
    foreach ($headers as $h) {
        echo '<Cell ss:StyleID="hdr"><Data ss:Type="String">' . report_xml_escape($h) . '</Data></Cell>';
    }
    echo '</Row>' . "\n";

    foreach ($matrix as $row) {
        echo '<Row>';
        foreach ($row as $cell) {
            echo '<Cell><Data ss:Type="String">' . report_xml_escape((string) $cell) . '</Data></Cell>';
        }
        echo '</Row>' . "\n";
    }

    echo '</Table></Worksheet></Workbook>';
    exit;
}

/**
 * Printable HTML + formal executive PDF download (html2pdf) — CJK-safe
 *
 * @param list<string> $headers
 * @param list<list<string>> $matrix
 * @param array<string, scalar> $summary
 * @param ?array<string, mixed> $employee
 */
function report_emit_pdf_html(
    string $title,
    string $period,
    array $headers,
    array $matrix,
    array $summary = [],
    string $extraTitle = '',
    string $filename = 'report.pdf',
    bool $autoPrint = false,
    ?array $employee = null,
    array $context = []
): void {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');

    $companyName = 'LASTO SDN. BHD.';
    $companyReg = '1444747M / 202101044447';
    $companyIncorporated = '2021-12-28';
    $companyAddress = 'No. 220 (Ground Floor), Jalan Legenda 8, Lagenda Heights, 08000 Sungai Petani, Kedah';
    $companyNature = 'Retail Sale of Computers, Computer Equipment and Supplies';

    $generated = format_datetime(now()->format('Y-m-d H:i:s'));
    $adminUser = current_user();
    $adminName = !empty($adminUser['full_name']) ? (string)$adminUser['full_name'] : 'System Administrator';
    $reportRef = 'LAR-' . now()->format('Ym') . '-' . strtoupper(substr(md5($filename . $period), 0, 4));

    $logoBase64 = '';
    $logoFile = __DIR__ . '/../assets/img/logo.png';
    if (file_exists($logoFile)) {
        $logoBase64 = 'data:image/png;base64,' . base64_encode((string)file_get_contents($logoFile));
    }

    $safeName = preg_replace('/[^a-zA-Z0-9_\-\.]+/', '_', $filename) ?: 'report.pdf';
    if (!str_ends_with(strtolower($safeName), '.pdf')) {
        $safeName .= '.pdf';
    }

    // Helper to format status cells as clean formal pill tags
    $formatCell = static function (string $cell): string {
        $trimmed = trim($cell);
        $statusMap = [
            'Completed' => 'badge-status-completed',
            'Present' => 'badge-status-completed',
            'Late' => 'badge-status-late',
            'Absent' => 'badge-status-absent',
            'Incomplete' => 'badge-status-absent',
            'Not Clocked In' => 'badge-status-absent',
            'Day Off' => 'badge-status-off',
            'Scheduled Day Off' => 'badge-status-off',
            'Work From Home (WFH)' => 'badge-status-wfh',
            'WFH' => 'badge-status-wfh',
            'On Break' => 'badge-status-break',
            'Selesai' => 'badge-status-completed',
            'Hadir' => 'badge-status-completed',
            'Lewat' => 'badge-status-late',
            'Tidak Hadir' => 'badge-status-absent',
            'Tidak Lengkap' => 'badge-status-absent',
            'Cuti' => 'badge-status-off',
            'Hari Off Berjadual' => 'badge-status-off',
            'Sedang Rehat' => 'badge-status-break',
            '排定休息日' => 'badge-status-off',
        ];
        foreach ([
            'completed' => 'badge-status-completed',
            'present' => 'badge-status-completed',
            'late' => 'badge-status-late',
            'absent' => 'badge-status-absent',
            'incomplete' => 'badge-status-absent',
            'not_clocked_in' => 'badge-status-absent',
            'day_off' => 'badge-status-off',
            'on_break' => 'badge-status-break',
        ] as $status => $class) {
            $statusMap[status_label($status)] = $class;
        }
        if (function_exists('__')) {
            $statusMap[__('leave_wfh')] = 'badge-status-wfh';
        }
        if (isset($statusMap[$trimmed])) {
            return '<span class="status-pill ' . $statusMap[$trimmed] . '">' . e($trimmed) . '</span>';
        }
        return e($cell);
    };

    // Analyze data structure and determine adaptive layout mode
    $isAttendance = (count($headers) === 9);
    $records = $context['records'] ?? [];
    $isSingleStaff = ($employee !== null);

    $distinctDates = [];
    $groupedByDate = [];
    if ($isAttendance && !empty($records)) {
        foreach ($records as $idx => $r) {
            $d = (string)$r['work_date'];
            $groupedByDate[$d][] = [
                'record' => $r,
                'matrix' => $matrix[$idx] ?? [],
            ];
        }
        ksort($groupedByDate);
        $distinctDates = array_keys($groupedByDate);
    }
    $isMultiDay = (count($distinctDates) > 1);

    // Layout modes:
    // 1. all_multi: All staff, multiple days -> Dedicated Page 1 Management Summary + Grouped Attendance Details
    // 2. all_single: All staff, 1 day -> Compact Summary + Date Section Table on Page 1
    // 3. single_multi: Single staff, multiple days -> Individual Profile + Timesheet Table
    // 4. single_single: Single staff, 1 day -> Compact Individual Report
    // 5. non_attendance: Non-attendance table (e.g. Breaks report)
    $layoutMode = 'non_attendance';
    if ($isAttendance) {
        if (!$isSingleStaff && $isMultiDay) {
            $layoutMode = 'all_multi';
        } elseif (!$isSingleStaff && !$isMultiDay) {
            $layoutMode = 'all_single';
        } elseif ($isSingleStaff && $isMultiDay) {
            $layoutMode = 'single_multi';
        } else {
            $layoutMode = 'single_single';
        }
    }

    echo '<!DOCTYPE html><html lang="' . e(html_lang()) . '"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>LASTO · ' . e($title) . ' (' . e($period) . ')</title>';
    echo '<style>
      :root { color-scheme: light; }
      * { box-sizing: border-box; }
      body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans", "Noto Sans CJK SC", "Microsoft YaHei", Arial, sans-serif;
        margin: 0;
        background: #f1f5f9;
        color: #0f172a;
        line-height: 1.35;
      }
      .toolbar {
        position: sticky;
        top: 0;
        z-index: 50;
        display: flex;
        flex-wrap: wrap;
        gap: .65rem;
        align-items: center;
        padding: .75rem 1.5rem;
        background: #0f172a;
        color: #e2e8f0;
        box-shadow: 0 4px 14px rgba(0,0,0,.25);
      }
      .toolbar .hint { flex: 1 1 220px; font-size: .85rem; color: #94a3b8; }
      .toolbar .tip-badge {
        display: inline-block;
        background: #0f766e;
        color: #ffffff;
        font-size: 0.68rem;
        font-weight: 800;
        padding: 2px 6px;
        border-radius: 4px;
        margin-right: 6px;
        letter-spacing: 0.04em;
        vertical-align: middle;
      }
      .btn {
        appearance: none;
        border: 0;
        border-radius: 6px;
        padding: .5rem 1.1rem;
        font-weight: 700;
        cursor: pointer;
        font-size: .88rem;
        transition: all 0.15s ease;
      }
      .btn-primary { background: #0f766e; color: #fff; }
      .btn-primary:hover { background: #115e59; }
      .btn-primary:disabled { opacity: .65; cursor: wait; }
      .btn-ghost { background: #1e293b; color: #e2e8f0; border: 1px solid #334155; }
      .btn-ghost:hover { background: #334155; }
      .wrap { padding: 1.5rem; max-width: 900px; margin: 0 auto; }
      #reportSheet {
        background: #ffffff;
        width: 100%;
        padding: 2rem 2.25rem;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(15,23,42,.08);
      }

      /* ——— Formal Corporate Header (A4 Portrait Adapted) ——— */
      .report-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1.25rem;
        padding-bottom: 1.15rem;
        border-bottom: 2.5px solid #0f766e;
        margin-bottom: 1.15rem;
        page-break-inside: avoid;
        break-inside: avoid;
      }
      .header-left {
        display: flex;
        align-items: center;
        gap: 1rem;
        flex: 1 1 auto;
      }
      .company-logo {
        width: 62px;
        height: 62px;
        object-fit: contain;
        border-radius: 8px;
        background: #0b1220;
        padding: 5px;
        flex-shrink: 0;
        box-shadow: 0 2px 8px rgba(0,0,0,0.12);
      }
      .company-details { line-height: 1.35; }
      .company-name {
        font-size: 1.25rem;
        font-weight: 800;
        color: #0f172a;
        letter-spacing: 0.02em;
        margin: 0 0 2px;
      }
      .company-reg {
        font-size: 8pt;
        color: #0f766e;
        font-weight: 700;
        margin-bottom: 2px;
      }
      .company-address, .company-nature {
        font-size: 7.8pt;
        color: #475569;
      }
      .company-nature { margin-top: 1px; }
      .header-right {
        flex-shrink: 0;
        text-align: right;
        max-width: 260px;
      }
      .doc-badge {
        display: inline-block;
        background: #0f766e;
        color: #ffffff;
        font-size: 7.5pt;
        font-weight: 800;
        padding: 3px 8px;
        border-radius: 4px;
        letter-spacing: 0.05em;
        margin-bottom: 5px;
      }
      .report-meta-table {
        font-size: 8pt;
        color: #334155;
        line-height: 1.4;
      }
      .report-meta-table strong { color: #0f172a; }

      /* ——— Report Introduction & Coverage ——— */
      .report-intro { margin-bottom: 1.15rem; page-break-inside: avoid; break-inside: avoid; }
      .report-title { margin: 0 0 4px; font-size: 17pt; font-weight: 800; letter-spacing: -.02em; color: #0f172a; }
      .report-coverage { display: flex; flex-wrap: wrap; gap: 4px 16px; font-size: 8.5pt; color: #475569; }
      .report-coverage strong { color: #0f172a; }

      /* ——— Employee Profile Box (Single Staff - Portrait 2x2 Grid) ——— */
      .emp-profile-card {
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        border-left: 4.5px solid #0f766e;
        border-radius: 6px;
        padding: 0.75rem 1rem;
        margin-bottom: 1.15rem;
        page-break-inside: avoid;
        break-inside: avoid;
      }
      .emp-profile-title {
        font-size: 8pt;
        font-weight: 800;
        color: #0f766e;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        margin-bottom: 0.45rem;
      }
      .emp-profile-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.75rem 1.25rem;
      }
      .emp-item { font-size: 8.5pt; }
      .emp-lbl {
        display: block;
        color: #64748b;
        font-size: 7pt;
        text-transform: uppercase;
        font-weight: 700;
        margin-bottom: 2px;
      }
      .emp-val { font-weight: 800; color: #0f172a; }

      /* ——— Executive Summary Cards (Portrait 2x2 Grid) ——— */
      .stats {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1.15rem;
        margin-bottom: 1.65rem;
        page-break-inside: avoid;
        break-inside: avoid;
      }
      .stat {
        border: 1px solid #cbd5e1;
        border-top: 4px solid #0f766e;
        border-radius: 6px;
        padding: 13px 16px;
        background: #ffffff;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
      }
      .stat-lbl {
        display: block;
        font-size: 8.2pt;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 700;
        margin-bottom: 5px;
      }
      .stat-num {
        display: block;
        font-size: 1.85rem;
        color: #0f172a;
        font-weight: 800;
        line-height: 1.15;
        font-variant-numeric: tabular-nums;
      }
      .stat-note {
        display: block;
        margin-top: 4px;
        color: #64748b;
        font-size: 7.8pt;
        line-height: 1.35;
      }

      /* ——— Callouts: Basis of Reporting & Review Items ——— */
      .report-section-heading { margin: 0 0 6px; font-size: 10.5pt; font-weight: 700; color: #0f172a; }
      .report-basis {
        border: 1px solid #e2e8f0;
        border-left: 4.5px solid #0f766e;
        padding: 12px 16px;
        background: #f8fafc;
        border-radius: 5px;
        margin-bottom: 1.35rem;
        page-break-inside: avoid;
        break-inside: avoid;
      }
      .report-basis p, .review-note { margin: 0; font-size: 8.8pt; color: #475569; line-height: 1.5; }
      .report-review {
        border: 1px solid #fde68a;
        border-left: 4.5px solid #d97706;
        padding: 12px 16px;
        background: #fffbeb;
        border-radius: 5px;
        margin-bottom: 1.75rem;
        page-break-inside: avoid;
        break-inside: avoid;
      }
      .review-counts { display: flex; flex-wrap: wrap; gap: 8px 18px; margin-bottom: 6px; font-size: 9pt; }
      .review-counts strong { color: #92400e; margin-right: 3px; font-size: 9.5pt; }
      .review-note { color: #78350f; font-size: 8.2pt; }

      /* Dedicated Summary Page Break (Case 1: All staff, multi-day) */
      .report-summary-page {
        page-break-after: always;
        break-after: page;
      }
      .summary-page-footer {
        margin-top: 3.25rem;
        padding-top: 1rem;
        border-top: 1.5px dashed #cbd5e1;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 8.5pt;
        color: #64748b;
        page-break-inside: avoid;
        break-inside: avoid;
      }
      .summary-page-notice {
        font-weight: 700;
        color: #0f766e;
      }

      /* ——— Date Group Banner & Daily Breakdown Metrics ——— */
      .date-group-block {
        margin-bottom: 1.25rem;
        page-break-inside: auto;
        break-inside: auto;
      }
      .date-group-banner {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.4rem 0.85rem;
        background: #f1f5f9;
        border: 1px solid #cbd5e1;
        border-left: 4.5px solid #0f766e;
        border-radius: 4px;
        padding: 6px 9px;
        margin-bottom: 7px;
        page-break-after: avoid !important;
        break-after: avoid !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
      }
      .date-banner-left {
        display: flex;
        align-items: center;
        gap: 6px;
      }
      .date-heading-title {
        font-size: 9.2pt;
        font-weight: 800;
        color: #0f172a;
      }
      .date-weekday {
        font-size: 8.2pt;
        font-weight: 600;
        color: #475569;
        margin-left: 3px;
      }
      .date-staff-badge {
        background: #0f766e;
        color: #ffffff;
        font-size: 7.2pt;
        font-weight: 700;
        padding: 2px 6px;
        border-radius: 3px;
        letter-spacing: 0.02em;
      }
      .date-metrics-bar {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 4px 8px;
        font-size: 8pt;
        color: #334155;
      }
      .dm-item { display: inline-flex; align-items: baseline; gap: 3px; }
      .dm-lbl { color: #64748b; font-weight: 500; }
      .dm-val { color: #0f172a; font-weight: 700; font-variant-numeric: tabular-nums; }
      .dm-sub { color: #92400e; font-weight: 600; font-size: 7.5pt; }
      .dm-sep { color: #cbd5e1; font-size: 8pt; }
      .dm-warn .dm-val { color: #b91c1c; }
      .dm-info .dm-val { color: #0369a1; }
      .date-continuation-sub {
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-left: 3.5px solid #0f766e;
        padding: 4px 8px;
        font-size: 8.5pt;
        font-weight: 700;
        color: #475569;
        margin-bottom: 7px;
        page-break-after: avoid !important;
        break-after: avoid !important;
      }

      /* ——— 6-Column Data Table Typography & Generous Row Breathing Room ——— */
      table.data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 10pt;
        table-layout: fixed;
        page-break-inside: auto;
        break-inside: auto;
      }
      thead {
        display: table-header-group !important;
      }
      tfoot {
        display: table-footer-group !important;
      }
      tr {
        page-break-inside: avoid !important;
        break-inside: avoid !important;
      }
      th, td {
        border: 1px solid #cbd5e1;
        padding: 7px 7.5px;
        vertical-align: middle;
        line-height: 1.35;
      }
      th {
        background: #f1f5f9;
        color: #1e293b;
        font-weight: 700;
        text-transform: uppercase;
        font-size: 8pt;
        letter-spacing: 0.04em;
        white-space: normal;
      }
      tr:nth-child(even) td { background: #fafbfd; }
      .nowrap { white-space: nowrap; }
      .data-table .time-cell { text-align: center; font-variant-numeric: tabular-nums; }
      .data-table .duration-cell { text-align: right; font-variant-numeric: tabular-nums; }
      .data-table .text-center { text-align: center; }
      .data-table td { overflow-wrap: anywhere; }
      .time-main { font-size: 9.8pt; font-weight: 600; color: #0f172a; line-height: 1.25; }
      .duration-main { font-size: 9.8pt; font-weight: 600; color: #0f172a; }
      .date-main { font-size: 9.8pt; color: #0f172a; }
      .status-in-progress {
        font-size: 9pt;
        font-style: italic;
        font-weight: 700;
        color: #0f766e;
        letter-spacing: 0.01em;
      }
      .emp-name-box {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 6px;
      }
      .emp-name {
        font-weight: 700;
        color: #0f172a;
        font-size: 10pt;
        line-height: 1.3;
        flex: 1 1 auto;
        overflow-wrap: break-word;
      }
      .staff-id {
        font-size: 7.8pt;
        color: #475569;
        background: #f1f5f9;
        border: 1px solid #cbd5e1;
        border-radius: 3px;
        padding: 1.5px 4.5px;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        flex-shrink: 0;
        font-weight: 600;
        margin-top: 1px;
        letter-spacing: 0.02em;
      }
      .sub-late {
        font-size: 7.6pt;
        font-weight: 700;
        color: #b45309;
        line-height: 1.15;
        margin-top: 2px;
        letter-spacing: 0.01em;
      }
      .status-cell-wrap {
        display: flex;
        align-items: center;
        justify-content: center;
        flex-wrap: wrap;
        gap: 3px 5px;
      }
      .sub-wfh {
        display: inline-block;
        font-size: 7.2pt;
        font-weight: 800;
        color: #0369a1;
        background: #e0f2fe;
        border: 1px solid #0284c7;
        border-radius: 3px;
        padding: 2px 5px;
        line-height: 1.1;
        letter-spacing: 0.03em;
      }
      .detail-caption { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; margin-bottom: 6px; }
      .detail-caption span { font-size: 8.8pt; color: #475569; }
      .table-wrap { overflow-x: auto; }

      /* ——— Status Badges (High Contrast & Grayscale Distinct) ——— */
      .status-pill {
        display: inline-block;
        padding: 3px 6.5px;
        border-radius: 4px;
        font-size: 7.5pt;
        font-weight: 700;
        border: 1px solid transparent;
        white-space: normal;
        word-break: normal;
        line-height: 1.25;
        text-align: center;
        max-width: 100%;
        box-sizing: border-box;
        text-transform: uppercase;
        letter-spacing: 0.01em;
      }
      .badge-status-completed {
        background: #ecfdf5;
        color: #065f46;
        border: 1px solid #059669;
      }
      .badge-status-present {
        background: #ecfdf5;
        color: #047857;
        border: 1px solid #10b981;
      }
      .badge-status-late {
        background: #fef3c7;
        color: #92400e;
        border: 1px solid #d97706;
      }
      .badge-status-absent {
        background: #fef2f2;
        color: #991b1b;
        border: 1px solid #dc2626;
      }
      .badge-status-off {
        background: #f1f5f9;
        color: #334155;
        border: 1px solid #94a3b8;
      }
      .badge-status-wfh {
        background: #e0f2fe;
        color: #0369a1;
        border: 1px solid #0284c7;
      }
      .badge-status-break {
        background: #ede9fe;
        color: #5b21b6;
        border: 1px solid #7c3aed;
      }
      .badge-status-incomplete {
        background: #fff1f2;
        color: #9f1239;
        border: 1px dashed #e11d48;
      }
      .badge-status-not-clocked {
        background: #f8fafc;
        color: #64748b;
        border: 1px dashed #cbd5e1;
      }

      /* ——— Formal Management Sign-Off & Official Stamp Block (A4 Portrait) ——— */
      .signoff-section {
        margin-top: 2rem;
        border: 1.5px solid #cbd5e1;
        border-radius: 8px;
        background: #ffffff;
        padding: 18px 22px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.03);
        page-break-inside: avoid !important;
        break-inside: avoid !important;
      }
      .signoff-header-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 2px solid #0f766e;
        padding-bottom: 8px;
        margin-bottom: 16px;
      }
      .signoff-main-title {
        font-size: 9.2pt;
        font-weight: 800;
        color: #0f766e;
        text-transform: uppercase;
        letter-spacing: 0.05em;
      }
      .signoff-ref-tag {
        font-size: 8pt;
        color: #64748b;
        font-family: ui-monospace, SFMono-Regular, monospace;
      }
      .signoff-grid {
        display: flex;
        gap: 20px;
      }
      .signoff-card {
        flex: 1;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 16px 20px;
        font-size: 8.5pt;
        line-height: 1.4;
      }
      .signoff-card.review-card {
        background: #ffffff;
        border: 1.5px solid #cbd5e1;
      }
      .signoff-role-badge {
        font-size: 8.8pt;
        font-weight: 800;
        color: #0f172a;
        margin-bottom: 12px;
        padding-bottom: 6px;
        border-bottom: 1px solid #e2e8f0;
      }
      .signoff-role-badge.review-badge {
        color: #0369a1;
      }
      .signoff-field {
        margin-bottom: 10px;
        display: flex;
        align-items: baseline;
        gap: 8px;
      }
      .sf-lbl {
        width: 90px;
        flex-shrink: 0;
        font-weight: 700;
        color: #475569;
        font-size: 8.2pt;
      }
      .sf-val {
        color: #0f172a;
        font-weight: 700;
        font-size: 9.2pt;
      }
      .sf-line {
        flex: 1;
        border-bottom: 1.5px solid #94a3b8;
        height: 18px;
        display: inline-block;
      }
      .sf-date-line {
        max-width: 180px;
      }
      .signoff-sig-zone {
        margin: 12px 0;
      }
      .sf-sig-box {
        min-height: 95px;
        border-radius: 6px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        box-sizing: border-box;
        padding: 10px 12px;
      }
      .sf-sig-box.system-verified {
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        color: #334155;
      }
      .sf-sig-box.manual-sig {
        background: #fafbfc;
        border: 1.5px dashed #64748b;
        color: #64748b;
      }
      .sig-status-icon {
        font-size: 16pt;
        color: #0f766e;
        line-height: 1;
        margin-bottom: 4px;
      }
      .sig-status-text {
        font-size: 8.5pt;
        font-weight: 800;
        color: #0f172a;
      }
      .sig-status-sub {
        font-size: 7.2pt;
        color: #64748b;
        margin-top: 3px;
      }
      .sig-placeholder-main {
        font-size: 8.8pt;
        font-weight: 700;
        color: #64748b;
        margin-bottom: 3px;
      }
      .sig-placeholder-sub {
        font-size: 7.2pt;
        font-style: italic;
        color: #94a3b8;
      }
      .signoff-notice {
        font-size: 7.5pt;
        color: #64748b;
        margin-top: 6px;
      }
      .signoff-disclaimer {
        font-size: 7.5pt;
        color: #b45309;
        font-style: italic;
        margin-top: 6px;
      }

      /* ——— Confidential Footer ——— */
      .report-footer {
        margin-top: 1.25rem;
        padding-top: 0.75rem;
        border-top: 1px solid #e2e8f0;
        font-size: 7.8pt;
        color: #64748b;
        display: flex;
        justify-content: space-between;
        align-items: center;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
      }
      .confidential-tag {
        color: #b91c1c;
        font-weight: 800;
        letter-spacing: 0.05em;
      }

      /* PDF measurement & pagination simulation (A4 Portrait: 720px width x 1040px height) */
      #reportSheet.pdf-render { width: 720px; padding: 0; border-radius: 0; box-shadow: none; }
      .pdf-render .report-header { flex-direction: row; }
      .pdf-render .header-right { text-align: right; }
      .pdf-render .stats, .pdf-render .emp-profile-grid { grid-template-columns: repeat(2, 1fr); }
      .pdf-render .data-table { min-width: 0; font-size: 10pt; }
      .pdf-render .table-wrap { overflow: visible; }
      .pdf-page { width: 720px; height: 1040px; background: #ffffff; break-after: page; page-break-after: always; padding: 10px 0; box-sizing: border-box; }
      .pdf-page:last-child { break-after: auto; page-break-after: auto; }
      .pdf-continuation { display: flex; justify-content: space-between; gap: 12px; border-bottom: 2px solid #0f766e; margin-bottom: 10px; padding-bottom: 5px; font-size: 8.5pt; }
      .pdf-continuation strong { font-size: 10pt; color: #0f172a; }
      .pdf-continuation span { color: #475569; }
      .report-tail { display: flow-root; }

      /* ——— Print Media Rules (A4 Portrait: 210 x 297 mm) ——— */
      @media print {
        @page {
          size: A4 portrait;
          margin: 10mm 10mm 12mm 10mm;
        }
        body { background: #ffffff !important; }
        .toolbar { display: none !important; }
        .wrap { padding: 0 !important; max-width: none !important; margin: 0 !important; }
        #reportSheet {
          box-shadow: none !important;
          border-radius: 0 !important;
          padding: 0 !important;
          width: 100% !important;
        }
        .report-summary-page {
          page-break-after: always !important;
          break-after: page !important;
        }
        tr {
          page-break-inside: avoid !important;
          break-inside: avoid !important;
        }
        thead {
          display: table-header-group !important;
        }
        .date-group-banner {
          page-break-after: avoid !important;
          break-after: avoid !important;
          page-break-inside: avoid !important;
          break-inside: avoid !important;
        }
        .table-wrap { overflow: visible; }
        .data-table { min-width: 0; font-size: 10pt; width: 100%; }
        .report-title { font-size: 16pt; }
        .stats, .emp-profile-grid { grid-template-columns: repeat(2, 1fr) !important; }
        .report-header { flex-direction: row; }
        .report-header .header-right { text-align: right; }
        .signoff-section, .report-footer, .stats, .emp-profile-card, .report-header, .report-intro, .report-basis, .report-review {
          page-break-inside: avoid !important;
          break-inside: avoid !important;
        }
      }
    </style></head><body>';

    echo '<div class="toolbar no-print">';
    echo '<button class="btn btn-primary" type="button" id="btnPdfDownload">' . e(__('pdf_download')) . '</button>';
    echo '<button class="btn btn-ghost" type="button" onclick="window.print()">' . e(__('print')) . '</button>';
    echo '<button class="btn btn-ghost" type="button" onclick="window.close()">' . e(__('close')) . '</button>';
    echo '<div class="hint" id="pdfHint"><span class="tip-badge">CLEAN PRINT TIP</span> In browser print dialog under <strong>More settings</strong>, uncheck <strong>"Headers and footers"</strong> to remove browser URL and timestamps. Or click <strong>Download PDF</strong> for a 100% clean document.</div>';
    echo '</div>';

    echo '<div class="wrap"><div id="reportSheet">';

    // Closure to render Corporate Header
    $renderHeader = static function () use ($logoBase64, $companyName, $companyReg, $companyIncorporated, $companyAddress, $companyNature, $reportRef, $generated, $adminName) {
        echo '<div class="report-header">';
        echo '<div class="header-left">';
        if ($logoBase64 !== '') {
            echo '<img src="' . $logoBase64 . '" alt="LASTO" class="company-logo">';
        }
        echo '<div class="company-details">';
        echo '<h1 class="company-name">' . e($companyName) . '</h1>';
        echo '<div class="company-reg">Registration No: ' . e($companyReg) . ' (Inc: ' . e($companyIncorporated) . ')</div>';
        echo '<div class="company-address">' . e($companyAddress) . '</div>';
        echo '<div class="company-nature">' . e($companyNature) . '</div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="header-right">';
        echo '<div class="doc-badge">' . e(__('report_internal')) . '</div>';
        echo '<div class="report-meta-table">';
        echo '<div><strong>' . e(__('report_reference')) . ':</strong> ' . e($reportRef) . '</div>';
        echo '<div><strong>' . e(__('generated_at')) . ':</strong> ' . e($generated) . '</div>';
        echo '<div>Asia/Kuala_Lumpur (UTC+08:00)</div>';
        echo '<div><strong>' . e(__('report_generated_by')) . ':</strong> ' . e($adminName) . '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    };

    // If Case 1 (All staff, multiple days): Open dedicated Management Summary Page container
    if ($layoutMode === 'all_multi') {
        echo '<div class="report-summary-page">';
    }

    // 1. Corporate Header
    $renderHeader();

    // 2. Report Intro & Coverage
    echo '<section class="report-intro">';
    echo '<h2 class="report-title">' . e($title) . '</h2>';
    echo '<div class="report-coverage">';
    echo '<span>' . e(__('report_period')) . ': <strong>' . e($period) . '</strong></span>';
    echo '<span>' . e(__('report_scope')) . ': <strong>' . e((string) ($context['scope'] ?? ($employee['full_name'] ?? __('all_staff')))) . '</strong></span>';
    if (isset($context['staff_count'])) {
        echo '<span>' . e(__('report_staff_covered')) . ': <strong>' . number_format((int) $context['staff_count']) . '</strong></span>';
    }
    if (isset($context['record_count'])) {
        echo '<span>' . e(__('total_attendance_records')) . ': <strong>' . number_format((int) $context['record_count']) . '</strong></span>';
    }
    echo '</div></section>';

    // 3. Employee Profile Box (When single staff is selected)
    if ($employee) {
        $weeklyOff = isset($employee['weekly_off_day'])
            ? weekday_name((int)$employee['weekly_off_day'], lang_current())
            : 'Sunday';
        echo '<div class="emp-profile-card">';
        echo '<div class="emp-profile-title">INDIVIDUAL EMPLOYEE TIMESHEET PROFILE</div>';
        echo '<div class="emp-profile-grid">';
        echo '<div class="emp-item"><span class="emp-lbl">Employee Name</span><span class="emp-val">' . e($employee['full_name']) . '</span></div>';
        echo '<div class="emp-item"><span class="emp-lbl">Employee ID</span><span class="emp-val">' . e(!empty($employee['employee_id']) ? $employee['employee_id'] : '—') . '</span></div>';
        echo '<div class="emp-item"><span class="emp-lbl">Designation / Role</span><span class="emp-val">' . e(!empty($employee['job_position']) ? $employee['job_position'] : 'Staff') . '</span></div>';
        echo '<div class="emp-item"><span class="emp-lbl">Weekly Rest Day</span><span class="emp-val">' . e($weeklyOff) . '</span></div>';
        echo '</div>';
        echo '</div>';
    }

    // 4. KPI Summary Cards (2x2 grid in Portrait)
    if ($summary) {
        echo '<div class="stats">';
        foreach ($summary as $label => $value) {
            $valStr = (string) $value;
            if (preg_match('/^(\d+)h\s*(\d+)m$/', $valStr, $m)) {
                $formattedVal = number_format((int)$m[1]) . ' hr' . ((int)$m[2] > 0 ? ' ' . (int)$m[2] . ' min' : '');
            } elseif (preg_match('/^(\d+)m$/', $valStr, $m)) {
                $formattedVal = (int)$m[1] . ' min';
            } else {
                $formattedVal = $valStr;
            }
            echo '<div class="stat"><span class="stat-lbl">' . e((string) $label) . '</span><b class="stat-num">' . e($formattedVal) . '</b>';
            if (!empty($context['summary_notes'][$label])) {
                echo '<span class="stat-note">' . e($context['summary_notes'][$label]) . '</span>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    // 5. Basis Statement & Review Exception Items
    if (!empty($context['basis'])) {
        echo '<section class="report-basis"><h3 class="report-section-heading">' . e(__('report_basis_title')) . '</h3><p>' . e($context['basis']) . '</p></section>';
    }
    if (!empty($context['review'])) {
        echo '<section class="report-review"><h3 class="report-section-heading">' . e(__('report_review_items')) . '</h3><div class="review-counts">';
        foreach ($context['review'] as $label => $count) {
            echo '<span><strong>' . e((string) $count) . '</strong> ' . e($label) . '</span>';
        }
        echo '</div><p class="review-note">' . e(__('report_review_note')) . '</p></section>';
    }

    // Close dedicated Management Summary Page container (Case 1)
    if ($layoutMode === 'all_multi') {
        echo '<div class="summary-page-footer">';
        echo '<div class="summary-page-notice">Section B — Daily Attendance Details follow on Page 2 &rarr;</div>';
        echo '<div>' . e($reportRef) . ' · ' . e($period) . '</div>';
        echo '</div>';
        echo '</div>'; // close .report-summary-page
    }

    // ——— Section B: Attendance Details ———
    echo '<div class="report-details-section">';

    if ($layoutMode === 'all_multi' || $layoutMode === 'all_single') {
        // All Staff Grouped by Date (A4 Portrait 6-Column Layout)
        echo '<div class="detail-caption"><h3 class="report-section-heading">' . e($extraTitle !== '' ? $extraTitle : __('report_detail_title')) . '</h3><span>' . number_format(count($matrix)) . ' ' . e(__('records')) . '</span></div>';

        foreach ($groupedByDate as $date => $dayItems) {
            $dayTotal = count($dayItems);
            $dayClockedIn = 0;
            $dayLate = 0;
            $dayDayOff = 0;
            $dayAbsent = 0;
            $dayIncomplete = 0;
            $dayOpen = 0;
            $dayWorkMinutes = 0;

            foreach ($dayItems as $item) {
                $r = $item['record'];
                $status = (string)($r['status'] ?? '');
                $isDayOff = ((int)($r['is_day_off'] ?? 0) === 1) || $status === 'day_off';
                $isLate = ((int)($r['late_minutes'] ?? 0) > 0) || $status === 'late';
                $hasClockIn = !empty($r['clock_in']);
                $hasClockOut = !empty($r['clock_out']);
                $workMins = (int)($r['work_minutes'] ?? 0);
                $dayWorkMinutes += $workMins;

                if ($isDayOff) {
                    $dayDayOff++;
                } elseif ($status === 'absent') {
                    $dayAbsent++;
                } elseif ($hasClockIn || in_array($status, ['completed', 'present', 'on_break', 'late', 'incomplete'], true)) {
                    $dayClockedIn++;
                    if ($isLate) {
                        $dayLate++;
                    }
                    if ($hasClockIn && !$hasClockOut) {
                        if ($r['work_date'] < today_str()) {
                            $dayIncomplete++;
                        } else {
                            $dayOpen++;
                        }
                    }
                }
            }

            $wkDay = weekday_name((int)date('w', strtotime($date)), lang_current());

            echo '<div class="date-group-block" data-date="' . e($date) . '">';
            echo '<div class="date-group-banner">';
            echo '<div class="date-banner-left">';
            echo '<div class="date-heading-title"><span class="date-name">' . e(format_date($date)) . '</span><span class="date-weekday">(' . e($wkDay) . ')</span></div>';
            echo '<span class="date-staff-badge">' . number_format($dayTotal) . ' ' . e(__('staff')) . '</span>';
            echo '</div>';
            echo '<div class="date-metrics-bar">';
            echo '<span class="dm-item"><span class="dm-lbl">' . e(__('clock_in_col')) . ':</span> <strong class="dm-val">' . $dayClockedIn . '</strong>';
            if ($dayLate > 0) {
                echo ' <span class="dm-sub">(incl. ' . $dayLate . ' ' . e(__('late')) . ')</span>';
            }
            echo '</span>';
            echo '<span class="dm-sep">&bull;</span>';
            echo '<span class="dm-item"><span class="dm-lbl">' . e(__('scheduled_days_off')) . ':</span> <strong class="dm-val">' . $dayDayOff . '</strong></span>';
            if ($dayAbsent > 0) {
                echo '<span class="dm-sep">&bull;</span>';
                echo '<span class="dm-item dm-warn"><span class="dm-lbl">' . e(__('absent')) . ':</span> <strong class="dm-val">' . $dayAbsent . '</strong></span>';
            }
            if ($dayIncomplete > 0) {
                echo '<span class="dm-sep">&bull;</span>';
                echo '<span class="dm-item dm-warn"><span class="dm-lbl">' . e(__('missing_clockout_count')) . ':</span> <strong class="dm-val">' . $dayIncomplete . '</strong></span>';
            }
            if ($dayOpen > 0) {
                echo '<span class="dm-sep">&bull;</span>';
                echo '<span class="dm-item dm-info"><span class="dm-lbl">' . e(__('report_open_shifts')) . ':</span> <strong class="dm-val">' . $dayOpen . '</strong></span>';
            }
            echo '<span class="dm-sep">&bull;</span>';
            echo '<span class="dm-item"><span class="dm-lbl">' . e(__('total_working_time')) . ':</span> <strong class="dm-val">' . format_minutes($dayWorkMinutes) . '</strong></span>';
            echo '</div></div>';

            // 6-Column A4 Portrait Table
            // Employee/ID (32%), Clock In (13%), Clock Out (13%), Break (12%), Worked (13%), Status (17%)
            echo '<div class="table-wrap"><table class="data-table"><colgroup>';
            echo '<col style="width:32%">'; // Employee / ID
            echo '<col style="width:13%">'; // Clock In (+ late subline)
            echo '<col style="width:13%">'; // Clock Out
            echo '<col style="width:12%">'; // Break
            echo '<col style="width:13%">'; // Worked
            echo '<col style="width:17%">'; // Status (+ WFH sub-badge)
            echo '</colgroup><thead><tr>';
            echo '<th scope="col">' . e(__('employee')) . ' / ID</th>';
            echo '<th scope="col" class="time-cell">' . e(__('clock_in_col')) . '</th>';
            echo '<th scope="col" class="time-cell">' . e(__('clock_out_col')) . '</th>';
            echo '<th scope="col" class="duration-cell">' . e(__('total_break_time')) . '</th>';
            echo '<th scope="col" class="duration-cell">' . e(__('total_working_time')) . '</th>';
            echo '<th scope="col" class="text-center">' . e(__('status')) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($dayItems as $item) {
                $r = $item['record'];
                $empName = (string)($r['full_name'] ?? '');
                $empId = (string)($r['employee_id'] ?? '');
                $clockIn = format_time($r['clock_in'] ? (string)$r['clock_in'] : null);
                $clockOut = format_time($r['clock_out'] ? (string)$r['clock_out'] : null);
                $lateMins = (int)($r['late_minutes'] ?? 0);
                $breakStr = format_minutes((int)($r['total_break_minutes'] ?? 0));
                $statusHtml = $formatCell(status_label((string)($r['status'] ?? '')));
                $isWfh = !empty($r['is_wfh']);

                $status = (string)($r['status'] ?? '');
                $workDate = (string)($r['work_date'] ?? '');
                $hasClockIn = !empty($r['clock_in']);
                $hasClockOut = !empty($r['clock_out']);
                $isActiveShift = ($hasClockIn && !$hasClockOut && $workDate >= today_str())
                    || in_array($status, ['present', 'on_break'], true);

                if ($isActiveShift) {
                    $workedHtml = '<span class="status-in-progress">' . e(__('in_progress')) . '</span>';
                } else {
                    $workedHtml = '<span class="duration-main">' . e(format_minutes((int)($r['work_minutes'] ?? 0))) . '</span>';
                }

                echo '<tr>';
                echo '<td><div class="emp-name-box"><span class="emp-name">' . e($empName) . '</span>';
                if ($empId !== '') {
                    echo '<span class="staff-id">' . e($empId) . '</span>';
                }
                echo '</div></td>';

                // Clock In with late-arrival minutes secondary indicator
                echo '<td class="time-cell nowrap"><div class="time-main">' . e($clockIn) . '</div>';
                if ($lateMins > 0) {
                    echo '<div class="sub-late">' . e(__('late')) . ' +' . $lateMins . ' min</div>';
                }
                echo '</td>';

                echo '<td class="time-cell nowrap"><div class="time-main">' . e($clockOut) . '</div></td>';
                echo '<td class="duration-cell nowrap"><div class="duration-main">' . e($breakStr) . '</div></td>';
                echo '<td class="duration-cell nowrap">' . $workedHtml . '</td>';

                // Status with WFH arrangement secondary indicator
                echo '<td class="text-center"><div class="status-cell-wrap">' . $statusHtml;
                if ($isWfh) {
                    echo '<span class="sub-wfh">WFH</span>';
                }
                echo '</div></td>';
                echo '</tr>';
            }

            echo '</tbody></table></div></div>'; // close table, .table-wrap, .date-group-block
        }
    } elseif ($layoutMode === 'single_multi' || $layoutMode === 'single_single') {
        // Single Staff Timesheet (A4 Portrait 6-Column Layout: Date/Day in Col 1)
        echo '<div class="detail-caption"><h3 class="report-section-heading">' . e($extraTitle !== '' ? $extraTitle : __('report_detail_title')) . '</h3><span>' . number_format(count($matrix)) . ' ' . e(__('records')) . '</span></div>';
        echo '<div class="date-group-block"><div class="table-wrap"><table class="data-table"><colgroup>';
        echo '<col style="width:26%">'; // Date / Day
        echo '<col style="width:15%">'; // Clock In (+ late subline)
        echo '<col style="width:15%">'; // Clock Out
        echo '<col style="width:13%">'; // Break
        echo '<col style="width:14%">'; // Worked
        echo '<col style="width:17%">'; // Status (+ WFH subline)
        echo '</colgroup><thead><tr>';
        echo '<th scope="col">' . e(__('date')) . ' / Day</th>';
        echo '<th scope="col" class="time-cell">' . e(__('clock_in_col')) . '</th>';
        echo '<th scope="col" class="time-cell">' . e(__('clock_out_col')) . '</th>';
        echo '<th scope="col" class="duration-cell">' . e(__('total_break_time')) . '</th>';
        echo '<th scope="col" class="duration-cell">' . e(__('total_working_time')) . '</th>';
        echo '<th scope="col" class="text-center">' . e(__('status')) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($matrix as $rowIndex => $row) {
            $r = $context['records'][$rowIndex] ?? [];
            $workDate = (string)($r['work_date'] ?? '');
            $wkDay = $workDate !== '' ? weekday_name((int)date('w', strtotime($workDate)), lang_current()) : '';
            $dateDisplay = format_date($workDate) . ($wkDay !== '' ? ' (' . $wkDay . ')' : '');
            $clockIn = (string)($row[2] ?? '—');
            $clockOut = (string)($row[3] ?? '—');
            $lateMins = (int)($r['late_minutes'] ?? 0);
            $breakStr = (string)($row[5] ?? '0m');
            $statusHtml = $formatCell((string)($row[7] ?? ''));
            $isWfh = !empty($r['is_wfh']);

            $status = (string)($r['status'] ?? '');
            $hasClockIn = !empty($r['clock_in']);
            $hasClockOut = !empty($r['clock_out']);
            $isActiveShift = ($hasClockIn && !$hasClockOut && $workDate >= today_str())
                || in_array($status, ['present', 'on_break'], true);

            if ($isActiveShift) {
                $workedHtml = '<span class="status-in-progress">' . e(__('in_progress')) . '</span>';
            } else {
                $workedHtml = '<span class="duration-main">' . e((string)($row[6] ?? '0m')) . '</span>';
            }

            echo '<tr>';
            echo '<td class="nowrap"><div class="date-main"><strong>' . e($dateDisplay) . '</strong></div></td>';
            echo '<td class="time-cell nowrap"><div class="time-main">' . e($clockIn) . '</div>';
            if ($lateMins > 0) {
                echo '<div class="sub-late">' . e(__('late')) . ' +' . $lateMins . ' min</div>';
            }
            echo '</td>';
            echo '<td class="time-cell nowrap"><div class="time-main">' . e($clockOut) . '</div></td>';
            echo '<td class="duration-cell nowrap"><div class="duration-main">' . e($breakStr) . '</div></td>';
            echo '<td class="duration-cell nowrap">' . $workedHtml . '</td>';
            echo '<td class="text-center"><div class="status-cell-wrap">' . $statusHtml;
            if ($isWfh) {
                echo '<span class="sub-wfh">WFH</span>';
            }
            echo '</div></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div></div>';
    } else {
        // Non-attendance table (e.g. Breaks report)
        echo '<div class="detail-caption"><h3 class="report-section-heading">' . e($extraTitle !== '' ? $extraTitle : __('report_detail_title')) . '</h3><span>' . number_format(count($matrix)) . ' ' . e(__('records')) . '</span></div>';
        echo '<div class="date-group-block"><div class="table-wrap"><table class="data-table"><colgroup>';
        foreach ([35, 20, 25, 20] as $w) {
            echo '<col style="width:' . $w . '%">';
        }
        echo '</colgroup><thead><tr>';
        foreach ($headers as $idx => $h) {
            $alignment = ($idx > 0) ? 'duration-cell' : '';
            echo '<th scope="col" class="' . $alignment . '">' . e($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if (!$matrix) {
            echo '<tr><td colspan="' . max(1, count($headers)) . '">' . e(__('no_report_records')) . '</td></tr>';
        } else {
            foreach ($matrix as $row) {
                echo '<tr>';
                foreach ($row as $idx => $cell) {
                    $cls = ($idx > 0) ? 'duration-cell nowrap' : '';
                    echo '<td class="' . $cls . '">' . e((string)$cell) . '</td>';
                }
                echo '</tr>';
            }
        }
        echo '</tbody></table></div></div>';
    }

    echo '</div>'; // close .report-details-section

    // Sign-Off & Verification Block
    echo '<div class="signoff-section">';
    echo '<div class="signoff-header-bar">';
    echo '<span class="signoff-main-title">Report Authentication & Administrative Review</span>';
    echo '<span class="signoff-ref-tag">' . e($reportRef) . '</span>';
    echo '</div>';
    echo '<div class="signoff-grid">';

    echo '<div class="signoff-card">';
    echo '<div class="signoff-role-badge">' . e(__('report_prepared_by')) . ' (System Export)</div>';
    echo '<div class="signoff-field"><span class="sf-lbl">Name:</span> <span class="sf-val">' . e($adminName) . '</span></div>';
    echo '<div class="signoff-field"><span class="sf-lbl">Designation:</span> <span class="sf-val">System Administrator</span></div>';
    echo '<div class="signoff-sig-zone">';
    echo '<div class="sf-sig-box system-verified">';
    echo '<span class="sig-status-icon">&#10003;</span>';
    echo '<span class="sig-status-text">Digitally Generated &amp; Exported</span>';
    echo '<span class="sig-status-sub">' . e(__('report_exported_note')) . '</span>';
    echo '</div>';
    echo '</div>';
    echo '<div class="signoff-field"><span class="sf-lbl">Date:</span> <span class="sf-val">' . e($generated) . '</span></div>';
    echo '<div class="signoff-notice">Official audit record generated from system database.</div>';
    echo '</div>';

    echo '<div class="signoff-card review-card">';
    echo '<div class="signoff-role-badge review-badge">' . e(__('report_reviewed_by')) . ' (Management)</div>';
    echo '<div class="signoff-field"><span class="sf-lbl">Name:</span> <span class="sf-line">&nbsp;</span></div>';
    echo '<div class="signoff-field"><span class="sf-lbl">Designation:</span> <span class="sf-line">&nbsp;</span></div>';
    echo '<div class="signoff-sig-zone">';
    echo '<div class="sf-sig-box manual-sig">';
    echo '<span class="sig-placeholder-main">Signature / Official Company Stamp</span>';
    echo '<span class="sig-placeholder-sub">(Affix management signature &amp; company seal)</span>';
    echo '</div>';
    echo '</div>';
    echo '<div class="signoff-field"><span class="sf-lbl">Date:</span> <span class="sf-line sf-date-line">&nbsp;</span></div>';
    echo '<div class="signoff-disclaimer">' . e(__('report_review_pending')) . '</div>';
    echo '</div>';

    echo '</div>'; // close .signoff-grid
    echo '</div>'; // close .signoff-section

    // Confidential Footer
    echo '<div class="report-footer">';
    echo '<div class="confidential-tag">' . e(__('report_internal_footer')) . '</div>';
    echo '<div>' . e($reportRef) . '</div>';
    echo '</div>';

    echo '</div></div>'; // close #reportSheet, .wrap

    if ($autoPrint) {
        echo '<script>window.addEventListener("load", function () { setTimeout(function () { window.print(); }, 300); });</script>';
    }
    echo '<script src="https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js"></script>';
    echo '<script>
(function () {
  var btn = document.getElementById("btnPdfDownload");
  var hint = document.getElementById("pdfHint");
  var sheet = document.getElementById("reportSheet");
  var fileName = ' . json_encode($safeName) . ';
  var preparing = ' . json_encode(__('pdf_preparing')) . ';
  var ready = ' . json_encode(__('pdf_ready_hint')) . ';
  var titleText = ' . json_encode($title) . ';
  var periodMeta = ' . json_encode($period . ' · ' . $reportRef) . ';
  if (!btn || !sheet) return;

  function setBusy(on) {
    btn.disabled = !!on;
    sheet.classList.toggle("pdf-render", !!on);
    btn.textContent = on ? preparing : ' . json_encode(__('pdf_download')) . ';
    if (hint) hint.textContent = on ? preparing : ready;
  }

  // Adaptive PDF page builder for A4 Portrait (720px width x 1040px height)
  function buildPdfPages() {
    var host = document.createElement("div");
    host.style.cssText = "position:absolute;left:-20000px;top:0;width:720px;";
    var root = document.createElement("div");
    root.className = "pdf-render";
    root.style.width = "720px";
    host.appendChild(root);
    document.body.appendChild(host);

    var tail = document.createElement("div");
    tail.className = "report-tail";
    Array.from(sheet.querySelectorAll(".signoff-section, .report-footer")).forEach(function (source) {
      tail.appendChild(source.cloneNode(true));
    });
    root.appendChild(tail);
    var tailHeight = tail.getBoundingClientRect().height || 130;
    tail.remove();

    var page;
    function newPage(isFirst) {
      page = document.createElement("div");
      page.className = "pdf-page";
      root.appendChild(page);
      if (!isFirst) {
        var heading = document.createElement("div");
        heading.className = "pdf-continuation";
        var name = document.createElement("strong");
        name.textContent = titleText;
        var meta = document.createElement("span");
        meta.textContent = periodMeta;
        heading.appendChild(name);
        heading.appendChild(meta);
        page.appendChild(heading);
      }
    }

    function overflows(reserved) {
      return (page.lastElementChild.getBoundingClientRect().bottom - page.getBoundingClientRect().top) > 1020 - (reserved || 0);
    }

    var summaryPage = sheet.querySelector(".report-summary-page");
    if (summaryPage) {
      // Case 1: All staff multi-day -> Dedicated Page 1 Management Summary
      newPage(true);
      page.appendChild(summaryPage.cloneNode(true));
      newPage(false);
    } else {
      // Flowing layout on Page 1 (Header + Intro + Profile / KPI Stats)
      newPage(true);
      Array.from(sheet.children).forEach(function (child) {
        if (!child.matches(".report-details-section, .signoff-section, .report-footer")) {
          page.appendChild(child.cloneNode(true));
        }
      });
    }

    // Process date group blocks or tables in details section
    var dateBlocks = Array.from(sheet.querySelectorAll(".date-group-block"));
    if (dateBlocks.length === 0) {
      var singleTable = sheet.querySelector(".data-table");
      if (singleTable) {
        dateBlocks = [{ querySelector: function(q) { return q === ".data-table" ? singleTable : null; } }];
      }
    }

    dateBlocks.forEach(function (block, blockIndex) {
      var banner = block.querySelector ? block.querySelector(".date-group-banner") : null;
      var sourceTable = block.querySelector ? block.querySelector(".data-table") : null;
      if (!sourceTable) return;

      var dateTitleText = "";
      if (banner) {
        var titleEl = banner.querySelector(".date-name") || banner.querySelector(".date-heading-title");
        if (titleEl) dateTitleText = titleEl.textContent.trim();
      }

      // Check if we have room to start this date group banner + table header + at least 2 rows
      if (page.children.length > 1 && overflows(140)) {
        newPage(false);
      }

      if (banner) {
        page.appendChild(banner.cloneNode(true));
      }

      var table = sourceTable.cloneNode(false);
      var colgroup = sourceTable.querySelector("colgroup");
      if (colgroup) table.appendChild(colgroup.cloneNode(true));
      if (sourceTable.tHead) table.appendChild(sourceTable.tHead.cloneNode(true));
      var tbody = document.createElement("tbody");
      table.appendChild(tbody);
      page.appendChild(table);

      var sourceRows = Array.from(sourceTable.tBodies[0].rows);
      var isLastBlock = (blockIndex === dateBlocks.length - 1);

      sourceRows.forEach(function (sourceRow, rowIndex) {
        var row = sourceRow.cloneNode(true);
        tbody.appendChild(row);

        var isNearEnd = isLastBlock && (rowIndex >= sourceRows.length - 3);
        var reserve = isNearEnd ? tailHeight : 0;

        if (overflows(reserve) && tbody.rows.length > 1) {
          row.remove();
          newPage(false);

          if (dateTitleText) {
            var contSub = document.createElement("div");
            contSub.className = "date-continuation-sub";
            contSub.textContent = dateTitleText + " (continued)";
            page.appendChild(contSub);
          }

          table = sourceTable.cloneNode(false);
          if (colgroup) table.appendChild(colgroup.cloneNode(true));
          if (sourceTable.tHead) table.appendChild(sourceTable.tHead.cloneNode(true));
          tbody = document.createElement("tbody");
          table.appendChild(tbody);
          page.appendChild(table);

          tbody.appendChild(row);
        }
      });
    });

    // Append sign-off section & confidential footer
    page.appendChild(tail);
    if (overflows()) {
      tail.remove();
      newPage(false);
      page.appendChild(tail);
    }

    return { element: root, cleanup: function () { host.remove(); } };
  }

  function downloadPdf() {
    if (typeof html2pdf !== "function") {
      window.print();
      return;
    }
    setBusy(true);
    var pages;
    try {
      pages = buildPdfPages();
    } catch (error) {
      setBusy(false);
      window.print();
      return;
    }
    var opt = {
      margin: [10, 10, 12, 10],
      filename: fileName,
      image: { type: "jpeg", quality: 0.98 },
      html2canvas: { scale: 2, useCORS: true, letterRendering: true, backgroundColor: "#ffffff" },
      jsPDF: { unit: "mm", format: "a4", orientation: "portrait" },
      pagebreak: {
        mode: ["css", "legacy"],
        avoid: ["tr", ".signoff-section", ".stats", ".emp-profile-card", ".report-header", ".report-footer", ".report-intro", ".report-basis", ".report-review", ".date-group-banner"]
      }
    };
    html2pdf().set(opt).from(pages.element).toPdf().get("pdf").then(function (pdf) {
      var total = pdf.internal.getNumberOfPages();
      for (var page = 1; page <= total; page++) {
        pdf.setPage(page);
        pdf.setFontSize(8);
        pdf.setTextColor(71, 85, 105);
        pdf.text(' . json_encode($reportRef) . ', 10, 288);
        pdf.text(page + " / " + total, 200, 288, { align: "right" });
      }
    }).save().then(function () {
      pages.cleanup();
      setBusy(false);
    }).catch(function () {
      pages.cleanup();
      setBusy(false);
      window.print();
    });
  }

  btn.addEventListener("click", downloadPdf);
})();
</script>';
    echo '</body></html>';
    exit;
}
