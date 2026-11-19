-- =====================================================================
-- Phase 3A Database Migration: Break Discrepancies & Safe Clock-Out Tracking
-- Non-destructive migration for tracking system-closed breaks & provisional hours
-- =====================================================================

ALTER TABLE `break_records`
  ADD COLUMN `is_auto_closed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `exceeded`,
  ADD COLUMN `closure_reason` ENUM('manual','clock_out','midnight_cron') NOT NULL DEFAULT 'manual' AFTER `is_auto_closed`,
  ADD COLUMN `system_closed_at` DATETIME DEFAULT NULL AFTER `closure_reason`,
  ADD KEY `idx_break_auto_closed` (`attendance_id`, `is_auto_closed`);

ALTER TABLE `attendance`
  ADD COLUMN `break_review_status` ENUM('none','under_review','correction_pending','rejected_unresolved','admin_verified','resolved') NOT NULL DEFAULT 'none' AFTER `status`,
  ADD COLUMN `provisional_work_minutes` INT UNSIGNED DEFAULT NULL AFTER `work_minutes`,
  ADD KEY `idx_att_break_review` (`break_review_status`);

