<?php
/**
 * Admin — Attendance Detail + Manual Correction + Audit
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

$id = (int) get('id', 0);
$returnTo = (string) get('return_to', '');
$returnSource = (string) get('return_source', '');
$returnPath = (string) (parse_url($returnTo, PHP_URL_PATH) ?: '');
if (!(str_ends_with($returnPath, '/admin/index.php') || str_ends_with($returnPath, '/admin/attendance.php'))) {
    $returnTo = $returnSource === 'dashboard' ? url('/admin/index.php') : url('/admin/attendance.php');
}
$stmt = db()->prepare(
    "SELECT a.*, u.full_name, u.employee_id, u.id AS uid
     FROM attendance a JOIN users u ON u.id = a.user_id WHERE a.id = ?"
);
$stmt->execute([$id]);
$att = $stmt->fetch();
if (!$att) {
    flash_set('danger', __('msg_attendance_not_found'));
    redirect('/admin/attendance.php');
}

function correction_time_value(?string $datetime): string
{
    return $datetime ? substr($datetime, 11, 5) : '';
}

function correction_datetime_for_time(string $workDate, string $time, ?string $existing): ?string
{
    if ($time === '') return null;
    if ($existing && substr($existing, 0, 10) === $workDate && correction_time_value($existing) === $time) {
        return $existing;
    }
    return $workDate . ' ' . $time;
}

if (is_post()) {
    require_csrf();
    $clockInTime  = trim((string) post('clock_in'));
    $clockOutTime = trim((string) post('clock_out'));
    $reason       = trim((string) post('reason'));
    $version      = trim((string) post('record_version'));

    if ($reason === '') {
        flash_set('danger', __('msg_correction_reason'));
    } else {
        // Conflict guard: if another admin saved this record after the form was opened, block the save.
        $vStmt = db()->prepare('SELECT updated_at FROM attendance WHERE id = ?');
        $vStmt->execute([$id]);
        $dbVersion = (string) $vStmt->fetchColumn();
        if ($dbVersion !== $version) {
            flash_set('warning', 'This attendance record was changed by another administrator. Reload the page and review the latest values before saving.');
        } else {
            try {
                $clockIn  = correction_datetime_for_time($att['work_date'], $clockInTime,  $att['clock_in']);
                $clockOut = correction_datetime_for_time($att['work_date'], $clockOutTime, $att['clock_out']);
                correct_attendance($id, $clockIn, $clockOut, (int) current_user()['id'], $reason);
                flash_set('success', __('msg_attendance_corrected'));
                redirect('/admin/attendance_detail.php?' . http_build_query(['id' => $id, 'return_to' => $returnTo, 'return_source' => $returnSource]));
            } catch (InvalidArgumentException $error) {
                flash_set('danger', $error->getMessage());
            } catch (Throwable $error) {
                error_log((string) $error);
                flash_set('danger', __('msg_process_fail'));
            }
        }
    }
}

$breaks = db()->prepare('SELECT * FROM break_records WHERE attendance_id = ? ORDER BY start_time');
$breaks->execute([$id]);
$breakRows = $breaks->fetchAll();

$audit = db()->prepare(
    "SELECT l.*, u.full_name AS changer
     FROM attendance_audit_logs l
     JOIN users u ON u.id = l.changed_by
     WHERE l.attendance_id = ?
     ORDER BY l.created_at DESC"
);
$audit->execute([$id]);
$auditRows = $audit->fetchAll();

$locations = db()->prepare('SELECT * FROM attendance_location_events WHERE attendance_id=? ORDER BY event_time, id');
$locations->execute([$id]);
$locationRows = $locations->fetchAll();
$locationStatusLabels = [
    'captured' => 'Captured',
    'low_accuracy' => 'Low accuracy',
    'permission_denied' => 'Permission denied',
    'timed_out' => 'Timed out',
    'unavailable' => 'Unavailable',
    'unsupported' => 'Unsupported browser',
    'sharing_disabled' => 'Location sharing off',
];
$locationStatusBadges = [
    'captured' => 'badge-present',
    'low_accuracy' => 'badge-late',
    'permission_denied' => 'badge-pending',
];

$pageTitle = __('attendance_detail');
$bodyClass = 'admin-attendance-detail';
$activeMenu = 'attendance';
$isAdminArea = true;
require __DIR__ . '/../includes/layout_header.php';

function audit_display_label(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    $translated = __($value);
    return $translated !== $value ? $translated : ucwords(str_replace('_', ' ', $value));
}

function audit_display_value(?string $value): string
{
    $value = (string)$value;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return format_date($value);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?$/', $value)) {
        return format_datetime($value);
    }
    return $value;
}

$formClockIn = is_post() ? $clockInTime : correction_time_value($att['clock_in']);
$formClockOut = is_post() ? $clockOutTime : correction_time_value($att['clock_out']);
$formReason = is_post() ? $reason : '';
?>

<div class="mb-3">
    <a href="<?= e($returnTo) ?>" class="btn btn-sm btn-outline-secondary">&larr; <?= e(__('back')) ?></a>
</div>

<div class="card-soft mb-3">
    <div class="card-header">Attendance location</div>
    <div class="card-body">
        <?php if (!$locationRows): ?>
            <p class="form-hint mb-0">Legacy record / location unavailable.</p>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($locationRows as $event): ?>
                    <?php
                    $hasCoordinates = $event['latitude'] !== null && $event['longitude'] !== null;
                    $status = (string) $event['location_status'];
                    $statusLabel = $locationStatusLabels[$status] ?? 'Unavailable';
                    $statusBadge = $locationStatusBadges[$status] ?? 'badge-off';
                    $clientIp = (string) ($event['client_ip'] ?? '');
                    $networkLabel = in_array($clientIp, ['127.0.0.1', '::1'], true)
                        ? 'Localhost'
                        : ($event['office_network'] === 'office' ? 'Office network'
                            : ($event['office_network'] === 'other' ? 'Other network' : 'Unknown'));
                    ?>
                    <div class="col-md-6">
                        <article class="attendance-location-event h-100">
                            <header class="attendance-location-event__header">
                                <div class="attendance-location-event__title"><?= e(strtoupper(str_replace('_', ' ', (string) $event['event_type']))) ?></div>
                                <div class="attendance-location-event__time"><?= e(format_datetime($event['event_time'])) ?></div>
                            </header>
                            <div class="attendance-location-event__status">
                                <span class="status-badge <?= e($statusBadge) ?>"><?= e($statusLabel) ?></span>
                                <?php if ($event['accuracy_metres'] !== null): ?>
                                    <span class="attendance-location-event__accuracy">Accuracy ±<?= e((string) round((float) $event['accuracy_metres'])) ?> m</span>
                                <?php endif; ?>
                            </div>
                            <div class="attendance-location-event__fact">
                                <span>Office area</span>
                                <?php if ($event['office_proximity'] === 'within'): ?>
                                    <strong class="attendance-location-event__positive">Within office area</strong>
                                <?php elseif ($event['office_proximity'] === 'outside'): ?>
                                    <strong class="attendance-location-event__warning">Outside office area</strong>
                                <?php else: ?>
                                    <strong>Office comparison unavailable</strong>
                                    <small><?= $hasCoordinates ? 'Office location has not been configured.' : 'No coordinates were captured for this event.' ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="attendance-location-event__fact">
                                <span>Network</span><strong><?= e($networkLabel) ?></strong>
                            </div>
                            <?php if ($hasCoordinates): ?>
                                <a class="btn btn-sm btn-outline-secondary attendance-location-event__map" target="_blank" rel="noopener noreferrer" href="<?= e('https://www.google.com/maps/search/?api=1&query=' . rawurlencode($event['latitude'] . ',' . $event['longitude'])) ?>">View Map <i class="bi bi-box-arrow-up-right ms-1" aria-hidden="true"></i></a>
                            <?php endif; ?>
                            <details class="policy-disclosure attendance-location-event__technical">
                                <summary><span class="attendance-location-event__show">Show technical details</span><span class="attendance-location-event__hide">Hide technical details</span></summary>
                                <div class="policy-disclosure-body">
                                    <dl class="attendance-location-event__technical-grid mb-0">
                                        <div><dt>Latitude</dt><dd><?= e($hasCoordinates ? (string) $event['latitude'] : 'Not recorded') ?></dd></div>
                                        <div><dt>Longitude</dt><dd><?= e($hasCoordinates ? (string) $event['longitude'] : 'Not recorded') ?></dd></div>
                                        <div><dt>Recorded IP</dt><dd><?= e($clientIp !== '' ? $clientIp : 'Unknown') ?></dd></div>
                                        <div><dt>Capture method</dt><dd><?= e($event['capture_method'] !== null ? ucwords(str_replace('_', ' ', (string) $event['capture_method'])) : 'Not recorded') ?></dd></div>
                                    </dl>
                                </div>
                            </details>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 align-items-stretch">
    <div class="col-lg-6">
        <div class="card-soft h-100">
            <div class="card-header"><?= e($att['full_name']) ?> · <span class="fw-normal"><?= e(format_date($att['work_date'])) ?></span></div>
            <div class="card-body">
                <div class="meta-grid mb-3">
                    <div class="meta-item"><div class="label"><?= e(__('status')) ?></div><div class="value"><?= attendance_status_badge($att) ?></div></div>
                    <div class="meta-item"><div class="label"><?= e(__('late')) ?></div><div class="value"><?= e(format_minutes((int)$att['late_minutes'])) ?></div></div>
                    <div class="meta-item"><div class="label"><?= e(__('early_leave')) ?></div><div class="value"><?= e(format_minutes((int)$att['early_leave_minutes'])) ?></div></div>
                    <div class="meta-item"><div class="label"><?= e(__('shift_duration')) ?></div><div class="value"><?= e(format_minutes((int)$att['shift_minutes'])) ?></div></div>
                    <div class="meta-item"><div class="label"><?= e(__('actual_working_time')) ?></div><div class="value"><?= e(format_minutes((int)$att['work_minutes'])) ?></div></div>
                </div>

                <h3 class="h6"><?= e(__('breaks')) ?></h3>
                <?php if (!$breakRows): ?>
                    <p class="text-muted"><?= e(__('no_breaks')) ?></p>
                <?php else: ?>
                    <div class="table-responsive-card mb-3">
                        <table class="table table-sm">
                            <thead><tr><th><?= e(__('type')) ?></th><th><?= e(__('start')) ?></th><th><?= e(__('end')) ?></th><th><?= e(__('duration')) ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($breakRows as $b): ?>
                                <tr>
                                    <td><?= e(break_label($b['break_type'], (int)$b['sequence_no'])) ?></td>
                                    <td><?= e(format_time($b['start_time'])) ?></td>
                                    <td><?= $b['end_time'] ? e(format_time($b['end_time'])) : e(__('open')) ?></td>
                                    <td><?= break_duration_html($b['start_time'], $b['end_time'], (int)$b['duration_minutes']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="attendance-detail-breaks-mobile">
                        <?php foreach ($breakRows as $b): ?>
                            <div class="attendance-detail-break-mobile"><strong><?= e(break_label($b['break_type'], (int)$b['sequence_no'])) ?></strong><span><?= e(format_time($b['start_time'])) ?>–<?= $b['end_time'] ? e(format_time($b['end_time'])) : e(__('open')) ?></span><span><?= break_duration_html($b['start_time'], $b['end_time'], (int)$b['duration_minutes']) ?></span></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card-soft h-100">
            <div class="card-header"><?= e(__('manual_correction')) ?></div>
            <div class="card-body">
                <p class="form-hint"><?= e(__('manual_correction_hint')) ?></p>
                <div class="mb-3">
                    <div class="form-label"><?= e(__('date')) ?></div>
                    <div class="fw-semibold"><?= e(format_date($att['work_date'])) ?></div>
                    <div class="form-hint"><?= e(__('manual_correction_work_date_hint')) ?></div>
                </div>
                <form id="manualCorrectionForm" method="post" data-correction-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="record_version" value="<?= e((string) $att['updated_at']) ?>">
                    <div class="mb-3">
                        <label class="form-label" for="correction-clock-in"><?= e(__('clock_in_col')) ?></label>
                        <input id="correction-clock-in" type="time" name="clock_in" class="form-control" data-time-picker="1" data-original-value="<?= e(correction_time_value($att['clock_in'])) ?>" data-review-label="<?= e(__('clock_in_col')) ?>" step="60" value="<?= e($formClockIn) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="correction-clock-out"><?= e(__('clock_out_col')) ?></label>
                        <input id="correction-clock-out" type="time" name="clock_out" class="form-control" data-time-picker="1" data-original-value="<?= e(correction_time_value($att['clock_out'])) ?>" data-review-label="<?= e(__('clock_out_col')) ?>" step="60" value="<?= e($formClockOut) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= e(__('reason_for_correction')) ?> *</label>
                        <textarea name="reason" class="form-control" rows="2" required><?= e($formReason) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-accent"><?= e(__('save_correction')) ?></button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade correction-confirm-modal" id="correctionConfirmModal" tabindex="-1" aria-labelledby="correctionConfirmTitle" aria-describedby="correctionConfirmDescription">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="correctionConfirmTitle"><?= e(__('confirm_correction_title')) ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__('close')) ?>"></button>
            </div>
            <div class="modal-body">
                <p id="correctionConfirmDescription" class="correction-confirm-context"><strong><?= e($att['full_name']) ?></strong> · <?= e(format_date($att['work_date'])) ?></p>
                <div class="table-responsive-card" data-correction-review-table>
                    <table class="table table-sm correction-confirm-table mb-3">
                        <thead><tr><th scope="col">#</th><th scope="col"><?= e(__('field')) ?></th><th scope="col"><?= e(__('before_value')) ?></th><th scope="col"><?= e(__('after_value')) ?></th></tr></thead>
                        <tbody data-correction-review-changes></tbody>
                    </table>
                </div>
                <p class="correction-confirm-empty" data-correction-no-changes hidden><?= e(__('no_correction_changes')) ?></p>
                <div class="correction-confirm-reason"><span class="form-label d-block mb-1"><?= e(__('reason_for_correction')) ?></span><span data-correction-review-reason></span></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-correction-cancel><?= e(__('cancel')) ?></button>
                <button type="submit" form="manualCorrectionForm" class="btn btn-accent" data-confirm-correction><?= e(__('apply_correction')) ?></button>
            </div>
        </div>
    </div>
</div>

<div class="card-soft mt-3">
    <div class="card-header"><?= e(__('audit_history')) ?></div>
    <div class="card-body p-0">
        <?php if (!$auditRows): ?>
            <div class="empty-state"><?= e(__('no_audit')) ?></div>
        <?php else: ?>
            <div class="table-responsive-card">
                <table class="table mb-0 table-sm audit-history-table">
                    <thead><tr><th><?= e(__('when')) ?></th><th><?= e(__('by')) ?></th><th><?= e(__('action')) ?></th><th><?= e(__('field')) ?></th><th><?= e(__('before_value')) ?></th><th><?= e(__('after_value')) ?></th><th><?= e(__('reason')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($auditRows as $a): ?>
                        <tr>
                            <td><span class="audit-date"><?= e(format_date($a['created_at'])) ?></span><span class="audit-time"><?= e(format_time($a['created_at'])) ?></span></td>
                            <td><?= e($a['changer']) ?></td>
                            <td><?= e(audit_display_label($a['action'])) ?></td>
                            <td><?= $a['field_name'] === null || $a['field_name'] === '' ? '<span class="audit-empty">—</span>' : e(audit_display_label($a['field_name'])) ?></td>
                            <td><?= $a['old_value'] === null || $a['old_value'] === '' ? '<span class="audit-empty">—</span>' : e(audit_display_value($a['old_value'])) ?></td>
                            <td><?= $a['new_value'] === null || $a['new_value'] === '' ? '<span class="audit-empty">—</span>' : e(audit_display_value($a['new_value'])) ?></td>
                            <td><?= $a['reason'] === null || $a['reason'] === '' ? '<span class="audit-empty">—</span>' : e((string)$a['reason']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="attendance-detail-audit-mobile">
                <?php foreach ($auditRows as $a): ?>
                    <article class="attendance-detail-audit-entry"><div><strong><?= e(format_date($a['created_at'])) ?></strong> <span><?= e(format_time($a['created_at'])) ?></span></div><div><?= e($a['changer']) ?> · <?= e(audit_display_label($a['action'])) ?></div><div><span><?= e(__('before_value')) ?>:</span> <?= $a['old_value'] === null || $a['old_value'] === '' ? '—' : e(audit_display_value($a['old_value'])) ?></div><div><span><?= e(__('after_value')) ?>:</span> <?= $a['new_value'] === null || $a['new_value'] === '' ? '—' : e(audit_display_value($a['new_value'])) ?></div><div><span><?= e(__('reason')) ?>:</span> <?= $a['reason'] === null || $a['reason'] === '' ? '—' : e((string)$a['reason']) ?></div></article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
