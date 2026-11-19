-- Migration: Persistent Login Rate Limiting & Brute-Force Protection
-- Staff Attendance & Workforce Management System

CREATE TABLE IF NOT EXISTS `login_rate_limits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rate_key` VARCHAR(191) NOT NULL COMMENT 'Unique lookup key e.g. user_ip:<hash>:<ip> or ip:<ip>',
  `identifier_type` ENUM('ip', 'account', 'ip_account') NOT NULL COMMENT 'Scope of rate limit entry',
  `identifier` VARCHAR(128) NOT NULL COMMENT 'Raw identifier e.g. IP address or normalized username',
  `failed_attempts` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Count of consecutive failed attempts in current window',
  `first_failed_at` DATETIME NOT NULL COMMENT 'Timestamp of first failure in current window',
  `last_failed_at` DATETIME NOT NULL COMMENT 'Timestamp of most recent failure',
  `throttled_until` DATETIME DEFAULT NULL COMMENT 'Timestamp when temporary throttle restriction expires',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rate_key` (`rate_key`),
  KEY `idx_throttled_until` (`throttled_until`),
  KEY `idx_identifier` (`identifier_type`, `identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

