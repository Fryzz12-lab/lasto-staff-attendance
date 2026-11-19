<?php
declare(strict_types=1);

/** SELECT-only integrity diagnostics. Does not load application workflows. */

function diagnostic_usage(): never
{
    fwrite(STDERR, "Usage: php cli/attendance_integrity_diagnostics.php --database=NAME --user=READ_ONLY_USER [--host=127.0.0.1] [--port=3306] [--format=text|json] [--from=YYYY-MM-DD] [--to=YYYY-MM-DD]\nPassword: ATTENDANCE_DIAGNOSTICS_DB_PASS environment variable.\n");
    exit(2);
}

function diagnostic_date(?string $value, string $label): ?string
{
    if ($value === null || $value === '') return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException("{$label} must be YYYY-MM-DD.");
    }
    return $value;
}

function diagnostic_range(string $alias, ?string $from, ?string $to): string
{
    $sql = '';
    if ($from !== null) $sql .= " AND {$alias}.work_date >= :date_from";
    if ($to !== null) $sql .= " AND {$alias}.work_date <= :date_to";
    return $sql;
}

function diagnostic_bounds(string $expression, ?string $from, ?string $to): string
{
    $sql = '';
    if ($from !== null) $sql .= " AND {$expression} >= :date_from";
    if ($to !== null) $sql .= " AND {$expression} <= :date_to";
    return $sql;
}

