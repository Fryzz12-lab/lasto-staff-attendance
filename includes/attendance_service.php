<?php
/**
 * Attendance & break business logic
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/adjustment_service.php';

/** The company schedule is provisioned by setup/migration, never by a staff form. */
function standard_schedule_id(): int
{
    $id = db()->query("SELECT id FROM work_schedules WHERE name='LASTO Standard Schedule' AND is_active=1 AND is_default=1 ORDER BY id LIMIT 1")->fetchColumn();
    if (!$id) {
        throw new RuntimeException('LASTO Standard Schedule is missing. Run the standard schedule migration.');
    }
    return (int) $id;
}

/**
 * Get effective schedule assignment for a user on a given date.
 */
function get_schedule_for_date(int $userId, string $date): ?array
{
    $sql = "SELECT sa.*, ws.name AS schedule_name, ws.start_time, ws.end_time,
                   ws.is_flexible, ws.work_days, ws.target_work_minutes,
                   u.weekly_off_day AS user_off_day
            FROM schedule_assignments sa
            JOIN work_schedules ws ON ws.id = sa.schedule_id
            JOIN users u ON u.id = sa.user_id
            WHERE sa.user_id = ?
              AND sa.effective_from <= ?
              AND (sa.effective_to IS NULL OR sa.effective_to >= ?)
            ORDER BY sa.effective_from DESC, sa.id DESC
            LIMIT 1";
    $stmt = db()->prepare($sql);
    $stmt->execute([$userId, $date, $date]);
    $row = $stmt->fetch();
    if (!$row) {
        // Fallback to default schedule
        $stmt = db()->query("SELECT * FROM work_schedules WHERE is_default = 1 AND is_active = 1 LIMIT 1");
        $ws = $stmt->fetch();
        // The company policy still applies if no active default template exists.
        $ws = $ws ?: ['id' => null, 'name' => 'Company schedule'];
        $u = db()->prepare('SELECT weekly_off_day FROM users WHERE id = ?');
        $u->execute([$userId]);
        $user = $u->fetch();
        return [
            'schedule_id' => $ws['id'] === null ? null : (int) $ws['id'],
            'schedule_name' => $ws['name'],
            'start_time' => POLICY_START_TIME,
            'end_time' => POLICY_END_TIME,
            'is_flexible' => 0,
            'work_days' => implode(',', working_weekdays($user ? (int) $user['weekly_off_day'] : 0)),
            'target_work_minutes' => POLICY_TARGET_MINUTES,
            'weekly_off_day' => $user ? (int) $user['weekly_off_day'] : 0,
        ];
    }

    $off = $row['weekly_off_day'] !== null ? (int) $row['weekly_off_day'] : (int) $row['user_off_day'];
    return [
        'schedule_id' => (int) $row['schedule_id'],
        'schedule_name' => $row['schedule_name'],
        'start_time' => POLICY_START_TIME,
        'end_time' => POLICY_END_TIME,
        'is_flexible' => 0,
        'work_days' => implode(',', working_weekdays($off)),
        'target_work_minutes' => POLICY_TARGET_MINUTES,
        'weekly_off_day' => $off,
    ];
}

function working_weekdays(int $offDay): array
{
    if ($offDay < 0 || $offDay > 6) {
        throw new InvalidArgumentException('Weekly off day must be between 0 and 6.');
    }
    return array_values(array_diff(range(0, 6), [$offDay]));
}

function is_scheduled_off(array $schedule, string $date): bool
{
    $dow = (int) (new DateTimeImmutable($date))->format('w');
    return $dow === (int) $schedule['weekly_off_day'];
}

/** Serialize employee mutations before reading attendance or break state. */
function with_employee_lock(int $userId, callable $action): mixed
{
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $lock = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $lock->execute([$userId]);
        if (!$lock->fetchColumn()) {
            throw new RuntimeException('Employee not found.');
        }
        $result = $action();
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/** Completed minutes after 10:00: 10:00:59 is on time; 10:01:00 is one minute late. */
function attendance_late_minutes(string $date, ?string $clockIn): int
{
    if (!$clockIn) {
        return 0;
    }
    $start = new DateTimeImmutable($date . ' ' . POLICY_START_TIME);
    return max(0, (int) floor(((new DateTimeImmutable($clockIn))->getTimestamp() - $start->getTimestamp()) / 60));
}

/** Replace today's assignment without overlapping or creating negative date ranges. */
function assign_employee_schedule(int $userId, int $scheduleId, int $offDay): void
{
    working_weekdays($offDay);
    with_employee_lock($userId, function () use ($userId, $scheduleId, $offDay): void {
        $stmt = db()->prepare('SELECT id FROM work_schedules WHERE id=? AND is_active=1 LOCK IN SHARE MODE');
        $stmt->execute([$scheduleId]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('Select an active work schedule.');
        }
        $today = today_str();
        $stmt = db()->prepare('SELECT * FROM schedule_assignments WHERE user_id=? AND (effective_to IS NULL OR effective_to>=?) ORDER BY effective_from, id FOR UPDATE');
        $stmt->execute([$userId, $today]);
        $assignments = $stmt->fetchAll();
        foreach ($assignments as $assignment) {
            if ($assignment['effective_from'] > $today) {
                throw new InvalidArgumentException('A future schedule assignment exists. Resolve it before changing the current schedule.');
            }
        }
        if (count($assignments) === 1 && (int) $assignments[0]['schedule_id'] === $scheduleId
            && $assignments[0]['weekly_off_day'] !== null && (int) $assignments[0]['weekly_off_day'] === $offDay) {
            return;
        }
        // Preserve earlier assignments. Repeated edits today replace today's assignment.
        db()->prepare('DELETE FROM schedule_assignments WHERE user_id=? AND effective_from=?')->execute([$userId, $today]);
        db()->prepare('UPDATE schedule_assignments SET effective_to=? WHERE user_id=? AND effective_from<? AND (effective_to IS NULL OR effective_to>=?)')
            ->execute([now()->modify('-1 day')->format('Y-m-d'), $userId, $today, $today]);
        db()->prepare('INSERT INTO schedule_assignments (user_id,schedule_id,effective_from,weekly_off_day) VALUES (?,?,?,?)')
            ->execute([$userId, $scheduleId, $today, $offDay]);
        $att = get_attendance($userId, $today);
        if ($att && !$att['clock_in']) {
            reconcile_attendance_day_status($userId, $today, $att);
        }
    });
}

function normalize_attendance_time(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }
    $value = str_replace('T', ' ', trim($value));
    if (strlen($value) === 16) {
        $value .= ':00';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
        throw new InvalidArgumentException('Enter a valid attendance date and time.');
    }
    return $value;
}

