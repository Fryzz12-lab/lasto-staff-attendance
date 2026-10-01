<?php
/**
 * Admin — Break Edit
 * Allows an admin to correct a single break record's start/end times.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

$breakId      = (int) get('id', 0);
$attendanceId = (int) get('attendance_id', 0);

if ($breakId <= 0) {
    flash_set('danger', __('msg_not_found'));
    redirect('/admin/breaks.php');
}

// Load break + parent attendance in one query
$stmt = db()->prepare(
    "SELECT br.*, a.work_date, a.clock_in, a.clock_out, a.user_id, a.id AS att_id,
            u.full_name
     FROM break_records br
     JOIN attendance a ON a.id = br.attendance_id
     JOIN users u ON u.id = a.user_id
     WHERE br.id = ? LIMIT 1"
);
$stmt->execute([$breakId]);
$break = $stmt->fetch();

if (!$break) {
    flash_set('danger', __('msg_not_found'));
    redirect('/admin/breaks.php');
}

// Back-link query string
$backQs = http_build_query(array_filter([
    'attendance_id' => (int) $break['att_id'],
]));

// ── POST handler ──────────────────────────────────────────────────────────────
if (is_post()) {
    require_csrf();

    $newStart  = trim((string) post('start_time'));
    $newEnd    = trim((string) post('end_time'));
    $reason    = trim((string) post('reason'));
    $version   = trim((string) post('record_version'));

    // Version / conflict check
    $currentVersion = db()->prepare('SELECT updated_at FROM break_records WHERE id=?');
    $currentVersion->execute([$breakId]);
    $dbVersion = (string) $currentVersion->fetchColumn();

    if ($dbVersion !== $version) {
        flash_set('warning', 'This break record was changed by another administrator while you had the form open. Review the latest values before saving.');
        redirect('/admin/break_edit.php?id=' . $breakId . '&attendance_id=' . $break['att_id']);
    }

    try {
        correct_break(
            $breakId,
            $newStart,
            $newEnd !== '' ? $newEnd : null,
            (int) current_user()['id'],
            $reason
        );
        flash_set('success', 'Break record updated successfully.');
        redirect('/admin/break_detail.php?' . $backQs);
    } catch (InvalidArgumentException $e) {
        flash_set('danger', $e->getMessage());
    } catch (Throwable $e) {
        error_log((string) $e);
        flash_set('danger', 'Could not save the correction. Please try again.');
    }
}

// ── Page render ───────────────────────────────────────────────────────────────
$pageTitle  = 'Edit Break';
$activeMenu = 'breaks';
$isAdminArea = true;
require __DIR__ . '/../includes/layout_header.php';

// Format datetime values for datetime-local inputs (YYYY-MM-DDTHH:MM)
$toInputVal = fn(?string $dt): string => $dt ? substr(str_replace(' ', 'T', $dt), 0, 16) : '';
?>

<div class="mb-3">
    <a href="<?= e(url('/admin/break_detail.php?' . $backQs)) ?>"
       class="btn btn-sm btn-outline-secondary">&larr; <?= e(__('back')) ?></a>
</div>

<div class="card-soft mb-3">
    <div class="card-header">
        Edit Break — <?= e($break['full_name']) ?> · <span class="fw-normal"><?= e(format_date((string) $break['work_date'])) ?></span>
    </div>
    <div class="card-body">
        <div class="meta-grid mb-4">
            <div class="meta-item">
                <div class="label"><?= e(__('type')) ?></div>
                <div class="value"><?= e(break_label((string) $break['break_type'], (int) $break['sequence_no'])) ?></div>
            </div>
            <div class="meta-item">
                <div class="label">Allowed Duration</div>
                <div class="value"><?= e(format_minutes((int) $break['allowed_minutes'])) ?></div>
            </div>
            <div class="meta-item">
                <div class="label">Original Start</div>
                <div class="value"><?= e(format_time((string) $break['start_time'])) ?></div>
            </div>
            <div class="meta-item">
                <div class="label">Original End</div>
                <div class="value">
                    <?php if (empty($break['end_time'])): ?>
                        <em class="text-warning">Open (not ended)</em>
                    <?php else: ?>
                        <?= e(format_time((string) $break['end_time'])) ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ((int) $break['exceeded']): ?>
            <div class="meta-item">
                <div class="label">Status</div>
                <div class="value"><span class="status-badge badge-late"><?= e(__('exceeded')) ?></span></div>
            </div>
            <?php endif; ?>
        </div>

        <hr class="mb-4">

        <form method="post" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="record_version" value="<?= e((string) $break['updated_at']) ?>">

            <div class="col-md-6">
                <label class="form-label" for="break-start"><strong>New Start Time</strong> *</label>
                <input
                    id="break-start"
                    type="datetime-local"
                    name="start_time"
                    class="form-control"
                    required
                    value="<?= e($toInputVal((string) $break['start_time'])) ?>"
                    min="<?= e($toInputVal((string) $break['clock_in'])) ?>"
                    <?= $break['clock_out'] ? 'max="' . e($toInputVal((string) $break['clock_out'])) . '"' : '' ?>
                >
                <div class="form-hint mt-1">Must be within the work session (<?= e(format_time((string) $break['clock_in'])) ?><?= $break['clock_out'] ? ' – ' . e(format_time((string) $break['clock_out'])) : '' ?>).</div>
            </div>

            <div class="col-md-6">
                <label class="form-label" for="break-end"><strong>New End Time</strong></label>
                <input
                    id="break-end"
                    type="datetime-local"
                    name="end_time"
                    class="form-control"
                    value="<?= e($toInputVal($break['end_time'] !== null ? (string) $break['end_time'] : null)) ?>"
                    min="<?= e($toInputVal((string) $break['clock_in'])) ?>"
                    <?= $break['clock_out'] ? 'max="' . e($toInputVal((string) $break['clock_out'])) . '"' : '' ?>
                >
                <div class="form-hint mt-1">Leave blank to keep the break as open (ongoing).</div>
            </div>

            <div class="col-12">
                <label class="form-label" for="break-reason"><strong>Reason for Edit</strong> *</label>
                <textarea
                    id="break-reason"
                    name="reason"
                    class="form-control"
                    rows="3"
                    required
                    placeholder="e.g. Employee confirmed lunch ended at 1:00 PM."
                ><?= e((string) post('reason', '')) ?></textarea>
                <div class="form-hint mt-1">All edits are permanently logged in the audit trail.</div>
            </div>

            <div class="col-12 d-flex gap-2">
                <button class="btn btn-accent">Save Changes</button>
                <a href="<?= e(url('/admin/break_detail.php?' . $backQs)) ?>"
                   class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
