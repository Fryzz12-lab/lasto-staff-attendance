<?php
declare(strict_types=1);

function adjustment_transaction(Closure $callback): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try { $callback(); $pdo->commit(); } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function adjustment_in_transaction(Closure $callback): void
{
    $pdo = db(); $owns = !$pdo->inTransaction(); if ($owns) $pdo->beginTransaction();
    try { $callback(); if ($owns) $pdo->commit(); } catch (Throwable $e) { if ($owns && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function attendance_is_completed(array $attendance): bool
{
    return !empty($attendance['clock_in']) && !empty($attendance['clock_out'])
        && empty($attendance['open_break_id']);
}

function ensure_offday_adjustment_review(int $attendanceId): ?int
{
    $stmt = db()->prepare('SELECT * FROM attendance WHERE id=? LIMIT 1');
    $stmt->execute([$attendanceId]);
    $att = $stmt->fetch();
    if (!$att || !attendance_is_completed($att)) return null;
    $historicalOff = (int)($att['was_scheduled_off_day'] ?? 0) === 1;
    if (!$historicalOff) {
        $schedule = get_schedule_for_date((int)$att['user_id'], (string)$att['work_date']);
        $historicalOff = $schedule && is_scheduled_off($schedule, (string)$att['work_date']) && !has_approved_leave((int)$att['user_id'], (string)$att['work_date']);
        if ($historicalOff) {
            db()->prepare('UPDATE attendance SET was_scheduled_off_day=1, scheduled_off_weekday=? WHERE id=?')->execute([(int)$schedule['weekly_off_day'], $attendanceId]);
        }
    }
    if (!$historicalOff) return null;
    $stmt = db()->prepare('SELECT id FROM offday_adjustment_reviews WHERE attendance_id=? LIMIT 1');
    $stmt->execute([$attendanceId]);
    $existing = $stmt->fetchColumn();
    if ($existing) return (int)$existing;
    $stmt = db()->prepare("INSERT INTO offday_adjustment_reviews (employee_id,attendance_id) VALUES (?,?)");
    $stmt->execute([(int)$att['user_id'], $attendanceId]);
    return (int)db()->lastInsertId();
}

function balance_total(int $employeeId, string $type): int
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM staff_balance_ledger WHERE employee_id=? AND balance_type=?');
    $stmt->execute([$employeeId, $type]);
    return (int)$stmt->fetchColumn();
}

function employee_balances(int $employeeId): array
{
    return [
        'replacement_off' => balance_total($employeeId, 'replacement_off'),
        'cuti_perlu_ganti' => balance_total($employeeId, 'cuti_perlu_ganti'),
    ];
}

function decide_offday_adjustment(int $reviewId, string $decision, int $adminId, string $note, bool $overrideNegative = false): void
{
    if (!in_array($decision, ['replacement_off','settle_cuti_ganti','no_adjustment'], true)) throw new InvalidArgumentException('Invalid adjustment decision.');
    if ($note === '') throw new InvalidArgumentException('A reason is required for every adjustment decision.');
    adjustment_transaction(function () use ($reviewId,$decision,$adminId,$note,$overrideNegative): void {
        $stmt = db()->prepare('SELECT r.*, a.clock_in, a.clock_out, a.user_id FROM offday_adjustment_reviews r JOIN attendance a ON a.id=r.attendance_id WHERE r.id=? FOR UPDATE');
        $stmt->execute([$reviewId]); $review = $stmt->fetch();
        if (!$review || $review['status'] !== 'pending' || !$review['clock_in'] || !$review['clock_out']) throw new InvalidArgumentException('This review is no longer pending or the attendance is incomplete.');
        $admin = db()->prepare("SELECT id FROM users WHERE id=? AND role='admin' AND is_active=1"); $admin->execute([$adminId]);
        if (!$admin->fetchColumn()) throw new InvalidArgumentException(__('msg_no_permission'));
        $amount = $decision === 'no_adjustment' ? 0 : 1;
        db()->prepare('UPDATE offday_adjustment_reviews SET status="decided",decision=?,amount=?,admin_id=?,admin_note=?,decided_at=NOW() WHERE id=?')->execute([$decision,$amount,$adminId,$note ?: null,$reviewId]);
        if ($decision === 'no_adjustment') return;
        $type = $decision === 'replacement_off' ? 'replacement_off' : 'cuti_perlu_ganti';
        $ledgerAmount = $decision === 'replacement_off' ? $amount : -$amount;
        $current = balance_total((int)$review['user_id'], $type);
        if ($type === 'cuti_perlu_ganti' && $current + $ledgerAmount < 0 && !$overrideNegative) throw new InvalidArgumentException('Settlement exceeds the current Cuti Perlu Ganti balance. Confirm an explicit override to continue.');
        db()->prepare('INSERT INTO staff_balance_ledger (employee_id,balance_type,amount,source_type,source_id,review_id,decision,admin_id,admin_note) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([(int)$review['user_id'],$type,$ledgerAmount,'offday_attendance',(int)$review['attendance_id'],$reviewId,$decision,$adminId,$note ?: null]);
    });
}

function reverse_offday_adjustment(int $reviewId, int $adminId, string $note): void
{
    if ($note === '') throw new InvalidArgumentException('A reversal note is required.');
    adjustment_transaction(function () use ($reviewId,$adminId,$note): void {
        $stmt = db()->prepare('SELECT * FROM offday_adjustment_reviews WHERE id=? FOR UPDATE'); $stmt->execute([$reviewId]); $review = $stmt->fetch();
        if (!$review || $review['status'] !== 'decided' || !$review['decision'] || $review['decision'] === 'no_adjustment') throw new InvalidArgumentException('Only a balance-affecting decision can be reversed.');
        $stmt = db()->prepare("SELECT * FROM staff_balance_ledger WHERE review_id=? AND source_type='offday_attendance' ORDER BY id DESC LIMIT 1 FOR UPDATE"); $stmt->execute([$reviewId]); $entry = $stmt->fetch();
        if (!$entry) throw new InvalidArgumentException('The ledger entry for this decision was not found.');
        $stmt = db()->prepare("SELECT id FROM users WHERE id=? AND role='admin' AND is_active=1"); $stmt->execute([$adminId]); if (!$stmt->fetchColumn()) throw new InvalidArgumentException(__('msg_no_permission'));
        db()->prepare('INSERT INTO staff_balance_ledger (employee_id,balance_type,amount,source_type,source_id,review_id,decision,reversal_of_id,admin_id,admin_note) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([(int)$entry['employee_id'],$entry['balance_type'],-(float)$entry['amount'],'reversal',(int)$entry['source_id'],$reviewId,'reversal',(int)$entry['id'],$adminId,$note]);
        db()->prepare("UPDATE offday_adjustment_reviews SET status='reversed',admin_note=CONCAT(COALESCE(admin_note,''),' [Reversed: ', ?, ']'),updated_at=NOW() WHERE id=?")->execute([$note,$reviewId]);
    });
}

function record_leave_replacement(int $leaveRequestId, int $employeeId, bool $required, int $adminId, ?string $note): void
{
    adjustment_in_transaction(function () use ($leaveRequestId,$employeeId,$required,$adminId,$note): void {
        $stmt = db()->prepare("SELECT id FROM users WHERE id=? AND role='admin' AND is_active=1"); $stmt->execute([$adminId]);
        if (!$stmt->fetchColumn()) throw new InvalidArgumentException(__('msg_no_permission'));
        $stmt = db()->prepare('SELECT id FROM leave_replacement_reviews WHERE leave_request_id=? AND status="active" LIMIT 1 FOR UPDATE'); $stmt->execute([$leaveRequestId]);
        if ($stmt->fetchColumn()) throw new InvalidArgumentException('A replacement decision already exists for this leave.');
        $stmt = db()->prepare('INSERT INTO leave_replacement_reviews (leave_request_id,employee_id,requires_replacement,amount,admin_id,admin_note) VALUES (?,?,?,?,?,?)');
        $amount = $required ? 1 : 0;
        $stmt->execute([$leaveRequestId,$employeeId,$required ? 1 : 0,$amount,$adminId,$note ?: null]);
        if ($required) {
            db()->prepare('INSERT INTO staff_balance_ledger (employee_id,balance_type,amount,source_type,source_id,decision,admin_id,admin_note) VALUES (?,"cuti_perlu_ganti",?,"leave",?,?,?,?)')
                ->execute([$employeeId,$amount,$leaveRequestId,'leave_requires_replacement',$adminId,$note ?: null]);
        }
    });
}
