<?php
/**
 * Attendance & break business logic
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/adjustment_service.php';
require_once __DIR__ . '/push_service.php';
require_once __DIR__ . '/notification_service.php';
require_once __DIR__ . '/attendance_location.php';

/** The company schedule is provisioned by setup/migration, never by a staff form. */
function standard_schedule_id(): int
{
    $stmt = db()->query("SELECT id, start_time, end_time, is_flexible FROM work_schedules WHERE name='LASTO Standard Schedule' AND is_active=1 AND is_default=1 ORDER BY id LIMIT 1");
    $schedule = $stmt->fetch();
    if (!$schedule) {
        throw new RuntimeException('LASTO Standard Schedule is missing. Run the standard schedule migration.');
    }
    if ((string) $schedule['start_time'] !== POLICY_START_TIME
        || (string) $schedule['end_time'] !== POLICY_END_TIME
        || (int) $schedule['is_flexible'] !== 0) {
        throw new RuntimeException('LASTO Standard Schedule must remain a fixed 10:00-20:00 schedule.');
    }
    return (int) $schedule['id'];
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
        $stmt = db()->query("SELECT * FROM work_schedules WHERE name='LASTO Standard Schedule' AND is_default = 1 AND is_active = 1 LIMIT 1");
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
        if ($att) {
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
        $workDate = (string) $att['work_date'];
        $nextDay = (new DateTimeImmutable($workDate))->modify('+1 day')->format('Y-m-d');
        if ($in && substr($in, 0, 10) !== $workDate) {
            throw new InvalidArgumentException(__('msg_correction_work_date', ['date' => format_date($workDate)]));
        }
        if ($out) {
            $outDate = substr($out, 0, 10);
            if ($outDate !== $workDate && $outDate !== $nextDay) {
                throw new InvalidArgumentException(__('msg_correction_work_date', ['date' => format_date($workDate)]));
            }
        }
        if ($out && (!$in || $out < $in)) {
            throw new InvalidArgumentException(__('msg_correction_clock_order'));
        }
        if ($in && $out) {
            $diffSeconds = (new DateTimeImmutable($out))->getTimestamp() - (new DateTimeImmutable($in))->getTimestamp();
            if ($diffSeconds > 86400) {
                throw new InvalidArgumentException('Shift duration cannot exceed 24 hours.');
            }
        }
        if (($in && $in > now()->format('Y-m-d H:i:s')) || ($out && $out > now()->format('Y-m-d H:i:s'))) {
            throw new InvalidArgumentException('Attendance times cannot be in the future.');
        }
        $stmt = db()->prepare('SELECT * FROM break_records WHERE attendance_id=? FOR UPDATE');
        $stmt->execute([$attendanceId]);
        foreach ($stmt->fetchAll() as $break) {
            if (!$in || $break['start_time'] < $in) {
                throw new InvalidArgumentException('Clock times must include all recorded breaks. Clock-in cannot be after a recorded break start.');
            }
            if ($out) {
                if (!$break['end_time']) {
                    throw new InvalidArgumentException('Clock times must include all recorded breaks. End an active break before setting clock-out.');
                }
                if ($break['end_time'] > $out) {
                    throw new InvalidArgumentException('Clock times must include all recorded breaks. Clock-out cannot be before a recorded break end.');
                }
            }
        }
        db()->prepare('UPDATE attendance SET clock_in=?, clock_out=?, version = version + 1 WHERE id=?')->execute([$in, $out, $attendanceId]);
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
    $notification = with_employee_lock($userId, function () use ($userId, $date, $type, $reason): array {
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
        $requestId = (int) db()->lastInsertId();
        $uStmt = db()->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
        $uStmt->execute([$userId]);
        $name = (string) ($uStmt->fetchColumn() ?: 'Staff member');
        $typeLabel = strtoupper($type);
        $admins = db()->query("SELECT id FROM users WHERE role='admin' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) {
            notification_create((int)$adminId, 'leave_request', 'leave-submitted-' . $requestId,
                "New {$typeLabel} request", "A staff member submitted a {$typeLabel} request for {$date}.", '/admin/leave_requests.php');
        }
        return ['id' => $requestId, 'name' => $name];
    });
    try {
        $tUpper = strtoupper($type);
        push_send_to_admins(
            "New {$tUpper} Request",
            "{$notification['name']} submitted a {$tUpper} request for {$date}.",
            '/admin/leave_requests.php',
            'admin-leave-' . $notification['id'],
            ($type === 'wfh' ? 'new_wfh_request' : 'new_leave_request')
        );
    } catch (Throwable $pe) {
        error_log('[Push] ' . $pe->getMessage());
    }
}

/**
 * Check whether a shift's correction request is past the standard deadline
 * (23:59:59 on the 3rd calendar day after work_date in Asia/Kuala_Lumpur).
 */
function is_correction_submission_late(string $workDate): bool
{
    $tz = new DateTimeZone('Asia/Kuala_Lumpur');
    $dateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $workDate, $tz);
    if (!$dateObj) {
        return false;
    }
    $deadline = $dateObj->modify('+3 days')->setTime(23, 59, 59);
    $now = new DateTimeImmutable('now', $tz);
    return $now > $deadline;
}

/**
 * Check whether a pending correction request already exists that conflicts
 * with the proposed field for this employee and shift date.
 */
