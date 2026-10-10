<?php
/**
 * Admin — Office Location Create / Edit Form
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

$id = (int) get('id', 0);
$office = null;

if ($id > 0) {
    $stmt = db()->prepare('SELECT * FROM offices WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $office = $stmt->fetch();
    if (!$office) {
        flash_set('danger', 'Office location not found.');
        redirect('/admin/offices.php');
    }
}

// Handle form submission
if (is_post()) {
    require_csrf();

    $name = trim((string) post('name', ''));
    $code = trim((string) post('code', ''));
    $address = trim((string) post('address', ''));
    $rawLat = trim((string) post('latitude', ''));
    $rawLon = trim((string) post('longitude', ''));
    $rawRadius = trim((string) post('radius_meters', '100'));
    $publicIp = trim((string) post('public_ip', ''));
    $isActive = (int) post('is_active', 1) === 1 ? 1 : 0;

    $lat = null;
    $lon = null;
    $errors = [];

    if ($name === '') {
        $errors[] = 'Office name is required.';
    } else {
        // Unique name check
        $checkStmt = db()->prepare('SELECT id FROM offices WHERE name = ?' . ($office ? ' AND id != ?' : '') . ' LIMIT 1');
        $checkStmt->execute($office ? [$name, $id] : [$name]);
        if ($checkStmt->fetch()) {
            $errors[] = 'An office with this name already exists.';
        }
    }

    if ($rawLat !== '' || $rawLon !== '') {
        $vLat = filter_var($rawLat, FILTER_VALIDATE_FLOAT);
        $vLon = filter_var($rawLon, FILTER_VALIDATE_FLOAT);
        if ($vLat === false || $vLat < -90 || $vLat > 90) {
            $errors[] = 'Latitude must be a valid number between -90 and 90.';
        } else {
            $lat = $vLat;
        }

        if ($vLon === false || $vLon < -180 || $vLon > 180) {
            $errors[] = 'Longitude must be a valid number between -180 and 180.';
        } else {
            $lon = $vLon;
        }
    }

    $radius = filter_var($rawRadius, FILTER_VALIDATE_INT);
    if ($radius === false || $radius < 10 || $radius > 10000) {
        $errors[] = 'Allowed radius must be between 10 and 10,000 metres.';
    }

    if ($publicIp !== '' && !filter_var($publicIp, FILTER_VALIDATE_IP)) {
        $errors[] = 'Office public IP must be a valid IPv4 or IPv6 address.';
    }

    if (!empty($errors)) {
        flash_set('danger', implode('<br>', $errors));
    } else {
        if ($office) {
            $updateSql = 'UPDATE offices SET name = ?, code = ?, address = ?, latitude = ?, longitude = ?, radius_meters = ?, public_ip = ?, is_active = ? WHERE id = ?';
            db()->prepare($updateSql)->execute([
                $name,
                $code !== '' ? $code : null,
                $address !== '' ? $address : null,
                $lat,
                $lon,
                $radius,
                $publicIp !== '' ? $publicIp : null,
                $isActive,
                $id,
            ]);
            $officeId = $id;
            flash_set('success', __('msg_office_saved'));
        } else {
            $insertSql = 'INSERT INTO offices (name, code, address, latitude, longitude, radius_meters, public_ip, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
            db()->prepare($insertSql)->execute([
                $name,
                $code !== '' ? $code : null,
                $address !== '' ? $address : null,
                $lat,
                $lon,
                $radius,
                $publicIp !== '' ? $publicIp : null,
                $isActive,
            ]);
            $officeId = (int) db()->lastInsertId();
            flash_set('success', __('msg_office_saved'));
        }

        // Backward compatibility sync if this is the first office or HQ
        if ($officeId === 1 || str_contains(strtoupper($name), 'HQ')) {
            if ($lat !== null && $lon !== null) {
                set_setting('office_latitude', (string) $lat);
                set_setting('office_longitude', (string) $lon);
            }
            set_setting('office_name', $name);
            set_setting('office_radius_metres', (string) $radius);
            if ($publicIp !== '') {
                set_setting('office_public_ip', $publicIp);
            }
        }

        redirect('/admin/offices.php');
    }
}

// Fetch assigned employee metrics if editing
$assignedCount = 0;
$staffPreview = [];
if ($office) {
    $cntStmt = db()->prepare('SELECT COUNT(*) FROM users WHERE office_id = ?');
    $cntStmt->execute([$id]);
    $assignedCount = (int) $cntStmt->fetchColumn();

    if ($assignedCount > 0 && $assignedCount <= 5) {
        $pStmt = db()->prepare('SELECT id, full_name, username, job_position, is_active FROM users WHERE office_id = ? ORDER BY is_active DESC, full_name ASC LIMIT 5');
        $pStmt->execute([$id]);
        $staffPreview = $pStmt->fetchAll();
    }
}

$pageTitle = $office ? __('edit_office') : __('add_office');
$activeMenu = 'offices';
$isAdminArea = true;
$bodyClass = 'admin-office-form-page';

$v = static function(string $key, mixed $fallback = '') use ($office) {
    return post($key, $office[$key] ?? $fallback);
};

require __DIR__ . '/../includes/layout_header.php';
?>

<div class="mb-3">
    <a href="<?= e(url('/admin/offices.php')) ?>" class="text-muted text-decoration-none small">
        <i class="bi bi-arrow-left me-1"></i> Back to Office Locations
    </a>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card-soft">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold"><?= e($pageTitle) ?></h5>
                <?php if ($office): ?>
                    <span class="badge bg-light text-dark border">Office ID: #<?= (int) $office['id'] ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form method="post" id="office-form">
                    <?= csrf_field() ?>

                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label" for="office_name"><?= e(__('office_name')) ?> *</label>
                            <input id="office_name" name="name" class="form-control" value="<?= e((string) $v('name')) ?>" placeholder="e.g. LASTO Sungai Petani" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="office_code"><?= e(__('office_code')) ?> <span class="text-muted small">(<?= e(__('optional')) ?>)</span></label>
                            <input id="office_code" name="code" class="form-control" value="<?= e((string) $v('code')) ?>" placeholder="e.g. HQ, SP, KL01" maxlength="32">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="office_address"><?= e(__('office_address')) ?></label>
                        <textarea id="office_address" name="address" class="form-control" rows="2" placeholder="Full street address of this branch"><?= e((string) $v('address')) ?></textarea>
                    </div>

                    <!-- Attendance Location Section -->
                    <div class="card border rounded p-3 mb-3 bg-light bg-opacity-25">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                            <div>
                                <h6 class="fw-bold mb-0"><i class="bi bi-geo-alt-fill text-accent me-1"></i> Attendance Location</h6>
                                <p class="text-muted small mb-0">Set the physical location used to verify attendance at this branch.</p>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="button" id="btn_use_current_location" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1">
                                    <i class="bi bi-geo-alt"></i> <?= e(__('use_current_location')) ?>
                                </button>
                                <button type="button" id="btn_clear_coords" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1" style="display:none;">
                                    <i class="bi bi-x-circle"></i> Clear
                                </button>
                            </div>
                        </div>

                        <div class="row g-3 mb-2">
                            <div class="col-md-6">
                                <label class="form-label" for="office_latitude"><?= e(__('office_latitude')) ?></label>
                                <input id="office_latitude" name="latitude" type="text" inputmode="decimal" class="form-control font-monospace" value="<?= e((string) $v('latitude')) ?>" placeholder="e.g. 5.663015">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="office_longitude"><?= e(__('office_longitude')) ?></label>
                                <input id="office_longitude" name="longitude" type="text" inputmode="decimal" class="form-control font-monospace" value="<?= e((string) $v('longitude')) ?>" placeholder="e.g. 100.502389">
                            </div>
                        </div>

                        <!-- Feedback, Accuracy, and Reassurance Panel -->
                        <div id="office_location_feedback_wrapper" class="mb-3" style="display:none;">
                            <div class="p-3 rounded bg-white border">
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-check-circle-fill text-success fs-5"></i>
                                        <span class="fw-bold text-success small text-uppercase tracking-wider" id="office_location_status_label"><?= e(__('location_configured')) ?></span>
                                        <span id="office_location_accuracy_badge" class="badge" style="display:none;"></span>
                                    </div>
                                    <a id="btn_preview_maps" href="#" target="_blank" rel="noopener noreferrer" class="small text-accent text-decoration-none" style="display:none;">
                                        <?= e(__('view_in_maps')) ?> <i class="bi bi-box-arrow-up-right small"></i>
                                    </a>
                                </div>
                                <div class="small font-monospace text-muted mb-2" id="office_location_coords_display"></div>
                                <div class="small text-muted" id="office_location_hint_text">
                                    <i class="bi bi-info-circle me-1"></i><?= e(__('review_location_notice')) ?>
                                </div>
                            </div>
                        </div>

                        <!-- Live OpenStreetMap Embed Preview -->
                        <div id="office_map_container" class="rounded overflow-hidden border mb-3" style="display:none; height:240px; background:#f0f0f0;">
                            <iframe id="office_osm_iframe" style="width:100%; height:100%; border:0;" loading="lazy" src="about:blank"></iframe>
                        </div>

                        <!-- Attendance Radius -->
                        <div class="mb-1">
                            <label class="form-label" for="office_radius_meters"><?= e(__('attendance_radius')) ?> *</label>
                            <div class="input-group" style="max-width: 260px;">
                                <input id="office_radius_meters" name="radius_meters" type="number" min="10" max="10000" class="form-control" value="<?= e((string) $v('radius_meters', '100')) ?>" required>
                                <span class="input-group-text"><?= e(__('policy_metres_unit') ?? 'metres') ?></span>
                            </div>
                            <div class="form-hint mt-1"><?= e(__('attendance_radius_hint')) ?></div>
                        </div>
                    </div>

                    <!-- Collapsible Advanced Settings (Public IP) -->
                    <details class="border rounded p-3 mb-3 bg-light bg-opacity-25" <?= !empty($v('public_ip')) ? 'open' : '' ?>>
                        <summary class="fw-semibold text-muted small cursor-pointer">
                            <i class="bi bi-gear me-1"></i> <?= e(__('advanced_network_settings')) ?>
                        </summary>
                        <div class="pt-3">
                            <label class="form-label" for="office_public_ip"><?= e(__('office_public_ip')) ?> <span class="text-muted small">(<?= e(__('optional')) ?>)</span></label>
                            <input id="office_public_ip" name="public_ip" class="form-control" value="<?= e((string) $v('public_ip')) ?>" placeholder="e.g. 203.106.12.34">
                            <div class="form-hint mt-1">Branch Wi-Fi public IP for supporting network check.</div>
                        </div>
                    </details>

                    <!-- Office Status -->
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= (int) $v('is_active', 1) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="is_active">
                                Office Active
                            </label>
                            <div class="form-hint"><?= e(__('office_active_hint')) ?></div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center gap-3 pt-3 border-top">
                        <a href="<?= e(url('/admin/offices.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" id="btn_save_office" class="btn btn-accent px-4">
                            <span id="btn_save_text"><?= e(__('save_office')) ?></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Assigned Staff Summary Sidebar -->
    <div class="col-lg-4">
        <div class="card-soft mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="bi bi-people me-1"></i> Assigned Staff</h6>
                <span class="badge bg-secondary"><?= number_format($assignedCount) ?></span>
            </div>
            <div class="card-body">
                <?php if (!$office || $assignedCount === 0): ?>
                    <div class="text-center py-3 text-muted">
                        <i class="bi bi-people text-muted opacity-50 fs-2 d-block mb-1"></i>
                        <p class="small mb-3">No employees currently assigned to this branch.</p>
                        <?php if ($office): ?>
                            <a href="<?= e(url('/admin/staff.php')) ?>" class="btn btn-sm btn-outline-secondary">
                                Assign in Staff Directory <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-2 mb-3">
                        <div class="display-6 fw-bold text-dark mb-0"><?= number_format($assignedCount) ?></div>
                        <div class="small text-muted">employees assigned to this branch</div>
                    </div>

                    <?php if (!empty($staffPreview)): ?>
                        <div class="list-group list-group-flush border-top border-bottom mb-3">
                            <?php foreach ($staffPreview as $s): ?>
                                <a href="<?= e(url('/admin/staff_form.php?id=' . (int) $s['id'])) ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2 px-2 small">
                                    <div>
                                        <span class="fw-semibold"><?= e($s['full_name']) ?></span>
                                        <span class="text-muted small">@<?= e($s['username']) ?></span>
                                    </div>
                                    <span class="badge <?= (int)$s['is_active'] ? 'bg-success bg-opacity-10 text-success' : 'bg-secondary bg-opacity-10 text-secondary' ?>" style="font-size:0.65rem;">
                                        <?= (int)$s['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="d-grid">
                        <a href="<?= e(url('/admin/staff.php?office_id=' . (int) $office['id'])) ?>" class="btn btn-outline-primary btn-sm">
                            <?= e(__('manage_staff_directory')) ?> <i class="bi bi-arrow-right ms-1"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const btnUseLocation = document.getElementById('btn_use_current_location');
    const btnClearCoords = document.getElementById('btn_clear_coords');
    const btnPreviewMaps = document.getElementById('btn_preview_maps');
    const inputLat = document.getElementById('office_latitude');
    const inputLon = document.getElementById('office_longitude');
    const feedbackWrapper = document.getElementById('office_location_feedback_wrapper');
    const coordsDisplay = document.getElementById('office_location_coords_display');
    const accuracyBadge = document.getElementById('office_location_accuracy_badge');
    const statusLabel = document.getElementById('office_location_status_label');
    const mapContainer = document.getElementById('office_map_container');
    const osmIframe = document.getElementById('office_osm_iframe');
    const officeForm = document.getElementById('office-form');
    const btnSaveOffice = document.getElementById('btn_save_office');
    const activeSwitch = document.getElementById('is_active');
    const assignedStaffCount = <?= (int) $assignedCount ?>;
    const officeName = <?= json_encode($office['name'] ?? '') ?>;

    // Form submit loading feedback
    if (officeForm && btnSaveOffice) {
        officeForm.addEventListener('submit', function() {
            btnSaveOffice.disabled = true;
            btnSaveOffice.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> <?= e(__('saving_office')) ?>';
        });
    }

    // Deactivation safeguard confirmation
    if (activeSwitch && assignedStaffCount > 0) {
        activeSwitch.addEventListener('change', function() {
            if (!activeSwitch.checked) {
                const msg = `Deactivate ${officeName}?\n\n${assignedStaffCount} employee(s) are currently assigned to this office. They will no longer be able to use this office for attendance verification.`;
                if (!confirm(msg)) {
                    activeSwitch.checked = true;
                }
            }
        });
    }

    function updatePreview(isCaptured = false) {
        const latVal = inputLat.value.trim();
        const lonVal = inputLon.value.trim();
        const hasCoords = latVal !== '' && lonVal !== '' && !isNaN(latVal) && !isNaN(lonVal);

        if (hasCoords) {
            const latNum = parseFloat(latVal);
            const lonNum = parseFloat(lonVal);
            coordsDisplay.textContent = `Coordinates: ${latNum.toFixed(6)}, ${lonNum.toFixed(6)}`;
            btnPreviewMaps.href = `https://www.google.com/maps?q=${latNum},${lonNum}`;
            btnPreviewMaps.style.display = 'inline-flex';
            btnClearCoords.style.display = 'inline-flex';
            feedbackWrapper.style.display = 'block';

            if (statusLabel) {
                statusLabel.textContent = isCaptured ? '<?= e(__('location_captured')) ?>' : '<?= e(__('location_configured')) ?>';
            }

            // OpenStreetMap embed preview
            const delta = 0.0035;
            const minLon = (lonNum - delta).toFixed(6);
            const maxLon = (lonNum + delta).toFixed(6);
            const minLat = (latNum - delta).toFixed(6);
            const maxLat = (latNum + delta).toFixed(6);
            osmIframe.src = `https://www.openstreetmap.org/export/embed.html?bbox=${minLon}%2C${minLat}%2C${maxLon}%2C${maxLat}&layer=mapnik&marker=${latNum}%2C${lonNum}`;
            mapContainer.style.display = 'block';
        } else {
            btnPreviewMaps.style.display = 'none';
            btnClearCoords.style.display = 'none';
            feedbackWrapper.style.display = 'none';
            mapContainer.style.display = 'none';
            osmIframe.src = 'about:blank';
        }
    }

    if (inputLat && inputLon) {
        inputLat.addEventListener('input', () => updatePreview(false));
        inputLon.addEventListener('input', () => updatePreview(false));
        updatePreview(false);
    }

    if (btnClearCoords) {
        btnClearCoords.addEventListener('click', function() {
            inputLat.value = '';
            inputLon.value = '';
            accuracyBadge.style.display = 'none';
            updatePreview(false);
        });
    }

    if (btnUseLocation) {
        btnUseLocation.addEventListener('click', function() {
            if (!navigator.geolocation) {
                alert('Geolocation is not supported by your browser.');
                return;
            }

            const originalHtml = btnUseLocation.innerHTML;
            btnUseLocation.disabled = true;
            btnUseLocation.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> <?= e(__('acquiring_location')) ?>';

            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    btnUseLocation.disabled = false;
                    btnUseLocation.innerHTML = originalHtml;

                    const lat = pos.coords.latitude.toFixed(6);
                    const lon = pos.coords.longitude.toFixed(6);
                    const acc = Math.round(pos.coords.accuracy);

                    inputLat.value = lat;
                    inputLon.value = lon;

                    accuracyBadge.textContent = `Accuracy: ±${acc} m`;
                    accuracyBadge.style.display = 'inline-block';
                    if (acc <= 25) {
                        accuracyBadge.className = 'badge bg-success bg-opacity-25 text-success border border-success border-opacity-50';
                    } else {
                        accuracyBadge.className = 'badge bg-warning bg-opacity-25 text-warning border border-warning border-opacity-50';
                    }

                    updatePreview(true);
                },
                function(err) {
                    btnUseLocation.disabled = false;
                    btnUseLocation.innerHTML = originalHtml;

                    let msg = 'Unable to acquire location.';
                    if (err.code === 1) {
                        msg = 'Location permission was denied. Please allow location access in your browser or enter coordinates manually.';
                    } else if (err.code === 2) {
                        msg = 'Position unavailable. Please check device location settings or enter coordinates manually.';
                    } else if (err.code === 3) {
                        msg = 'Location request timed out. Please try again or enter coordinates manually.';
                    }
                    alert(msg);
                },
                {
                    enableHighAccuracy: true,
                    timeout: 12000,
                    maximumAge: 0
                }
            );
        });
    }
});
</script>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>