/** Shared manual/request correction path. Caller may hold an outer transaction. */
function correct_attendance(int $attendanceId, ?string $clockIn, ?string $clockOut, int $changedBy, string $reason, string $action = 'admin_correct'): void
{
    $stmt = db()->prepare('SELECT user_id FROM attendance WHERE id=?');
    $stmt->execute([$attendanceId]);
    $userId = (int) $stmt->fetchColumn();
    with_employee_lock($userId, function () use ($attendanceId, $clockIn, $clockOut, $changedBy, $reason, $action, $userId): void {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A correction reason is required.');
        }
        $stmt = db()->prepare('SELECT * FROM attendance WHERE id=? FOR UPDATE');
        $stmt->execute([$attendanceId]);
        $att = $stmt->fetch();
        $in = normalize_attendance_time($clockIn);
        $out = normalize_attendance_time($clockOut);
        if (($in && substr($in, 0, 10) !== $att['work_date'])
            || ($out && substr($out, 0, 10) !== $att['work_date'])) {
            throw new InvalidArgumentException(__('msg_correction_work_date', ['date' => format_date($att['work_date'])]));
        }
        if ($out && (!$in || $out < $in)) {
            throw new InvalidArgumentException(__('msg_correction_clock_order'));
        }
        if (($in && $in > now()->format('Y-m-d H:i:s')) || ($out && $out > now()->format('Y-m-d H:i:s'))) {
            throw new InvalidArgumentException('Attendance times cannot be in the future.');
        }
        $stmt = db()->prepare('SELECT * FROM break_records WHERE attendance_id=? FOR UPDATE');
        $stmt->execute([$attendanceId]);
        foreach ($stmt->fetchAll() as $break) {
            if (!$in || $break['start_time'] < $in || ($out && (!$break['end_time'] || $break['end_time'] > $out))) {
                throw new InvalidArgumentException('Clock times must include all recorded breaks. End an active break before setting clock-out.');
            }
        }
        db()->prepare('UPDATE attendance SET clock_in=?, clock_out=? WHERE id=?')->execute([$in, $out, $attendanceId]);
        foreach (['clock_in' => $in, 'clock_out' => $out] as $field => $value) {
            if ($att[$field] !== $value) {
                write_audit($attendanceId, $userId, $changedBy, $action, $field, $att[$field], $value, $reason);
            }
        }
        if (!$in) {
            reconcile_attendance_day_status($userId, $att['work_date']);
        }
        recalculate_attendance($attendanceId);
        ensure_offday_adjustment_review($attendanceId);
    });
}

function has_approved_leave(int $userId, string $date): bool
{
    $stmt = db()->prepare("SELECT id FROM leave_requests WHERE user_id = ? AND leave_date = ? AND status = 'approved' AND leave_type <> 'wfh' LIMIT 1");
    $stmt->execute([$userId, $date]);
    return (bool) $stmt->fetch();
}

function has_approved_wfh(int $userId, string $date): bool
{
    $stmt = db()->prepare("SELECT id FROM leave_requests WHERE user_id=? AND leave_date=? AND status='approved' AND leave_type='wfh' LIMIT 1");
    $stmt->execute([$userId, $date]);
    return (bool) $stmt->fetch();
}

function submit_leave_request(int $userId, string $date, string $type, string $reason): void
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date || $date < today_str()
        || !in_array($type, ['medical','wfh','day_off','annual','emergency','other'], true) || trim($reason) === '') {
        throw new InvalidArgumentException(__('msg_leave_invalid'));
    }
    with_employee_lock($userId, function () use ($userId, $date, $type, $reason): void {
        if (!employment_date_eligible($userId, $date)) {
            throw new InvalidArgumentException('Requests are not available for this employment date.');
        }
        $schedule = get_schedule_for_date($userId, $date);
        if ($type === 'wfh' && $schedule && is_scheduled_off($schedule, $date)) {
            throw new InvalidArgumentException(__('msg_wfh_off_day'));
        }
        $stmt = db()->prepare("SELECT id FROM leave_requests WHERE user_id=? AND leave_date=? AND status IN ('pending','approved') LIMIT 1");
        $stmt->execute([$userId, $date]);
        if ($stmt->fetchColumn()) {
            throw new InvalidArgumentException(__('msg_request_date_conflict'));
        }
        db()->prepare('INSERT INTO leave_requests (user_id,leave_date,leave_type,reason) VALUES (?,?,?,?)')
            ->execute([$userId, $date, $type, trim($reason)]);
    });
}

