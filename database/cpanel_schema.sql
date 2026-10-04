-- cPanel import only.
-- 1. In cPanel → MySQL Databases, create a NEW empty database (example: lgsubsc1_attend).
-- 2. Create a dedicated user, grant ALL PRIVILEGES on that database only.
-- 3. In phpMyAdmin, click that database name so it is selected.
-- 4. Import this file. Do not import database/schema.sql (it creates `staff_attendance`).
-- 5. Then import database/cpanel_seed.sql.
-- Fresh, EMPTY database only. Existing tables cause an error instead of being deleted.

SET NAMES utf8mb4;

CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` VARCHAR(32) NOT NULL,
  `username` VARCHAR(64) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(120) DEFAULT NULL,
  `email_verified_at` DATETIME DEFAULT NULL,
  `auth_version` INT UNSIGNED NOT NULL DEFAULT 0,
  `phone` VARCHAR(30) DEFAULT NULL,
  `job_position` VARCHAR(100) DEFAULT NULL,
  `role` ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  `joining_date` DATE DEFAULT NULL,
  `employment_end_date` DATE DEFAULT NULL COMMENT 'Last working day (inclusive). NULL = still employed.',
  `profile_photo` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `weekly_off_day` TINYINT UNSIGNED DEFAULT 0 COMMENT '0=Sun ... 6=Sat',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_employee_id` (`employee_id`),
  UNIQUE KEY `uk_users_username` (`username`),
  UNIQUE KEY `uk_users_email` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_active` (`is_active`),
  KEY `idx_users_name` (`full_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_security_questions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `question_key` VARCHAR(64) NOT NULL,
  `answer_hash` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_question` (`user_id`, `question_key`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_security_q_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE `auth_remember_tokens` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `selector` VARCHAR(32) NOT NULL,
  `token_hash` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_remember_selector` (`selector`),
  KEY `idx_remember_user` (`user_id`),
  CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `work_schedules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `is_flexible` TINYINT(1) NOT NULL DEFAULT 0,
  `work_days` VARCHAR(20) NOT NULL DEFAULT '0,1,2,3,4,5,6' COMMENT 'Comma list 0-6',
  `target_work_minutes` INT UNSIGNED NOT NULL DEFAULT 450 COMMENT '7h30m = 450',
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_schedules_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `schedule_assignments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `schedule_id` INT UNSIGNED NOT NULL,
  `effective_from` DATE NOT NULL,
  `effective_to` DATE DEFAULT NULL,
  `weekly_off_day` TINYINT UNSIGNED DEFAULT NULL COMMENT 'Override; null = use user default',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sa_user_dates` (`user_id`, `effective_from`, `effective_to`),
  CONSTRAINT `fk_sa_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_sa_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `work_schedules` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attendance` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `work_date` DATE NOT NULL,
  `schedule_id` INT UNSIGNED DEFAULT NULL,
  `scheduled_start` TIME DEFAULT NULL,
  `scheduled_end` TIME DEFAULT NULL,
  `is_day_off` TINYINT(1) NOT NULL DEFAULT 0,
  `was_scheduled_off_day` TINYINT(1) NOT NULL DEFAULT 0,
  `scheduled_off_weekday` TINYINT UNSIGNED DEFAULT NULL,
  `is_wfh` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Approved work arrangement, independent of attendance status',
  `clock_in` DATETIME DEFAULT NULL,
  `clock_out` DATETIME DEFAULT NULL,
  `status` ENUM(
    'not_clocked_in','present','late','on_break','completed','absent','day_off','wfh','incomplete'
  ) NOT NULL DEFAULT 'not_clocked_in',
  `late_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
  `early_leave_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_break_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
  `shift_minutes` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ClockOut - ClockIn',
  `work_minutes` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Shift - breaks',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_attendance_user_date` (`user_id`, `work_date`),
  KEY `idx_attendance_date` (`work_date`),
  KEY `idx_attendance_status` (`status`),
  CONSTRAINT `fk_att_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_att_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `work_schedules` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `break_records` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `attendance_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `break_type` ENUM('lunch','solat','short','other') NOT NULL,
  `sequence_no` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `start_time` DATETIME NOT NULL,
  `end_time` DATETIME DEFAULT NULL,
  `duration_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
  `allowed_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
  `exceeded` TINYINT(1) NOT NULL DEFAULT 0,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_break_att` (`attendance_id`),
  KEY `idx_break_user` (`user_id`),
  KEY `idx_break_type` (`break_type`),
  KEY `idx_break_open` (`user_id`, `end_time`),
  CONSTRAINT `fk_break_att` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_break_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `leave_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `leave_date` DATE NOT NULL,
  `leave_type` ENUM('day_off','annual','emergency','medical','wfh','other') NOT NULL DEFAULT 'day_off',
  `reason` TEXT NOT NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_note` VARCHAR(500) DEFAULT NULL,
  `reviewed_by` INT UNSIGNED DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_leave_user` (`user_id`),
  KEY `idx_leave_status` (`status`),
  KEY `idx_leave_date` (`leave_date`),
  CONSTRAINT `fk_leave_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_leave_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `offday_adjustment_reviews` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `employee_id` INT UNSIGNED NOT NULL,
  `attendance_id` INT UNSIGNED NOT NULL, `status` ENUM('pending','decided','reversed','cancelled') NOT NULL DEFAULT 'pending',
  `decision` ENUM('replacement_off','settle_cuti_ganti','no_adjustment') DEFAULT NULL,
  `amount` TINYINT UNSIGNED NOT NULL DEFAULT 0, `admin_id` INT UNSIGNED DEFAULT NULL,
  `admin_note` VARCHAR(500) DEFAULT NULL, `decided_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_offday_review_attendance` (`attendance_id`), KEY `idx_offday_review_status` (`status`),
  CONSTRAINT `fk_offday_review_employee` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_offday_review_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_offday_review_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `leave_replacement_reviews` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `leave_request_id` INT UNSIGNED NOT NULL,
  `employee_id` INT UNSIGNED NOT NULL, `status` ENUM('active','reversed') NOT NULL DEFAULT 'active',
  `requires_replacement` TINYINT(1) NOT NULL, `amount` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `admin_id` INT UNSIGNED NOT NULL, `admin_note` VARCHAR(500) DEFAULT NULL,
  `reversal_of_id` INT UNSIGNED DEFAULT NULL, `decided_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`),
  KEY `idx_leave_repl_request` (`leave_request_id`), KEY `idx_leave_repl_status` (`status`),
  CONSTRAINT `fk_leave_repl_request` FOREIGN KEY (`leave_request_id`) REFERENCES `leave_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_leave_repl_employee` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_leave_repl_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_leave_repl_reversal` FOREIGN KEY (`reversal_of_id`) REFERENCES `leave_replacement_reviews` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `staff_balance_ledger` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `employee_id` INT UNSIGNED NOT NULL,
  `balance_type` ENUM('replacement_off','cuti_perlu_ganti') NOT NULL, `amount` SMALLINT NOT NULL,
  `source_type` ENUM('offday_attendance','leave','manual','reversal') NOT NULL, `source_id` INT UNSIGNED DEFAULT NULL,
  `review_id` INT UNSIGNED DEFAULT NULL, `decision` VARCHAR(50) DEFAULT NULL, `reversal_of_id` INT UNSIGNED DEFAULT NULL,
  `admin_id` INT UNSIGNED NOT NULL, `admin_note` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`), KEY `idx_balance_employee_type` (`employee_id`,`balance_type`),
  CONSTRAINT `fk_balance_employee` FOREIGN KEY (`employee_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_balance_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_balance_review` FOREIGN KEY (`review_id`) REFERENCES `offday_adjustment_reviews` (`id`),
  CONSTRAINT `fk_balance_reversal` FOREIGN KEY (`reversal_of_id`) REFERENCES `staff_balance_ledger` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attendance_correction_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `attendance_id` INT UNSIGNED DEFAULT NULL,
  `work_date` DATE NOT NULL,
  `field_name` ENUM('clock_in','clock_out','both','status') NOT NULL,
  `requested_clock_in` DATETIME DEFAULT NULL,
  `requested_clock_out` DATETIME DEFAULT NULL,
  `reason` TEXT NOT NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_note` VARCHAR(500) DEFAULT NULL,
  `reviewed_by` INT UNSIGNED DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_corr_user` (`user_id`),
  KEY `idx_corr_status` (`status`),
  KEY `idx_corr_date` (`work_date`),
  CONSTRAINT `fk_corr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_corr_att` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_corr_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attendance_audit_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `attendance_id` INT UNSIGNED DEFAULT NULL,
  `user_id` INT UNSIGNED NOT NULL COMMENT 'Affected employee',
  `changed_by` INT UNSIGNED NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `field_name` VARCHAR(50) DEFAULT NULL,
  `old_value` TEXT DEFAULT NULL,
  `new_value` TEXT DEFAULT NULL,
  `reason` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_att` (`attendance_id`),
  KEY `idx_audit_user` (`user_id`),
  CONSTRAINT `fk_audit_att` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_audit_changer` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `system_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NOT NULL,
  `setting_group` VARCHAR(50) NOT NULL DEFAULT 'general',
  `description` VARCHAR(255) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
