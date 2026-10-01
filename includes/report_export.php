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
            'Work From Home (WFH)' => 'badge-status-wfh',
            'WFH' => 'badge-status-wfh',
            'On Break' => 'badge-status-break',
            'Selesai' => 'badge-status-completed',
            'Hadir' => 'badge-status-completed',
            'Lewat' => 'badge-status-late',
            'Tidak Hadir' => 'badge-status-absent',
            'Tidak Lengkap' => 'badge-status-absent',
            'Cuti' => 'badge-status-off',
            'Sedang Rehat' => 'badge-status-break',
        ];
        foreach (['completed' => 'badge-status-completed', 'present' => 'badge-status-completed', 'late' => 'badge-status-late',
            'absent' => 'badge-status-absent', 'incomplete' => 'badge-status-absent', 'not_clocked_in' => 'badge-status-absent',
            'day_off' => 'badge-status-off', 'on_break' => 'badge-status-break'] as $status => $class) {
            $statusMap[status_label($status)] = $class;
        }
        $statusMap[__('leave_wfh')] = 'badge-status-wfh';
        if (isset($statusMap[$trimmed])) {
            return '<span class="status-pill ' . $statusMap[$trimmed] . '">' . e($trimmed) . '</span>';
        }
        return e($cell);
    };

    echo '<!DOCTYPE html><html lang="' . e(html_lang()) . '"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' · ' . e($companyName) . '</title>';
    echo '<style>
      :root { color-scheme: light; }
      * { box-sizing: border-box; }
      body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        margin: 0;
        background: #f1f5f9;
        color: #0f172a;
        line-height: 1.4;
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
      .wrap { padding: 1.5rem; max-width: 1300px; margin: 0 auto; }
      #reportSheet {
        background: #ffffff;
        width: 100%;
        padding: 2rem 2.25rem;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(15,23,42,.08);
      }

      /* ——— Formal Corporate Header ——— */
      .report-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1.5rem;
        padding-bottom: 1.25rem;
        border-bottom: 2.5px solid #0f766e;
        margin-bottom: 1.25rem;
        page-break-inside: avoid;
      }
      .header-left {
        display: flex;
        align-items: center;
        gap: 1.25rem;
        flex: 1 1 auto;
      }
      .company-logo {
        width: 76px;
        height: 76px;
        object-fit: contain;
        border-radius: 8px;
        background: #0b1220;
        padding: 5px;
        flex-shrink: 0;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
      }
      .company-details { line-height: 1.35; }
      .company-name {
        font-size: 1.35rem;
        font-weight: 800;
        color: #0f172a;
        letter-spacing: 0.02em;
        margin: 0 0 3px;
      }
      .company-reg {
        font-size: 8.5pt;
        color: #0f766e;
        font-weight: 700;
        margin-bottom: 3px;
      }
      .company-address, .company-nature {
        font-size: 8pt;
        color: #475569;
      }
      .company-nature { margin-top: 2px; }
      .header-right {
        flex-shrink: 0;
        text-align: right;
      }
      .doc-badge {
        display: inline-block;
        background: #0f766e;
        color: #ffffff;
        font-size: 8pt;
        font-weight: 800;
        padding: 4px 10px;
        border-radius: 4px;
        letter-spacing: 0.06em;
        margin-bottom: 8px;
      }
      .report-meta-table {
        font-size: 8.5pt;
        color: #334155;
        line-height: 1.5;
      }
      .report-meta-table strong { color: #0f172a; }

      /* ——— Employee Profile Box (Single Staff Filter) ——— */
      .emp-profile-card {
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        border-left: 4.5px solid #0f766e;
        border-radius: 6px;
        padding: 0.75rem 1.15rem;
        margin-bottom: 1.25rem;
        page-break-inside: avoid;
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
        grid-template-columns: repeat(4, 1fr);
        gap: 1rem;
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

      /* ——— Executive Summary Cards ——— */
      .stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 0.85rem;
        margin-bottom: 1.35rem;
        page-break-inside: avoid;
      }
      .stat {
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        padding: 0.65rem 0.95rem;
        background: #f8fafc;
      }
      .stat-lbl {
        display: block;
        font-size: 7.2pt;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        font-weight: 700;
        margin-bottom: 3px;
      }
      .stat-num {
        display: block;
        font-size: 1.25rem;
        color: #0f172a;
        font-weight: 800;
      }

      /* ——— Data Table (Repeating headers & no cut rows) ——— */
      table {
        width: 100%;
        border-collapse: collapse;
        font-size: 9pt;
        table-layout: fixed;
        page-break-inside: auto;
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
        padding: 6px 8px;
        vertical-align: middle;
        text-align: left;
      }
      th {
        background: #eef2f6;
        color: #0f172a;
        font-weight: 700;
        text-transform: uppercase;
        font-size: 7.2pt;
        letter-spacing: 0.04em;
        white-space: normal;
      }
      tr:nth-child(even) td { background: #fafbfd; }
      .nowrap { white-space: nowrap; }
      .report-title { margin: 0 0 6px; font-size: 22pt; font-weight: 700; letter-spacing: -.02em; }
      .report-intro { margin-bottom: 18px; }
      .report-coverage { display: flex; flex-wrap: wrap; gap: 6px 24px; font-size: 9pt; color: #475569; }
      .report-coverage strong { color: #0f172a; }
      .stat-note { display: block; margin-top: 6px; color: #475569; font-size: 8pt; line-height: 1.4; }
      .stat-lbl { font-size: 8pt; color: #475569; text-transform: none; letter-spacing: 0; }
      .stat-num { font-variant-numeric: tabular-nums; }
      .report-section-heading { margin: 0 0 8px; font-size: 11pt; color: #0f172a; }
      .report-basis { border-left: 3px solid #0f766e; padding: 10px 14px; background: #f8fafc; margin-bottom: 18px; break-inside: avoid; }
      .report-basis p, .review-note { margin: 0; font-size: 9pt; color: #475569; line-height: 1.55; }
      .report-review { margin-bottom: 18px; break-inside: avoid; }
      .review-counts { display: flex; flex-wrap: wrap; gap: 8px 20px; margin-bottom: 7px; font-size: 9pt; }
      .review-counts strong { color: #92400e; margin-right: 4px; }
      .data-table .time-cell { text-align: center; font-variant-numeric: tabular-nums; }
      .data-table .duration-cell { text-align: right; font-variant-numeric: tabular-nums; }
      .data-table td { overflow-wrap: anywhere; }
      .staff-id { display: block; margin-top: 2px; font-size: 8pt; color: #64748b; }
      .detail-caption { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; }
      .detail-caption span { font-size: 8pt; color: #475569; }
      .table-wrap { overflow-x: auto; }
      .report-header .company-logo { width: 60px; height: 60px; box-shadow: none; }
      .report-header .company-name { font-size: 16pt; }
      .report-header .header-right { max-width: 280px; }
      .signoff-section { gap: 2rem; margin-top: 1.5rem; }
      .signoff-box { border-top: 1px solid #cbd5e1; }
      @media (max-width: 760px) {
        .wrap { padding: 12px; }
        #reportSheet { padding: 20px; }
        .report-header { flex-direction: column; }
        .header-right { text-align: left; }
        .stats, .emp-profile-grid { grid-template-columns: repeat(2, 1fr); }
        .data-table { min-width: 980px; }
      }
      #reportSheet.pdf-render { width: 1062px; padding: 0; border-radius: 0; box-shadow: none; }
      .pdf-render .report-header { flex-direction: row; }
      .pdf-render .header-right { text-align: right; }
      .pdf-render .stats, .pdf-render .emp-profile-grid { grid-template-columns: repeat(4, 1fr); }
      .pdf-render .data-table { min-width: 0; font-size: 8.5pt; }
      .pdf-render .table-wrap { overflow: visible; }
      .pdf-page { width: 1062px; height: 720px; background: #ffffff; break-after: page; page-break-after: always; }
      .pdf-page:last-child { break-after: auto; page-break-after: auto; }
      .pdf-continuation { display: flex; justify-content: space-between; gap: 16px; border-bottom: 2px solid #0f766e; margin-bottom: 14px; padding-bottom: 8px; font-size: 9pt; }
      .pdf-continuation strong { font-size: 12pt; }
      .report-tail { display: flow-root; }

      /* ——— Status Badges ——— */
      .status-pill {
        display: inline-block;
        padding: 2px 7px;
        border-radius: 4px;
        font-size: 7.2pt;
        font-weight: 700;
        border: 1px solid transparent;
        white-space: nowrap;
        text-transform: uppercase;
        letter-spacing: 0.02em;
      }
      .badge-status-completed {
        background: #ecfdf5;
        color: #065f46;
        border-color: #a7f3d0;
      }
      .badge-status-late {
        background: #fef3c7;
        color: #92400e;
        border-color: #fcd34d;
      }
      .badge-status-absent {
        background: #fef2f2;
        color: #991b1b;
        border-color: #fecaca;
      }
      .badge-status-off {
        background: #f1f5f9;
        color: #475569;
        border-color: #cbd5e1;
      }
      .badge-status-wfh {
        background: #e0f2fe;
        color: #0369a1;
        border-color: #bae6fd;
      }
      .badge-status-break {
        background: #ede9fe;
        color: #5b21b6;
        border-color: #ddd6fe;
      }

      /* ——— Sign-Off & Verification Block ——— */
      .signoff-section {
        display: flex;
        justify-content: space-between;
        gap: 5rem;
        margin-top: 2.25rem;
        padding-top: 1rem;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
      }
      .signoff-box {
        flex: 1;
        border-top: 1.5px dashed #94a3b8;
        padding-top: 0.5rem;
        font-size: 8.5pt;
        color: #334155;
        line-height: 1.45;
      }
      .signoff-role {
        font-weight: 700;
        color: #0f172a;
      }
      .signoff-sub {
        font-size: 7.5pt;
        color: #64748b;
        margin-bottom: 4px;
      }
      .signoff-date {
        font-size: 7.5pt;
        color: #64748b;
      }

      /* ——— Confidential Footer ——— */
      .report-footer {
        margin-top: 2rem;
        padding-top: 0.85rem;
        border-top: 1px solid #e2e8f0;
        font-size: 7.5pt;
        color: #64748b;
        display: flex;
        justify-content: space-between;
        align-items: center;
        page-break-inside: avoid;
      }
      .confidential-tag {
        color: #b91c1c;
        font-weight: 800;
        letter-spacing: 0.05em;
      }

      @media print {
        @page {
          size: A4 landscape;
          margin: 8mm 8mm 10mm 8mm;
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
        tr {
          page-break-inside: avoid !important;
          break-inside: avoid !important;
        }
        thead {
          display: table-header-group !important;
        }
        .table-wrap { overflow: visible; }
        .data-table { min-width: 0; font-size: 8.5pt; }
        .report-title { font-size: 18pt; }
        .stats, .emp-profile-grid { grid-template-columns: repeat(4, 1fr); }
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
    echo '<div class="hint" id="pdfHint">' . e(__('pdf_ready_hint')) . '</div>';
    echo '</div>';

    echo '<div class="wrap"><div id="reportSheet">';

    // Official Corporate Header
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

    echo '<section class="report-intro">';
    echo '<h2 class="report-title">' . e($title) . '</h2>';
    echo '<div class="report-coverage">';
    echo '<span>' . e(__('report_period')) . ': <strong>' . e($period) . '</strong></span>';
    echo '<span>' . e(__('report_scope')) . ': <strong>' . e((string) ($context['scope'] ?? ($employee['full_name'] ?? __('all_staff')))) . '</strong></span>';
    if (isset($context['staff_count'])) {
        echo '<span>' . e(__('report_staff_covered')) . ': <strong>' . number_format((int) $context['staff_count']) . '</strong></span>';
    }
    echo '</div></section>';

    // Employee Timesheet Profile Card (When single staff is selected)
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

    // KPI Summary Cards
    if ($summary) {
        echo '<div class="stats">';
        foreach ($summary as $label => $value) {
            echo '<div class="stat"><span class="stat-lbl">' . e((string) $label) . '</span><b class="stat-num">' . e((string) $value) . '</b>';
            if (!empty($context['summary_notes'][$label])) {
                echo '<span class="stat-note">' . e($context['summary_notes'][$label]) . '</span>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

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

    // Data Table
    echo '<div class="detail-caption"><h3 class="report-section-heading">' . e($extraTitle !== '' ? $extraTitle : __('report_detail_title')) . '</h3><span>' . number_format(count($matrix)) . ' ' . e(__('records')) . '</span></div>';
    $attendanceTable = count($headers) === 9;
    echo '<div class="table-wrap"><table class="data-table"><colgroup>';
    foreach ($attendanceTable ? [9, 24, 8, 8, 6, 8, 9, 10, 18] : [40, 20, 20, 20] as $width) {
        echo '<col style="width:' . $width . '%">';
    }
    echo '</colgroup><thead><tr>';
    foreach ($headers as $idx => $h) {
        $alignment = $attendanceTable && in_array($idx, [2, 3], true) ? 'time-cell' : (($attendanceTable && in_array($idx, [4, 5, 6], true)) || (!$attendanceTable && $idx > 0) ? 'duration-cell' : '');
        echo '<th scope="col" class="' . $alignment . '">' . e($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    if (!$matrix) {
        echo '<tr><td colspan="' . max(1, count($headers)) . '">' . e(__('no_report_records')) . '</td></tr>';
    } else {
        foreach ($matrix as $rowIndex => $row) {
            echo '<tr>';
            foreach ($row as $idx => $cell) {
                $rawText = (string)$cell;
                $formatted = $attendanceTable && in_array($idx, [7, 8], true) ? $formatCell($rawText) : e($rawText);
                if ($attendanceTable && $idx === 1 && !empty($context['records'][$rowIndex]['employee_id'])) {
                    $formatted .= '<span class="staff-id">' . e((string) $context['records'][$rowIndex]['employee_id']) . '</span>';
                }
                // Apply nowrap to date, time, and numeric columns to prevent line breaks
                $classes = [];
                if ($idx === 0 || ($attendanceTable && in_array($idx, [2, 3, 4, 5, 6], true))) $classes[] = 'nowrap';
                if ($attendanceTable && in_array($idx, [2, 3], true)) $classes[] = 'time-cell';
                if (($attendanceTable && in_array($idx, [4, 5, 6], true)) || (!$attendanceTable && $idx > 0)) $classes[] = 'duration-cell';
                echo '<td class="' . implode(' ', $classes) . '">' . $formatted . '</td>';
            }
            echo '</tr>';
        }
    }
    echo '</tbody></table></div>';

    // Sign-Off & Verification Block
    echo '<div class="signoff-section">';
    echo '<div class="signoff-box">';
    echo '<div class="signoff-role">' . e(__('report_prepared_by')) . ': <strong>' . e($adminName) . '</strong></div>';
    echo '<div class="signoff-sub">' . e(__('report_exported_note')) . '</div>';
    echo '<div class="signoff-date">' . e($generated) . '</div>';
    echo '</div>';
    echo '<div class="signoff-box">';
    echo '<div class="signoff-role">' . e(__('report_reviewed_by')) . ': ________________________</div>';
    echo '<div class="signoff-sub">' . e(__('report_review_pending')) . '</div>';
    echo '</div>';
    echo '</div>';

    // Confidential Footer
    echo '<div class="report-footer">';
    echo '<div class="confidential-tag">' . e(__('report_internal_footer')) . '</div>';
    echo '<div>' . e($reportRef) . '</div>';
    echo '</div>';

    echo '</div></div>';

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
  if (!btn || !sheet) return;

  function setBusy(on) {
    btn.disabled = !!on;
    sheet.classList.toggle("pdf-render", !!on);
    btn.textContent = on ? preparing : ' . json_encode(__('pdf_download')) . ';
    if (hint) hint.textContent = on ? preparing : ready;
  }

  // Measure complete rows at A4 width, then repeat the table header on each page.
  function buildPdfPages() {
    var host = document.createElement("div");
    host.style.cssText = "position:absolute;left:-20000px;top:0;width:1062px;";
    var root = document.createElement("div");
    root.className = "pdf-render";
    root.style.width = "1062px";
    host.appendChild(root);
    document.body.appendChild(host);
    var page;
    var table;
    var tbody;
    var sourceTable = sheet.querySelector(".data-table");
    var caption = sheet.querySelector(".detail-caption");
    var first = true;
    var tail = document.createElement("div");
    tail.className = "report-tail";
    Array.from(sheet.querySelectorAll(".signoff-section, .report-footer")).forEach(function (source) {
      tail.appendChild(source.cloneNode(true));
    });
    root.appendChild(tail);
    var tailHeight = tail.getBoundingClientRect().height;
    tail.remove();

    function newPage() {
      page = document.createElement("div");
      page.className = "pdf-page";
      root.appendChild(page);
      if (first) {
        Array.from(sheet.children).forEach(function (child) {
          if (!child.matches(".table-wrap, .detail-caption, .signoff-section, .report-footer")) page.appendChild(child.cloneNode(true));
        });
        first = false;
      } else {
        var heading = document.createElement("div");
        heading.className = "pdf-continuation";
        var name = document.createElement("strong");
        name.textContent = ' . json_encode($title) . ';
        var meta = document.createElement("span");
        meta.textContent = ' . json_encode($period . ' · ' . $reportRef) . ';
        heading.appendChild(name);
        heading.appendChild(meta);
        page.appendChild(heading);
      }
    }
    function newTable() {
      if (caption) page.appendChild(caption.cloneNode(true));
      table = sourceTable.cloneNode(false);
      table.appendChild(sourceTable.querySelector("colgroup").cloneNode(true));
      table.appendChild(sourceTable.tHead.cloneNode(true));
      tbody = document.createElement("tbody");
      table.appendChild(tbody);
      page.appendChild(table);
    }
    function overflows(reserved) {
      return page.lastElementChild.getBoundingClientRect().bottom - page.getBoundingClientRect().top > 700 - (reserved || 0);
    }
    newPage();
    newTable();
    if (overflows() || (sourceTable.tBodies[0].rows.length > 1 && overflows(160))) {
      table.remove();
      page.querySelector(".detail-caption").remove();
      newPage();
      newTable();
    }
    var sourceRows = Array.from(sourceTable.tBodies[0].rows);
    sourceRows.forEach(function (sourceRow, index) {
      var row = sourceRow.cloneNode(true);
      tbody.appendChild(row);
      var reserve = index >= sourceRows.length - 3 ? tailHeight : 0;
      if (overflows(reserve) && tbody.rows.length > 1) {
        row.remove();
        newPage();
        newTable();
        tbody.appendChild(row);
      }
    });
    page.appendChild(tail);
    if (overflows()) {
      tail.remove();
      newPage();
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
      margin: [8, 8, 10, 8],
      filename: fileName,
      image: { type: "jpeg", quality: 0.98 },
      html2canvas: { scale: 2, useCORS: true, letterRendering: true, backgroundColor: "#ffffff" },
      jsPDF: { unit: "mm", format: "a4", orientation: "landscape" },
      pagebreak: {
        mode: ["css", "legacy"],
        avoid: ["tr", ".signoff-section", ".stats", ".emp-profile-card", ".report-header", ".report-footer", ".report-intro", ".report-basis", ".report-review"]
      }
    };
    html2pdf().set(opt).from(pages.element).toPdf().get("pdf").then(function (pdf) {
      var total = pdf.internal.getNumberOfPages();
      for (var page = 1; page <= total; page++) {
        pdf.setPage(page);
        pdf.setFontSize(8);
        pdf.setTextColor(71, 85, 105);
        pdf.text(' . json_encode($reportRef) . ', 8, 206);
        pdf.text(page + " / " + total, 289, 206, { align: "right" });
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