function review_leave_request(int $requestId, string $decision, int $reviewerId, ?string $note, bool $requiresReplacement = false): void
{
    $stmt = db()->prepare("SELECT id FROM users WHERE id=? AND role='admin' AND is_active=1");
    $stmt->execute([$reviewerId]);
    if (!$stmt->fetchColumn()) {
        throw new InvalidArgumentException(__('msg_no_permission'));
    }
    if (!in_array($decision, ['approved','rejected'], true)) {
        throw new InvalidArgumentException(__('msg_invalid_decision'));
    }
    $stmt = db()->prepare('SELECT user_id FROM leave_requests WHERE id=?');
    $stmt->execute([$requestId]);
    $userId = (int) $stmt->fetchColumn();
    if (!$userId) throw new InvalidArgumentException(__('msg_request_not_found'));
    with_employee_lock($userId, function () use ($requestId, $decision, $reviewerId, $note, $userId): void {
        $stmt = db()->prepare('SELECT * FROM leave_requests WHERE id=? FOR UPDATE');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        if (!$request || $request['status'] !== 'pending') {
            throw new InvalidArgumentException(__('msg_request_not_found'));
        }
        if ($decision === 'approved') {
            if (!employment_date_eligible($userId, $request['leave_date'])) {
                throw new InvalidArgumentException('Requests are not available for this employment date.');
            }
            $stmt = db()->prepare("SELECT id FROM leave_requests WHERE user_id=? AND leave_date=? AND status='approved' AND id<>? LIMIT 1");
            $stmt->execute([$userId, $request['leave_date'], $requestId]);
            if ($stmt->fetchColumn()) throw new InvalidArgumentException(__('msg_request_date_conflict'));
            $schedule = get_schedule_for_date($userId, $request['leave_date']);
            if ($request['leave_type'] === 'wfh' && $schedule && is_scheduled_off($schedule, $request['leave_date'])) {
                throw new InvalidArgumentException(__('msg_wfh_off_day'));
            }
            $att = get_attendance($userId, $request['leave_date']);
            if ($request['leave_type'] !== 'wfh' && !empty($att['clock_in'])) {
                throw new InvalidArgumentException(__('msg_leave_already_worked'));
            }
        }
        db()->prepare('UPDATE leave_requests SET status=?,admin_note=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?')
            ->execute([$decision, $note, $reviewerId, $requestId]);
        if ($decision === 'approved') {
            record_leave_replacement($requestId, $userId, $requiresReplacement, $reviewerId, $note);
        }
        if ($decision === 'approved' && $request['leave_date'] <= today_str()) {
            $att = ensure_attendance_row($userId, $request['leave_date']);
            write_audit((int) $att['id'], $userId, $reviewerId, 'request_approved', 'work_arrangement', null, $request['leave_type'], $note);
        }
    });
}

function get_attendance(int $userId, string $date): ?array
{
    $stmt = db()->prepare('SELECT * FROM attendance WHERE user_id = ? AND work_date = ? LIMIT 1');
    $stmt->execute([$userId, $date]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Attendance is only expected for staff who were employed on the date.
 *
 * Rules:
 *  - The date must be today or in the past (never future).
 *  - joining_date <= date (staff had joined by that date).
 *  - employment_end_date IS NULL OR employment_end_date >= date
 *    (staff had not yet left by that date).
 *  - is_active = 1 (deactivated accounts stop generating new rows but
 *    their past history remains visible through other queries).
 */
function attendance_date_eligible(int $userId, string $date): bool
{
    return $date <= today_str() && employment_date_eligible($userId, $date);
}

function employment_date_eligible(int $userId, string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        return false;
    }
    $stmt = db()->prepare(
        "SELECT joining_date, employment_end_date, is_active
         FROM users
         WHERE id = ? AND role = 'staff'
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        return false;
    }
    // Must have joined by this date
    if ($user['joining_date'] !== null && $user['joining_date'] > $date) {
        return false;
    }
    // Must not have left before this date
    if ($user['employment_end_date'] !== null && $user['employment_end_date'] < $date) {
        return false;
    }
    return (bool) $user['is_active'];
}

/** Ensure all eligible staff have a row for one date. Safe to call repeatedly. */
function ensure_attendance_rows_for_date(string $date): int
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date || $date > today_str()) {
        return 0;
    }
    // A report may need thousands of missing staff-days. Insert them in one
    // statement so the first page load does not perform several queries and
    // a row lock for every employee. The unique user/date key handles races.
    $sql = "INSERT IGNORE INTO attendance
            (user_id, work_date, schedule_id, scheduled_start, scheduled_end, is_day_off, was_scheduled_off_day, scheduled_off_weekday, is_wfh, status)
            SELECT eligible.id, ?, eligible.schedule_id, ?, ?,
                   eligible.day_off, eligible.scheduled_off,
                   eligible.off_weekday,
                   IF(eligible.day_off = 0 AND eligible.wfh = 1, 1, 0),
                   IF(eligible.day_off = 1, 'day_off', IF(? < CURDATE(), 'absent', 'not_clocked_in'))
            FROM (
                SELECT u.id, COALESCE(sa.schedule_id, ?) AS schedule_id,
                       IF(COALESCE(sa.weekly_off_day, u.weekly_off_day) = DAYOFWEEK(?) - 1
                          OR EXISTS (SELECT 1 FROM leave_requests lr
                                     WHERE lr.user_id = u.id AND lr.leave_date = ?
                                     AND lr.status = 'approved' AND lr.leave_type <> 'wfh'), 1, 0) AS day_off,
                       IF(COALESCE(sa.weekly_off_day, u.weekly_off_day) = DAYOFWEEK(?) - 1, 1, 0) AS scheduled_off,
                       COALESCE(sa.weekly_off_day, u.weekly_off_day) AS off_weekday,
                       EXISTS (SELECT 1 FROM leave_requests lr
                               WHERE lr.user_id = u.id AND lr.leave_date = ?
                                 AND lr.status = 'approved' AND lr.leave_type = 'wfh') AS wfh
                FROM users u
                LEFT JOIN schedule_assignments sa ON sa.id = (
                    SELECT sa2.id FROM schedule_assignments sa2
                    WHERE sa2.user_id = u.id AND sa2.effective_from <= ?
                      AND (sa2.effective_to IS NULL OR sa2.effective_to >= ?)
                    ORDER BY sa2.effective_from DESC, sa2.id DESC LIMIT 1
                )
                LEFT JOIN attendance a ON a.user_id = u.id AND a.work_date = ?
                WHERE u.role = 'staff' AND u.is_active = 1
                  AND (u.joining_date IS NULL OR u.joining_date <= ?)
                  AND (u.employment_end_date IS NULL OR u.employment_end_date >= ?)
                  AND a.id IS NULL
            ) eligible";
    $defaultScheduleId = db()->query("SELECT id FROM work_schedules WHERE is_default=1 AND is_active=1 LIMIT 1")->fetchColumn();
    $stmt = db()->prepare($sql);
    $stmt->execute([
        $date, POLICY_START_TIME, POLICY_END_TIME, $date,
        $defaultScheduleId ?: null, $date, $date, $date, $date,
        $date, $date, $date, $date, $date,
    ]);
    return $stmt->rowCount();
}