function has_conflicting_correction_request(int $userId, string $workDate, string $field): bool
{
    $conflictingFields = match ($field) {
        'both' => ['clock_in', 'clock_out', 'both'],
        'clock_in' => ['clock_in', 'both'],
        'clock_out' => ['clock_out', 'both'],
        default => ['clock_in', 'clock_out', 'both'],
    };
    $inClause = implode(',', array_fill(0, count($conflictingFields), '?'));
    $stmt = db()->prepare("
        SELECT id FROM attendance_correction_requests
        WHERE user_id = ? AND work_date = ? AND status = 'pending' AND field_name IN ($inClause)
        LIMIT 1
    ");
    $stmt->execute(array_merge([$userId, $workDate], $conflictingFields));
    return (bool) $stmt->fetchColumn();
}

/**
 * Submit an attendance clock-in / clock-out correction request.
 */
function submit_attendance_correction_request(
    int $userId,
    string $workDate,
    string $field,
    ?string $clockIn,
    ?string $clockOut,
    string $reason,
    ?string $lateReason = null
): array {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $workDate);
    if (!$parsed || $parsed->format('Y-m-d') !== $workDate) {
        throw new InvalidArgumentException('Invalid work date specified.');
    }
    if ($workDate > today_str()) {
        throw new InvalidArgumentException('Cannot submit correction requests for future dates.');
    }
    if (!attendance_date_eligible($userId, $workDate)) {
        throw new InvalidArgumentException(__('employment_date_unavailable'));
    }
    if (!in_array($field, ['clock_in', 'clock_out', 'both'], true)) {
        throw new InvalidArgumentException('Invalid correction field specified.');
    }
    $reason = trim($reason);
    if ($reason === '') {
        throw new InvalidArgumentException(__('msg_correction_reason'));
    }

    $isLate = is_correction_submission_late($workDate);
    $lateReason = trim((string)$lateReason);
    if ($isLate && $lateReason === '') {
        throw new InvalidArgumentException('The standard 3-day submission window has closed. An explanation for the late submission is required for administrator review.');
    }

    $notification = with_employee_lock($userId, function () use ($userId, $workDate, $field, $clockIn, $clockOut, $reason, $isLate, $lateReason): array {
        if (has_conflicting_correction_request($userId, $workDate, $field)) {
            throw new InvalidArgumentException('You already have a pending correction request for this shift. Please wait for administrator review before submitting another request.');
        }

        $att = ensure_attendance_row_locked($userId, $workDate);
        $attId = (int) $att['id'];
        $targetVersion = (int) ($att['version'] ?? 1);

        $in = normalize_attendance_time($clockIn);
        $out = normalize_attendance_time($clockOut);

        $finalIn = in_array($field, ['clock_in', 'both'], true) ? $in : $att['clock_in'];
        $finalOut = in_array($field, ['clock_out', 'both'], true) ? $out : $att['clock_out'];

        if (in_array($field, ['clock_in', 'both'], true)) {
            if (!$in) {
                throw new InvalidArgumentException('Please specify a valid requested clock-in time.');
            }
            if (substr($in, 0, 10) !== $workDate) {
                throw new InvalidArgumentException(__('msg_correction_work_date', ['date' => format_date($workDate)]));
            }
            if ($in > now()->format('Y-m-d H:i:s')) {
                throw new InvalidArgumentException('Clock-in time cannot be in the future.');
            }
        }

        if (in_array($field, ['clock_out', 'both'], true)) {
            if (!$out) {
                throw new InvalidArgumentException('Please specify a valid requested clock-out time.');
            }
            $nextDay = (new DateTimeImmutable($workDate))->modify('+1 day')->format('Y-m-d');
            $outDate = substr($out, 0, 10);
            if ($outDate !== $workDate && $outDate !== $nextDay) {
                throw new InvalidArgumentException(__('msg_correction_work_date', ['date' => format_date($workDate)]));
            }
            if ($out > now()->format('Y-m-d H:i:s')) {
                throw new InvalidArgumentException('Clock-out time cannot be in the future.');
            }
        }

        if ($finalOut && (!$finalIn || $finalOut < $finalIn)) {
            throw new InvalidArgumentException(__('msg_correction_clock_order'));
        }

        if ($finalIn && $finalOut) {
            $diffSeconds = (new DateTimeImmutable($finalOut))->getTimestamp() - (new DateTimeImmutable($finalIn))->getTimestamp();
            if ($diffSeconds > 86400) {
                throw new InvalidArgumentException('Shift duration cannot exceed 24 hours.');
            }
        }

        $bStmt = db()->prepare('SELECT * FROM break_records WHERE attendance_id=? FOR UPDATE');
        $bStmt->execute([$attId]);
        foreach ($bStmt->fetchAll() as $break) {
            if ($finalIn && $break['start_time'] < $finalIn) {
                throw new InvalidArgumentException('Clock times must include all recorded breaks. Clock-in cannot be after a recorded break start.');
            }
            if ($finalOut) {
                if (!$break['end_time']) {
                    throw new InvalidArgumentException('Clock times must include all recorded breaks. End an active break before setting clock-out.');
                }
                if ($break['end_time'] > $finalOut) {
                    throw new InvalidArgumentException('Clock times must include all recorded breaks. Clock-out cannot be before a recorded break end.');
                }
            }
        }

        // Check if requested punch conflicts with any pending break requests on this shift
        $pbStmt = db()->prepare("
            SELECT requested_break_start, requested_break_end, break_type
            FROM attendance_correction_requests
            WHERE user_id = ? AND work_date = ? AND status = 'pending' AND field_name = 'break'
        ");
        $pbStmt->execute([$userId, $workDate]);
        foreach ($pbStmt->fetchAll() as $pb) {
            if ($finalIn && $pb['requested_break_start'] < $finalIn) {
                throw new InvalidArgumentException('Requested clock-in time cannot be after a pending break correction request (' . format_time($pb['requested_break_start']) . ').');
            }
            if ($finalOut && $pb['requested_break_end'] > $finalOut) {
                throw new InvalidArgumentException('Requested clock-out time cannot be before a pending break correction request (' . format_time($pb['requested_break_end']) . ').');
            }
        }

        $hasInChange = in_array($field, ['clock_in', 'both'], true) && ($in !== $att['clock_in']);
        $hasOutChange = in_array($field, ['clock_out', 'both'], true) && ($out !== $att['clock_out']);
        if (!$hasInChange && !$hasOutChange) {
            throw new InvalidArgumentException(__('no_correction_changes'));
        }

        $stmt = db()->prepare('
            INSERT INTO attendance_correction_requests (
                user_id, attendance_id, target_attendance_version, work_date,
                field_name, requested_clock_in, requested_clock_out,
                reason, status, is_late_submission, late_reason
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending", ?, ?)
        ');
        $stmt->execute([
            $userId,
            $attId,
            $targetVersion,
            $workDate,
            $field,
            in_array($field, ['clock_in', 'both'], true) ? $in : null,
            in_array($field, ['clock_out', 'both'], true) ? $out : null,
            $reason,
            $isLate ? 1 : 0,
            $isLate ? $lateReason : null,
        ]);
        $reqId = (int) db()->lastInsertId();
        db()->prepare("UPDATE attendance SET break_review_status = 'correction_pending' WHERE id = ?")->execute([$attId]);

        $uStmt = db()->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
        $uStmt->execute([$userId]);
        $staffName = (string) ($uStmt->fetchColumn() ?: 'Staff member');

        $admins = db()->query("SELECT id FROM users WHERE role='admin' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) {
            notification_create(
                (int)$adminId,
                'correction_request',
                'corr-submitted-' . $reqId,
                'New attendance correction request',
                "{$staffName} submitted a correction request for {$workDate}.",
                '/admin/corrections.php'
            );
        }

        return [
            'id' => $reqId,
            'name' => $staffName,
            'work_date' => $workDate,
            'is_late' => $isLate,
        ];
    });

    try {
        push_send_to_admins(
            'New Correction Request',
            "{$notification['name']} submitted a correction request for {$notification['work_date']}.",
            '/admin/corrections.php',
            'admin-corr-' . $notification['id'],
            'new_correction_request'
        );
    } catch (Throwable $pe) {
        error_log('[Push] ' . $pe->getMessage());
    }

    return $notification;
}

/**
 * Check whether a pending break correction request conflicts with the proposed request.
 */
function has_conflicting_break_correction_request(
    int $userId,
    string $workDate,
    ?int $breakId,
    string $breakType,
    ?string $start,
    ?string $end
): bool {
    // 1. If targeting an existing break, check if there's already a pending request for that exact break
    if ($breakId !== null) {
        $stmt = db()->prepare("
            SELECT id FROM attendance_correction_requests
            WHERE user_id = ? AND break_id = ? AND status = 'pending'
            LIMIT 1
        ");
        $stmt->execute([$userId, $breakId]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    } else {
        // Missing break: check if there's already a pending missing break of this category on this date
        $stmt = db()->prepare("
            SELECT id FROM attendance_correction_requests
            WHERE user_id = ? AND work_date = ? AND break_id IS NULL AND break_type = ? AND status = 'pending'
            LIMIT 1
        ");
        $stmt->execute([$userId, $workDate, $breakType]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }

    // 2. Check time overlaps against ANY pending break correction request on this work_date
    if ($start !== null && $end !== null) {
        $stmt = db()->prepare("
            SELECT requested_break_start, requested_break_end 
            FROM attendance_correction_requests
            WHERE user_id = ? AND work_date = ? AND status = 'pending' AND field_name = 'break'
        ");
        $stmt->execute([$userId, $workDate]);
        foreach ($stmt->fetchAll() as $pending) {
            $pStart = $pending['requested_break_start'];
            $pEnd = $pending['requested_break_end'];
            if ($pStart && $pEnd) {
                if (max($start, $pStart) < min($end, $pEnd)) {
                    return true;
                }
            }
        }
    }

    return false;
}

/**
 * Submit an attendance break correction request (Phase 3B).
 */
function submit_break_correction_request(
    int $userId,
    string $workDate,
    string $correctionType,
    ?int $breakId,
    string $breakType,
    ?string $breakStart,
    ?string $breakEnd,
    string $reason,
    ?string $lateReason = null
): array {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $workDate);
    if (!$parsed || $parsed->format('Y-m-d') !== $workDate) {
        throw new InvalidArgumentException('Invalid work date specified.');
    }
    if ($workDate > today_str()) {
        throw new InvalidArgumentException('Cannot submit correction requests for future dates.');
    }
    if (!attendance_date_eligible($userId, $workDate)) {
        throw new InvalidArgumentException(__('employment_date_unavailable'));
    }
    if (!in_array($correctionType, ['start', 'end', 'both', 'missing', 'auto_closed'], true)) {
        throw new InvalidArgumentException('Invalid break correction type specified.');
    }
    if (!in_array($breakType, ['lunch', 'solat', 'short'], true)) {
        throw new InvalidArgumentException('Invalid break category specified.');
    }
    $reason = trim($reason);
    if ($reason === '') {
        throw new InvalidArgumentException(__('msg_correction_reason'));
    }

    $isLate = is_correction_submission_late($workDate);
    $lateReason = trim((string)$lateReason);
    if ($isLate && $lateReason === '') {
        throw new InvalidArgumentException('The standard 3-day submission window has closed. An explanation for the late submission is required for administrator review.');
    }

    $notification = with_employee_lock($userId, function () use (
        $userId, $workDate, $correctionType, $breakId, $breakType,
        $breakStart, $breakEnd, $reason, $isLate, $lateReason
    ): array {
        $att = ensure_attendance_row_locked($userId, $workDate);
        $attId = (int) $att['id'];
        $targetVersion = (int) ($att['version'] ?? 1);

        if (!$att['clock_in']) {
            throw new InvalidArgumentException('Cannot submit break corrections for a shift without a recorded clock-in time.');
        }

        $nextDay = (new DateTimeImmutable($workDate))->modify('+1 day')->format('Y-m-d');
        $validDates = [$workDate, $nextDay];

        $reqStart = null;
        $reqEnd = null;

        if ($breakId !== null) {
            $stmt = db()->prepare('SELECT * FROM break_records WHERE id = ? FOR UPDATE');
            $stmt->execute([$breakId]);
            $break = $stmt->fetch();
            if (!$break || (int) $break['user_id'] !== $userId || (int) $break['attendance_id'] !== $attId) {
                throw new InvalidArgumentException('The selected break record does not belong to this shift.');
            }
            if ($break['break_type'] !== $breakType) {
                throw new InvalidArgumentException('Break category mismatch with existing record.');
            }

            if ($correctionType === 'auto_closed' && empty($break['is_auto_closed'])) {
                throw new InvalidArgumentException('The selected break was not auto-closed by the system.');
            }

            $origStart = (string) $break['start_time'];
            $origEnd = $break['end_time'] !== null ? (string) $break['end_time'] : null;

            if ($correctionType === 'start') {
                $reqStart = normalize_attendance_time($breakStart);
                $reqEnd = $origEnd;
                if (!$reqStart) {
                    throw new InvalidArgumentException('Please specify a valid requested break start time.');
                }
            } elseif ($correctionType === 'end' || $correctionType === 'auto_closed') {
                $reqStart = $origStart;
                $reqEnd = normalize_attendance_time($breakEnd);
                if (!$reqEnd) {
                    throw new InvalidArgumentException('Please specify a valid requested return time.');
                }
            } elseif ($correctionType === 'both') {
                $reqStart = normalize_attendance_time($breakStart);
                $reqEnd = normalize_attendance_time($breakEnd);
                if (!$reqStart || !$reqEnd) {
                    throw new InvalidArgumentException('Please specify both break start and return times.');
                }
            }

            // Check if any change was requested
            if ($reqStart === $origStart && $reqEnd === $origEnd) {
                throw new InvalidArgumentException(__('no_correction_changes'));
            }
        } else {
            // Missing break
            $reqStart = normalize_attendance_time($breakStart);
            $reqEnd = normalize_attendance_time($breakEnd);
            if (!$reqStart || !$reqEnd) {
                throw new InvalidArgumentException('Please specify both break start and return times for the missing break.');
            }

            // Check category frequency limit against policy
            $limits = break_limits()[$breakType] ?? null;
            if ($limits) {
                $cntStmt = db()->prepare('SELECT COUNT(*) FROM break_records WHERE attendance_id = ? AND break_type = ?');
                $cntStmt->execute([$attId, $breakType]);
                $existingCount = (int) $cntStmt->fetchColumn();

                $pStmt = db()->prepare("SELECT COUNT(*) FROM attendance_correction_requests WHERE user_id = ? AND work_date = ? AND break_id IS NULL AND break_type = ? AND status = 'pending'");
                $pStmt->execute([$userId, $workDate, $breakType]);
                $pendingCount = (int) $pStmt->fetchColumn();

                if (($existingCount + $pendingCount) >= $limits['max_sessions']) {
                    throw new InvalidArgumentException('Company policy allows a maximum of ' . $limits['max_sessions'] . ' ' . break_type_name($breakType) . ' session(s) per shift.');
                }
            }
        }

        // Validate time ordering and boundaries
        if ($reqEnd <= $reqStart) {
            throw new InvalidArgumentException('Break return time must be strictly after the start time.');
        }

        $nowStr = now()->format('Y-m-d H:i:s');
        if ($reqStart > $nowStr || $reqEnd > $nowStr) {
            throw new InvalidArgumentException('Break times cannot be in the future.');
        }

        if (!in_array(substr($reqStart, 0, 10), $validDates, true) || !in_array(substr($reqEnd, 0, 10), $validDates, true)) {
            throw new InvalidArgumentException('Break times must fall on the shift date (' . format_date($workDate) . ') or following morning for overnight shifts.');
        }

        if ($reqStart < $att['clock_in']) {
            throw new InvalidArgumentException('Break start time (' . format_time($reqStart) . ') cannot be before your clock-in time (' . format_time($att['clock_in']) . ').');
        }

        if ($att['clock_out'] && $reqEnd > $att['clock_out']) {
            throw new InvalidArgumentException('Break return time (' . format_time($reqEnd) . ') cannot be after your clock-out time (' . format_time($att['clock_out']) . ').');
        }

        // Validate overlap against other recorded breaks on this shift
        $otherBreaksStmt = db()->prepare('SELECT id, break_type, start_time, end_time FROM break_records WHERE attendance_id = ?' . ($breakId ? ' AND id != ?' : ''));
        $otherBreaksParams = $breakId ? [$attId, $breakId] : [$attId];
        $otherBreaksStmt->execute($otherBreaksParams);
        foreach ($otherBreaksStmt->fetchAll() as $ob) {
            if ($ob['end_time']) {
                if (max($reqStart, $ob['start_time']) < min($reqEnd, $ob['end_time'])) {
                    throw new InvalidArgumentException('Requested break times overlap with an existing ' . break_type_name($ob['break_type']) . ' (' . format_time($ob['start_time']) . ' - ' . format_time($ob['end_time']) . ').');
                }
            } else {
                if ($reqEnd > $ob['start_time']) {
                    throw new InvalidArgumentException('Requested break times overlap with an open break.');
                }
            }
        }

        // Validate boundary against pending punch correction on this shift
        $pPunchStmt = db()->prepare("
            SELECT requested_clock_in, requested_clock_out 
            FROM attendance_correction_requests 
            WHERE user_id = ? AND work_date = ? AND status = 'pending' AND field_name IN ('clock_in', 'clock_out', 'both')
            ORDER BY id DESC LIMIT 1
        ");
        $pPunchStmt->execute([$userId, $workDate]);
        $pendingPunch = $pPunchStmt->fetch();
        if ($pendingPunch) {
            if ($pendingPunch['requested_clock_in'] && $reqStart < $pendingPunch['requested_clock_in']) {
                throw new InvalidArgumentException('Requested break start (' . format_time($reqStart) . ') is before your pending requested clock-in time (' . format_time($pendingPunch['requested_clock_in']) . ').');
            }
            if ($pendingPunch['requested_clock_out'] && $reqEnd > $pendingPunch['requested_clock_out']) {
                throw new InvalidArgumentException('Requested break return (' . format_time($reqEnd) . ') is after your pending requested clock-out time (' . format_time($pendingPunch['requested_clock_out']) . ').');
            }
        }

        // Validate conflict with other pending break requests
        if (has_conflicting_break_correction_request($userId, $workDate, $breakId, $breakType, $reqStart, $reqEnd)) {
            throw new InvalidArgumentException('You already have a pending break correction request conflicting with this submission.');
        }

        // Insert into attendance_correction_requests
        $stmt = db()->prepare('
            INSERT INTO attendance_correction_requests (
                user_id, attendance_id, target_attendance_version, work_date,
                field_name, break_id, break_type, break_correction_type,
                requested_break_start, requested_break_end,
                reason, status, is_late_submission, late_reason
            ) VALUES (?, ?, ?, ?, "break", ?, ?, ?, ?, ?, ?, "pending", ?, ?)
        ');
        $stmt->execute([
            $userId,
            $attId,
            $targetVersion,
            $workDate,
            $breakId,
            $breakType,
            $correctionType,
            $reqStart,
            $reqEnd,
            $reason,
            $isLate ? 1 : 0,
            $isLate ? $lateReason : null,
        ]);
        $reqId = (int) db()->lastInsertId();
        db()->prepare("UPDATE attendance SET break_review_status = 'correction_pending' WHERE id = ?")->execute([$attId]);

        $uStmt = db()->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
        $uStmt->execute([$userId]);
        $staffName = (string) ($uStmt->fetchColumn() ?: 'Staff member');

        $admins = db()->query("SELECT id FROM users WHERE role='admin' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) {
            notification_create(
                (int)$adminId,
                'correction_request',
                'corr-break-submitted-' . $reqId,
                'New break correction request',
                "{$staffName} submitted a break correction request for {$workDate}.",
                '/admin/corrections.php'
            );
        }

        return [
            'id' => $reqId,
            'name' => $staffName,
            'work_date' => $workDate,
            'is_late' => $isLate,
        ];
    });

    try {
        push_send_to_admins(
            'New Break Correction Request',
            "{$notification['name']} submitted a break correction request for {$notification['work_date']}.",
            '/admin/corrections.php',
            'admin-corr-' . $notification['id'],
            'new_correction_request'
        );
    } catch (Throwable $pe) {
        error_log('[Push] ' . $pe->getMessage());
    }

    return $notification;
}

/**
 * Fetch correction requests submitted by a specific user.
 */
function get_correction_requests_for_user(int $userId, ?string $status = null, int $limit = 50): array
{
    $sql = 'SELECT c.*, 
                   a.clock_in AS current_clock_in, a.clock_out AS current_clock_out, a.version AS current_version,
                   br.start_time AS current_break_start, br.end_time AS current_break_end,
                   br.is_auto_closed AS current_break_is_auto_closed, br.closure_reason AS current_break_closure_reason,
                   br.break_type AS current_break_type
            FROM attendance_correction_requests c
            LEFT JOIN attendance a ON a.id = c.attendance_id
            LEFT JOIN break_records br ON br.id = c.break_id
            WHERE c.user_id = ?';
    $params = [$userId];
    if ($status !== null && $status !== 'all') {
        $sql .= ' AND c.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY c.created_at DESC, c.id DESC LIMIT ?';
    $stmt = db()->prepare($sql);
    foreach ($params as $idx => $val) {
        $stmt->bindValue($idx + 1, $val);
    }
    $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Review an attendance correction request (punch or break correction).
 *
 * @param int $requestId
 * @param string $decision 'approved' | 'rejected'
 * @param int $adminId
 * @param string|null $note
 * @return array Processed request metadata
 * @throws InvalidArgumentException
 */
function review_attendance_correction_request(int $requestId, string $decision, int $adminId, ?string $note = null): array
{
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        throw new InvalidArgumentException(__('msg_invalid_decision'));
    }

    $adminCheck = db()->prepare("SELECT id FROM users WHERE id=? AND role='admin' AND is_active=1");
    $adminCheck->execute([$adminId]);
    if (!$adminCheck->fetchColumn()) {
        throw new InvalidArgumentException(__('msg_no_permission'));
    }

    $initStmt = db()->prepare('SELECT id, user_id, status FROM attendance_correction_requests WHERE id = ?');
    $initStmt->execute([$requestId]);
    $initial = $initStmt->fetch();
    if (!$initial || $initial['status'] !== 'pending') {
        throw new InvalidArgumentException(__('msg_request_not_found'));
    }
    if ((int) $initial['user_id'] === $adminId) {
        throw new InvalidArgumentException('Administrators cannot approve or reject their own correction requests.');
    }

    $note = trim((string) $note);

    $result = with_employee_lock((int) $initial['user_id'], function () use ($requestId, $decision, $adminId, $note): array {
        $stmt = db()->prepare('SELECT * FROM attendance_correction_requests WHERE id = ? FOR UPDATE');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();

        if (!$request || $request['status'] !== 'pending') {
            throw new InvalidArgumentException('This correction request has already been processed.');
        }
        if ((int) $request['user_id'] === $adminId) {
            throw new InvalidArgumentException('Administrators cannot approve or reject their own correction requests.');
        }

        $userId = (int) $request['user_id'];
        $workDate = (string) $request['work_date'];

        if (!empty($request['attendance_id'])) {
            $attStmt = db()->prepare('SELECT * FROM attendance WHERE id = ? FOR UPDATE');
            $attStmt->execute([(int) $request['attendance_id']]);
            $currentAtt = $attStmt->fetch();
        } else {
            $att = ensure_attendance_row_locked($userId, $workDate);
            $attStmt = db()->prepare('SELECT * FROM attendance WHERE id = ? FOR UPDATE');
            $attStmt->execute([(int) $att['id']]);
            $currentAtt = $attStmt->fetch();
        }

        if (!$currentAtt) {
            throw new InvalidArgumentException('Associated attendance record not found.');
        }

        $attId = (int) $currentAtt['id'];

        if ($decision === 'approved') {
            if (!isset($request['target_attendance_version']) || $request['target_attendance_version'] === null) {
                throw new InvalidArgumentException('This request predates version tracking and cannot be approved through the automated queue. Please review the shift in Attendance Detail and apply any adjustment directly with an audit note.');
            }
            if ((int) ($currentAtt['version'] ?? 1) !== (int) $request['target_attendance_version']) {
                throw new InvalidArgumentException('The attendance record has been modified since this request was submitted (version conflict). Please review the updated timeline before approving.');
            }

            if (in_array($request['field_name'], ['clock_in', 'clock_out', 'both'], true)) {
                $newIn = in_array($request['field_name'], ['clock_in', 'both'], true) ? $request['requested_clock_in'] : $currentAtt['clock_in'];
                $newOut = in_array($request['field_name'], ['clock_out', 'both'], true) ? $request['requested_clock_out'] : $currentAtt['clock_out'];
                correct_attendance($attId, $newIn, $newOut, $adminId, $request['reason'], 'correction_approved');
            } elseif ($request['field_name'] === 'break') {
                $breakType = (string) $request['break_type'];
                $corrType = (string) $request['break_correction_type'];
                $nextDay = (new DateTimeImmutable($workDate))->modify('+1 day')->format('Y-m-d');
                $validDates = [$workDate, $nextDay];
                $nowStr = now()->format('Y-m-d H:i:s');
                $targetBreakId = null;

                if ($request['break_id'] !== null) {
                    $bStmt = db()->prepare('SELECT * FROM break_records WHERE id = ? FOR UPDATE');
                    $bStmt->execute([(int) $request['break_id']]);
                    $break = $bStmt->fetch();
                    if (!$break || (int) $break['attendance_id'] !== $attId || (int) $break['user_id'] !== $userId) {
                        throw new InvalidArgumentException('The selected break record does not exist or does not belong to this attendance shift.');
                    }
                    $targetBreakId = (int) $break['id'];

                    $origStart = (string) $break['start_time'];
                    $origEnd = $break['end_time'] !== null ? (string) $break['end_time'] : null;

                    if ($corrType === 'start') {
                        $newStart = normalize_attendance_time($request['requested_break_start']);
                        $newEnd = $origEnd;
                    } elseif ($corrType === 'end' || $corrType === 'auto_closed') {
                        $newStart = $origStart;
                        $newEnd = normalize_attendance_time($request['requested_break_end']);
                    } elseif ($corrType === 'both') {
                        $newStart = normalize_attendance_time($request['requested_break_start']);
                        $newEnd = normalize_attendance_time($request['requested_break_end']);
                    } else {
                        throw new InvalidArgumentException('Invalid break correction type.');
                    }

                    if (!$newStart || !$newEnd) {
                        throw new InvalidArgumentException('Both break start and return times are required for approval.');
                    }
                    if ($newEnd <= $newStart) {
                        throw new InvalidArgumentException('Break return time must be strictly after the start time.');
                    }
                    if ($newStart > $nowStr || $newEnd > $nowStr) {
                        throw new InvalidArgumentException('Break times cannot be in the future.');
                    }
                    if (!in_array(substr($newStart, 0, 10), $validDates, true) || !in_array(substr($newEnd, 0, 10), $validDates, true)) {
                        throw new InvalidArgumentException('Break times must fall on the shift date (' . format_date($workDate) . ') or following morning for overnight shifts.');
                    }
                    if ($currentAtt['clock_in'] && $newStart < $currentAtt['clock_in']) {
                        throw new InvalidArgumentException('Break start time (' . format_time($newStart) . ') cannot be before clock-in time (' . format_time($currentAtt['clock_in']) . ').');
                    }
                    if ($currentAtt['clock_out'] && $newEnd > $currentAtt['clock_out']) {
                        throw new InvalidArgumentException('Break return time (' . format_time($newEnd) . ') cannot be after clock-out time (' . format_time($currentAtt['clock_out']) . ').');
                    }

                    // Check overlap against other recorded breaks on this shift
                    $obStmt = db()->prepare('SELECT id, break_type, start_time, end_time FROM break_records WHERE attendance_id = ? AND id != ?');
                    $obStmt->execute([$attId, $targetBreakId]);
                    foreach ($obStmt->fetchAll() as $ob) {
                        if ($ob['end_time']) {
                            if (max($newStart, $ob['start_time']) < min($newEnd, $ob['end_time'])) {
                                throw new InvalidArgumentException('Approved break times overlap with an existing ' . break_type_name($ob['break_type']) . ' (' . format_time($ob['start_time']) . ' - ' . format_time($ob['end_time']) . ').');
                            }
                        } elseif ($newEnd > $ob['start_time']) {
                            throw new InvalidArgumentException('Approved break times overlap with an open break.');
                        }
                    }

                    $seconds = (new DateTimeImmutable($newEnd))->getTimestamp() - (new DateTimeImmutable($newStart))->getTimestamp();
                    $durationMinutes = (int) floor(max(0, $seconds) / 60);
                    $allowed = (int) $break['allowed_minutes'];
                    $exceeded = $seconds > ($allowed * 60) ? 1 : 0;

                    // Update break_records preserving auto_closed provenance
                    db()->prepare('UPDATE break_records SET start_time = ?, end_time = ?, duration_minutes = ?, exceeded = ?, updated_at = NOW() WHERE id = ?')
                        ->execute([$newStart, $newEnd, $durationMinutes, $exceeded, $targetBreakId]);

                    if ($origStart !== $newStart) {
                        write_audit($attId, $userId, $adminId, 'break_corrected', 'break_start', $origStart, $newStart, $request['reason']);
                    }
                    if ($origEnd !== $newEnd) {
                        write_audit($attId, $userId, $adminId, 'break_corrected', 'break_end', $origEnd, $newEnd, $request['reason']);
                    }
                } else {
                    // Missing break insertion ($request['break_id'] === null)
                    $newStart = normalize_attendance_time($request['requested_break_start']);
                    $newEnd = normalize_attendance_time($request['requested_break_end']);

                    if (!$newStart || !$newEnd) {
                        throw new InvalidArgumentException('Both break start and return times are required.');
                    }
                    if ($newEnd <= $newStart) {
                        throw new InvalidArgumentException('Break return time must be strictly after the start time.');
                    }
                    if ($newStart > $nowStr || $newEnd > $nowStr) {
                        throw new InvalidArgumentException('Break times cannot be in the future.');
                    }
                    if (!in_array(substr($newStart, 0, 10), $validDates, true) || !in_array(substr($newEnd, 0, 10), $validDates, true)) {
                        throw new InvalidArgumentException('Break times must fall on the shift date (' . format_date($workDate) . ') or following morning for overnight shifts.');
                    }
                    if ($currentAtt['clock_in'] && $newStart < $currentAtt['clock_in']) {
                        throw new InvalidArgumentException('Break start time (' . format_time($newStart) . ') cannot be before clock-in time (' . format_time($currentAtt['clock_in']) . ').');
                    }
                    if ($currentAtt['clock_out'] && $newEnd > $currentAtt['clock_out']) {
                        throw new InvalidArgumentException('Break return time (' . format_time($newEnd) . ') cannot be after clock-out time (' . format_time($currentAtt['clock_out']) . ').');
                    }

                    // Check session frequency limit against policy
                    $limits = break_limits()[$breakType] ?? null;
                    if ($limits) {
                        $cntStmt = db()->prepare('SELECT COUNT(*) FROM break_records WHERE attendance_id = ? AND break_type = ?');
                        $cntStmt->execute([$attId, $breakType]);
                        $existingCount = (int) $cntStmt->fetchColumn();
                        if ($existingCount >= $limits['max_sessions']) {
                            throw new InvalidArgumentException('Company policy allows a maximum of ' . $limits['max_sessions'] . ' ' . break_type_name($breakType) . ' session(s) per shift.');
                        }
                    }

                    // Overlap check
                    $obStmt = db()->prepare('SELECT id, break_type, start_time, end_time FROM break_records WHERE attendance_id = ?');
                    $obStmt->execute([$attId]);
                    foreach ($obStmt->fetchAll() as $ob) {
                        if ($ob['end_time']) {
                            if (max($newStart, $ob['start_time']) < min($newEnd, $ob['end_time'])) {
                                throw new InvalidArgumentException('Approved break times overlap with an existing ' . break_type_name($ob['break_type']) . ' (' . format_time($ob['start_time']) . ' - ' . format_time($ob['end_time']) . ').');
                            }
                        } elseif ($newEnd > $ob['start_time']) {
                            throw new InvalidArgumentException('Approved break times overlap with an open break.');
                        }
                    }

                    $seqStmt = db()->prepare('SELECT COALESCE(MAX(sequence_no), 0) + 1 FROM break_records WHERE attendance_id = ? AND break_type = ?');
                    $seqStmt->execute([$attId, $breakType]);
                    $seqNo = (int) $seqStmt->fetchColumn();

                    $allowedMinutes = (int) ($limits['suggest_session'] ?? ($limits['allowed_minutes'] ?? 0));
                    $seconds = (new DateTimeImmutable($newEnd))->getTimestamp() - (new DateTimeImmutable($newStart))->getTimestamp();
                    $durationMinutes = (int) floor(max(0, $seconds) / 60);
                    $exceeded = $seconds > ($allowedMinutes * 60) ? 1 : 0;

                    $ins = db()->prepare('
                        INSERT INTO break_records (
                            attendance_id, user_id, break_type, sequence_no, start_time, end_time,
                            duration_minutes, allowed_minutes, exceeded, is_auto_closed, closure_reason, system_closed_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, "manual", NULL)
                    ');
                    $ins->execute([$attId, $userId, $breakType, $seqNo, $newStart, $newEnd, $durationMinutes, $allowedMinutes, $exceeded]);
                    $targetBreakId = (int) db()->lastInsertId();

                    db()->prepare('UPDATE attendance_correction_requests SET break_id = ? WHERE id = ?')->execute([$targetBreakId, $requestId]);
                    write_audit($attId, $userId, $adminId, 'break_added', 'break_session', null, "{$breakType}: {$newStart} - {$newEnd}", $request['reason']);
                }

                // Increment attendance version monotonically for material data mutation
                db()->prepare('UPDATE attendance SET version = version + 1 WHERE id = ?')->execute([$attId]);

                // Discrepancy reconciliation: check for any remaining open or unverified auto-closed breaks
                $unresolvedStmt = db()->prepare("
                    SELECT COUNT(*) FROM break_records br
                    WHERE br.attendance_id = ? AND (
                        br.end_time IS NULL
                        OR (
                            br.is_auto_closed = 1
                            AND br.id != ?
                            AND NOT EXISTS (SELECT 1 FROM attendance_correction_requests acr WHERE acr.break_id = br.id AND acr.status = 'approved')
                        )
                    )
                ");
                $unresolvedStmt->execute([$attId, (int) $targetBreakId]);
                $remainingDiscrepancies = (int) $unresolvedStmt->fetchColumn();

                if (in_array($currentAtt['break_review_status'] ?? '', ['under_review', 'correction_pending', 'rejected_unresolved'], true)) {
                    if ($remainingDiscrepancies === 0) {
                        db()->prepare("UPDATE attendance SET break_review_status = 'resolved' WHERE id = ?")->execute([$attId]);
                    } else {
                        db()->prepare("UPDATE attendance SET break_review_status = 'under_review' WHERE id = ?")->execute([$attId]);
                    }
                }
                recalculate_attendance($attId);
            } else {
                throw new InvalidArgumentException('Unsupported correction field name.');
            }
        } elseif ($decision === 'rejected') {
            if ($request['field_name'] === 'break') {
                $chkStmt = db()->prepare("SELECT COUNT(*) FROM break_records WHERE attendance_id = ? AND (is_auto_closed = 1 OR end_time IS NULL)");
                $chkStmt->execute([$attId]);
                $hasDiscrepancy = (int) $chkStmt->fetchColumn() > 0;
                if ($hasDiscrepancy || in_array($currentAtt['break_review_status'] ?? '', ['under_review', 'correction_pending'], true)) {
                    db()->prepare("UPDATE attendance SET break_review_status = 'rejected_unresolved' WHERE id = ?")->execute([$attId]);
                    recalculate_attendance($attId);
                }
            }
            write_audit($attId, $userId, $adminId, 'correction_rejected', $request['field_name'], null, null, $note ?: 'Correction request rejected by administrator');
        }

        db()->prepare('UPDATE attendance_correction_requests SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')
            ->execute([$decision, $note ?: null, $adminId, $requestId]);

        $noticeTitle = $decision === 'approved' ? 'Correction request approved' : 'Correction request rejected';
        $breakDesc = $request['field_name'] === 'break' ? ('break ' . ($request['break_type'] ? break_type_name($request['break_type']) . ' ' : '')) : '';
        $noticeMsg = $decision === 'approved'
            ? "Your {$breakDesc}correction request for {$workDate} has been approved."
            : "Your {$breakDesc}correction request for {$workDate} was rejected." . ($note ? " Note: {$note}" : '');
        notification_create(
            $userId,
            'correction_decision',
            'corr-reviewed-' . $requestId,
            $noticeTitle,
            $noticeMsg,
            '/staff/corrections.php'
        );

        return [
            'id' => $requestId,
            'user_id' => $userId,
            'work_date' => $workDate,
            'field_name' => $request['field_name'],
            'decision' => $decision,
        ];
    });

    try {
        $pushTitle = $decision === 'approved' ? 'Correction Request Approved' : 'Correction Request Declined';
        $pushBody = $decision === 'approved'
            ? "Your attendance correction request for {$result['work_date']} has been approved."
            : "Your attendance correction request for {$result['work_date']} was rejected.";
        push_send_to_user(
            (int) $result['user_id'],
            $pushTitle,
            $pushBody,
            '/staff/corrections.php',
            'staff-corr-' . $requestId,
            'correction_decision'
        );
    } catch (Throwable $pe) {
        error_log('[Push] ' . $pe->getMessage());
    }

    return $result;
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
    $notification = with_employee_lock($userId, function () use ($requestId, $decision, $reviewerId, $note, $userId, $requiresReplacement): array {
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
        $typeLabel = strtoupper((string)$request['leave_type']);
        notification_create($userId, 'leave_decision', 'leave-reviewed-' . $requestId,
            "{$typeLabel} request " . ($decision === 'approved' ? 'approved' : 'declined'),
            "Your {$typeLabel} request for {$request['leave_date']} was " . ($decision === 'approved' ? 'approved.' : 'declined.'),
            '/staff/leave.php');
        return ['type' => (string) $request['leave_type'], 'date' => (string) $request['leave_date']];
    });
    try {
        $tUpper = strtoupper($notification['type']);
        $sText = ($decision === 'approved') ? 'Approved' : 'Declined';
        $body = ($decision === 'approved')
            ? "Your {$tUpper} request for {$notification['date']} has been approved."
            : "Your {$tUpper} request for {$notification['date']} was declined.";
        push_send_to_user(
            $userId,
            "{$tUpper} Request {$sText}",
            $body,
            '/staff/leave.php',
            'staff-leave-' . $requestId,
            'leave_decision'
        );
    } catch (Throwable $pe) {
        error_log('[Push] ' . $pe->getMessage());
    }
}

function get_attendance(int $userId, string $date): ?array
{
    $stmt = db()->prepare('SELECT * FROM attendance WHERE user_id = ? AND work_date = ? LIMIT 1');
    $stmt->execute([$userId, $date]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Resolve attendance state without changing attendance, break, schedule, or
 * review records. This is an observation boundary for UI and future API use;
 * mutation paths continue to use their existing locked workflows.
 */
function resolve_attendance_state(int $userId, ?string $calendarDate = null): array
{
    $calendarDate = $calendarDate ?? today_str();
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $calendarDate);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $calendarDate) {
        throw new InvalidArgumentException('Calendar date must be a valid YYYY-MM-DD date.');
    }

    $current = get_attendance($userId, $calendarDate);

    // An open attendance row is only a candidate. Historical rows are kept
    // unresolved so a stale shift cannot be treated as today's active shift.
    $openStmt = db()->prepare(
        'SELECT id, work_date, clock_in, clock_out, status, break_review_status,
                schedule_id, scheduled_start, scheduled_end, is_day_off,
                was_scheduled_off_day, scheduled_off_weekday, is_wfh
         FROM attendance
         WHERE user_id = ? AND clock_in IS NOT NULL AND clock_out IS NULL
         ORDER BY work_date DESC, id DESC'
    );
    $openStmt->execute([$userId]);
    $openRows = $openStmt->fetchAll() ?: [];
    $openIds = array_map(static fn(array $row): int => (int) $row['id'], $openRows);

    // Inspect all open breaks once so an open break attached to another
    // attendance row is visible as an unresolved stale association.
    $breakStmt = db()->prepare(
        'SELECT b.id, b.attendance_id, b.break_type, b.start_time,
                b.allowed_minutes, b.is_auto_closed, a.work_date
         FROM break_records b
         LEFT JOIN attendance a ON a.id = b.attendance_id
         WHERE b.user_id = ? AND b.end_time IS NULL
         ORDER BY b.id DESC'
    );
    $breakStmt->execute([$userId]);
    $openBreaks = $breakStmt->fetchAll() ?: [];

    $selected = $current;
    $classification = null;
    $candidateOpenShift = false;
    $validityUnresolved = false;
    $activeBreak = null;

    if ($selected) {
        $selectedId = (int) $selected['id'];
        foreach ($openBreaks as $break) {
            if ((int) $break['attendance_id'] === $selectedId) {
                $activeBreak = [
                    'id' => (int) $break['id'],
                    'attendance_id' => (int) $break['attendance_id'],
                    'break_type' => (string) $break['break_type'],
                    'start_time' => $break['start_time'],
                    'allowed_minutes' => (int) $break['allowed_minutes'],
                    'is_auto_closed' => (bool) $break['is_auto_closed'],
                ];
                break;
            }
        }

        $reviewStatus = (string) ($selected['break_review_status'] ?? 'none');
        $underReview = in_array($reviewStatus, ['under_review', 'correction_pending', 'rejected_unresolved'], true);
        if ($underReview) {
            $classification = 'under_review';
            $validityUnresolved = true;
        } elseif ((string) ($selected['status'] ?? '') === 'incomplete') {
            $classification = 'incomplete';
        } elseif ($selected['clock_out'] !== null) {
            $classification = 'completed';
        } elseif ($selected['clock_in'] !== null) {
            $classification = $activeBreak ? 'on_break' : 'working';
            $candidateOpenShift = true;
        } else {
            $classification = 'today_not_started';
        }

        if ($activeBreak && $selected['clock_out'] !== null) {
            $classification = 'stale_break_unresolved';
            $candidateOpenShift = false;
            $validityUnresolved = true;
        }

        foreach ($openBreaks as $break) {
            if ((int) $break['attendance_id'] !== $selectedId) {
                $validityUnresolved = true;
                if ($classification === 'working' || $classification === 'on_break') {
                    $classification = 'stale_break_unresolved';
                    $candidateOpenShift = false;
                }
                break;
            }
        }
    } elseif (count($openRows) === 1) {
        $selected = $openRows[0];
        $classification = 'open_shift_unresolved';
        $validityUnresolved = true;
        foreach ($openBreaks as $break) {
            if ((int) $break['attendance_id'] === (int) $selected['id']) {
                $activeBreak = [
                    'id' => (int) $break['id'],
                    'attendance_id' => (int) $break['attendance_id'],
                    'break_type' => (string) $break['break_type'],
                    'start_time' => $break['start_time'],
                    'allowed_minutes' => (int) $break['allowed_minutes'],
                    'is_auto_closed' => (bool) $break['is_auto_closed'],
                ];
                break;
            }
        }
    } elseif (count($openRows) > 1) {
        $classification = 'ambiguous_open_attendance';
        $validityUnresolved = true;
    } else {
        $classification = 'no_attendance';
    }

    $schedule = [
        'schedule_id' => null,
        'scheduled_start' => null,
        'scheduled_end' => null,
        'was_scheduled_off_day' => null,
        'scheduled_off_weekday' => null,
        'source' => 'unknown',
    ];
    if ($selected) {
        $schedule = [
            'schedule_id' => $selected['schedule_id'] === null ? null : (int) $selected['schedule_id'],
            'scheduled_start' => $selected['scheduled_start'],
            'scheduled_end' => $selected['scheduled_end'],
            'was_scheduled_off_day' => $selected['was_scheduled_off_day'] === null ? null : (bool) $selected['was_scheduled_off_day'],
            'scheduled_off_weekday' => $selected['scheduled_off_weekday'] === null ? null : (int) $selected['scheduled_off_weekday'],
            'source' => 'attendance_snapshot',
        ];
    } elseif ($classification === 'no_attendance') {
        $effective = get_schedule_for_date($userId, $calendarDate);
        if ($effective) {
            $schedule = [
                'schedule_id' => $effective['schedule_id'] === null ? null : (int) $effective['schedule_id'],
                'scheduled_start' => $effective['start_time'] ?? null,
                'scheduled_end' => $effective['end_time'] ?? null,
                'was_scheduled_off_day' => is_scheduled_off($effective, $calendarDate),
                'scheduled_off_weekday' => $effective['weekly_off_day'] === null ? null : (int) $effective['weekly_off_day'],
                'source' => 'effective_schedule',
            ];
        }
    }

    $recorded = $selected ? [
        'attendance_id' => (int) $selected['id'],
        'status' => $selected['status'] ?? null,
        'break_review_status' => $selected['break_review_status'] ?? null,
        'clock_in' => $selected['clock_in'],
        'clock_out' => $selected['clock_out'],
    ] : null;

    return [
        'contract_version' => 1,
        'attendance_id' => $selected ? (int) $selected['id'] : null,
        'work_date' => $selected['work_date'] ?? null,
        'calendar_date' => $calendarDate,
        'status' => $selected['status'] ?? null,
        'clock_in' => $selected['clock_in'] ?? null,
        'clock_out' => $selected['clock_out'] ?? null,
        'active_break' => $activeBreak,
        'schedule' => $schedule,
        'recorded' => $recorded,
        'derived' => [
            'classification' => $classification,
            'candidate_open_shift' => $candidateOpenShift,
            'validity_unresolved' => $validityUnresolved,
            'open_attendance_count' => count($openRows),
            'open_attendance_ids' => $openIds,
        ],
        'policy' => [
            'eligibility' => 'unknown',
        ],
        'server_timestamp' => now()->format('Y-m-d H:i:s'),
    ];
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
    // Most report views revisit dates whose staff rows already exist. Avoid the
    // schedule and leave lookups in the INSERT when there is nothing to add.
    $missingStmt = db()->prepare("SELECT 1 FROM users u
        LEFT JOIN attendance a ON a.user_id = u.id AND a.work_date = ?
        WHERE u.role = 'staff' AND u.is_active = 1
          AND (u.joining_date IS NULL OR u.joining_date <= ?)
          AND (u.employment_end_date IS NULL OR u.employment_end_date >= ?)
          AND a.id IS NULL LIMIT 1");
    $missingStmt->execute([$date, $date, $date]);
    if (!$missingStmt->fetchColumn()) {
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
    $defaultScheduleId = db()->query("SELECT id FROM work_schedules WHERE name='LASTO Standard Schedule' AND is_default=1 AND is_active=1 LIMIT 1")->fetchColumn();
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
    $dates = [];
    for ($date = $start; $date <= $effectiveEnd; $date = $date->modify('+1 day')) {
        $dates[] = $date->format('Y-m-d');
    }
    // One check per small date batch avoids a database round trip for every
    // day when an admin revisits a report whose attendance rows already exist.
    $hasMissingRows = false;
    foreach (array_chunk($dates, 30) as $batch) {
        $dateSelects = implode(' UNION ALL ', array_fill(0, count($batch), 'SELECT ? AS work_date'));
        $missingStmt = db()->prepare("SELECT 1 FROM ({$dateSelects}) dates
            JOIN users u ON u.role = 'staff' AND u.is_active = 1
              AND (u.joining_date IS NULL OR u.joining_date <= dates.work_date)
              AND (u.employment_end_date IS NULL OR u.employment_end_date >= dates.work_date)
            LEFT JOIN attendance a ON a.user_id = u.id AND a.work_date = dates.work_date
            WHERE a.id IS NULL LIMIT 1");
        $missingStmt->execute($batch);
        if ($missingStmt->fetchColumn()) {
            $hasMissingRows = true;
            break;
        }
    }
    if (!$hasMissingRows) {
        return 0;
    }
    $created = 0;
    foreach ($dates as $date) {
        $created += ensure_attendance_rows_for_date($date);
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
            ->execute([(int) $isWfh, $isWfh ? 0 : (int) $shouldOff, $scheduledOff ? 1 : (int)($att['was_scheduled_off_day'] ?? 0), $schedule['weekly_off_day'] ?? ($att['scheduled_off_weekday'] ?? null), $att['id']]);
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

/** Return an open break only when it belongs to the intended attendance row. */
function get_active_break_for_attendance(int $userId, int $attendanceId): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM break_records
         WHERE user_id = ? AND attendance_id = ? AND end_time IS NULL
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$userId, $attendanceId]);
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
        'UPDATE attendance SET shift_minutes = ?, work_minutes = ?, provisional_work_minutes = ?, early_leave_minutes = ?, status = ?, late_minutes = ?, updated_at = NOW() WHERE id = ?'
    );
    $provisionalWork = null;
    $reviewStatus = (string) ($att['break_review_status'] ?? 'none');
    if (in_array($reviewStatus, ['under_review', 'correction_pending', 'rejected_unresolved'], true) && $att['clock_in'] && $att['clock_out']) {
        $stmtProv = db()->prepare("SELECT COALESCE(SUM(CASE WHEN is_auto_closed = 1 THEN allowed_minutes ELSE duration_minutes END), 0) FROM break_records WHERE attendance_id = ? AND end_time IS NOT NULL");
        $stmtProv->execute([$attendanceId]);
        $provBreak = (int) $stmtProv->fetchColumn();
        $provisionalWork = max(0, $shift - $provBreak);
    }
    $upd->execute([$shift, $work, $provisionalWork, $early, $status, $late, $attendanceId]);
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
        $nextDay = (new DateTimeImmutable($workDate))->modify('+1 day')->format('Y-m-d');
        $validDates = [$workDate, $nextDay];

        if (!in_array(substr($startNorm, 0, 10), $validDates, true) || ($endNorm && !in_array(substr($endNorm, 0, 10), $validDates, true))) {
            throw new InvalidArgumentException('Break times must fall on the shift date (' . format_date($workDate) . ') or the following morning for overnight shifts.');
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

        db()->prepare('UPDATE attendance SET version = version + 1 WHERE id=?')->execute([(int) $break['attendance_id']]);
        recalculate_attendance((int) $break['attendance_id']);
    });
}
function clock_in(int $userId): array
{
    return with_employee_lock($userId, function () use ($userId): array {
        $date = today_str();
        if (has_approved_leave($userId, $date)) {
            return ['success' => false, 'message' => __('msg_leave_today')];
        }
        $att = ensure_attendance_row($userId, $date);
        if ($att['clock_in']) {
            return ['success' => false, 'message' => __('msg_already_clocked_in')];
        }
        $clockIn = now()->format('Y-m-d H:i:s');
        $late = attendance_late_minutes($date, $clockIn);
        db()->prepare('UPDATE attendance SET clock_in=?, late_minutes=?, status=?, version = version + 1 WHERE id=?')
            ->execute([$clockIn, $late, $late > 0 ? 'late' : 'present', $att['id']]);
        write_audit((int) $att['id'], $userId, $userId, 'clock_in', 'clock_in', null, $clockIn, 'Staff clock in');
        $locationEventId = attendance_location_start((int) $att['id'], $userId, 'clock_in', $clockIn);
        $message = __('msg_clocked_in', ['time' => format_time($clockIn)]);
        $message .= $late > 0 ? __('msg_status_late', ['time' => format_minutes($late)]) : __('msg_status_ontime');
        return ['success' => true, 'message' => $message, 'attendance_id' => (int) $att['id'], 'location_event_id' => $locationEventId];
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

        $clockOut = now()->format('Y-m-d H:i:s');
        $activeBreak = get_active_break($userId);
        if ($activeBreak && (int) $activeBreak['attendance_id'] !== (int) $att['id']) {
            write_audit((int) $att['id'], $userId, $userId, 'stale_break_detected', 'break', null, (string) $activeBreak['id'], 'Open break belongs to a different attendance record; clock-out was not changed');
            return ['success' => false, 'message' => __('msg_stale_break_exists')];
        }
        $hadOpenBreak = false;
        $openBreakType = '';

        if ($activeBreak) {
            $hadOpenBreak = true;
            $openBreakType = (string) $activeBreak['break_type'];
            $bStart = (string) $activeBreak['start_time'];
            $seconds = max(0, strtotime($clockOut) - strtotime($bStart));
            $dur = (int) floor($seconds / 60);
            $allowed = (int) $activeBreak['allowed_minutes'];
            $exceeded = $dur > $allowed ? 1 : 0;

            db()->prepare("UPDATE break_records 
                           SET end_time = ?, duration_minutes = ?, exceeded = ?, is_auto_closed = 1, closure_reason = 'clock_out', system_closed_at = ? 
                           WHERE id = ?")
                ->execute([$clockOut, $dur, $exceeded, $clockOut, $activeBreak['id']]);

            write_audit(
                (int) $att['id'],
                $userId,
                $userId,
                'break_auto_closed',
                'break_end',
                null,
                $clockOut,
                "System auto-closed open {$openBreakType} break at clock-out"
            );
        }

        if ($hadOpenBreak) {
            db()->prepare("UPDATE attendance 
                           SET clock_out = ?, break_review_status = 'under_review', version = version + 1 
                           WHERE id = ?")
                ->execute([$clockOut, $att['id']]);
        } else {
            db()->prepare("UPDATE attendance SET clock_out = ?, version = version + 1 WHERE id = ?")
                ->execute([$clockOut, $att['id']]);
        }

        recalculate_attendance((int) $att['id']);
        ensure_offday_adjustment_review((int)$att['id']);
        write_audit((int) $att['id'], $userId, $userId, 'clock_out', 'clock_out', null, $clockOut, 'Staff clock out');
        $locationEventId = attendance_location_start((int) $att['id'], $userId, 'clock_out', $clockOut);
        $att = get_attendance($userId, today_str());

        if ($hadOpenBreak) {
            $typeName = break_type_name($openBreakType);
            $message = __('msg_clocked_out_open_break', [
                'time' => format_time($clockOut),
                'break' => $typeName,
                'work' => format_minutes((int) $att['work_minutes']),
            ]);
        } else {
            $message = __('msg_clocked_out', [
                'time' => format_time($clockOut),
                'work' => format_minutes((int) $att['work_minutes']),
            ]);
        }

        return [
            'success' => true,
            'location_event_id' => $locationEventId,
            'message' => $message,
            'had_open_break' => $hadOpenBreak,
        ];
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
        $activeBreak = get_active_break($userId);
        if ($activeBreak) {
            if ((int) $activeBreak['attendance_id'] !== (int) $att['id']) {
                write_audit((int) $att['id'], $userId, $userId, 'stale_break_detected', 'break', null, (string) $activeBreak['id'], 'Open break belongs to a different attendance record; new break was not created');
                return ['success' => false, 'message' => __('msg_stale_break_exists')];
            }
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
        db()->prepare("UPDATE attendance SET status='on_break', version = version + 1 WHERE id=?")->execute([$att['id']]);
        write_audit((int) $att['id'], $userId, $userId, 'break_start', 'break', null, "{$type}#{$seq}", 'Start break');
        return ['success' => true, 'break_id' => $breakId, 'message' => __('msg_break_started', [
            'label' => break_label($type, $seq), 'allowed' => format_minutes($allowed),
        ])];
    });
}

function end_break(int $userId): array
{
    return with_employee_lock($userId, function () use ($userId): array {
        $att = get_attendance($userId, today_str());
        $break = $att ? get_active_break_for_attendance($userId, (int) $att['id']) : null;
        $anyActiveBreak = get_active_break($userId);
        if (!$break && $anyActiveBreak && (!$att || (int) $anyActiveBreak['attendance_id'] !== (int) $att['id'])) {
            write_audit($att ? (int) $att['id'] : null, $userId, $userId, 'stale_break_detected', 'break', null, (string) $anyActiveBreak['id'], 'Open break belongs to a different attendance record; end request was not applied');
            return ['success' => false, 'message' => __('msg_stale_break_exists')];
        }
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
        db()->prepare('UPDATE attendance SET version = version + 1 WHERE id=?')->execute([(int) $break['attendance_id']]);
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

/** Close one discovered candidate using the shared employee-first lock order. */
function close_unended_break_candidate(int $breakId, int $attendanceId, int $userId, string $eligibility): bool
{
    if (!in_array($eligibility, ['clock_out', 'midnight_cron'], true)) {
        throw new InvalidArgumentException('Invalid unended-break cleanup eligibility.');
    }

    return with_employee_lock($userId, function () use ($breakId, $attendanceId, $userId, $eligibility): bool {
        $attendanceStmt = db()->prepare('SELECT * FROM attendance WHERE id=? AND user_id=? FOR UPDATE');
        $attendanceStmt->execute([$attendanceId, $userId]);
        $attendance = $attendanceStmt->fetch();
        if (!$attendance) return false;

        $breakStmt = db()->prepare('SELECT * FROM break_records WHERE id=? AND attendance_id=? AND user_id=? FOR UPDATE');
        $breakStmt->execute([$breakId, $attendanceId, $userId]);
        $break = $breakStmt->fetch();
        if (!$break || $break['end_time'] !== null) return false;

        if ($eligibility === 'clock_out') {
            if ($attendance['clock_out'] === null) return false;
            $endTime = (string) $attendance['clock_out'];
            $closureReason = 'clock_out';
            $auditReason = 'System auto-closed lingering break matching clock-out';
        } else {
            if ((string) $attendance['work_date'] >= today_str()) return false;
            $endTime = (string) $attendance['work_date'] . ' ' . POLICY_END_TIME;
            $closureReason = 'midnight_cron';
            $auditReason = 'System auto-closed unended break at midnight cron';
        }

        if (strtotime($endTime) <= strtotime((string) $break['start_time'])) {
            $endTime = date('Y-m-d H:i:s', strtotime((string) $break['start_time']) + ((int) $break['allowed_minutes'] * 60));
        }
        $seconds = max(0, strtotime($endTime) - strtotime((string) $break['start_time']));
        $duration = (int) floor($seconds / 60);
        $allowed = (int) $break['allowed_minutes'];
        $exceeded = $duration > $allowed ? 1 : 0;
        $nowStr = now()->format('Y-m-d H:i:s');

        $updateBreak = db()->prepare("UPDATE break_records
            SET end_time=?, duration_minutes=?, exceeded=?, is_auto_closed=1, closure_reason=?, system_closed_at=?
            WHERE id=? AND attendance_id=? AND user_id=? AND end_time IS NULL");
        $updateBreak->execute([$endTime, $duration, $exceeded, $closureReason, $nowStr, $breakId, $attendanceId, $userId]);
        if ($updateBreak->rowCount() !== 1) return false;

        db()->prepare("UPDATE attendance
            SET break_review_status=CASE WHEN break_review_status='none' THEN 'under_review' ELSE break_review_status END,
                version=version+1
            WHERE id=? AND user_id=?")
            ->execute([$attendanceId, $userId]);
        recalculate_attendance($attendanceId);
        write_audit($attendanceId, $userId, $userId, 'break_auto_closed', 'break_end', null, $endTime, $auditReason);
        return true;
    });
}

function close_unended_breaks(): int
{
    // Candidate scans are snapshots only; each row is reloaded and revalidated
    // inside its own employee-scoped transaction before any update is applied.
    $failures = 0;
    $clockOutCandidates = db()->query("SELECT b.id, b.attendance_id, a.user_id
        FROM break_records b JOIN attendance a ON a.id=b.attendance_id
        WHERE b.end_time IS NULL AND a.clock_out IS NOT NULL")->fetchAll();
    foreach ($clockOutCandidates as $candidate) {
        try {
            close_unended_break_candidate((int) $candidate['id'], (int) $candidate['attendance_id'], (int) $candidate['user_id'], 'clock_out');
        } catch (Throwable $error) {
            $failures++;
            error_log('[Attendance cleanup] Clock-out break repair failed for break ' . (int) $candidate['id'] . ': ' . $error->getMessage());
        }
    }

    $pastCandidates = db()->prepare("SELECT b.id, b.attendance_id, a.user_id
        FROM break_records b JOIN attendance a ON a.id=b.attendance_id
        WHERE b.end_time IS NULL AND a.work_date < ?");
    $pastCandidates->execute([today_str()]);
    foreach ($pastCandidates->fetchAll() as $candidate) {
        try {
            close_unended_break_candidate((int) $candidate['id'], (int) $candidate['attendance_id'], (int) $candidate['user_id'], 'midnight_cron');
        } catch (Throwable $error) {
            $failures++;
            error_log('[Attendance cleanup] Past-shift break repair failed for break ' . (int) $candidate['id'] . ': ' . $error->getMessage());
        }
    }
    return $failures;
}

function mark_incomplete_attendances(): int
{
    // Reconcile one past open shift per employee transaction, sharing the
    // same lock boundary as clock-out and other employee actions.
    $stmt = db()->prepare("SELECT id, user_id FROM attendance
        WHERE clock_in IS NOT NULL AND clock_out IS NULL AND work_date < ?
          AND status NOT IN ('day_off', 'incomplete')");
    $stmt->execute([today_str()]);
    $failures = 0;
    foreach ($stmt->fetchAll() as $candidate) {
        try {
            with_employee_lock((int) $candidate['user_id'], function () use ($candidate): void {
                $id = (int) $candidate['id'];
                $userId = (int) $candidate['user_id'];
                $eligibility = db()->prepare("SELECT id FROM attendance WHERE id=? AND user_id=?
                    AND clock_in IS NOT NULL AND clock_out IS NULL AND work_date < ?
                    AND status NOT IN ('day_off', 'incomplete') FOR UPDATE");
                $eligibility->execute([$id, $userId, today_str()]);
                if (!$eligibility->fetchColumn()) return;
                db()->prepare("UPDATE attendance SET status='incomplete', version=version+1, updated_at=NOW()
                    WHERE id=? AND user_id=? AND clock_in IS NOT NULL AND clock_out IS NULL
                      AND work_date < ? AND status NOT IN ('day_off', 'incomplete')")
                    ->execute([$id, $userId, today_str()]);
            });
        } catch (Throwable $error) {
            $failures++;
            error_log('[Attendance cleanup] Incomplete attendance update failed for attendance ' . (int) $candidate['id'] . ': ' . $error->getMessage());
        }
    }

    return $failures + close_unended_breaks();
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


/**
 * Processes automated reminders for unresolved break discrepancies and pending break corrections.
 * Uses compound deduplication keys to ensure idempotency.
 * Returns the count of newly created notification events.
 */
function process_break_discrepancy_reminders(?DateTimeImmutable $now = null): int
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur'));
    $todayDate = $now->format('Y-m-d');
    $pdo = db();
    $createdCount = 0;

    // 1. Staff reminders: Active staff with shifts in 'under_review' (auto-closed open break)
    $staffStmt = $pdo->prepare("
        SELECT a.id, a.user_id, a.work_date
        FROM attendance a
        JOIN users u ON u.id = a.user_id
        WHERE a.break_review_status = 'under_review'
          AND a.work_date >= DATE_SUB(?, INTERVAL 14 DAY)
          AND u.role = 'staff'
          AND u.is_active = 1
    ");
    $staffStmt->execute([$todayDate]);
    $discrepancies = $staffStmt->fetchAll();

    foreach ($discrepancies as $row) {
        $attId = (int) $row['id'];
        $userId = (int) $row['user_id'];
        $workDate = (string) $row['work_date'];

        // Compound deduplication key: 1 reminder per attendance record per day
        $eventKey = "break-disc-{$attId}-{$todayDate}";
        $title = 'Unresolved Break Discrepancy';
        $message = "Your shift on {$workDate} has an auto-closed break under review. Please submit a break correction request.";
        $destination = '/staff/corrections.php';

        if (notification_create($userId, 'break_discrepancy', $eventKey, $title, $message, $destination)) {
            $createdCount++;
            if (function_exists('push_send_to_user')) {
                push_send_to_user($userId, $title, $message, $destination, $eventKey, 'break_discrepancy');
            }
        }
    }

    // 2. Admin reminders: Pending break correction requests awaiting review for > 24 hours
    $adminStmt = $pdo->query("
        SELECT r.id, r.user_id, r.attendance_id, r.created_at, u.full_name
        FROM attendance_correction_requests r
        JOIN users u ON u.id = r.user_id
        WHERE (r.break_id IS NOT NULL OR r.break_correction_type IS NOT NULL)
          AND r.status = 'pending'
          AND r.created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $pendingRequests = $adminStmt ? $adminStmt->fetchAll() : [];

    if (!empty($pendingRequests)) {
        $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll();
        $pendingCount = count($pendingRequests);

        foreach ($admins as $adm) {
            $adminId = (int) $adm['id'];
            $eventKey = "admin-pending-breaks-{$todayDate}";
            $title = 'Pending Break Correction Requests';
            $message = "There are {$pendingCount} pending break correction request(s) awaiting managerial review.";
            $destination = '/admin/corrections.php';

            if (notification_create($adminId, 'pending_break_correction', $eventKey, $title, $message, $destination)) {
                $createdCount++;
                if (function_exists('push_send_to_user')) {
                    push_send_to_user($adminId, $title, $message, $destination, $eventKey, 'pending_break_correction');
                }
            }
        }
    }

    return $createdCount;
}
