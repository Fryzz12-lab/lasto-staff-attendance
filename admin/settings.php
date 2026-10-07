<?php
/**
 * Admin — System Settings
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

if (is_post() && (string) post('form_action', '') === '') {
    require_csrf();
    $officeLat = trim((string) post('office_latitude', ''));
    $officeLon = trim((string) post('office_longitude', ''));
    $officeIp = trim((string) post('office_public_ip', ''));
    $officeRadius = trim((string) post('office_radius_metres', '100'));
    if (trim((string) post('company_name')) === ''
        || !in_array(post('default_language'), ['en', 'ms', 'zh'], true)
        || filter_var(post('session_timeout_minutes'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10080]]) === false
        || (($officeLat === '') !== ($officeLon === ''))
        || ($officeLat !== '' && (!is_numeric($officeLat) || (float)$officeLat < -90 || (float)$officeLat > 90))
        || ($officeLon !== '' && (!is_numeric($officeLon) || (float)$officeLon < -180 || (float)$officeLon > 180))
        || !is_numeric($officeRadius) || (float)$officeRadius < 1 || (float)$officeRadius > 100000
        || ($officeIp !== '' && !filter_var($officeIp, FILTER_VALIDATE_IP))) {
        flash_set('danger', __('settings_invalid'));
        redirect('/admin/settings.php');
    }
    $keys = [
        'company_name', 'default_language', 'enable_leave_module',
        'enable_early_leave_calc', 'session_timeout_minutes',
        'office_latitude', 'office_longitude', 'office_radius_metres', 'office_public_ip',
    ];
    $stmt = db()->prepare(
        'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach ($keys as $key) {
        $val = post($key);
        if ($val === null) {
            if (str_starts_with($key, 'enable_') || str_starts_with($key, 'allow_')) {
                $val = '0';
            } else {
                continue;
            }
        }
        $stmt->execute([$key, (string) $val]);
    }
    flash_set('success', __('msg_settings_saved'));
    redirect('/admin/settings.php');
}

function s(string $key, string $default = ''): string
{
    return (string) (get_setting($key, $default) ?? $default);
}

$pageTitle = __('settings');
$activeMenu = 'settings';
$isAdminArea = true;
$bodyClass = 'settings-page';
require __DIR__ . '/../includes/layout_header.php';
?>

<form method="post" class="settings-form mx-auto">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-12">
            <div class="card-soft mb-3">
                <div class="card-header"><?= e(__('general')) ?></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label"><?= e(__('company_name')) ?></label>
                        <input name="company_name" class="form-control" required value="<?= e(s('company_name', 'LASTO TECHNOLOGY')) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= e(__('default_language')) ?></label>
                        <select name="default_language" class="form-select">
                            <?php foreach (['en' => 'English', 'zh' => '中文', 'ms' => 'Bahasa Melayu'] as $code => $label): ?>
                                <option value="<?= $code ?>" <?= s('default_language', 'en') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= e(__('session_timeout')) ?></label>
                        <div class="row g-2 align-items-center">
                            <div class="col-auto">
                                <div class="input-group" style="width: 200px; max-width: 100%;">
                                    <input type="number" name="session_timeout_minutes" class="form-control text-center" min="1" max="10080" required value="<?= e(s('session_timeout_minutes', '480')) ?>" data-timeout-minutes>
                                    <span class="input-group-text"><?= e(__('policy_minutes_unit')) ?></span>
                                </div>
                            </div>
                            <div class="col-auto">
                                <span class="timeout-display-badge px-3 py-2 fs-6 d-inline-flex align-items-center" data-timeout-display aria-live="polite">
                                    <?= e(format_minutes((int) s('session_timeout_minutes', '480'))) ?>
                                </span>
                            </div>
                        </div>
                        <div class="form-hint mt-2"><?= e(__('settings_timeout_help')) ?></div>
                    </div>
                </div>
            </div>

            <div class="card-soft">
                <div class="card-header"><?= e(__('settings_attendance_features')) ?></div>
                <div class="card-body">
                    <?php
                    $checks = [
                        'enable_leave_module' => __('settings_leave_label'),
                        'enable_early_leave_calc' => __('settings_early_label'),
                    ];
                    foreach ($checks as $key => $label):
                    ?>
                        <div class="form-check mb-2">
                            <input type="hidden" name="<?= e($key) ?>" value="0">
                            <input class="form-check-input" type="checkbox" name="<?= e($key) ?>" value="1" id="<?= e($key) ?>"
                                <?= s($key, '1') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                            <div class="form-hint mt-1 mb-3"><?= e(__($key === 'enable_leave_module' ? 'settings_leave_help' : 'settings_early_help')) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card-soft mt-3">
                <div class="card-header">Office attendance location</div>
                <div class="card-body">
                    <p class="form-hint">Coordinates compare captured positions with the office area. Network matches are supporting evidence only.</p>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label" for="office_latitude">Office latitude</label><input id="office_latitude" name="office_latitude" type="number" step="any" min="-90" max="90" class="form-control" value="<?= e(s('office_latitude')) ?>"></div>
                        <div class="col-md-6"><label class="form-label" for="office_longitude">Office longitude</label><input id="office_longitude" name="office_longitude" type="number" step="any" min="-180" max="180" class="form-control" value="<?= e(s('office_longitude')) ?>"></div>
                        <div class="col-md-6"><label class="form-label" for="office_radius_metres">Office radius (metres)</label><input id="office_radius_metres" name="office_radius_metres" type="number" min="1" max="100000" class="form-control" value="<?= e(s('office_radius_metres', '100')) ?>"></div>
                        <div class="col-md-6"><label class="form-label" for="office_public_ip">Office public IP (optional)</label><input id="office_public_ip" name="office_public_ip" class="form-control" value="<?= e(s('office_public_ip')) ?>"></div>
                    </div>
                </div>
            </div>
        </div>

    </div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mt-3">
        <a href="<?= e(url('/admin/schedules.php')) ?>"><?= e(__('schedules')) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        <button class="btn btn-accent"><?= e(__('save_settings')) ?></button>
    </div>
</form>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