/** Ensure a bounded historical range exists before an admin report reads it. */
function ensure_attendance_rows_for_range(string $from, string $to): int
{
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
    if (!$start || !$end || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to || $end < $start) {
        return 0;
    }
    // Avoid an accidental multi-year request creating millions of rows.
    if ($start->diff($end)->days > 366) {
        throw new InvalidArgumentException('Attendance reports are limited to a 12-month period.');
    }
    $today = today_str();
    $effectiveEnd = $end > DateTimeImmutable::createFromFormat('!Y-m-d', $today)
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $today)
        : $end;
    if ($start > $effectiveEnd) {
        return 0;
    }
    $created = 0;
    for ($date = $start; $date <= $effectiveEnd; $date = $date->modify('+1 day')) {
        $created += ensure_attendance_rows_for_date($date->format('Y-m-d'));
    }
    return $created;
}

function ensure_attendance_row(int $userId, string $date): array
{
    if (!attendance_date_eligible($userId, $date)) {
        throw new InvalidArgumentException('Attendance is not available for this employment date.');
    }
    return with_employee_lock($userId, fn() => ensure_attendance_row_locked($userId, $date));
}

function ensure_attendance_row_locked(int $userId, string $date): array
{
    $existing = get_attendance($userId, $date);
    if ($existing) {
        return reconcile_attendance_day_status($userId, $date, $existing) ?? $existing;
    }

    $schedule = get_schedule_for_date($userId, $date);
    $isOff = false;
    $wasScheduledOff = false;
    $scheduledOffWeekday = null;
    $schedId = null;
    $start = POLICY_START_TIME;
    $end = POLICY_END_TIME;
    $status = 'not_clocked_in';

    if ($schedule) {
        $schedId = $schedule['schedule_id'];
        $start = $schedule['start_time'];
        $end = $schedule['end_time'];
        $wasScheduledOff = is_scheduled_off($schedule, $date);
        $scheduledOffWeekday = (int)($schedule['weekly_off_day'] ?? 0);
        $isOff = $wasScheduledOff || has_approved_leave($userId, $date);
        if ($isOff) {
            $status = 'day_off';
        }
    } elseif (has_approved_leave($userId, $date)) {
        $isOff = true;
        $status = 'day_off';
    }

    $stmt = db()->prepare(
        'INSERT INTO attendance (user_id, work_date, schedule_id, scheduled_start, scheduled_end, is_day_off, was_scheduled_off_day, scheduled_off_weekday, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $date, $schedId, $start, $end, $isOff ? 1 : 0, $wasScheduledOff ? 1 : 0, $scheduledOffWeekday, $status]);

    return reconcile_attendance_day_status($userId, $date, get_attendance($userId, $date));
}

/**
 * Keep rest days as day_off (never "not_clocked_in"),
 * and past workdays with no punch as absent.
 */
function reconcile_attendance_day_status(int $userId, string $date, ?array $att = null): ?array
{
    $att = $att ?? get_attendance($userId, $date);
    if (!$att) {
        return null;
    }

    $schedule = get_schedule_for_date($userId, $date);
    $scheduledOff = $schedule && is_scheduled_off($schedule, $date);
    $shouldOff = $scheduledOff || has_approved_leave($userId, $date);
    $isWfh = !$shouldOff && has_approved_wfh($userId, $date);
    if (!empty($att['clock_in'])) {
        // Preserve punches and their status; WFH remains visible throughout the shift.
        db()->prepare('UPDATE attendance SET is_wfh=?, is_day_off=?, was_scheduled_off_day=?, scheduled_off_weekday=? WHERE id=?')
            ->execute([(int) $isWfh, $isWfh ? 0 : (int) $att['is_day_off'], $scheduledOff ? 1 : (int)($att['was_scheduled_off_day'] ?? 0), $schedule['weekly_off_day'] ?? ($att['scheduled_off_weekday'] ?? null), $att['id']]);
        if ($att['status'] === 'wfh') recalculate_attendance((int) $att['id']);
        return get_attendance($userId, $date);
    }
    $status = $shouldOff ? 'day_off' : ($date < today_str() ? 'absent' : 'not_clocked_in');
    db()->prepare('UPDATE attendance SET schedule_id=?, scheduled_start=?, scheduled_end=?, is_day_off=?, was_scheduled_off_day=?, scheduled_off_weekday=?, is_wfh=?, status=? WHERE id=? AND clock_in IS NULL')
        ->execute([$schedule['schedule_id'] ?? null, POLICY_START_TIME, POLICY_END_TIME, (int) $shouldOff, $scheduledOff ? 1 : (int)($att['was_scheduled_off_day'] ?? 0), $schedule['weekly_off_day'] ?? ($att['scheduled_off_weekday'] ?? null), (int) $isWfh, $status, $att['id']]);
    return get_attendance($userId, $date);
}

/**
 * Ensure + reconcile rows for an inclusive date range (staff history views).
 */
function sync_attendance_range(int $userId, string $from, string $to): void
{
    try {
        $start = new DateTimeImmutable($from, new DateTimeZone('Asia/Kuala_Lumpur'));
        $end = new DateTimeImmutable($to, new DateTimeZone('Asia/Kuala_Lumpur'));
    } catch (Exception $e) {
        return;
    }
    if ($end < $start) {
        return;
    }
    // Safety cap
    if ($start->diff($end)->days > 92) {
        $start = $end->modify('-92 days');
    }

    for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
        $date = $d->format('Y-m-d');
        if (attendance_date_eligible($userId, $date)) {
            ensure_attendance_row($userId, $date);
        }
    }
}

