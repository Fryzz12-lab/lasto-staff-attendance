<?php
$noticeCount = notification_unread_count((int)$user['id']);
$noticeItems = notification_list((int)$user['id'], false, 5);
?>
<div class="dropdown notification-bell">
    <button class="btn btn-sm btn-outline-secondary notification-bell__button" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= e(__('notifications_title') . ', ' . __('notifications_unread', ['count' => $noticeCount])) ?>">
        <i class="bi bi-bell" aria-hidden="true"></i><?php if ($noticeCount): ?><span class="notification-bell__count"><?= $noticeCount > 99 ? '99+' : $noticeCount ?></span><?php endif; ?>
    </button>
    <div class="dropdown-menu dropdown-menu-end notification-dropdown">
        <div class="notification-dropdown__heading"><strong><?= e(__('notifications_title')) ?></strong><span><?= e(__('notifications_unread', ['count' => $noticeCount])) ?></span></div>
        <?php if (!$noticeItems): ?><p class="notification-empty"><?= e(__('notifications_empty_short')) ?></p><?php endif; ?>
        <?php foreach ($noticeItems as $notice): ?>
        <?php $localized = notification_localized_content($notice); ?>
        <form method="post" action="<?= e(url('/notification_open.php')) ?>" class="notification-dropdown__item<?= $notice['read_at'] ? '' : ' is-unread' ?>">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$notice['id'] ?>">
            <button type="submit" class="notification-open-button"><strong class="notification-title<?= $notice['read_at'] ? '' : ' is-unread' ?>"><?php if (!$notice['read_at']): ?><span class="notification-unread-dot" aria-hidden="true"></span><?php endif; ?><?= e($localized['title']) ?></strong><small><?= e($localized['message']) ?></small><time datetime="<?= e($notice['created_at']) ?>"><?= e(notification_format_date((string)$notice['created_at'])) ?></time></button>
        </form>
        <?php endforeach; ?>
        <a class="notification-dropdown__all" href="<?= e(url('/notifications.php')) ?>"><?= e(__('notifications_view_all')) ?></a>
    </div>
</div>
