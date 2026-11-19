-- Phase 2: Staff Clock-In/Clock-Out Attendance Correction Requests
-- Non-destructive migration to track late submissions and late explanation

ALTER TABLE `attendance_correction_requests`
  ADD COLUMN `is_late_submission` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `status`,
  ADD COLUMN `late_reason` TEXT DEFAULT NULL AFTER `is_late_submission`;

