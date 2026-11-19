-- ============================================================================
-- cPanel Safe Database Upgrade Script (Phase 6B.4 / H5)
-- AGY Staff Attendance & Workforce Management System
-- LASTO Technology
--
-- This script safely and idempotently updates an existing deployed database
-- to support all features up to Phase 6B.4:
--   1. Multi-Office & Attendance Location Tracking (if not already applied)
--   2. In-App Notification Center & History
--   3. Web Push Notification Subscriptions & Device Preferences
--   4. Persistent Login Rate Limiting & Brute-Force Protection
--   5. Recommended Performance Indexes on Adjustment & Balance Ledgers
--
-- IDEMPOTENT: Safe to run against any existing database. It checks metadata
-- in INFORMATION_SCHEMA before applying any alterations, preventing duplicate
-- table or duplicate column errors.
-- NON-DESTRUCTIVE: Preserves all existing records, primary keys, and relationships.
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 1. Push Notification Subscriptions Table
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `endpoint` TEXT NOT NULL,
  `p256dh` VARCHAR(255) NOT NULL,
  `auth` VARCHAR(255) NOT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `notification_preferences` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_push_user_id` (`user_id`),
  CONSTRAINT `fk_push_subscriptions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. In-App Notifications History Table
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- 3. Persistent Login Rate Limiting Table
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- 4. Attendance Location Events Table
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_location_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `attendance_id` INT UNSIGNED DEFAULT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `event_type` ENUM('clock_in','clock_out') NOT NULL,
  `office_id` INT UNSIGNED DEFAULT NULL,
  `latitude` DECIMAL(10,7) DEFAULT NULL,
  `longitude` DECIMAL(10,7) DEFAULT NULL,
  `accuracy_meters` DECIMAL(8,2) DEFAULT NULL,
  `distance_meters` INT UNSIGNED DEFAULT NULL,
  `device_ip` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `evaluation_status` ENUM('verified_in_range','verified_branch_matched','out_of_range','ip_matched_only','branch_mismatch','unverified_no_coords','error') NOT NULL DEFAULT 'unverified_no_coords',
  `evaluation_message` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ale_att` (`attendance_id`),
  KEY `idx_ale_user` (`user_id`),
  KEY `idx_ale_office` (`office_id`),
  KEY `idx_ale_status` (`evaluation_status`),
  CONSTRAINT `fk_ale_att` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ale_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_ale_office` FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. Safe Column Migrations (Checked via INFORMATION_SCHEMA)
-- ----------------------------------------------------------------------------

