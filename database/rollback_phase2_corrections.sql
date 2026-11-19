-- Rollback Phase 2: Staff Clock-In/Clock-Out Attendance Correction Requests

ALTER TABLE `attendance_correction_requests`
  DROP COLUMN `late_reason`,
  DROP COLUMN `is_late_submission`;