function diagnostic_query(PDO $pdo, array $diagnostic, array $params): array
{
    preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $diagnostic['sql'], $matches);
    $usedParams = [];
    foreach (array_unique($matches[1]) as $name) {
        if (array_key_exists(':' . $name, $params)) $usedParams[':' . $name] = $params[':' . $name];
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM (' . $diagnostic['sql'] . ') AS diagnostic_rows');
    $count->execute($usedParams);
    $rowCount = (int) $count->fetchColumn();
    $sample = $pdo->prepare('SELECT * FROM (' . $diagnostic['sql'] . ') AS diagnostic_rows LIMIT 10');
    $sample->execute($usedParams);
    return [
        'id' => $diagnostic['id'],
        'table' => $diagnostic['table'],
        'invariant' => $diagnostic['invariant'],
        'severity_if_genuine' => $diagnostic['severity'],
        'classification' => $diagnostic['classification'],
        'database_constraints_and_gaps' => diagnostic_protections($diagnostic['id'])['database'],
        'application_protections' => diagnostic_protections($diagnostic['id'])['application'],
        'false_positive_and_history' => $diagnostic['caveat'],
        'investigation' => $diagnostic['investigation'],
        'matching_rows' => $rowCount,
        'sample_limit' => 10,
        'sample' => $sample->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function diagnostic_protections(string $id): array
{
    $map = [
        'D01'=>['database'=>'Attendance user FK and UNIQUE(user_id,work_date); no clock-order CHECK.','application'=>'Clock and correction services validate punch ordering; review historical/manual rows.'],
        'D02'=>['database'=>'Separate break user and attendance FKs; no composite owner FK.','application'=>'Break service validates attendance ownership and uses employee-first locking.'],
        'D03'=>['database'=>'Separate location user and attendance FKs; unique attendance/event type; no owner-equality constraint.','application'=>'Location service checks event attendance ownership.'],
        'D04'=>['database'=>'Break attendance FK and indexes; no unique constraint for one open break.','application'=>'Break transitions serialize by employee/attendance and recheck state.'],
        'D05'=>['database'=>'No start/end ordering CHECK.','application'=>'Break closure validates timestamps and computes duration in service code.'],
        'D06'=>['database'=>'Attendance/break FKs only; no timestamp-context CHECK.','application'=>'Attendance services and scheduled repair apply clock, date, and closure policy.'],
        'D07'=>['database'=>'Status ENUM; no CHECK relating status to punch/break state.','application'=>'Attendance recalculation derives status from current workflow state.'],
        'D08'=>['database'=>'Independent nullable attendance/break/user FKs; no composite owner/date FK.','application'=>'Correction services validate employee, attendance, break, date, and version.'],
        'D09'=>['database'=>'Independent nullable attendance FK and user FK; no equality constraint.','application'=>'Audit writes are produced within validated service operations.'],
        'D10'=>['database'=>'Migration adds separate owner/attendance/break FKs and UNIQUE(user_id,request_id); no composite ownership constraint.','application'=>'Idempotency service checks owner/ref consistency and expiry/tombstone transitions.'],
        'D11'=>['database'=>'Schedule/user FKs and lookup index; no effective-range CHECK.','application'=>'Schedule assignment/configuration paths validate dates.'],
        'D12'=>['database'=>'Lookup index; no non-overlap constraint.','application'=>'Resolver chooses latest effective_from then highest id; overlaps are policy-dependent.'],
        'D13'=>['database'=>'Active/default flags and active index; no unique active-default key.','application'=>'Schedule selection/configuration resolves fallback in application code.'],
        'D14'=>['database'=>'Ledger employee/admin/review/reversal FKs and source index; source_id is polymorphic without FK.','application'=>'Adjustment service validates source/review/employee and reversal links.'],
        'D15'=>['database'=>'Review and ledger FKs separately; no decision-state/ledger constraint.','application'=>'Adjustment review service writes state and ledger changes transactionally.'],
        'D16'=>['database'=>'Separate leave/employee/admin FKs; no owner-equality or active-review uniqueness constraint.','application'=>'Leave replacement review logic checks requester and review state.'],
        'D17'=>['database'=>'weekly_off_day is nullable TINYINT UNSIGNED; no 0..6 CHECK.','application'=>'Schedule assignment inputs validate weekday values.'],
        'D18'=>['database'=>'Idempotency state ENUM and owner/ref FKs; no terminal metadata CHECK.','application'=>'Idempotency service persists terminal response and clears response refs for expiry tombstones.'],
        'D19'=>['database'=>'UNIQUE(user_id,work_date) protects duplicate attendance rows when present.','application'=>'Attendance creation uses unique-key-safe insert/retrieval.'],
    ];
    return $map[$id] ?? ['database'=>'Not assessed.','application'=>'Not assessed.'];
}

function diagnostic_definitions(?string $from, ?string $to): array
{
    $a = diagnostic_range('a', $from, $to);
    $x = diagnostic_range('x', $from, $to);
    $sql = static fn(string $query): string => $query;
    return [
        [
            'id'=>'D01','table'=>'attendance','invariant'=>'clock_out must not precede clock_in.',
            'severity'=>'high','classification'=>'potential_business_rule_anomaly',
            'caveat'=>'A genuine reversal is inconsistent with current service validation; inspect legacy corrections and timestamp/timezone context.',
            'investigation'=>'Review the attendance audit trail and source punches before deciding whether it is erroneous.',
            'sql'=>$sql("SELECT a.id AS record_id,a.user_id,a.work_date FROM attendance a WHERE a.clock_in IS NOT NULL AND a.clock_out IS NOT NULL AND a.clock_out < a.clock_in{$a}"),
        ],
        [
            'id'=>'D02','table'=>'break_records','invariant'=>'Break employee must match its attendance employee.',
            'severity'=>'high','classification'=>'confirmed_structural_violation',
            'caveat'=>'Separate foreign keys permit both references to be valid while owners differ; historical manual imports may explain the row but do not satisfy ownership.',
            'investigation'=>'Compare the attendance and break audit/history using record IDs; do not reassign automatically.',
            'sql'=>$sql("SELECT b.id AS record_id,b.attendance_id,b.user_id AS break_user_id,a.user_id AS attendance_user_id,a.work_date FROM break_records b JOIN attendance a ON a.id=b.attendance_id WHERE b.user_id<>a.user_id{$a}"),
        ],
        [
            'id'=>'D03','table'=>'attendance_location_events','invariant'=>'Location event employee must match attendance employee.',
            'severity'=>'high','classification'=>'confirmed_structural_violation',
            'caveat'=>'Separate foreign keys do not encode cross-owner equality. Location retention and access policy still govern review.',
            'investigation'=>'Review linked event and attendance IDs with restricted access; avoid exporting coordinates or IPs.',
            'sql'=>$sql("SELECT e.id AS record_id,e.attendance_id,e.user_id AS event_user_id,a.user_id AS attendance_user_id,a.work_date FROM attendance_location_events e JOIN attendance a ON a.id=e.attendance_id WHERE e.user_id<>a.user_id{$a}"),
        ],
        [
            'id'=>'D04','table'=>'break_records','invariant'=>'At most one open break should exist for an attendance at a time.',
            'severity'=>'medium','classification'=>'potential_business_rule_anomaly',
            'caveat'=>'No unique partial key exists. Legacy stale-break records across different attendances are intentionally possible; this diagnostic groups only by one attendance.',
            'investigation'=>'Inspect all open break IDs and service audit events for that attendance.',
            'sql'=>$sql("SELECT MIN(b.id) AS record_id,b.attendance_id,COUNT(*) AS related_id,MIN(a.user_id) AS user_id,MIN(a.work_date) AS work_date FROM break_records b JOIN attendance a ON a.id=b.attendance_id WHERE b.end_time IS NULL{$a} GROUP BY b.attendance_id HAVING COUNT(*)>1"),
        ],
        [
            'id'=>'D05','table'=>'break_records','invariant'=>'A closed break end must be strictly later than its start.',
            'severity'=>'high','classification'=>'potential_business_rule_anomaly',
            'caveat'=>'Open breaks have NULL end_time and are valid; historical/manual corrections may need contextual review.',
            'investigation'=>'Compare correction requests, audit history, and clock timestamps.',
            'sql'=>$sql("SELECT b.id AS record_id,b.attendance_id,a.user_id,a.work_date FROM break_records b JOIN attendance a ON a.id=b.attendance_id WHERE b.end_time IS NOT NULL AND b.end_time<=b.start_time{$a}"),
        ],
        [
            'id'=>'D06','table'=>'break_records','invariant'=>'Break times should fit the attendance clock interval and the work date or following calendar day.',
            'severity'=>'medium','classification'=>'historical_or_policy_dependent_exception',
            'caveat'=>'Overnight shifts and the cleanup policy can close old breaks at policy end or allowed duration. An open break alongside clock_out can be awaiting scheduled repair.',
            'investigation'=>'Check schedule snapshot, punches, correction history, and whether the row was awaiting cleanup at the snapshot time.',
            'sql'=>$sql("SELECT b.id AS record_id,b.attendance_id,a.user_id,a.work_date FROM break_records b JOIN attendance a ON a.id=b.attendance_id WHERE (b.start_time<a.clock_in OR (a.clock_out IS NOT NULL AND b.end_time IS NULL) OR (b.end_time IS NOT NULL AND a.clock_out IS NOT NULL AND b.end_time>a.clock_out) OR DATE(b.start_time) NOT IN (a.work_date,DATE_ADD(a.work_date,INTERVAL 1 DAY)) OR (b.end_time IS NOT NULL AND DATE(b.end_time) NOT IN (a.work_date,DATE_ADD(a.work_date,INTERVAL 1 DAY)))){$a}"),
        ],
        [
            'id'=>'D07','table'=>'attendance','invariant'=>'Status should agree with punch and active-break state.',
            'severity'=>'medium','classification'=>'potential_business_rule_anomaly',
            'caveat'=>'Status is recalculated by services and may lag during legacy/manual updates; day-off, leave, corrections, and review states affect interpretation.',
            'investigation'=>'Review the attendance audit trail, break state, and approved leave/WFH before classifying.',
            'sql'=>$sql("SELECT a.id AS record_id,a.user_id,a.work_date FROM attendance a WHERE ((a.clock_out IS NOT NULL AND a.clock_in IS NULL) OR (a.status='completed' AND a.clock_out IS NULL) OR (a.status='on_break' AND NOT EXISTS (SELECT 1 FROM break_records b WHERE b.attendance_id=a.id AND b.end_time IS NULL)) OR (a.status IN ('present','late') AND (a.clock_in IS NULL OR a.clock_out IS NOT NULL)) OR (a.status='incomplete' AND a.clock_out IS NOT NULL)){$a}"),
        ],
        [
            'id'=>'D08','table'=>'attendance_correction_requests','invariant'=>'Correction employee, attendance, break, and date references should agree when linked.',
            'severity'=>'high','classification'=>'confirmed_structural_violation',
            'caveat'=>'Nullable attendance/break references are intentionally supported for legacy/deleted records; checks apply only to non-NULL references.',
            'investigation'=>'Review request, linked attendance/break IDs, and correction audit records before any action.',
            'sql'=>$sql("SELECT c.id AS record_id,c.user_id,c.attendance_id,c.break_id,COALESCE(a.work_date,c.work_date) AS work_date FROM attendance_correction_requests c LEFT JOIN attendance a ON a.id=c.attendance_id LEFT JOIN break_records b ON b.id=c.break_id WHERE ((c.attendance_id IS NOT NULL AND (a.id IS NULL OR a.user_id<>c.user_id OR a.work_date<>c.work_date)) OR (c.break_id IS NOT NULL AND (b.id IS NULL OR b.user_id<>c.user_id OR (c.attendance_id IS NOT NULL AND b.attendance_id<>c.attendance_id)))" . diagnostic_bounds('COALESCE(a.work_date,c.work_date)', $from, $to) . ')'),
        ],
        [
            'id'=>'D09','table'=>'attendance_audit_logs','invariant'=>'When linked, audit employee must match attendance employee.',
            'severity'=>'high','classification'=>'confirmed_structural_violation',
            'caveat'=>'attendance_id is nullable with ON DELETE SET NULL; NULL linkage is permitted and cannot be cross-checked.',
            'investigation'=>'Review audit and attendance IDs with administrator access; do not expose free-text fields in diagnostic output.',
            'sql'=>$sql("SELECT l.id AS record_id,l.user_id,a.user_id AS attendance_user_id,DATE(l.created_at) AS work_date FROM attendance_audit_logs l JOIN attendance a ON a.id=l.attendance_id WHERE l.attendance_id IS NOT NULL AND l.user_id<>a.user_id" . diagnostic_bounds('DATE(l.created_at)', $from, $to)),
        ],
        [
            'id'=>'D10','table'=>'attendance_idempotency_operations','invariant'=>'Idempotency owner must match linked attendance and break owners/attendance.',
            'severity'=>'high','classification'=>'confirmed_structural_violation',
            'caveat'=>'Nullable references are expected for rejected operations and expired tombstones.',
            'investigation'=>'Review operation IDs/status and linked IDs only; payload and response bodies are deliberately excluded.',
            'sql'=>$sql("SELECT o.id AS record_id,o.user_id,o.attendance_id,o.break_id,COALESCE(a.work_date,DATE(o.created_at)) AS work_date FROM attendance_idempotency_operations o LEFT JOIN attendance a ON a.id=o.attendance_id LEFT JOIN break_records b ON b.id=o.break_id WHERE ((o.attendance_id IS NOT NULL AND (a.id IS NULL OR a.user_id<>o.user_id)) OR (o.break_id IS NOT NULL AND (b.id IS NULL OR b.user_id<>o.user_id OR (o.attendance_id IS NOT NULL AND b.attendance_id<>o.attendance_id)))" . diagnostic_bounds('COALESCE(a.work_date,DATE(o.created_at))', $from, $to) . ')'),
        ],
        [
            'id'=>'D11','table'=>'schedule_assignments','invariant'=>'effective_to must be NULL or on/after effective_from.',
            'severity'=>'medium','classification'=>'potential_business_rule_anomaly',
            'caveat'=>'Historical assignment edits may create ranges that require HR policy review.',
            'investigation'=>'Inspect assignment history and employee transfer dates.',
            'sql'=>$sql("SELECT sa.id AS record_id,sa.user_id,sa.schedule_id AS related_id,sa.effective_from AS work_date FROM schedule_assignments sa WHERE sa.effective_to IS NOT NULL AND sa.effective_to<sa.effective_from"),
        ],
        [
            'id'=>'D12','table'=>'schedule_assignments','invariant'=>'Overlapping effective ranges for one employee need explicit precedence/policy review.',
            'severity'=>'medium','classification'=>'historical_or_policy_dependent_exception',
            'caveat'=>'The resolver explicitly selects latest effective_from then highest id, so overlap is deterministic in code and not automatically corruption.',
            'investigation'=>'Compare schedule-change approvals and the resolver-selected assignment for affected dates.',
            'sql'=>$sql("SELECT x.id AS record_id,x.user_id,y.id AS related_id,x.effective_from AS work_date FROM schedule_assignments x JOIN schedule_assignments y ON y.user_id=x.user_id AND y.id>x.id AND (x.effective_to IS NULL OR y.effective_from<=x.effective_to) AND (y.effective_to IS NULL OR x.effective_from<=y.effective_to) WHERE 1=1" . diagnostic_bounds('x.effective_from', $from, $to)),
        ],
        [
            'id'=>'D13','table'=>'work_schedules','invariant'=>'At most one active default schedule should be configured for unambiguous fallback.',
            'severity'=>'medium','classification'=>'potential_business_rule_anomaly',
            'caveat'=>'Some code paths select the named standard schedule; others may use a default flag. Inactive defaults are not counted.',
            'investigation'=>'Review active schedule configuration and standard-schedule policy before editing.',
            'sql'=>$sql("SELECT NULL AS record_id,COUNT(*) AS related_id,NULL AS user_id FROM work_schedules WHERE is_default=1 AND is_active=1 HAVING COUNT(*)>1"),
        ],
        [
            'id'=>'D14','table'=>'staff_balance_ledger','invariant'=>'Typed ledger source, review, employee, and reversal references should agree.',
            'severity'=>'high','classification'=>'potential_business_rule_anomaly',
            'caveat'=>'source_type/source_id is intentionally polymorphic and has no FK. Manual entries may have NULL source_id. Authorized negative-balance overrides are not anomalies.',
            'investigation'=>'Compare source records, review IDs, and reversal lineage. Never infer a balance error from a negative SUM alone.',
            'sql'=>$sql("SELECT l.id AS record_id,l.employee_id,l.source_id AS related_id,l.source_type,DATE(l.created_at) AS work_date FROM staff_balance_ledger l LEFT JOIN attendance a ON l.source_type='offday_attendance' AND a.id=l.source_id LEFT JOIN offday_adjustment_reviews r ON r.id=l.review_id LEFT JOIN leave_requests lr ON l.source_type='leave' AND lr.id=l.source_id LEFT JOIN staff_balance_ledger original ON l.source_type='reversal' AND original.id=l.reversal_of_id WHERE ((l.source_type='offday_attendance' AND (a.id IS NULL OR a.user_id<>l.employee_id OR r.id IS NULL OR r.attendance_id<>a.id OR r.employee_id<>l.employee_id)) OR (l.source_type='leave' AND (lr.id IS NULL OR lr.user_id<>l.employee_id)) OR (l.source_type='reversal' AND (original.id IS NULL OR original.employee_id<>l.employee_id OR original.balance_type<>l.balance_type OR l.amount<>-original.amount OR l.source_id<>original.source_id)) OR (l.source_type IN ('offday_attendance','leave') AND l.source_id IS NOT NULL AND (SELECT COUNT(*) FROM staff_balance_ledger duplicate_source WHERE duplicate_source.source_type=l.source_type AND duplicate_source.source_id=l.source_id)>1)" . diagnostic_bounds('DATE(l.created_at)', $from, $to) . ')'),
        ],
        [
            'id'=>'D15','table'=>'offday_adjustment_reviews','invariant'=>'Balance-affecting decision state should have its corresponding original/reversal ledger entries.',
            'severity'=>'high','classification'=>'potential_business_rule_anomaly',
            'caveat'=>'No-adjustment decisions legitimately have no ledger row. Historical reversal workflows should be checked against current code.',
            'investigation'=>'Review decision, related ledger entries, and admin audit history before considering a correction.',
            'sql'=>$sql("SELECT r.id AS record_id,r.employee_id,r.attendance_id AS related_id,a.work_date FROM offday_adjustment_reviews r JOIN attendance a ON a.id=r.attendance_id WHERE ((r.status='decided' AND r.decision IN ('replacement_off','settle_cuti_ganti') AND NOT EXISTS (SELECT 1 FROM staff_balance_ledger l WHERE l.review_id=r.id AND l.source_type='offday_attendance')) OR (r.status='decided' AND r.decision='no_adjustment' AND EXISTS (SELECT 1 FROM staff_balance_ledger l WHERE l.review_id=r.id AND l.source_type='offday_attendance')) OR (r.status='reversed' AND NOT EXISTS (SELECT 1 FROM staff_balance_ledger l WHERE l.review_id=r.id AND l.source_type='reversal')) OR (r.status='pending' AND EXISTS (SELECT 1 FROM staff_balance_ledger l WHERE l.review_id=r.id AND l.source_type='offday_attendance')))" . $a),
        ],
        [
            'id'=>'D16','table'=>'leave_replacement_reviews','invariant'=>'Replacement review employee should match leave requester; one active decision per leave request.',
            'severity'=>'medium','classification'=>'potential_business_rule_anomaly',
            'caveat'=>'The schema permits multiple active reviews and does not enforce owner equality across the two FKs.',
            'investigation'=>'Review leave request and replacement-review history; reversed rows are not treated as active.',
            'sql'=>$sql("SELECT rr.id AS record_id,rr.employee_id,rr.leave_request_id AS related_id,lr.leave_date AS work_date FROM leave_replacement_reviews rr LEFT JOIN leave_requests lr ON lr.id=rr.leave_request_id WHERE (lr.id IS NULL OR lr.user_id<>rr.employee_id OR (rr.status='active' AND (SELECT COUNT(*) FROM leave_replacement_reviews active WHERE active.leave_request_id=rr.leave_request_id AND active.status='active')>1)" . diagnostic_bounds('lr.leave_date', $from, $to) . ')'),
        ],
        [
            'id'=>'D17','table'=>'schedule_assignments','invariant'=>'weekly_off_day override, when present, must be in 0..6.',
            'severity'=>'low','classification'=>'potential_business_rule_anomaly',
            'caveat'=>'NULL means use the employee-level value and is valid.',
            'investigation'=>'Check assignment form/import history and schedule policy.',
            'sql'=>$sql("SELECT sa.id AS record_id,sa.user_id,sa.schedule_id AS related_id,sa.effective_from AS work_date FROM schedule_assignments sa WHERE sa.weekly_off_day IS NOT NULL AND sa.weekly_off_day NOT BETWEEN 0 AND 6"),
        ],
        [
            'id'=>'D18','table'=>'attendance_idempotency_operations','invariant'=>'Terminal idempotency rows should have terminal metadata; expired tombstones should not retain replay data.',
            'severity'=>'medium','classification'=>'potential_business_rule_anomaly',
            'caveat'=>'Processing rows may be transient only inside uncommitted transactions; committed processing rows may reflect historical/manual paths.',
            'investigation'=>'Inspect operation status/timestamps and deployed cleanup behavior without exposing payload or response contents.',
            'sql'=>$sql("SELECT o.id AS record_id,o.user_id,o.status AS related_id,DATE(o.created_at) AS work_date FROM attendance_idempotency_operations o WHERE ((o.status IN ('succeeded','rejected') AND (o.completed_at IS NULL OR o.response_http_status IS NULL OR o.response_body IS NULL)) OR (o.status='expired' AND (o.response_http_status IS NOT NULL OR o.response_body IS NOT NULL OR o.attendance_id IS NOT NULL OR o.break_id IS NOT NULL))" . diagnostic_bounds('DATE(o.created_at)', $from, $to) . ')'),
        ],
        [
            'id'=>'D19','table'=>'attendance','invariant'=>'One attendance row per employee/work date.',
            'severity'=>'high','classification'=>'diagnostic_limitation',
            'caveat'=>'The checked-in schema has UNIQUE(user_id,work_date), so duplicates cannot be inserted while that key exists. Zero results do not verify that a deployed schema retained the key.',
            'investigation'=>'Confirm SHOW INDEX/metadata on the target schema; do not drop the key to test this diagnostic.',
            'sql'=>$sql("SELECT MIN(a.id) AS record_id,a.user_id,COUNT(*) AS related_id,MIN(a.work_date) AS work_date FROM attendance a WHERE 1=1{$a} GROUP BY a.user_id,a.work_date HAVING COUNT(*)>1"),
        ],
    ];
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $options = getopt('', ['database:', 'user:', 'host:', 'port:', 'format:', 'from:', 'to:', 'help']);
    if (isset($options['help'])) diagnostic_usage();
    if (!isset($options['database'], $options['user'])) diagnostic_usage();
    $database = (string) $options['database'];
    $user = (string) $options['user'];
    $host = strtolower((string) ($options['host'] ?? '127.0.0.1'));
    $port = (int) ($options['port'] ?? 3306);
    $format = (string) ($options['format'] ?? 'text');
    if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $database)) throw new InvalidArgumentException('Database name contains unsupported characters.');
    if (!in_array($host, ['127.0.0.1','localhost','::1'], true)) throw new InvalidArgumentException('Diagnostics only connect to a local database host.');
    if ($port < 1 || $port > 65535 || $user === '') throw new InvalidArgumentException('Invalid connection options.');
    if (!in_array($format, ['text','json'], true)) throw new InvalidArgumentException('--format must be text or json.');
    $blockedNames = ['staff_attendance','staff_attendance_uat','mysql','information_schema','performance_schema','sys'];
    if (in_array(strtolower($database), $blockedNames, true)
        || preg_match('/(^|_)(prod|production|live)(_|$)/i', $database)) {
        throw new InvalidArgumentException('Refusing a protected or production-like database name.');
    }
    $from = diagnostic_date(isset($options['from']) ? (string) $options['from'] : null, '--from');
    $to = diagnostic_date(isset($options['to']) ? (string) $options['to'] : null, '--to');
    if ($from !== null && $to !== null && $from > $to) throw new InvalidArgumentException('--from must not be later than --to.');

    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, (string) (getenv('ATTENDANCE_DIAGNOSTICS_DB_PASS') ?: ''), [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    $identity = $pdo->query('SELECT DATABASE() AS database_name, VERSION() AS server_version')->fetch();
    if (!$identity || $identity['database_name'] !== $database) throw new RuntimeException('Connected database identity did not match the explicit target.');
    fwrite(STDERR, 'READ-ONLY TARGET: ' . $identity['database_name'] . ' @ ' . $host . ':' . $port . ' (' . $identity['server_version'] . ')' . PHP_EOL);

    $pdo->exec('START TRANSACTION READ ONLY');
    $tableStmt = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME');
    $tableStmt->execute([$database]);
    $presentTables = array_column($tableStmt->fetchAll(), 'TABLE_NAME');
    $inventory = [
        'schema_file'=>'database/schema.sql',
        'idempotency_migration_file'=>'database/migration_phase3b_idempotency.sql',
        'idempotency_table_present'=>in_array('attendance_idempotency_operations', $presentTables, true),
        'relevant_tables_present'=>array_values(array_intersect($presentTables, ['users','attendance','break_records','attendance_location_events','work_schedules','schedule_assignments','leave_requests','attendance_correction_requests','attendance_audit_logs','offday_adjustment_reviews','leave_replacement_reviews','staff_balance_ledger','notifications','attendance_idempotency_operations'])),
    ];
    $params = [];
    if ($from !== null) $params[':date_from'] = $from;
    if ($to !== null) $params[':date_to'] = $to;
    $diagnostics = [];
    foreach (diagnostic_definitions($from, $to) as $definition) {
        $diagnostics[] = diagnostic_query($pdo, $definition, $params);
    }
    $pdo->commit();

    $report = [
        'database_identity'=>['database'=>$identity['database_name'],'host'=>$host,'port'=>$port,'server_version'=>$identity['server_version']],
        'execution_date'=> (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
        'scope'=>['from'=>$from,'to'=>$to],
        'schema_inventory'=>$inventory,
        'results'=>$diagnostics,
        'summary'=>['diagnostics'=>count($diagnostics),'matching_rows'=>array_sum(array_column($diagnostics,'matching_rows')),'nonzero_diagnostics'=>count(array_filter($diagnostics,static fn($d)=>$d['matching_rows']>0))],
        'mutation_performed'=>false,
        'existing_employee_data_verified'=>false,
        'limitations'=>[
            'This tool refuses local-host violations and obvious protected/production-like names, but name checks are not authorization.',
            'Use a database account with SELECT-only privileges for any real-data copy; the read-only transaction is an additional guard.',
            'Matching rows are candidates, not automatic proof of corruption; use each result classification and caveat.',
            'Samples contain internal record IDs only; personal fields, coordinates, IPs, payload hashes, and response bodies are omitted.',
        ],
    ];

    if ($format === 'json') {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        echo "Attendance integrity diagnostics\n";
        echo "Target: {$identity['database_name']} @ {$host}:{$port} ({$identity['server_version']})\n";
        echo 'Executed: ' . $report['execution_date'] . "\n";
        echo 'Scope: ' . ($from ?? 'beginning') . ' through ' . ($to ?? 'current') . "\n";
        echo "Existing employee data verified: NO (target is caller-selected; findings require review)\n";
        foreach ($diagnostics as $diagnostic) {
            echo sprintf("%s %-38s rows=%d class=%s severity=%s\n", $diagnostic['id'], $diagnostic['invariant'], $diagnostic['matching_rows'], $diagnostic['classification'], $diagnostic['severity_if_genuine']);
        }
        echo sprintf("Summary: %d diagnostics, %d matching rows, %d nonzero diagnostics. No mutations performed.\n", $report['summary']['diagnostics'], $report['summary']['matching_rows'], $report['summary']['nonzero_diagnostics']);
    }
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Diagnostic failed safely: ' . $error->getMessage() . PHP_EOL);
    exit(2);
}
