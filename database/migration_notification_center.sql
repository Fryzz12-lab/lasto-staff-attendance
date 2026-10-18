-- Additive Phase 2 migration. Run before deploying notification-center PHP code.
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `event_type` VARCHAR(48) NOT NULL,
  `event_key` VARCHAR(100) NOT NULL,
  `title` VARCHAR(160) NOT NULL,
  `message` VARCHAR(500) NOT NULL,
  `destination` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notification_event` (`user_id`, `event_key`),
  KEY `idx_notification_inbox` (`user_id`, `read_at`, `created_at`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @device_pref_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_subscriptions' AND COLUMN_NAME = 'notification_preferences'
);
SET @device_pref_sql = IF(@device_pref_exists = 0,
  'ALTER TABLE `push_subscriptions` ADD COLUMN `notification_preferences` TEXT DEFAULT NULL AFTER `user_agent`',
  'SELECT 1');
PREPARE device_pref_stmt FROM @device_pref_sql;
EXECUTE device_pref_stmt;
DEALLOCATE PREPARE device_pref_stmt;
