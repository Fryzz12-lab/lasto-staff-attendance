<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
$user = current_user();
$userId = (int)$user['id'];
$role = (string)$user['role'];
$filter = get('filter') === 'unread' ? 'unread' : 'all';
if (is_post()) {
    require_csrf();
    $action = (string)post('action');
    if ($action === 'read') notification_mark_read($userId, (int)post('id'));
    if ($action === 'read_all') notification_mark_all_read($userId);
    redirect('/notifications.php?filter=' . $filter);
}
$page = max(1, (int)get('page', 1));
$unreadCount = notification_unread_count($userId);
$items = notification_list($userId, $filter === 'unread', 30, ($page - 1) * 30);
$pageTitle = 'Notifications';
$bodyClass = 'notification-center-page';
$isAdminArea = $role === 'admin';
require __DIR__ . '/includes/layout_header.php';
?>
<div class="notification-page">
    <div class="notification-page__heading">
        <div><h1 class="h4 mb-1">Notifications</h1><p class="text-muted mb-0"><?= $unreadCount ?> unread</p></div>
        <?php if ($unreadCount): ?><form method="post" action="<?= e(url('/notifications.php?filter=' . $filter)) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="read_all"><button class="btn btn-sm btn-outline-primary" type="submit">Mark all as read</button></form><?php endif; ?>
    </div>
    <nav class="notification-filters" aria-label="Notification filters">
        <a class="btn btn-sm <?= $filter === 'unread' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(url('/notifications.php?filter=unread')) ?>">Unread</a>
        <a class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(url('/notifications.php?filter=all')) ?>">All</a>
    </nav>
    <?php if (!$items): ?><p class="notification-empty">No notifications here yet.</p><?php endif; ?>
    <div class="notification-list">
    <?php foreach ($items as $item): ?>
        <article class="notification-item<?= $item['read_at'] ? '' : ' is-unread' ?>">
            <div class="notification-item__body">
                <h2 class="h6 mb-1"><form method="post" action="<?= e(url('/notification_open.php')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button type="submit" class="notification-open-button"><?= e($item['title']) ?></button></form></h2>
                <p class="mb-1"><?= e($item['message']) ?></p>
                <time class="text-muted small" datetime="<?= e($item['created_at']) ?>"><?= e($item['created_at']) ?></time>
            </div>
            <?php if (!$item['read_at']): ?><form method="post" action="<?= e(url('/notifications.php?filter=' . $filter)) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button class="btn btn-sm btn-outline-secondary" type="submit">Mark read</button></form><?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>
    <?php if (count($items) === 30): ?><a class="btn btn-sm btn-outline-secondary mt-3" href="<?= e(url('/notifications.php?filter=' . $filter . '&page=' . ($page + 1))) ?>">Next page</a><?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