function get_active_break(int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM break_records WHERE user_id = ? AND end_time IS NULL ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function recount_breaks(int $attendanceId): void
{
    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(duration_minutes), 0) AS total FROM break_records WHERE attendance_id = ? AND end_time IS NOT NULL'
    );
    $stmt->execute([$attendanceId]);
    $total = (int) $stmt->fetchColumn();

    $upd = db()->prepare('UPDATE attendance SET total_break_minutes = ? WHERE id = ?');
    $upd->execute([$total, $attendanceId]);
}

function recalculate_attendance(int $attendanceId): void
{
    $stmt = db()->prepare('SELECT * FROM attendance WHERE id = ?');
    $stmt->execute([$attendanceId]);
    $att = $stmt->fetch();
    if (!$att) {
        return;
    }

    recount_breaks($attendanceId);
    $stmt->execute([$attendanceId]);
    $att = $stmt->fetch();

    $shift = 0;
    $work = 0;
    $early = 0;
    $status = $att['status'];

    $late = attendance_late_minutes($att['work_date'], $att['clock_in']);
    if (!$att['clock_in']) {
        $status = (int) $att['is_day_off'] ? 'day_off' : ($att['work_date'] < today_str() ? 'absent' : 'not_clocked_in');
    } elseif ($att['clock_out']) {
        $in = new DateTimeImmutable($att['clock_in']);
        $out = new DateTimeImmutable($att['clock_out']);
        $shift = max(0, (int) floor(($out->getTimestamp() - $in->getTimestamp()) / 60));
        $work = max(0, $shift - (int) $att['total_break_minutes']);

        if (setting_bool('enable_early_leave_calc', true) && $att['scheduled_end']) {
            $schedEnd = new DateTimeImmutable($att['work_date'] . ' ' . POLICY_END_TIME);
            if ($out < $schedEnd) {
                $early = (int) floor(($schedEnd->getTimestamp() - $out->getTimestamp()) / 60);
            }
        }
        $status = 'completed';
    } else {
        $active = get_active_break((int) $att['user_id']);
        if ($att['work_date'] < today_str()) {
            $status = 'incomplete';
        } elseif ($active && (int) $active['attendance_id'] === $attendanceId) {
            $status = 'on_break';
        } elseif ($late > 0) {
            $status = 'late';
        } else {
            $status = 'present';
        }
    }

    $upd = db()->prepare(
        'UPDATE attendance SET shift_minutes = ?, work_minutes = ?, early_leave_minutes = ?, status = ?, late_minutes = ?, updated_at = NOW() WHERE id = ?'
    );
    $upd->execute([$shift, $work, $early, $status, $late, $attendanceId]);
}

