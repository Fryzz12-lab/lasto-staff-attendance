-- =====================================================================
-- Phase 3B: Durable attendance operation idempotency foundation
-- MariaDB 10.4-compatible, non-destructive, safe to rerun.
--
-- Prerequisites:
--   * users.id, attendance.id, and break_records.id are INT UNSIGNED.
--   * The three parent tables already exist in the selected database.
--   * The caller has verified the target database before execution.
--
-- This migration is intentionally not included in schema.sql and does not
-- select a database. Apply it only to an explicitly selected disposable or
-- deployment database after a backup and schema check.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `attendance_idempotency_operations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `operation` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_hash` BINARY(32) NOT NULL,
  `status` ENUM('processing','succeeded','rejected','expired') NOT NULL DEFAULT 'processing',
  `response_http_status` SMALLINT UNSIGNED DEFAULT NULL,
  `response_body` LONGTEXT DEFAULT NULL,
  `attendance_id` INT UNSIGNED DEFAULT NULL,
  `break_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_idem_user_request` (`user_id`,`request_id`),
  KEY `idx_idem_user_status_expiry` (`user_id`,`status`,`expires_at`),
  KEY `idx_idem_status_expiry` (`status`,`expires_at`),
  CONSTRAINT `fk_idem_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_idem_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_idem_break` FOREIGN KEY (`break_id`) REFERENCES `break_records` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification queries (run separately after the migration):
-- SHOW CREATE TABLE attendance_idempotency_operations;
-- SELECT COUNT(*) FROM attendance_idempotency_operations;
