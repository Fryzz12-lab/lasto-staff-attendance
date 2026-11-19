<?php
declare(strict_types=1);

if (in_array(($argv[1] ?? ''), ['--render-csv', '--render-excel', '--render-pdf'], true)) {
    $mode = $argv[1];
    $_SESSION = [];
    function current_user(): ?array { return null; }
    require_once __DIR__ . '/../includes/helpers.php';
    require_once __DIR__ . '/../includes/i18n.php';
    require_once __DIR__ . '/../includes/report_export.php';

    $record = [
        'work_date'=>'2026-09-02', 'full_name'=>'Synthetic <Employee>, "QA"', 'employee_id'=>'SYN-01',
        'clock_in'=>'2026-09-02 10:00:00', 'clock_out'=>'2026-09-02 20:00:00', 'late_minutes'=>0,
        'total_break_minutes'=>60, 'work_minutes'=>540, 'provisional_work_minutes'=>null,
        'break_review_status'=>'none', 'status'=>'completed', 'is_wfh'=>0, 'is_day_off'=>0,
    ];
    $headers = report_attendance_headers();
    $matrix = report_attendance_export_matrix([$record]);
    if ($mode === '--render-csv') report_emit_csv('synthetic.csv', $headers, $matrix);
    if ($mode === '--render-excel') report_emit_excel('synthetic.xls', 'Synthetic', $headers, $matrix);
    report_emit_pdf_html(
        'Synthetic Attendance Report', '2026-09-02', $headers, $matrix, [], '', 'synthetic.pdf', false,
        ['full_name'=>'Synthetic <Employee>, "QA"','employee_id'=>'SYN-01','weekly_off_day'=>0], ['records'=>[$record]]
    );
    exit(2);
}

function render_export(string $mode): string
{
    $process = proc_open([PHP_BINARY, __FILE__, $mode], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException("could not render {$mode}");
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) throw new RuntimeException("{$mode} exited {$exitCode}: {$stderr}");
    return $output;
}

try {
    $csvBytes = render_export('--render-csv');
    if (!str_starts_with($csvBytes, "\xEF\xBB\xBF")) throw new RuntimeException('CSV UTF-8 BOM is missing');
    $stream = fopen('php://temp', 'r+'); fwrite($stream, substr($csvBytes, 3)); rewind($stream);
    $csvHeaders = fgetcsv($stream); $csvRow = fgetcsv($stream); fclose($stream);
    $statusIndex = array_search('Status', $csvHeaders, true);
    $reviewIndex = array_search('Break Review Status', $csvHeaders, true);
    if ($statusIndex !== 9 || $reviewIndex !== 7 || $csvRow[$statusIndex] !== 'Completed' || $csvRow[$reviewIndex] !== '-') {
        throw new RuntimeException('CSV status/review columns do not match the shared contract');
    }
    if ($csvRow[1] !== 'Synthetic <Employee>, "QA"') throw new RuntimeException('CSV quoting did not round-trip');

    $xml = simplexml_load_string(render_export('--render-excel'));
    if (!$xml) throw new RuntimeException('Excel XML is invalid');
    $xml->registerXPathNamespace('ss', 'urn:schemas-microsoft-com:office:spreadsheet');
    $xmlRows = $xml->xpath('//ss:Table/ss:Row');
    $excelHeaders = []; foreach ($xmlRows[0]->Cell as $cell) $excelHeaders[] = (string)$cell->Data;
    $excelValues = []; foreach ($xmlRows[1]->Cell as $cell) $excelValues[] = (string)$cell->Data;
    $excelStatus = array_search('Status', $excelHeaders, true);
    $excelReview = array_search('Break Review Status', $excelHeaders, true);
    if ($excelStatus !== 9 || $excelReview !== 7 || $excelValues[$excelStatus] !== 'Completed' || $excelValues[$excelReview] !== '-') {
        throw new RuntimeException('Excel status/review columns do not match the shared contract');
    }
    if ($excelValues[1] !== 'Synthetic <Employee>, "QA"') throw new RuntimeException('Excel XML escaping did not round-trip');

    $html = render_export('--render-pdf');
    $expected = '<td class="text-center"><div class="status-cell-wrap"><span class="status-pill badge-status-completed">Completed</span>';
    if (!str_contains($html, $expected)) throw new RuntimeException('single-employee PDF did not render Completed in its status cell');
    if (!str_contains($html, 'Synthetic &lt;Employee&gt;, &quot;QA&quot;')) throw new RuntimeException('PDF HTML escaping failed');
    echo "PASS: CSV and Excel preserve status/review alignment and escaping; PDF renders the attendance status correctly.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}