function write_audit(?int $attendanceId, int $userId, int $changedBy, string $action, ?string $field, ?string $old, ?string $new, ?string $reason = null): void
{
    $stmt = db()->prepare(
        'INSERT INTO attendance_audit_logs (attendance_id, user_id, changed_by, action, field_name, old_value, new_value, reason)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$attendanceId, $userId, $changedBy, $action, $field, $old, $new, $reason]);
}


/**
 * Admin correction for a single break record.
 *
 * Allows an admin to fix a forgotten break end time or adjust start/end times.
 * Recalculates attendance totals and writes an audit entry.
 *
 * @param int         $breakId   The break_records.id to correct.
 * @param string      $startTime New start time in 'Y-m-d H:i:s' format.
 * @param string|null $endTime   New end time (null leaves the break open).
 * @param int         $changedBy Admin user ID performing the correction.
 * @param string      $reason    Required explanation for the audit log.
 */
function correct_break(int $breakId, string $startTime, ?string $endTime, int $changedBy, string $reason): void
{
    if (trim($reason) === '') {
        throw new InvalidArgumentException('A correction reason is required.');
    }

    $startNorm = normalize_attendance_time($startTime);
    $endNorm   = $endTime !== null && trim($endTime) !== '' ? normalize_attendance_time($endTime) : null;

    if ($startNorm === null) {
        throw new InvalidArgumentException('A valid break start time is required.');
    }
    if ($endNorm !== null && $endNorm <= $startNorm) {
        throw new InvalidArgumentException('Break end time must be after the start time.');
    }
    if ($startNorm > now()->format('Y-m-d H:i:s') || ($endNorm && $endNorm > now()->format('Y-m-d H:i:s'))) {
        throw new InvalidArgumentException('Break times cannot be in the future.');
    }

    $stmt = db()->prepare('SELECT br.*, a.work_date, a.clock_in, a.clock_out, a.user_id
                           FROM break_records br
                           JOIN attendance a ON a.id = br.attendance_id
                           WHERE br.id = ?');
    $stmt->execute([$breakId]);
    $break = $stmt->fetch();
    if (!$break) {
        throw new InvalidArgumentException('Break record not found.');
    }

    $userId = (int) $break['user_id'];

    with_employee_lock($userId, function () use ($break, $breakId, $startNorm, $endNorm, $changedBy, $reason, $userId): void {
        $stmt = db()->prepare('SELECT br.*, a.work_date, a.clock_in, a.clock_out
                               FROM break_records br
                               JOIN attendance a ON a.id = br.attendance_id
                               WHERE br.id = ? FOR UPDATE');
        $stmt->execute([$breakId]);
        $break = $stmt->fetch();

        $workDate = (string) $break['work_date'];

        if (substr($startNorm, 0, 10) !== $workDate || ($endNorm && substr($endNorm, 0, 10) !== $workDate)) {
            throw new InvalidArgumentException('Break times must fall on the same date as the attendance record (' . format_date($workDate) . ').');
        }
        if ($break['clock_in'] && $startNorm < $break['clock_in']) {
            throw new InvalidArgumentException('Break start cannot be before the clock-in time.');
        }
        if ($break['clock_out'] && $endNorm && $endNorm > $break['clock_out']) {
            throw new InvalidArgumentException('Break end cannot be after the clock-out time.');
        }

        $durationMinutes = 0;
        $exceeded        = 0;
        if ($endNorm) {
            $seconds         = (new DateTimeImmutable($endNorm))->getTimestamp() - (new DateTimeImmutable($startNorm))->getTimestamp();
            $durationMinutes = (int) floor(max(0, $seconds) / 60);
            $exceeded        = $seconds > (int) $break['allowed_minutes'] * 60 ? 1 : 0;
        }

        $oldStart = (string) $break['start_time'];
        $oldEnd   = $break['end_time'] !== null ? (string) $break['end_time'] : null;

        db()->prepare(
            'UPDATE break_records SET start_time = ?, end_time = ?, duration_minutes = ?, exceeded = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$startNorm, $endNorm, $durationMinutes, $exceeded, $breakId]);

        if ($oldStart !== $startNorm) {
            write_audit((int) $break['attendance_id'], $userId, $changedBy, 'break_corrected', 'break_start', $oldStart, $startNorm, $reason);
        }
        if ($oldEnd !== $endNorm) {
            write_audit((int) $break['attendance_id'], $userId, $changedBy, 'break_corrected', 'break_end', $oldEnd, $endNorm, $reason);
        }

        recalculate_attendance((int) $break['attendance_id']);
    });
}
function clock_in(int $userId): array
{
    return with_employee_lock($userId, function () use ($userId): array {
        $date = today_str();
        $att = ensure_attendance_row($userId, $date);
        if ($att['clock_in']) {
            return ['success' => false, 'message' => __('msg_already_clocked_in')];
        }
        $clockIn = now()->format('Y-m-d H:i:s');
        $late = attendance_late_minutes($date, $clockIn);
        db()->prepare('UPDATE attendance SET clock_in=?, late_minutes=?, status=? WHERE id=?')
            ->execute([$clockIn, $late, $late > 0 ? 'late' : 'present', $att['id']]);
        write_audit((int) $att['id'], $userId, $userId, 'clock_in', 'clock_in', null, $clockIn, 'Staff clock in');
        $message = __('msg_clocked_in', ['time' => format_time($clockIn)]);
        $message .= $late > 0 ? __('msg_status_late', ['time' => format_minutes($late)]) : __('msg_status_ontime');
        return ['success' => true, 'message' => $message, 'attendance_id' => (int) $att['id']];
    });
}

function clock_out(int $userId): array
{
    return with_employee_lock($userId, function () use ($userId): array {
        $att = get_attendance($userId, today_str());
        if (!$att || !$att['clock_in']) {
            return ['success' => false, 'message' => __('msg_must_clock_in_first')];
        }
        if ($att['clock_out']) {
            return ['success' => false, 'message' => __('msg_already_clocked_out')];
        }
        if (get_active_break($userId)) {
            return ['success' => false, 'message' => __('msg_end_break_before_out')];
        }
        $clockOut = now()->format('Y-m-d H:i:s');
        db()->prepare('UPDATE attendance SET clock_out=? WHERE id=?')->execute([$clockOut, $att['id']]);
        recalculate_attendance((int) $att['id']);
        ensure_offday_adjustment_review((int)$att['id']);
        write_audit((int) $att['id'], $userId, $userId, 'clock_out', 'clock_out', null, $clockOut, 'Staff clock out');
        $att = get_attendance($userId, today_str());
        return ['success' => true, 'message' => __('msg_clocked_out', [
            'time' => format_time($clockOut), 'work' => format_minutes((int) $att['work_minutes']),
        ])];
    });
}

function break_limits(): array
{
    return [
        'lunch' => ['suggest_session' => 60, 'max_sessions' => 1],
        'solat' => ['suggest_session' => 20, 'max_sessions' => 3],
        'short' => ['suggest_session' => 10, 'max_sessions' => 3],
    ];
}

function break_used_minutes_total(int $attendanceId): int
{
    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(duration_minutes), 0) FROM break_records
         WHERE attendance_id = ? AND end_time IS NOT NULL'
    );
    $stmt->execute([$attendanceId]);
    return (int) $stmt->fetchColumn();
}

function break_used_minutes(int $attendanceId, string $type): int
{
    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(duration_minutes), 0) FROM break_records
         WHERE attendance_id = ? AND break_type = ? AND end_time IS NOT NULL'
    );
    $stmt->execute([$attendanceId, $type]);
    return (int) $stmt->fetchColumn();
}

