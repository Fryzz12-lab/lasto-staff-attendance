-- Apply once to an existing staff_attendance database before deploying the new recovery flow.
ALTER TABLE `users`
  ADD COLUMN `email_verified_at` DATETIME DEFAULT NULL AFTER `email`,
  ADD COLUMN `auth_version` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `email_verified_at`,
  ADD UNIQUE KEY `uk_users_email` (`email`);

CREATE TABLE `account_recovery_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `purpose` ENUM('invite','reset','verify_email') NOT NULL,
  `email_delivery` TINYINT(1) NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_recovery_token_hash` (`token_hash`),
  KEY `idx_recovery_user_purpose` (`user_id`, `purpose`, `used_at`),
  CONSTRAINT `fk_recovery_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_recovery_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
