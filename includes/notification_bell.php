<?php
$noticeCount = notification_unread_count((int)$user['id']);
$noticeItems = notification_list((int)$user['id'], false, 5);
?>
<div class="dropdown notification-bell">
    <button class="btn btn-sm btn-outline-secondary notification-bell__button" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications, <?= $noticeCount ?> unread">
        <i class="bi bi-bell" aria-hidden="true"></i><?php if ($noticeCount): ?><span class="notification-bell__count"><?= $noticeCount > 99 ? '99+' : $noticeCount ?></span><?php endif; ?>
    </button>
    <div class="dropdown-menu dropdown-menu-end notification-dropdown">
        <div class="notification-dropdown__heading"><strong>Notifications</strong><span><?= $noticeCount ?> unread</span></div>
        <?php if (!$noticeItems): ?><p class="notification-empty">No notifications yet.</p><?php endif; ?>
        <?php foreach ($noticeItems as $notice): ?>
        <form method="post" action="<?= e(url('/notification_open.php')) ?>" class="notification-dropdown__item<?= $notice['read_at'] ? '' : ' is-unread' ?>">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$notice['id'] ?>">
            <button type="submit" class="notification-open-button"><strong><?= e($notice['title']) ?></strong><small><?= e($notice['message']) ?></small><time><?= e($notice['created_at']) ?></time></button>
        </form>
        <?php endforeach; ?>
        <a class="notification-dropdown__all" href="<?= e(url('/notifications.php')) ?>">View all notifications</a>
    </div>
</div>