function count_breaks_today(int $attendanceId, string $type): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM break_records WHERE attendance_id = ? AND break_type = ?');
    $stmt->execute([$attendanceId, $type]);
    return (int) $stmt->fetchColumn();
}

function start_break(int $userId, string $type): array
{
    return with_employee_lock($userId, function () use ($userId, $type): array {
        $limits = break_limits();
        if (!isset($limits[$type])) {
            return ['success' => false, 'message' => __('msg_invalid_break_type')];
        }
        $att = get_attendance($userId, today_str());
        if (!$att || !$att['clock_in']) {
            return ['success' => false, 'message' => __('msg_clock_in_before_break')];
        }
        if ($att['clock_out']) {
            return ['success' => false, 'message' => __('msg_clocked_out_no_break')];
        }
        if (get_active_break($userId)) {
            return ['success' => false, 'message' => __('msg_active_break_exists')];
        }
        $usedCount = count_breaks_today((int) $att['id'], $type);
        if ($usedCount >= $limits[$type]['max_sessions']) {
            return ['success' => false, 'message' => __('msg_break_limit', [
                'type' => break_type_name($type), 'max' => (string) $limits[$type]['max_sessions'],
            ])];
        }
        $seq = $usedCount + 1;
        $allowed = $limits[$type]['suggest_session'];
        db()->prepare('INSERT INTO break_records (attendance_id, user_id, break_type, sequence_no, start_time, allowed_minutes) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$att['id'], $userId, $type, $seq, now()->format('Y-m-d H:i:s'), $allowed]);
        $breakId = (int) db()->lastInsertId();
        db()->prepare("UPDATE attendance SET status='on_break' WHERE id=?")->execute([$att['id']]);
        write_audit((int) $att['id'], $userId, $userId, 'break_start', 'break', null, "{$type}#{$seq}", 'Start break');
        return ['success' => true, 'break_id' => $breakId, 'message' => __('msg_break_started', [
            'label' => break_label($type, $seq), 'allowed' => format_minutes($allowed),
        ])];
    });
}

function end_break(int $userId): array
{
    return with_employee_lock($userId, function () use ($userId): array {
        $break = get_active_break($userId);
        if (!$break) {
            return ['success' => false, 'message' => __('msg_no_active_break')];
        }
        $end = now();
        $seconds = max(0, $end->getTimestamp() - (new DateTimeImmutable($break['start_time']))->getTimestamp());
        $duration = (int) floor($seconds / 60);
        $allowed = (int) $break['allowed_minutes'];
        $exceeded = $seconds > $allowed * 60;
        db()->prepare('UPDATE break_records SET end_time=?, duration_minutes=?, exceeded=? WHERE id=?')
            ->execute([$end->format('Y-m-d H:i:s'), $duration, (int) $exceeded, $break['id']]);
        recalculate_attendance((int) $break['attendance_id']);
        write_audit((int) $break['attendance_id'], $userId, $userId, 'break_end', 'break', null, (string) $duration, 'End break');
        $message = __('msg_break_ended', ['label' => break_label($break['break_type'], (int) $break['sequence_no']), 'duration' => format_minutes($duration)]);
        if ($exceeded) {
            $message .= __('msg_break_exceeded_warn', ['allowed' => format_minutes($allowed)]);
        }
        return ['success' => true, 'message' => $message, 'exceeded' => $exceeded];
    });
}

function break_type_name(string $type): string
{
    $keyed = match ($type) {
        'lunch' => __('break_lunch'),
        'solat' => __('break_solat', ['n' => '']),
        'short' => __('break_short', ['n' => '']),
        'other' => __('break_other', ['n' => '']),
        default => ucfirst($type),
    };
    return trim(str_replace(['#:n', '#'], '', $keyed));
}

function break_label(string $type, int $seq = 1): string
{
    return match ($type) {
        'lunch' => __('break_lunch') . ($seq > 1 ? " #{$seq}" : ''),
        'solat' => __('break_solat', ['n' => (string) $seq]),
        'short' => __('break_short', ['n' => (string) $seq]),
        'other' => __('break_other', ['n' => (string) $seq]),
        default => ucfirst($type),
    };
}

function break_usage_summary(int $attendanceId): array
{
    $stmt = db()->prepare('SELECT break_type, COUNT(*) AS cnt, COALESCE(SUM(duration_minutes),0) AS mins FROM break_records WHERE attendance_id=? GROUP BY break_type');
    $stmt->execute([$attendanceId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[$row['break_type']] = $row;
    }
    $summary = [];
    foreach (break_limits() as $type => $rule) {
        $count = (int) ($rows[$type]['cnt'] ?? 0);
        $summary[$type] = [
            'used_count' => $count,
            'max_count' => $rule['max_sessions'],
            'used_minutes' => (int) ($rows[$type]['mins'] ?? 0),
            'allowed_per' => $rule['suggest_session'],
            'can_start' => $count < $rule['max_sessions'],
        ];
    }
    return $summary;
}

function staff_dashboard_data(int $userId): array
{
    $date = today_str();
    $schedule = get_schedule_for_date($userId, $date);
    $att = attendance_date_eligible($userId, $date) ? ensure_attendance_row($userId, $date) : null;
    $activeBreak = $att ? get_active_break($userId) : null;
    if ($activeBreak && (int) $activeBreak['attendance_id'] !== (int) $att['id']) $activeBreak = null;
    $breaks = [];
    if ($att) {
        $stmt = db()->prepare('SELECT * FROM break_records WHERE attendance_id = ? ORDER BY start_time ASC');
        $stmt->execute([$att['id']]);
        $breaks = $stmt->fetchAll();
    }

    $elapsedWork = 0;
    if ($att && $att['clock_in'] && !$att['clock_out']) {
        $in = new DateTimeImmutable($att['clock_in']);
        $elapsed = (int) floor((now()->getTimestamp() - $in->getTimestamp()) / 60);
        // Subtract completed breaks; active break time still counts in shift but not in net work display roughly
        $elapsedWork = max(0, $elapsed - (int) $att['total_break_minutes']);
        if ($activeBreak) {
            $bStart = new DateTimeImmutable($activeBreak['start_time']);
            $activeMins = (int) floor((now()->getTimestamp() - $bStart->getTimestamp()) / 60);
            $elapsedWork = max(0, $elapsedWork - $activeMins);
        }
    }

    return [
        'date' => $date,
        'schedule' => $schedule,
        'attendance' => $att,
        'active_break' => $activeBreak,
        'breaks' => $breaks,
        'break_usage' => $att ? break_usage_summary((int) $att['id']) : [],
        'elapsed_work_minutes' => $elapsedWork,
        'is_day_off' => $att && ((int) $att['is_day_off'] === 1 || $att['status'] === 'day_off'),
    ];
}

function close_unended_breaks(): void
{
    // 1. Shifts with recorded clock_out but lingering open breaks
    $stmt = db()->query("
        SELECT b.id, b.attendance_id, b.start_time, b.allowed_minutes, a.clock_out
        FROM break_records b
        JOIN attendance a ON a.id = b.attendance_id
        WHERE b.end_time IS NULL AND a.clock_out IS NOT NULL
    ");
    while ($b = $stmt->fetch()) {
        $endTime = (string) $b['clock_out'];
        if (strtotime($endTime) <= strtotime($b['start_time'])) {
            $endTime = date('Y-m-d H:i:s', strtotime($b['start_time']) + ((int) $b['allowed_minutes'] * 60));
        }
        $seconds = max(0, strtotime($endTime) - strtotime($b['start_time']));
        $dur = (int) floor($seconds / 60);
        $allowed = (int) $b['allowed_minutes'];
        $exceeded = $dur > $allowed ? 1 : 0;
        db()->prepare("
            UPDATE break_records
            SET end_time = ?, duration_minutes = ?, exceeded = ?
            WHERE id = ?
        ")->execute([$endTime, $dur, $exceeded, $b['id']]);
        recalculate_attendance((int) $b['attendance_id']);
    }

    // 2. Past shifts (work_date < TODAY) missing break end
    $stmt = db()->prepare("
        SELECT b.id, b.attendance_id, b.start_time, b.allowed_minutes, a.work_date
        FROM break_records b
        JOIN attendance a ON a.id = b.attendance_id
        WHERE b.end_time IS NULL AND a.work_date < ?
    ");
    $stmt->execute([today_str()]);
    while ($b = $stmt->fetch()) {
        $endTime = $b['work_date'] . ' ' . POLICY_END_TIME;
        if (strtotime($endTime) <= strtotime($b['start_time'])) {
            $endTime = date('Y-m-d H:i:s', strtotime($b['start_time']) + ((int) $b['allowed_minutes'] * 60));
        }
        $seconds = max(0, strtotime($endTime) - strtotime($b['start_time']));
        $dur = (int) floor($seconds / 60);
        $allowed = (int) $b['allowed_minutes'];
        $exceeded = $dur > $allowed ? 1 : 0;
        db()->prepare("
            UPDATE break_records
            SET end_time = ?, duration_minutes = ?, exceeded = ?
            WHERE id = ?
        ")->execute([$endTime, $dur, $exceeded, $b['id']]);
        recalculate_attendance((int) $b['attendance_id']);
    }
}

function mark_incomplete_attendances(): void
{
    // Flag yesterday (and older open) records missing clock out
    $stmt = db()->prepare(
        "UPDATE attendance
         SET status = 'incomplete', updated_at = NOW()
         WHERE clock_in IS NOT NULL
           AND clock_out IS NULL
           AND work_date < ?
           AND status NOT IN ('day_off', 'incomplete')"
    );
    $stmt->execute([today_str()]);

    close_unended_breaks();
}

function greeting_text(): string
{
    $h = (int) now()->format('G');
    if ($h < 12) {
        return __('good_morning');
    }
    if ($h < 18) {
        return __('good_afternoon');
    }
    return __('good_evening');
}
