-- =====================================================================
-- Phase 3B Database Migration: Staff Break Correction Request Submissions
-- Non-destructive migration to support break correction submissions
-- =====================================================================

ALTER TABLE `attendance_correction_requests`
  MODIFY COLUMN `field_name` ENUM('clock_in','clock_out','both','status','break') NOT NULL,
  ADD COLUMN `break_id` INT UNSIGNED DEFAULT NULL AFTER `requested_clock_out`,
  ADD COLUMN `break_type` ENUM('lunch','solat','short') DEFAULT NULL AFTER `break_id`,
  ADD COLUMN `break_correction_type` ENUM('start','end','both','missing','auto_closed') DEFAULT NULL AFTER `break_type`,
  ADD COLUMN `requested_break_start` DATETIME DEFAULT NULL AFTER `break_correction_type`,
  ADD COLUMN `requested_break_end` DATETIME DEFAULT NULL AFTER `requested_break_start`,
  ADD KEY `idx_corr_break` (`break_id`),
  ADD CONSTRAINT `fk_corr_break` FOREIGN KEY (`break_id`) REFERENCES `break_records` (`id`) ON DELETE SET NULL;

