<?php
/**
 * Admin — Staff Management
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_admin();

$q = trim((string) get('q', ''));
$status = (string) get('status', 'all');

$where = " FROM users u WHERE u.role = 'staff'";
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

$page = max(1, (int) get('page', 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$countStmt = db()->prepare("SELECT COUNT(*)" . $where);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$sql = "SELECT u.*" . $where . " ORDER BY u.full_name LIMIT {$perPage} OFFSET {$offset}";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = __('staff_management');
$activeMenu = 'staff';
$isAdminArea = true;
require __DIR__ . '/../includes/layout_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h2 class="h4 mb-0 d-lg-none"><?= e(__('staff_accounts')) ?></h2>
    <a href="<?= e(url('/admin/staff_form.php')) ?>" class="btn btn-accent ms-auto"><i class="bi bi-plus-lg"></i> Add New Staff</a>
</div>

<div class="card-soft mb-3">
    <div class="card-body">
        <form class="row g-2" method="get">
            <div class="col-md-8">
                <label class="form-label" for="filter-q">Search by Name, Username or Email</label>
                <div class="search-bar-combo">
                    <input id="filter-q" type="search" name="q" class="form-control search-bar-combo__input" placeholder="Name, username or email…" value="<?= e($q) ?>">
                    <button type="submit" class="search-bar-combo__btn" data-keep-visible="1" title="<?= e(__('search')) ?>" aria-label="<?= e(__('search')) ?>">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <span><?= e(__('search')) ?></span>
                    </button>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="filter-status"><?= e(__('filter_account_status')) ?></label>
                <select id="filter-status" name="status" class="form-select">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>><?= e(__('all_statuses')) ?></option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>><?= e(__('active')) ?></option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>><?= e(__('inactive')) ?></option>
                </select>
            </div>
            <div class="col-12 d-flex flex-wrap align-items-center gap-3" style="flex-basis:100%">
                <span class="form-hint" data-auto-filter-hint hidden><?= e(__('filters_auto_hint')) ?></span>
                <a href="<?= e(url('/admin/staff.php')) ?>" class="small"><?= e(__('filters_reset')) ?></a>
            </div>
        </form>
    </div>
</div>

<div class="card-soft">
    <div class="card-body p-0">
        <?php if (!$rows): ?>
            <div class="empty-state"><?= e(__('no_staff')) ?></div>
        <?php else: ?>
            <div class="table-responsive-card">
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
                            <td><?= e((string) $r['job_position']) ?></td>
                            <td>
                                <?= (int)$r['is_active']
                                    ? '<span class="status-badge badge-completed">' . e(__('active')) . '</span>'
                                    : '<span class="status-badge badge-absent">' . e(__('inactive')) . '</span>' ?>
                            </td>
                            <td><?= e(weekday_name((int) $r['weekly_off_day'], lang_current())) ?></td>
                            <td class="text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/staff_form.php?id=' . (int)$r['id'])) ?>"><?= e(__('edit')) ?></a>
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
            <?php
            $filterParams = array_filter([
                'q'      => $q !== '' ? $q : null,
                'status' => $status !== 'all' ? $status : null,
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

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-delete-staff]').forEach(btn => {
        btn.addEventListener('click', () => {
            const id   = btn.dataset.staffId;
            const name = btn.dataset.staffName;
            const csrf = btn.dataset.csrf;
            document.getElementById('deleteStaffName').textContent = name;
            document.getElementById('deleteStaffForm').action = <?= json_encode(url('/admin/staff_form.php?id=')) ?> + id;
            document.getElementById('deleteStaffCsrf').value = csrf;
            const modalEl = document.getElementById('deleteStaffModal');
            // When modal is dismissed without submitting, stop any lingering loading overlay
            modalEl.addEventListener('hidden.bs.modal', () => {
                if (typeof window.stopLoading === 'function') window.stopLoading();
                if (document.querySelector('[data-loading-overlay]')) {
                    document.querySelector('[data-loading-overlay]').hidden = true;
                }
            }, { once: true });
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        });
    });
});
</script>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
