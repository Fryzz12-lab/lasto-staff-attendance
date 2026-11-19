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
$pageTitle = __('notifications_title');
$bodyClass = 'notification-center-page';
$isAdminArea = $role === 'admin';
require __DIR__ . '/includes/layout_header.php';
?>
<div class="notification-page">
    <div class="notification-page__heading">
        <div><h1 class="h4 mb-1"><?= e(__('notifications_title')) ?></h1><p class="text-muted mb-0"><?= e(__('notifications_unread', ['count' => $unreadCount])) ?></p></div>
        <?php if ($unreadCount): ?><form method="post" action="<?= e(url('/notifications.php?filter=' . $filter)) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="read_all"><button class="btn btn-sm btn-outline-primary" type="submit"><?= e(__('notifications_mark_all_read')) ?></button></form><?php endif; ?>
    </div>
    <nav class="notification-filters" aria-label="<?= e(__('notifications_filter_label')) ?>">
        <a class="btn btn-sm <?= $filter === 'unread' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(url('/notifications.php?filter=unread')) ?>"><?= e(__('notifications_unread_filter')) ?></a>
        <a class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(url('/notifications.php?filter=all')) ?>"><?= e(__('notifications_all_filter')) ?></a>
    </nav>
    <?php if (!$items): ?><p class="notification-empty"><?= e(__('notifications_empty')) ?></p><?php endif; ?>
    <div class="notification-list">
    <?php foreach ($items as $item): ?>
        <?php $localized = notification_localized_content($item); ?>
        <article class="notification-item<?= $item['read_at'] ? '' : ' is-unread' ?>">
            <div class="notification-item__body">
                <h2 class="h6 mb-1"><form method="post" action="<?= e(url('/notification_open.php')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button type="submit" class="notification-open-button"><span class="notification-title<?= $item['read_at'] ? '' : ' is-unread' ?>"><?php if (!$item['read_at']): ?><span class="notification-unread-dot" aria-hidden="true"></span><?php endif; ?><?= e($localized['title']) ?></span></button></form></h2>
                <p class="mb-1"><?= e($localized['message']) ?></p>
                <time class="text-muted small" datetime="<?= e($item['created_at']) ?>"><?= e(notification_format_date((string)$item['created_at'])) ?></time>
            </div>
            <?php if (!$item['read_at']): ?><form method="post" action="<?= e(url('/notifications.php?filter=' . $filter)) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button class="btn btn-sm btn-outline-secondary" type="submit"><?= e(__('notifications_mark_read')) ?></button></form><?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>
    <?php if (count($items) === 30): ?><a class="btn btn-sm btn-outline-secondary mt-3" href="<?= e(url('/notifications.php?filter=' . $filter . '&page=' . ($page + 1))) ?>"><?= e(__('notifications_next_page')) ?></a><?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
