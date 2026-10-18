<?php
/**
 * Admin — Multi-Branch & Office Locations Management
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

// Handle POST actions
if (is_post()) {
    require_csrf();
    $action = (string) post('action', '');

    if ($action === 'toggle_status') {
        $officeId = (int) post('office_id', 0);
        $stmt = db()->prepare('SELECT id, name, is_active FROM offices WHERE id = ?');
        $stmt->execute([$officeId]);
        $office = $stmt->fetch();
        if ($office) {
            $newStatus = (int) $office['is_active'] === 1 ? 0 : 1;
            db()->prepare('UPDATE offices SET is_active = ? WHERE id = ?')->execute([$newStatus, $officeId]);
            flash_set('success', $newStatus ? 'Office "' . e($office['name']) . '" activated.' : 'Office "' . e($office['name']) . '" deactivated.');
        }
        redirect('/admin/offices.php');
    }

    if ($action === 'delete') {
        $officeId = (int) post('office_id', 0);
        $stmt = db()->prepare('SELECT id, name FROM offices WHERE id = ?');
        $stmt->execute([$officeId]);
        $office = $stmt->fetch();
        if ($office) {
            // Check if employees or attendance records reference this office
            $staffCount = (int) db()->prepare('SELECT COUNT(*) FROM users WHERE office_id = ?')->execute([$officeId]) ? (int) db()->query("SELECT COUNT(*) FROM users WHERE office_id = {$officeId}")->fetchColumn() : 0;
            $attCount = (int) db()->prepare('SELECT COUNT(*) FROM attendance_location_events WHERE office_id = ?')->execute([$officeId]) ? (int) db()->query("SELECT COUNT(*) FROM attendance_location_events WHERE office_id = {$officeId}")->fetchColumn() : 0;

            if ($staffCount > 0 || $attCount > 0) {
                // Soft deactivate to preserve historical audit data
                db()->prepare('UPDATE offices SET is_active = 0 WHERE id = ?')->execute([$officeId]);
                flash_set('warning', __('msg_office_deactivated'));
            } else {
                db()->prepare('DELETE FROM offices WHERE id = ?')->execute([$officeId]);
                flash_set('success', __('msg_office_deleted'));
            }
        }
        redirect('/admin/offices.php');
    }

    if ($action === 'save_policy') {
        $policy = (string) post('office_attendance_policy', 'assigned_only');
        if (!in_array($policy, ['assigned_only', 'any_branch'], true)) {
            $policy = 'assigned_only';
        }
        set_setting('office_attendance_policy', $policy);
        flash_set('success', 'Attendance location policy updated.');
        redirect('/admin/offices.php');
    }
}

// Fetch all offices
$offices = db()->query('SELECT * FROM offices ORDER BY is_active DESC, name ASC')->fetchAll();

// Fetch staff counts per office
$staffCountsRaw = db()->query('SELECT office_id, COUNT(*) AS cnt FROM users WHERE role = "staff" GROUP BY office_id')->fetchAll();
$staffCounts = [];
foreach ($staffCountsRaw as $row) {
    if ($row['office_id'] !== null) {
        $staffCounts[(int) $row['office_id']] = (int) $row['cnt'];
    }
}

// Setup completeness calculation
$totalActive = 0;
$configuredActive = 0;
$unconfiguredOffices = [];

foreach ($offices as $off) {
    if ((int) $off['is_active'] === 1) {
        $totalActive++;
        $hasCoords = $off['latitude'] !== null && $off['longitude'] !== null && is_numeric($off['latitude']) && is_numeric($off['longitude']);
        if ($hasCoords) {
            $configuredActive++;
        } else {
            $unconfiguredOffices[] = $off;
        }
    }
}

$currentPolicy = get_setting('office_attendance_policy', 'assigned_only');

$pageTitle = __('office_locations');
$activeMenu = 'offices';
$isAdminArea = true;
$bodyClass = 'admin-offices-page';

require __DIR__ . '/../includes/layout_header.php';
?>
<style>
.office-policy-options { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; }
.office-policy-option { display: flex; align-items: flex-start; gap: .75rem; min-height: 105px; padding: 1rem; border: 1px solid var(--border); border-radius: .75rem; cursor: pointer; }
.office-policy-option:has(input:checked) { border-color: var(--accent); background: color-mix(in srgb, var(--accent) 8%, var(--surface)); }
.office-policy-option input { flex: 0 0 auto; margin-top: .25rem; accent-color: #0f766e; }
.office-policy-option__body { display: grid; gap: .25rem; min-width: 0; }
.office-policy-option__body small { color: var(--muted); line-height: 1.45; }
.office-policy-current { display: inline-block; width: fit-content; margin-top: .25rem; padding: .15rem .5rem; border-radius: 999px; background: #0f766e; color: #fff; font-size: .7rem; font-weight: 700; text-transform: uppercase; }
.office-location-status { background: #f8fafc; border: 1px solid #dbe3ec; }
.office-staff-count { color: #0f766e; }
.office-staff-count:hover { color: #115e59; }
html[data-theme='night'] .admin-offices-page .office-location-status { background: #122238; border-color: #29415d; }
html[data-theme='night'] .admin-offices-page .office-location-status .text-muted { color: #b6c8de !important; }
html[data-theme='night'] .admin-offices-page .office-location-status .text-success { color: #6ee7b7 !important; }
html[data-theme='night'] .admin-offices-page .office-location-status .text-warning { color: #fcd34d !important; }
html[data-theme='night'] .admin-offices-page .office-staff-count { color: #5eead4; }
html[data-theme='night'] .admin-offices-page .office-staff-count:hover { color: #99f6e4; }
html[data-theme='night'] .admin-offices-page .office-badge--success,
html[data-theme='night'] .admin-offices-page .office-policy-current {
    background: #064e3b !important;
    border: 1px solid #34d399 !important;
    color: #d1fae5 !important;
}
html[data-theme='night'] .admin-offices-page .office-badge--warning {
    background: #78350f !important;
    border: 1px solid #fbbf24 !important;
    color: #fef3c7 !important;
}
html[data-theme='night'] .admin-offices-page .office-badge--inactive,
html[data-theme='night'] .admin-offices-page .office-badge--code {
    background: #25364d !important;
    border: 1px solid #71839e !important;
    color: #f1f5f9 !important;
}
@media (max-width: 767.98px) { .office-policy-options { grid-template-columns: 1fr; } }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1"><i class="bi bi-buildings me-2 text-accent"></i><?= e(__('office_locations')) ?></h1>
        <p class="text-muted mb-0"><?= e(__('office_locations_intro')) ?></p>
    </div>
    <div>
        <a href="<?= e(url('/admin/office_form.php')) ?>" class="btn btn-accent">
            <i class="bi bi-plus-lg me-1"></i> <?= e(__('add_office')) ?>
        </a>
    </div>
</div>

<!-- Completeness Progress Banner -->
<?php if ($totalActive === 0): ?>
    <div class="alert alert-info d-flex align-items-center gap-3 mb-4" role="alert">
        <i class="bi bi-info-circle-fill fs-3 text-info"></i>
        <div>
            <div class="fw-semibold"><?= e(__('no_active_offices')) ?></div>
            <div class="small"><?= e(__('add_first_office_hint')) ?></div>
        </div>
    </div>
<?php elseif ($configuredActive === $totalActive): ?>
    <div class="card-soft mb-4 border-start border-4 border-success">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-success bg-opacity-10 p-3 text-success d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                    <i class="bi bi-check2-circle fs-3"></i>
                </div>
                <div>
                    <h5 class="mb-0 fw-semibold text-success"><?= e(__('all_offices_configured')) ?></h5>
                    <p class="text-muted small mb-0">
                        <?= e(strtr(__('active_offices_configured'), [':count' => (string) $configuredActive, ':total' => (string) $totalActive])) ?>. <?= e(__('all_offices_valid_locations')) ?>
                    </p>
                </div>
            </div>
            <span class="badge office-badge--success bg-success bg-opacity-25 text-success border border-success border-opacity-50 px-3 py-2 fs-6">
                <i class="bi bi-shield-check me-1"></i> <?= $configuredActive ?> / <?= $totalActive ?> <?= e(__('ready')) ?>
            </span>
        </div>
    </div>
<?php else: ?>
    <div class="card-soft mb-4 border-start border-4 border-warning">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-2">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-warning bg-opacity-10 p-3 text-warning d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                        <i class="bi bi-exclamation-triangle-fill fs-4"></i>
                    </div>
                    <div>
                        <h5 class="mb-0 fw-semibold text-warning">
                            <?= e(strtr(__('active_offices_configured'), [':count' => (string) $configuredActive, ':total' => (string) $totalActive])) ?>
                        </h5>
                        <p class="text-muted small mb-0">
                            <?= e(strtr(__('offices_missing_gps'), [':count' => (string) count($unconfiguredOffices)])) ?>
                        </p>
                    </div>
                </div>
                <span class="badge office-badge--warning bg-warning bg-opacity-25 text-warning border border-warning border-opacity-50 px-3 py-2 fs-6">
                    <i class="bi bi-exclamation-circle me-1"></i> <?= e(__('action_required')) ?>
                </span>
            </div>
            <div class="mt-3 pt-2 border-top">
                <div class="small fw-semibold text-muted mb-2"><?= e(__('offices_requiring_setup')) ?></div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($unconfiguredOffices as $uOff): ?>
                        <a href="<?= e(url('/admin/office_form.php?id=' . (int) $uOff['id'])) ?>" class="btn btn-sm btn-outline-warning">
                            <i class="bi bi-geo-alt me-1"></i> <?= e(__('configure')) ?> <?= e($uOff['name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Attendance location verification policy -->
<div class="card-soft mb-4">
    <div class="card-body">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_policy">
            <h5 class="card-title mb-1"><i class="bi bi-geo-alt me-2 text-accent"></i><?= e(__('branch_attendance_policy')) ?></h5>
            <p class="text-muted small mb-3"><?= e(__('office_policy_question')) ?></p>
            <div class="office-policy-options">
                <?php foreach (['assigned_only' => 'policy_assigned_only', 'any_branch' => 'policy_any_branch'] as $value => $labelKey): ?>
                    <label class="office-policy-option">
                        <input type="radio" name="office_attendance_policy" value="<?= e($value) ?>" <?= $currentPolicy === $value ? 'checked' : '' ?> required>
                        <span class="office-policy-option__body">
                            <strong><?= e(__($labelKey)) ?></strong>
                            <small><?= e(__($labelKey . '_desc')) ?></small>
                            <?php if ($currentPolicy === $value): ?><span class="office-policy-current"><?= e(__('office_policy_current')) ?></span><?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="text-muted small mt-3 mb-3"><?= e(__('office_policy_note')) ?></p>
            <button type="submit" class="btn btn-accent"><?= e(__('office_policy_save')) ?></button>
        </form>
    </div>
</div>

<!-- Offices Cards / Grid -->
<div class="row g-3 mb-4">
    <?php if (empty($offices)): ?>
        <div class="col-12">
            <div class="card-soft text-center py-5">
                <div class="text-muted fs-1 mb-2"><i class="bi bi-buildings"></i></div>
                <h5><?= e(__('no_offices_found')) ?></h5>
                <p class="text-muted mb-3"><?= e(__('no_branch_offices')) ?></p>
                <div>
                    <a href="<?= e(url('/admin/office_form.php')) ?>" class="btn btn-accent">
                        <i class="bi bi-plus-lg me-1"></i> <?= e(__('add_office')) ?>
                    </a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($offices as $off): ?>
            <?php
                $isActive = (int) $off['is_active'] === 1;
                $hasCoords = $off['latitude'] !== null && $off['longitude'] !== null && is_numeric($off['latitude']) && is_numeric($off['longitude']);
                $staffCount = $staffCounts[(int) $off['id']] ?? 0;
            ?>
            <div class="col-md-6 col-xl-4">
                <div class="card-soft h-100 d-flex flex-column justify-content-between <?= !$isActive ? 'opacity-75' : '' ?>">
                    <div class="card-body">
                        <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                            <div>
                                <h5 class="fw-bold mb-1"><?= e($off['name']) ?></h5>
                                <?php if (!empty($off['code'])): ?>
                                    <span class="badge office-badge--code bg-light text-dark border"><?= e($off['code']) ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="badge <?= $isActive ? 'office-badge--success bg-success bg-opacity-25 text-success border border-success border-opacity-50' : 'office-badge--inactive bg-secondary bg-opacity-25 text-secondary border border-secondary border-opacity-50' ?>">
                                <?= $isActive ? strtoupper(e(__('active'))) : strtoupper(e(__('inactive'))) ?>
                            </span>
                        </div>

                        <?php if (!empty($off['address'])): ?>
                            <div class="text-muted small text-truncate-2 mb-3">
                                <i class="bi bi-geo-alt me-1 text-muted"></i><?= e($off['address']) ?>
                            </div>
                        <?php else: ?>
                            <div class="mb-3"></div>
                        <?php endif; ?>

                        <!-- Attendance Location Status -->
                        <div class="office-location-status p-3 rounded mb-3">
                            <?php if ($hasCoords): ?>
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <span class="text-success small fw-semibold">
                                        <i class="bi bi-check-circle-fill me-1"></i> <?= e(__('location_configured')) ?>
                                    </span>
                                    <a href="https://www.google.com/maps?q=<?= (float)$off['latitude'] ?>,<?= (float)$off['longitude'] ?>" target="_blank" rel="noopener noreferrer" class="small text-muted text-decoration-none">
                                        <?= e(__('view_in_maps')) ?> <i class="bi bi-box-arrow-up-right small"></i>
                                    </a>
                                </div>
                                <div class="small text-muted">
                                    <?= e(__('radius')) ?>: <strong><?= (int) ($off['radius_meters'] ?? 100) ?> m</strong>
                                </div>
                            <?php else: ?>
                                <div class="text-warning small fw-semibold">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e(__('location_not_configured')) ?>
                                </div>
                                <div class="text-muted small"><?= e(__('gps_coordinates_needed')) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Staff Count Metric -->
                        <div class="d-flex align-items-center justify-content-between small text-muted mb-1">
                            <span><i class="bi bi-people me-1"></i> <?= e(__('assigned_staff')) ?>:</span>
                            <a href="<?= e(url('/admin/staff.php?office_id=' . (int) $off['id'])) ?>" class="office-staff-count fw-bold fs-6 text-decoration-none">
                                <?= number_format($staffCount) ?>
                            </a>
                        </div>
                    </div>

                    <!-- Card Actions Footer -->
                    <div class="card-footer bg-transparent border-top p-3 d-flex justify-content-between align-items-center">
                        <div class="d-flex gap-2">
                            <a href="<?= e(url('/admin/office_form.php?id=' . (int) $off['id'])) ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil me-1"></i> <?= e(__('edit')) ?>
                            </a>
                            <a href="<?= e(url('/admin/staff.php?office_id=' . (int) $off['id'])) ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-people me-1"></i> <?= e(__('manage_staff')) ?>
                            </a>
                        </div>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="<?= e(__('more_options')) ?>">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <li>
                                    <a class="dropdown-item" href="<?= e(url('/admin/office_form.php?id=' . (int) $off['id'])) ?>">
                                        <i class="bi bi-pencil me-2 text-muted"></i> <?= e(__('edit_office')) ?>
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="<?= e(url('/admin/staff.php?office_id=' . (int) $off['id'])) ?>">
                                        <i class="bi bi-people me-2 text-muted"></i> <?= e(__('manage_staff')) ?>
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="post" data-confirm="<?= e($isActive && $staffCount > 0 ? strtr(__('deactivate_office_confirm'), [':name' => $off['name'], ':count' => (string) $staffCount]) : ($isActive ? 'Deactivate this office?' : 'Activate this office?')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="office_id" value="<?= (int) $off['id'] ?>">
                                        <button type="submit" class="dropdown-item <?= $isActive ? 'text-warning' : 'text-success' ?>">
                                            <i class="bi <?= $isActive ? 'bi-pause-circle me-2' : 'bi-play-circle me-2' ?>"></i> <?= $isActive ? 'Deactivate office' : 'Activate office' ?>
                                        </button>
                                    </form>
                                </li>
                                <li>
                                    <form method="post" data-confirm="Are you sure you want to delete this office? If staff or attendance records are attached, it will be safely deactivated instead.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="office_id" value="<?= (int) $off['id'] ?>">
                                        <button type="submit" class="dropdown-item text-danger">
                                            <i class="bi bi-trash me-2"></i> Delete office
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
