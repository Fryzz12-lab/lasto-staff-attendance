-- =====================================================================
-- Phase 3A Database Rollback: Break Discrepancies & Safe Clock-Out Tracking
-- =====================================================================

ALTER TABLE `attendance`
  DROP KEY `idx_att_break_review`,
  DROP COLUMN `provisional_work_minutes`,
  DROP COLUMN `break_review_status`;

ALTER TABLE `break_records`
  DROP KEY `idx_break_auto_closed`,
  DROP COLUMN `system_closed_at`,
  DROP COLUMN `closure_reason`,
  DROP COLUMN `is_auto_closed`;

