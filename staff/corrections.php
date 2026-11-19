<?php
/**
 * Staff — Attendance Clock-In / Clock-Out & Break Correction Requests
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$user = current_user();
$userId = (int) $user['id'];

// Handle POST Submission
if (is_post()) {
    require_csrf();
    $workDate = trim((string) post('work_date'));
    $targetMode = trim((string) post('target_mode', 'punch'));
    $reason = trim((string) post('reason'));
    $lateReason = trim((string) post('late_reason'));

    if ($targetMode === 'break') {
        $breakCorrType = trim((string) post('break_correction_type', 'both'));
        $rawBreakId = post('break_id');
        $breakId = ($rawBreakId !== null && $rawBreakId !== '' && $rawBreakId !== 'missing') ? (int) $rawBreakId : null;
        $breakCategory = trim((string) post('break_category', 'lunch'));
        $startTime = trim((string) post('break_start'));
        $endTime = trim((string) post('break_end'));
        $endD = trim((string) post('break_end_date', $workDate));

        $startDt = $startTime !== '' ? (strlen($startTime) <= 8 ? "{$workDate} {$startTime}" : $startTime) : null;
        $endDt = $endTime !== '' ? (strlen($endTime) <= 8 ? "{$endD} {$endTime}" : $endTime) : null;

        if ($breakId === null) {
            $breakCorrType = 'missing';
        }

        try {
            submit_break_correction_request(
                $userId,
                $workDate,
                $breakCorrType,
                $breakId,
                $breakCategory,
                $startDt,
                $endDt,
                $reason,
                $lateReason
            );
            flash_set('success', __('msg_break_correction_submitted'));
            redirect('/staff/corrections.php?date=' . urlencode($workDate) . '&type=break');
        } catch (InvalidArgumentException $error) {
            flash_set('danger', $error->getMessage());
        } catch (Throwable $error) {
            error_log((string) $error);
            flash_set('danger', __('msg_process_fail'));
        }
    } else {
        // Attendance Punch Correction (Phase 2)
        $field = trim((string) post('field_name', 'both'));
        $clockInTime = trim((string) post('clock_in'));
        $clockOutTime = trim((string) post('clock_out'));
        $outDate = trim((string) post('clock_out_date', $workDate));

        $clockIn = null;
        if (in_array($field, ['clock_in', 'both'], true) && $clockInTime !== '') {
            $clockIn = strlen($clockInTime) <= 8 ? "{$workDate} {$clockInTime}" : $clockInTime;
        }
        $clockOut = null;
        if (in_array($field, ['clock_out', 'both'], true) && $clockOutTime !== '') {
            $clockOut = strlen($clockOutTime) <= 8 ? "{$outDate} {$clockOutTime}" : $clockOutTime;
        }

        try {
            submit_attendance_correction_request($userId, $workDate, $field, $clockIn, $clockOut, $reason, $lateReason);
            flash_set('success', __('msg_correction_submitted'));
            redirect('/staff/corrections.php?date=' . urlencode($workDate));
        } catch (InvalidArgumentException $error) {
            flash_set('danger', $error->getMessage());
        } catch (Throwable $error) {
            error_log((string) $error);
            flash_set('danger', __('msg_process_fail'));
        }
    }
}

// Contextual pre-population from query string
$selectedDate = trim((string) get('date', ''));
if ($selectedDate !== '') {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $selectedDate);
    if (!$parsed || $parsed->format('Y-m-d') !== $selectedDate) {
        $selectedDate = '';
    }
}
if ($selectedDate === '') {
    $selectedDate = today_str();
}

$selectedType = trim((string) get('type', 'punch'));
if (!in_array($selectedType, ['punch', 'break'], true)) {
    $selectedType = 'punch';
}
$selectedBreakId = get('break_id') ? (int) get('break_id') : null;

$existingAtt = get_attendance($userId, $selectedDate);
$isLateSubmission = is_correction_submission_late($selectedDate);

$recordedBreaks = [];
if ($existingAtt) {
    $stmtBreaks = db()->prepare('SELECT * FROM break_records WHERE attendance_id = ? ORDER BY start_time ASC');
    $stmtBreaks->execute([$existingAtt['id']]);
    $recordedBreaks = $stmtBreaks->fetchAll();
}

// Auto-suggest break correction if shift has auto-closed break and type wasn't explicitly provided
$hasAutoClosed = false;
$autoClosedId = null;
foreach ($recordedBreaks as $rb) {
    if (!empty($rb['is_auto_closed'])) {
        $hasAutoClosed = true;
        if ($autoClosedId === null) {
            $autoClosedId = (int) $rb['id'];
        }
    }
}
if (get('type') === null && $hasAutoClosed) {
    $selectedType = 'break';
    if ($selectedBreakId === null) {
        $selectedBreakId = $autoClosedId;
    }
}

// Filter requests
$statusFilter = (string) get('status', 'all');
if (!in_array($statusFilter, ['all', 'pending', 'approved', 'rejected'], true)) {
    $statusFilter = 'all';
}
$rows = get_correction_requests_for_user($userId, $statusFilter, 50);

$pendingCount = 0;
$approvedCount = 0;
$rejectedCount = 0;
foreach (get_correction_requests_for_user($userId, 'all', 100) as $item) {
    if ($item['status'] === 'pending') {
        $pendingCount++;
    } elseif ($item['status'] === 'approved') {
        $approvedCount++;
    } elseif ($item['status'] === 'rejected') {
        $rejectedCount++;
    }
}

$fieldLabels = [
    'clock_in' => __('clock_in_only'),
    'clock_out' => __('clock_out_only'),
    'both' => __('both_clock'),
    'break' => __('correction_type_break'),
];

$breakTypeNames = [
    'lunch' => break_type_name('lunch'),
    'solat' => break_type_name('solat'),
    'short' => break_type_name('short'),
];

$breakCorrTypeNames = [
    'start' => __('corr_type_start'),
    'end' => __('corr_type_end'),
    'both' => __('corr_type_both'),
    'missing' => __('corr_type_missing'),
    'auto_closed' => __('corr_type_auto_closed'),
];

$pageTitle = __('my_correction_requests');
$activeMenu = 'attendance';
$isAdminArea = false;
$bodyClass = 'staff-history-page staff-corrections-page';
require __DIR__ . '/../includes/layout_header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
        <h2 class="h4 mb-0 d-none d-lg-block"><?= e(__('my_correction_requests')) ?></h2>
        <span class="text-muted small"><?= e(__('correction_hint')) ?></span>
    </div>
    <div>
        <a href="<?= e(url('/staff/attendance.php')) ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i><?= e(__('my_attendance')) ?>
        </a>
    </div>
</div>

<!-- Mobile Status Summary Chips (< 768px) -->
<div class="d-md-none mb-3">
    <div class="d-flex gap-2">
        <div class="flex-fill p-2 rounded text-center leave-mobile-stat-card leave-mobile-stat-card--pending">
            <span class="d-block text-muted" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em;"><?= e(__('st_pending')) ?></span>
            <span class="fw-bold font-mono fs-5 leave-mobile-stat-card__pending-count"><?= $pendingCount ?></span>
        </div>
        <div class="flex-fill p-2 rounded text-center leave-mobile-stat-card">
            <span class="d-block text-muted" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em;"><?= e(__('st_approved')) ?></span>
            <span class="fw-bold font-mono text-success fs-5"><?= $approvedCount ?></span>
        </div>
        <div class="flex-fill p-2 rounded text-center leave-mobile-stat-card">
            <span class="d-block text-muted" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em;"><?= e(__('st_rejected')) ?></span>
            <span class="fw-bold font-mono text-danger fs-5"><?= $rejectedCount ?></span>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Form Column -->
    <div class="col-12 col-lg-5">
        <div class="card-soft">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-pencil-square text-accent"></i>
                <span class="fw-semibold"><?= e(__('request_correction')) ?></span>
            </div>
            <div class="card-body">
                <form method="post" id="correctionForm">
                    <?= csrf_field() ?>

                    <!-- Shift Date -->
                    <div class="mb-3">
                        <label class="form-label" for="work_date"><?= e(__('date')) ?> <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="work_date" name="work_date"
                               value="<?= e($selectedDate) ?>" max="<?= e(today_str()) ?>" required
                               onchange="window.location.href = '<?= e(url('/staff/corrections.php?date=')) ?>' + this.value + '&type=' + getSelectedMode();">
                        <div class="form-hint"><?= e(__('manual_correction_work_date_hint')) ?></div>
                    </div>

                    <!-- Mode Toggle (Punch vs Break) -->
                    <div class="mb-3">
                        <label class="form-label d-block"><?= e(__('correction_target_mode')) ?> <span class="text-danger">*</span></label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="target_mode" id="mode_punch" value="punch" <?= $selectedType !== 'break' ? 'checked' : '' ?> autocomplete="off" onchange="toggleCorrectionMode()">
                            <label class="btn btn-outline-secondary btn-sm" for="mode_punch">
                                <i class="bi bi-clock me-1"></i><?= e(__('correction_type_attendance')) ?>
                            </label>

                            <input type="radio" class="btn-check" name="target_mode" id="mode_break" value="break" <?= $selectedType === 'break' ? 'checked' : '' ?> autocomplete="off" onchange="toggleCorrectionMode()">
                            <label class="btn btn-outline-secondary btn-sm" for="mode_break">
                                <i class="bi bi-cup-hot me-1"></i><?= e(__('correction_type_break')) ?>
                            </label>
                        </div>
                    </div>

                    <!-- Existing Shift Info Card -->
                    <?php if ($existingAtt): ?>
                        <div class="p-2 mb-3 rounded bg-secondary-subtle border small font-monospace">
                            <div class="text-muted mb-1 fw-bold text-uppercase" style="font-size: 0.68rem; letter-spacing: 0.05em;">Currently Recorded</div>
                            <div>
                                <span class="text-muted">In:</span>
                                <strong><?= e(format_time($existingAtt['clock_in']) ?: '-') ?></strong>
                                <span class="text-muted ms-2">Out:</span>
                                <strong><?= e(format_time($existingAtt['clock_out']) ?: '-') ?></strong>
                                <span class="ms-2"><?= attendance_status_badge($existingAtt) ?></span>
                            </div>
                            <?php if (!empty($recordedBreaks)): ?>
                                <div class="mt-1 pt-1 border-top border-secondary-subtle" style="font-size:0.75rem;">
                                    <span class="text-muted"><?= count($recordedBreaks) ?> recorded break(s):</span>
                                    <?php foreach ($recordedBreaks as $rb): ?>
                                        <span class="badge bg-dark-subtle text-light-emphasis me-1">
                                            <?= e(break_type_name($rb['break_type'])) ?>:
                                            <?= e(format_time($rb['start_time'])) ?> - <?= e(format_time($rb['end_time']) ?: (!empty($rb['is_auto_closed']) ? 'Auto-Closed' : 'Active')) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Late Submission Alert -->
                    <div id="lateAlert" class="alert alert-warning py-2 small mb-3 <?= $isLateSubmission ? '' : 'd-none' ?>">
                        <div class="d-flex align-items-start gap-2">
                            <i class="bi bi-exclamation-triangle-fill mt-1 flex-shrink-0"></i>
                            <div>
                                <strong>Standard 3-day window has passed.</strong><br>
                                You may still submit this request for administrator exception review. Please provide an explanation below.
                            </div>
                        </div>
                    </div>

                    <!-- ================= PUNCH CORRECTION SECTION ================= -->
                    <div id="punchSection" class="<?= $selectedType === 'break' ? 'd-none' : '' ?>">
                        <!-- Target Fields -->
                        <div class="mb-3">
                            <label class="form-label d-block"><?= e(__('requested_change')) ?> <span class="text-danger">*</span></label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="field_name" id="field_both" value="both" checked autocomplete="off" onchange="toggleFieldInputs()">
                                <label class="btn btn-outline-secondary btn-sm" for="field_both"><?= e(__('both_clock')) ?></label>

                                <input type="radio" class="btn-check" name="field_name" id="field_in" value="clock_in" autocomplete="off" onchange="toggleFieldInputs()">
                                <label class="btn btn-outline-secondary btn-sm" for="field_in"><?= e(__('clock_in_only')) ?></label>

                                <input type="radio" class="btn-check" name="field_name" id="field_out" value="clock_out" autocomplete="off" onchange="toggleFieldInputs()">
                                <label class="btn btn-outline-secondary btn-sm" for="field_out"><?= e(__('clock_out_only')) ?></label>
                            </div>
                        </div>

                        <!-- Clock In Time Input -->
                        <?php
                        $initIn = $existingAtt['clock_in'] ? substr($existingAtt['clock_in'], 11, 5) : '10:00';
                        $initOut = $existingAtt['clock_out'] ? substr($existingAtt['clock_out'], 11, 5) : '20:00';
                        $initOutDate = $existingAtt['clock_out'] ? substr($existingAtt['clock_out'], 0, 10) : $selectedDate;
                        ?>
                        <div class="mb-3" id="clockInGroup">
                            <label class="form-label" for="clock_in"><?= e(__('clock_in')) ?> <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" id="clock_in" name="clock_in"
                                   value="<?= e($initIn) ?>" step="60">
                        </div>

                        <!-- Clock Out Time Input -->
                        <div class="mb-3" id="clockOutGroup">
                            <label class="form-label" for="clock_out"><?= e(__('clock_out')) ?> <span class="text-danger">*</span></label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label" for="clock_out_date"><?= e(__('date')) ?></label>
                                    <select class="form-select" id="clock_out_date" name="clock_out_date">
                                        <option value="<?= e($selectedDate) ?>" <?= $initOutDate === $selectedDate ? 'selected' : '' ?>>Same Day (<?= e(substr($selectedDate, 5)) ?>)</option>
                                        <?php $nextD = (new DateTimeImmutable($selectedDate))->modify('+1 day')->format('Y-m-d'); ?>
                                        <option value="<?= e($nextD) ?>" <?= $initOutDate === $nextD ? 'selected' : '' ?>>Next Day (<?= e(substr($nextD, 5)) ?>)</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <input type="time" class="form-control" id="clock_out" name="clock_out"
                                           value="<?= e($initOut) ?>" step="60">
                                </div>
                            </div>
                            <div class="form-hint">For overnight shifts, select 'Next Day'.</div>
                        </div>
                    </div>

                    <!-- ================= BREAK CORRECTION SECTION ================= -->
                    <div id="breakSection" class="<?= $selectedType !== 'break' ? 'd-none' : '' ?>">
                        <!-- Break Selection -->
                        <div class="mb-3">
                            <label class="form-label" for="break_id"><?= e(__('select_break')) ?> <span class="text-danger">*</span></label>
                            <select class="form-select" id="break_id" name="break_id" onchange="onBreakSelectionChange()">
                                <?php if (!empty($recordedBreaks)): ?>
                                    <optgroup label="Recorded Breaks on <?= e(format_date($selectedDate)) ?>">
                                        <?php foreach ($recordedBreaks as $b): ?>
                                            <?php
                                            $bTitle = break_type_name($b['break_type']) . ' #' . $b['sequence_no'];
                                            $bStartFmt = format_time($b['start_time']);
                                            $bEndFmt = $b['end_time'] ? format_time($b['end_time']) : (!empty($b['is_auto_closed']) ? 'Auto-Closed' : 'Active');
                                            $isAuto = !empty($b['is_auto_closed']) ? ' [Auto-Closed]' : '';
                                            $isSelected = ($selectedBreakId === (int)$b['id']);
                                            ?>
                                            <option value="<?= (int)$b['id'] ?>"
                                                    data-type="<?= e($b['break_type']) ?>"
                                                    data-start="<?= e(substr($b['start_time'], 11, 5)) ?>"
                                                    data-end="<?= e($b['end_time'] ? substr($b['end_time'], 11, 5) : '') ?>"
                                                    data-auto-closed="<?= !empty($b['is_auto_closed']) ? '1' : '0' ?>"
                                                    <?= $isSelected ? 'selected' : '' ?>>
                                                <?= e($bTitle) ?>: <?= e($bStartFmt) ?> - <?= e($bEndFmt) ?><?= e($isAuto) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                                <option value="missing" <?= empty($recordedBreaks) || $selectedBreakId === 'missing' ? 'selected' : '' ?>>
                                    <?= e(__('missing_break_option')) ?>
                                </option>
                            </select>
                        </div>

                        <!-- Auto-Closed Break Notice -->
                        <div id="breakAutoClosedBanner" class="alert alert-warning py-2 small mb-3 d-none">
                            <div class="d-flex align-items-start gap-2">
                                <i class="bi bi-clock-history mt-1 flex-shrink-0"></i>
                                <div><?= e(__('break_auto_closed_banner')) ?></div>
                            </div>
                        </div>

                        <!-- Missing Break Notice -->
                        <div id="breakMissingBanner" class="alert alert-info py-2 small mb-3 d-none">
                            <div class="d-flex align-items-start gap-2">
                                <i class="bi bi-info-circle-fill mt-1 flex-shrink-0"></i>
                                <div><?= e(__('missing_break_banner')) ?></div>
                            </div>
                        </div>

                        <!-- Break Category (for missing break or display) -->
                        <div class="mb-3" id="breakCategoryGroup">
                            <label class="form-label" for="break_category"><?= e(__('break_category')) ?> <span class="text-danger">*</span></label>
                            <select class="form-select" id="break_category" name="break_category">
                                <option value="lunch"><?= e(__('break_type_lunch')) ?> (Max 60m &middot; 1 session)</option>
                                <option value="solat"><?= e(__('break_type_solat')) ?> (Max 20m &middot; 3 sessions)</option>
                                <option value="short"><?= e(__('break_type_short')) ?> (Max 10m &middot; 3 sessions)</option>
                            </select>
                        </div>

                        <!-- Subtype Selection for Existing Break -->
                        <div class="mb-3" id="breakCorrectionTypeGroup">
                            <label class="form-label d-block"><?= e(__('requested_change')) ?> <span class="text-danger">*</span></label>
                            <div class="btn-group w-100" role="group" id="breakSubtypeGroup">
                                <input type="radio" class="btn-check" name="break_correction_type" id="bcorr_both" value="both" checked autocomplete="off" onchange="toggleBreakTimeInputs()">
                                <label class="btn btn-outline-secondary btn-sm" for="bcorr_both"><?= e(__('corr_type_both')) ?></label>

                                <input type="radio" class="btn-check" name="break_correction_type" id="bcorr_end" value="end" autocomplete="off" onchange="toggleBreakTimeInputs()">
                                <label class="btn btn-outline-secondary btn-sm" for="bcorr_end"><?= e(__('corr_type_end')) ?></label>

                                <input type="radio" class="btn-check" name="break_correction_type" id="bcorr_start" value="start" autocomplete="off" onchange="toggleBreakTimeInputs()">
                                <label class="btn btn-outline-secondary btn-sm" for="bcorr_start"><?= e(__('corr_type_start')) ?></label>
                            </div>
                        </div>

                        <!-- Break Start Input -->
                        <div class="mb-3" id="breakStartGroup">
                            <label class="form-label" for="break_start"><?= e(__('break_start_time')) ?> <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" id="break_start" name="break_start" value="13:00" step="60">
                        </div>

                        <!-- Break Return Input -->
                        <div class="mb-3" id="breakEndGroup">
                            <label class="form-label" for="break_end"><?= e(__('break_end_time')) ?> <span class="text-danger">*</span></label>
                            <div class="row g-2">
                                <div class="col-5">
                                    <select class="form-select" id="break_end_date" name="break_end_date">
                                        <option value="<?= e($selectedDate) ?>">Same Day</option>
                                        <?php $nextD = (new DateTimeImmutable($selectedDate))->modify('+1 day')->format('Y-m-d'); ?>
                                        <option value="<?= e($nextD) ?>">Next Day</option>
                                    </select>
                                </div>
                                <div class="col-7">
                                    <input type="time" class="form-control" id="break_end" name="break_end" value="14:00" step="60">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Late Submission Reason -->
                    <div class="mb-3 <?= $isLateSubmission ? '' : 'd-none' ?>" id="lateReasonGroup">
                        <label class="form-label text-warning-emphasis" for="late_reason">
                            <?= e(__('late_submission_reason') ?: 'Explanation for Late Submission') ?> <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control border-warning" id="late_reason" name="late_reason" rows="2"
                                  placeholder="Explain why this request is submitted after the 3-day window..." <?= $isLateSubmission ? 'required' : '' ?>></textarea>
                    </div>

                    <!-- General Reason -->
                    <div class="mb-3">
                        <label class="form-label" for="reason"><?= e(__('reason_for_correction')) ?> <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="reason" name="reason" rows="2"
                                  placeholder="<?= e(__('reason_for_correction')) ?>" required></textarea>
                    </div>

                    <button type="submit" class="btn btn-accent w-100 py-2 fw-semibold">
                        <i class="bi bi-send me-1"></i><?= e(__('save_correction')) ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Requests History Column -->
    <div class="col-12 col-lg-7">
        <div class="card-soft mb-3">
            <div class="card-body py-2 px-3">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <span class="small fw-bold text-muted text-uppercase" style="letter-spacing: 0.05em;"><?= e(__('filter_request_status')) ?></span>
                    <div class="btn-group btn-group-sm" role="group">
                        <a href="<?= e(url('/staff/corrections.php?status=all')) ?>" class="btn btn-outline-secondary <?= $statusFilter === 'all' ? 'active' : '' ?>"><?= e(__('all')) ?></a>
                        <a href="<?= e(url('/staff/corrections.php?status=pending')) ?>" class="btn btn-outline-secondary <?= $statusFilter === 'pending' ? 'active' : '' ?>"><?= e(__('st_pending')) ?></a>
                        <a href="<?= e(url('/staff/corrections.php?status=approved')) ?>" class="btn btn-outline-secondary <?= $statusFilter === 'approved' ? 'active' : '' ?>"><?= e(__('st_approved')) ?></a>
                        <a href="<?= e(url('/staff/corrections.php?status=rejected')) ?>" class="btn btn-outline-secondary <?= $statusFilter === 'rejected' ? 'active' : '' ?>"><?= e(__('st_rejected')) ?></a>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-soft">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span class="fw-semibold"><?= e(__('my_correction_requests')) ?></span>
                <span class="badge bg-secondary-subtle text-secondary-emphasis"><?= count($rows) ?> <?= e(__('records')) ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($rows)): ?>
                    <div class="empty-state py-4"><i class="bi bi-inbox fs-3 d-block mb-2"></i><?= e(__('no_corrections')) ?></div>
                <?php else: ?>
                    <!-- Desktop Table (>= 768px) -->
                    <div class="table-responsive-card d-none d-md-block">
                        <table class="table mb-0 align-middle">
                            <thead>
                            <tr>
                                <th><?= e(__('date')) ?></th>
                                <th><?= e(__('requested_change')) ?></th>
                                <th><?= e(__('reason')) ?></th>
                                <th><?= e(__('status')) ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= e(format_date($r['work_date'])) ?></div>
                                        <div class="text-muted small"><?= e(date('j M Y, H:i', strtotime($r['created_at']))) ?></div>
                                        <?php if (!empty($r['is_late_submission'])): ?>
                                            <span class="badge bg-warning text-dark mt-1" style="font-size:0.7rem;"><i class="bi bi-clock-history me-1"></i>Late</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($r['field_name'] === 'break'): ?>
                                            <div class="badge bg-warning text-dark mb-1">
                                                <i class="bi bi-cup-hot me-1"></i><?= e($breakTypeNames[$r['break_type']] ?? ucfirst((string)$r['break_type'])) ?>
                                                (<?= e($breakCorrTypeNames[$r['break_correction_type']] ?? ucfirst((string)$r['break_correction_type'])) ?>)
                                            </div>
                                            <div class="small font-monospace text-muted">
                                                <?php if (!empty($r['break_id'])): ?>
                                                    Original: <?= e(format_time($r['current_break_start']) ?: '-') ?> &ndash; <?= e(format_time($r['current_break_end']) ?: (!empty($r['current_break_is_auto_closed']) ? 'Auto-Closed' : '-')) ?>
                                                    <?php if (!empty($r['current_break_is_auto_closed'])): ?>
                                                        <span class="badge bg-secondary" style="font-size:0.65rem;">Auto-Closed</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    Unrecorded Session
                                                <?php endif; ?>
                                            </div>
                                            <div class="small font-monospace text-accent fw-semibold">
                                                Requested: <?= e(format_time($r['requested_break_start'])) ?> &ndash; <?= e(format_time($r['requested_break_end'])) ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary-emphasis mb-1"><?= e($fieldLabels[$r['field_name']] ?? $r['field_name']) ?></span>
                                            <div class="small font-monospace text-muted">
                                                Original: In <?= e(format_time($r['current_clock_in']) ?: '-') ?> | Out <?= e(format_time($r['current_clock_out']) ?: '-') ?>
                                            </div>
                                            <div class="small font-monospace text-accent fw-semibold">
                                                Requested:
                                                <?php if ($r['requested_clock_in']): ?>In <?= e(format_time($r['requested_clock_in'])) ?><?php endif; ?>
                                                <?php if ($r['requested_clock_in'] && $r['requested_clock_out']): ?> | <?php endif; ?>
                                                <?php if ($r['requested_clock_out']): ?>Out <?= e(format_time($r['requested_clock_out'])) ?><?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?= e($r['reason']) ?></div>
                                        <?php if (!empty($r['late_reason'])): ?>
                                            <div class="small text-warning-emphasis mt-1 bg-warning-subtle p-1 rounded" style="font-size:0.78rem;">
                                                <strong>Late reason:</strong> <?= e($r['late_reason']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($r['admin_note'])): ?>
                                            <div class="small text-muted mt-1">
                                                <i class="bi bi-chat-left-text me-1"></i><strong>Admin:</strong> <?= e($r['admin_note']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= status_badge($r['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile Cards (< 768px) -->
                    <div class="d-md-none p-3">
                        <?php foreach ($rows as $r): ?>
                            <div class="attendance-mobile-record-card p-3 mb-2 rounded border">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <div class="fw-bold font-monospace text-white-emphasis">
                                        <?= e(format_date($r['work_date'])) ?>
                                        <?php if (!empty($r['is_late_submission'])): ?>
                                            <span class="badge bg-warning text-dark ms-1" style="font-size:0.65rem;">Late</span>
                                        <?php endif; ?>
                                    </div>
                                    <div><?= status_badge($r['status']) ?></div>
                                </div>
                                <div class="mb-2">
                                    <?php if ($r['field_name'] === 'break'): ?>
                                        <span class="badge bg-warning text-dark" style="font-size:0.75rem;">
                                            <i class="bi bi-cup-hot me-1"></i><?= e($breakTypeNames[$r['break_type']] ?? ucfirst((string)$r['break_type'])) ?>
                                            (<?= e($breakCorrTypeNames[$r['break_correction_type']] ?? ucfirst((string)$r['break_correction_type'])) ?>)
                                        </span>
                                        <div class="small font-monospace text-muted mt-1">
                                            <?php if (!empty($r['break_id'])): ?>
                                                Original: <?= e(format_time($r['current_break_start']) ?: '-') ?> &ndash; <?= e(format_time($r['current_break_end']) ?: (!empty($r['current_break_is_auto_closed']) ? 'Auto-Closed' : '-')) ?>
                                            <?php else: ?>
                                                Unrecorded Session
                                            <?php endif; ?>
                                        </div>
                                        <div class="small font-monospace text-accent fw-semibold">
                                            Requested: <?= e(format_time($r['requested_break_start'])) ?> &ndash; <?= e(format_time($r['requested_break_end'])) ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis" style="font-size:0.75rem;"><?= e($fieldLabels[$r['field_name']] ?? $r['field_name']) ?></span>
                                        <div class="small font-monospace text-muted mt-1">
                                            Original: In <?= e(format_time($r['current_clock_in']) ?: '-') ?> | Out <?= e(format_time($r['current_clock_out']) ?: '-') ?>
                                        </div>
                                        <div class="small font-monospace text-accent fw-semibold">
                                            Requested:
                                            <?php if ($r['requested_clock_in']): ?>In <?= e(format_time($r['requested_clock_in'])) ?><?php endif; ?>
                                            <?php if ($r['requested_clock_in'] && $r['requested_clock_out']): ?> | <?php endif; ?>
                                            <?php if ($r['requested_clock_out']): ?>Out <?= e(format_time($r['requested_clock_out'])) ?><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="small text-secondary mb-1">
                                    <strong><?= e(__('reason')) ?>:</strong> <?= e($r['reason']) ?>
                                </div>
                                <?php if (!empty($r['late_reason'])): ?>
                                    <div class="small text-warning-emphasis bg-warning-subtle p-2 rounded mb-1" style="font-size:0.78rem;">
                                        <strong>Late reason:</strong> <?= e($r['late_reason']) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($r['admin_note'])): ?>
                                    <div class="small text-info-emphasis bg-info-subtle p-2 rounded mt-2" style="font-size:0.78rem;">
                                        <strong>Admin Note:</strong> <?= e($r['admin_note']) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="text-muted small mt-2 pt-1 border-top border-secondary-subtle font-monospace" style="font-size:0.72rem;">
                                    Submitted: <?= e(date('j M Y, H:i', strtotime($r['created_at']))) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function getSelectedMode() {
    var checked = document.querySelector('input[name="target_mode"]:checked');
    return checked ? checked.value : 'punch';
}

function toggleCorrectionMode() {
    var mode = getSelectedMode();
    var punchSec = document.getElementById('punchSection');
    var breakSec = document.getElementById('breakSection');

    if (mode === 'break') {
        punchSec.classList.add('d-none');
        breakSec.classList.remove('d-none');
        // Disable punch inputs so they don't block validation
        document.getElementById('clock_in').required = false;
        document.getElementById('clock_out').required = false;
        onBreakSelectionChange();
    } else {
        punchSec.classList.remove('d-none');
        breakSec.classList.add('d-none');
        document.getElementById('break_start').required = false;
        document.getElementById('break_end').required = false;
        toggleFieldInputs();
    }
}

function toggleFieldInputs() {
    if (getSelectedMode() !== 'punch') return;
    var field = document.querySelector('input[name="field_name"]:checked').value;
    var inGroup = document.getElementById('clockInGroup');
    var outGroup = document.getElementById('clockOutGroup');
    var inInput = document.getElementById('clock_in');
    var outInput = document.getElementById('clock_out');

    if (field === 'clock_in') {
        inGroup.style.display = 'block';
        inInput.required = true;
        outGroup.style.display = 'none';
        outInput.required = false;
    } else if (field === 'clock_out') {
        inGroup.style.display = 'none';
        inInput.required = false;
        outGroup.style.display = 'block';
        outInput.required = true;
    } else {
        inGroup.style.display = 'block';
        inInput.required = true;
        outGroup.style.display = 'block';
        outInput.required = true;
    }
}

function onBreakSelectionChange() {
    if (getSelectedMode() !== 'break') return;
    var sel = document.getElementById('break_id');
    var selectedOpt = sel.options[sel.selectedIndex];
    var isMissing = !selectedOpt || selectedOpt.value === 'missing';

    var autoBanner = document.getElementById('breakAutoClosedBanner');
    var missingBanner = document.getElementById('breakMissingBanner');
    var catGroup = document.getElementById('breakCategoryGroup');
    var subtypeGroup = document.getElementById('breakCorrectionTypeGroup');
    var catSelect = document.getElementById('break_category');

    var bcorrBoth = document.getElementById('bcorr_both');
    var bcorrEnd = document.getElementById('bcorr_end');
    var bcorrStart = document.getElementById('bcorr_start');

    if (isMissing) {
        autoBanner.classList.add('d-none');
        missingBanner.classList.remove('d-none');
        catGroup.classList.remove('d-none');
        catSelect.disabled = false;
        subtypeGroup.classList.add('d-none');

        document.getElementById('breakStartGroup').style.display = 'block';
        document.getElementById('breakEndGroup').style.display = 'block';
        document.getElementById('break_start').required = true;
        document.getElementById('break_end').required = true;
    } else {
        missingBanner.classList.add('d-none');
        catGroup.classList.add('d-none');
        catSelect.disabled = true;
        catSelect.value = selectedOpt.dataset.type || 'lunch';

        var isAuto = selectedOpt.dataset.autoClosed === '1';
        var endLabel = document.querySelector('label[for="bcorr_end"]');
        if (isAuto) {
            autoBanner.classList.remove('d-none');
            bcorrEnd.value = 'auto_closed';
            if (endLabel) endLabel.innerText = 'Actual Return Time';
            bcorrEnd.checked = true;
        } else {
            autoBanner.classList.add('d-none');
            bcorrEnd.value = 'end';
            if (endLabel) endLabel.innerText = 'Return Time Only';
        }

        subtypeGroup.classList.remove('d-none');

        // Prefill times
        if (selectedOpt.dataset.start) {
            document.getElementById('break_start').value = selectedOpt.dataset.start;
        }
        if (selectedOpt.dataset.end) {
            document.getElementById('break_end').value = selectedOpt.dataset.end;
        }

        toggleBreakTimeInputs();
    }
}

function toggleBreakTimeInputs() {
    if (getSelectedMode() !== 'break') return;
    var sel = document.getElementById('break_id');
    if (sel.value === 'missing') return;

    var subtype = document.querySelector('input[name="break_correction_type"]:checked')?.value || 'both';
    var startGroup = document.getElementById('breakStartGroup');
    var endGroup = document.getElementById('breakEndGroup');
    var startInput = document.getElementById('break_start');
    var endInput = document.getElementById('break_end');

    if (subtype === 'start') {
        startGroup.style.display = 'block';
        startInput.required = true;
        endGroup.style.display = 'none';
        endInput.required = false;
    } else if (subtype === 'end' || subtype === 'auto_closed') {
        startGroup.style.display = 'none';
        startInput.required = false;
        endGroup.style.display = 'block';
        endInput.required = true;
    } else {
        startGroup.style.display = 'block';
        startInput.required = true;
        endGroup.style.display = 'block';
        endInput.required = true;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    toggleCorrectionMode();
});
</script>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