-- Add attendance_location_enabled to users if missing
SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'attendance_location_enabled'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `attendance_location_enabled` TINYINT(1) DEFAULT NULL COMMENT "NULL=undecided, 1=enabled, 0=disabled" AFTER `is_active`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add notification_preferences to users if missing
SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'notification_preferences'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `notification_preferences` TEXT DEFAULT NULL COMMENT "JSON map of notification preferences" AFTER `attendance_location_enabled`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add notification_preferences to push_subscriptions if missing
SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_subscriptions' AND COLUMN_NAME = 'notification_preferences'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `push_subscriptions` ADD COLUMN `notification_preferences` TEXT DEFAULT NULL AFTER `user_agent`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 6. Performance Indexes on Adjustment & Balance Ledgers
-- ----------------------------------------------------------------------------
SET @idx_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'offday_adjustment_reviews' AND INDEX_NAME = 'idx_offday_review_employee'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE `offday_adjustment_reviews` ADD KEY `idx_offday_review_employee` (`employee_id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_replacement_reviews' AND INDEX_NAME = 'idx_leave_repl_employee'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE `leave_replacement_reviews` ADD KEY `idx_leave_repl_employee` (`employee_id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_balance_ledger' AND INDEX_NAME = 'idx_balance_source'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE `staff_balance_ledger` ADD KEY `idx_balance_source` (`source_type`, `source_id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 6. Phase 1 Concurrency & Versioning Columns
-- ----------------------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'version'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `attendance` ADD COLUMN `version` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `work_minutes`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_correction_requests' AND COLUMN_NAME = 'target_attendance_version'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `attendance_correction_requests` ADD COLUMN `target_attendance_version` INT UNSIGNED DEFAULT NULL AFTER `attendance_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- ----------------------------------------------------------------------------
-- 7. Phase 2 Correction Requests Late Submissions
-- ----------------------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_correction_requests' AND COLUMN_NAME = 'is_late_submission'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `attendance_correction_requests` ADD COLUMN `is_late_submission` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `status`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_correction_requests' AND COLUMN_NAME = 'late_reason'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `attendance_correction_requests` ADD COLUMN `late_reason` TEXT DEFAULT NULL AFTER `is_late_submission`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 8. Phase 3A Break Discrepancies & Safe Clock-Out Tracking
-- ----------------------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'break_records' AND COLUMN_NAME = 'is_auto_closed'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `break_records` ADD COLUMN `is_auto_closed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `exceeded`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'break_records' AND COLUMN_NAME = 'closure_reason'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `break_records` ADD COLUMN `closure_reason` ENUM(\'manual\',\'clock_out\',\'midnight_cron\') NOT NULL DEFAULT \'manual\' AFTER `is_auto_closed`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'break_records' AND COLUMN_NAME = 'system_closed_at'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `break_records` ADD COLUMN `system_closed_at` DATETIME DEFAULT NULL AFTER `closure_reason`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'break_records' AND INDEX_NAME = 'idx_break_auto_closed'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE `break_records` ADD KEY `idx_break_auto_closed` (`attendance_id`, `is_auto_closed`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'break_review_status'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `attendance` ADD COLUMN `break_review_status` ENUM(\'none\',\'under_review\',\'correction_pending\',\'rejected_unresolved\',\'admin_verified\',\'resolved\') NOT NULL DEFAULT \'none\' AFTER `status`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'provisional_work_minutes'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `attendance` ADD COLUMN `provisional_work_minutes` INT UNSIGNED DEFAULT NULL AFTER `work_minutes`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND INDEX_NAME = 'idx_att_break_review'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE `attendance` ADD KEY `idx_att_break_review` (`break_review_status`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 9. Phase 3B Staff Break Correction Request Submissions
-- ----------------------------------------------------------------------------
ALTER TABLE `attendance_correction_requests`
  MODIFY COLUMN `field_name` ENUM('clock_in','clock_out','both','status','break') NOT NULL;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_correction_requests' AND COLUMN_NAME = 'break_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `attendance_correction_requests` ADD COLUMN `break_id` INT UNSIGNED DEFAULT NULL AFTER `requested_clock_out`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_correction_requests' AND COLUMN_NAME = 'break_type'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `attendance_correction_requests` ADD COLUMN `break_type` ENUM(\'lunch\',\'solat\',\'short\') DEFAULT NULL AFTER `break_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_correction_requests' AND COLUMN_NAME = 'break_correction_type'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `attendance_correction_requests` ADD COLUMN `break_correction_type` ENUM(\'start\',\'end\',\'both\',\'missing\',\'auto_closed\') DEFAULT NULL AFTER `break_type`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_correction_requests' AND COLUMN_NAME = 'requested_break_start'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `attendance_correction_requests` ADD COLUMN `requested_break_start` DATETIME DEFAULT NULL AFTER `break_correction_type`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_correction_requests' AND COLUMN_NAME = 'requested_break_end'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `attendance_correction_requests` ADD COLUMN `requested_break_end` DATETIME DEFAULT NULL AFTER `requested_break_start`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_correction_requests' AND INDEX_NAME = 'idx_corr_break'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE `attendance_correction_requests` ADD KEY `idx_corr_break` (`break_id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_correction_requests' AND CONSTRAINT_NAME = 'fk_corr_break'
);
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE `attendance_correction_requests` ADD CONSTRAINT `fk_corr_break` FOREIGN KEY (`break_id`) REFERENCES `break_records` (`id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
