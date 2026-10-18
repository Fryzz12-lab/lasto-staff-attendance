<?php
/**
 * Admin — Staff Management
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

$q = trim((string) get('q', ''));
$status = (string) get('status', 'all');
$officeFilter = get('office_id');

$offices = db()->query('SELECT id, name, code FROM offices ORDER BY is_active DESC, name ASC')->fetchAll();

$where = " FROM users u LEFT JOIN offices o ON o.id = u.office_id WHERE u.role = 'staff'";
$params = [];

if ($q !== '') {
    $where .= ' AND (u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like];
}
if ($status === 'active') {
    $where .= ' AND u.is_active = 1';
} elseif ($status === 'inactive') {
    $where .= ' AND u.is_active = 0';
}
if ($officeFilter !== null && $officeFilter !== '' && $officeFilter !== 'all') {
    $where .= ' AND u.office_id = ?';
    $params[] = (int) $officeFilter;
}

$page = max(1, (int) get('page', 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$countStmt = db()->prepare("SELECT COUNT(*)" . $where);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$sql = "SELECT u.*, o.name AS office_name, o.code AS office_code" . $where . " ORDER BY u.full_name LIMIT {$perPage} OFFSET {$offset}";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$hasActiveOffice = ($officeFilter !== null && $officeFilter !== '' && $officeFilter !== 'all');
$hasActiveStatus = ($status !== 'all');
$hasActiveAdvanced = $hasActiveOffice || $hasActiveStatus;
$activeFilterCount = ($hasActiveOffice ? 1 : 0) + ($hasActiveStatus ? 1 : 0);
$isFiltered = $q !== '' || $hasActiveAdvanced;

$pageTitle = __('staff_management');
$bodyClass = 'admin-staff-management';
$activeMenu = 'staff';
$isAdminArea = true;
require __DIR__ . '/../includes/layout_header.php';
?>

<div class="staff-page-header d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><?= e($pageTitle) ?></h1>
    <a data-spa="0" href="<?= e(url('/admin/staff_form.php?' . http_build_query(['return_to' => $_SERVER['REQUEST_URI'] ?? '/admin/staff.php']))) ?>" class="btn btn-accent"><i class="bi bi-plus-lg" aria-hidden="true"></i> <span class="d-none d-sm-inline">Add New Staff</span><span class="d-sm-none">Add Staff</span></a>
</div>

<div class="card-soft mb-3 staff-filter-card">
    <div class="card-body">
        <form class="row g-2" method="get">
            <div class="col-md-6 col-12">
                <label class="form-label d-none d-md-block" for="filter-q">Search by Name, Username or Email</label>
                <div class="search-bar-combo">
                    <input id="filter-q" type="search" name="q" class="form-control search-bar-combo__input" placeholder="Name, username or email…" value="<?= e($q) ?>">
                    <button type="submit" class="search-bar-combo__btn" data-keep-visible="1" title="<?= e(__('search')) ?>" aria-label="<?= e(__('search')) ?>">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <span><?= e(__('search')) ?></span>
                    </button>
                </div>
            </div>

            <!-- Mobile quick filter toolbar -->
            <div class="col-12 d-md-none staff-filter-mobile-toolbar d-flex align-items-center justify-content-between pt-1">
                <button type="button" class="btn btn-sm btn-outline-secondary staff-filter-toggle-btn d-inline-flex align-items-center gap-1" data-bs-toggle="collapse" data-bs-target="#advancedStaffFilters" aria-expanded="<?= $hasActiveAdvanced ? 'true' : 'false' ?>">
                    <i class="bi bi-funnel" aria-hidden="true"></i>
                    <span><?= e(__('filters')) ?></span>
                    <?php if ($activeFilterCount > 0): ?>
                        <span class="badge bg-accent text-dark ms-1"><?= $activeFilterCount ?></span>
                    <?php endif; ?>
                </button>
                <?php if ($isFiltered): ?>
                    <a href="<?= e(url('/admin/staff.php')) ?>" class="staff-filter-reset-link small text-muted"><?= e(__('filters_reset')) ?></a>
                <?php endif; ?>
            </div>

            <!-- Advanced Filters: Collapsible on mobile, direct grid columns on desktop -->
            <div class="collapse d-md-contents <?= $hasActiveAdvanced ? 'show' : '' ?>" id="advancedStaffFilters">
                <div class="col-md-3 col-6">
                    <label class="form-label" for="filter-office"><?= e(__('assigned_office')) ?></label>
                    <select id="filter-office" name="office_id" class="form-select">
                        <option value="all" <?= ($officeFilter === null || $officeFilter === 'all' || $officeFilter === '') ? 'selected' : '' ?>>All Offices</option>
                        <?php foreach ($offices as $off): ?>
                            <option value="<?= (int) $off['id'] ?>" <?= ((string) $officeFilter === (string) $off['id']) ? 'selected' : '' ?>>
                                <?= e($off['name']) ?><?= !empty($off['code']) ? ' (' . e($off['code']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 col-6">
                    <label class="form-label" for="filter-status"><?= e(__('filter_account_status')) ?></label>
                    <select id="filter-status" name="status" class="form-select">
                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>><?= e(__('all_statuses')) ?></option>
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>><?= e(__('active')) ?></option>
                        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>><?= e(__('inactive')) ?></option>
                    </select>
                </div>
                <div class="col-12 d-md-none mt-2">
                    <button type="submit" class="btn btn-sm btn-accent w-100">
                        <i class="bi bi-check2 me-1"></i>Apply Filters
                    </button>
                </div>
            </div>

            <!-- Desktop reset/hint row -->
            <div class="col-12 d-none d-md-flex flex-wrap align-items-center gap-3" style="flex-basis:100%">
                <span class="form-hint" data-auto-filter-hint hidden><?= e(__('filters_auto_hint')) ?></span>
                <a href="<?= e(url('/admin/staff.php')) ?>" class="small staff-filter-reset-link"><?= e(__('filters_reset')) ?></a>
            </div>
        </form>
    </div>
</div>

<div class="card-soft">
    <div class="card-body p-0">
        <?php if (!$rows): ?>
            <div class="empty-state"><?= e(__('no_staff')) ?></div>
        <?php else: ?>
            <div class="table-responsive-card staff-management-desktop">
                <table class="table mb-0 staff-table" style="table-layout: fixed; width: 100%;">
                    <colgroup>
                        <col style="width: 24%;">
                        <col style="width: 17%;">
                        <col style="width: 21%;">
                        <col style="width: 13%;">
                        <col style="width: 13%;">
                        <col style="width: 12%;">
                    </colgroup>
                    <thead>
                    <tr>
                        <th><?= e(__('employee')) ?></th>
                        <th><?= e(__('username')) ?></th>
                        <th><?= e(__('position')) ?></th>
                        <th><?= e(__('status')) ?></th>
                        <th><?= e(__('off_day')) ?></th>
                        <th><?= e(__('action')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e($r['full_name']) ?></div>
                                <?php if (!empty($r['email'])): ?><div class="form-hint small"><?= e($r['email']) ?></div><?php endif; ?>
                            </td>
                            <td><code class="username-badge"><?= e($r['username']) ?></code></td>
                            <td>
                                <div><?= e((string) $r['job_position']) ?></div>
                                <?php if (!empty($r['office_name'])): ?>
                                    <div class="small text-muted mt-1">
                                        <i class="bi bi-buildings me-1 text-accent"></i><?= e($r['office_name']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= (int)$r['is_active']
                                    ? '<span class="status-badge badge-completed">' . e(__('active')) . '</span>'
                                    : '<span class="status-badge badge-absent">' . e(__('inactive')) . '</span>' ?>
                            </td>
                            <td><?= e(weekday_name((int) $r['weekly_off_day'], lang_current())) ?></td>
                            <td class="text-nowrap">
                                <a data-spa="0" class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/staff_form.php?' . http_build_query(['id' => (int)$r['id'], 'return_to' => $_SERVER['REQUEST_URI'] ?? '/admin/staff.php']))) ?>"><?= e(__('edit')) ?></a>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        data-delete-staff
                                        data-staff-id="<?= (int)$r['id'] ?>"
                                        data-staff-name="<?= e($r['full_name']) ?>"
                                        data-csrf="<?= e(csrf_token()) ?>"
                                ><?= e(__('delete')) ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="staff-management-mobile">
                <?php foreach ($rows as $r): ?>
                    <article class="staff-card">
                        <div class="staff-card__head">
                            <div class="staff-card__identity">
                                <div class="staff-card__name fw-semibold"><?= e($r['full_name']) ?></div>
                                <div class="staff-card__meta-sub">
                                    <code class="username-badge"><?= e($r['username']) ?></code>
                                    <?php if (!empty($r['email'])): ?>
                                        <span class="staff-card__email"><?= e($r['email']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="staff-card__status">
                                <?= (int)$r['is_active']
                                    ? '<span class="status-badge badge-completed">' . e(__('active')) . '</span>'
                                    : '<span class="status-badge badge-absent">' . e(__('inactive')) . '</span>' ?>
                            </div>
                        </div>

                        <div class="staff-card__body">
                            <?php if (!empty($r['job_position'])): ?>
                                <div class="staff-card__pos-row">
                                    <span class="staff-card__label"><?= e(__('position')) ?></span>
                                    <span class="staff-card__pos-val"><?= e($r['job_position']) ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="staff-card__meta-grid">
                                <div class="staff-card__meta-cell">
                                    <span class="staff-card__label"><?= e(__('assigned_office')) ?></span>
                                    <span class="staff-card__meta-val">
                                        <?php if (!empty($r['office_name'])): ?>
                                            <i class="bi bi-buildings text-accent me-1" aria-hidden="true"></i><?= e($r['office_name']) ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="staff-card__meta-cell">
                                    <span class="staff-card__label"><?= e(__('off_day')) ?></span>
                                    <span class="staff-card__meta-val"><?= e(weekday_name((int) $r['weekly_off_day'], lang_current())) ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="staff-card__actions">
                            <a data-spa="0" class="btn btn-sm btn-outline-secondary staff-card__btn-edit" href="<?= e(url('/admin/staff_form.php?' . http_build_query(['id' => (int)$r['id'], 'return_to' => $_SERVER['REQUEST_URI'] ?? '/admin/staff.php']))) ?>">
                                <i class="bi bi-pencil-square me-1" aria-hidden="true"></i><?= e(__('edit')) ?>
                            </a>
                            <button type="button" class="btn btn-sm btn-outline-danger staff-card__btn-delete"
                                    data-delete-staff
                                    data-staff-id="<?= (int)$r['id'] ?>"
                                    data-staff-name="<?= e($r['full_name']) ?>"
                                    data-csrf="<?= e(csrf_token()) ?>"
                            >
                                <i class="bi bi-trash3 me-1" aria-hidden="true"></i><?= e(__('delete')) ?>
                            </button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php
            $filterParams = array_filter([
                'q'         => $q !== '' ? $q : null,
                'status'    => $status !== 'all' ? $status : null,
                'office_id' => ($officeFilter !== null && $officeFilter !== 'all' && $officeFilter !== '') ? $officeFilter : null,
            ]);
            echo pagination_links($total, $page, $perPage, $filterParams);
            ?>
        <?php endif; ?>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteStaffModal" tabindex="-1" aria-labelledby="deleteStaffModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteStaffModalLabel"><i class="bi bi-trash3 text-danger me-2" aria-hidden="true"></i>Delete Staff Account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Are you sure you want to delete <strong id="deleteStaffName"></strong>?<br>
                <span class="form-hint">If the staff has attendance history, the account will be deactivated instead of permanently deleted.</span></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form id="deleteStaffForm" method="post">
                    <input type="hidden" name="form_action" value="delete">
                    <input type="hidden" id="deleteStaffCsrf" name="csrf_token">
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash3 me-1" aria-hidden="true"></i>Yes, Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
