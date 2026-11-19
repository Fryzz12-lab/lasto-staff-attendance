-- =====================================================================
-- Phase 3B Database Rollback: Staff Break Correction Request Submissions
-- =====================================================================

ALTER TABLE `attendance_correction_requests`
  DROP FOREIGN KEY `fk_corr_break`,
  DROP KEY `idx_corr_break`,
  DROP COLUMN `requested_break_end`,
  DROP COLUMN `requested_break_start`,
  DROP COLUMN `break_correction_type`,
  DROP COLUMN `break_type`,
  DROP COLUMN `break_id`,
  MODIFY COLUMN `field_name` ENUM('clock_in','clock_out','both','status') NOT NULL;

