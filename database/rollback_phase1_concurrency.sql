-- Rollback for Phase 1 Concurrency Migration
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'attendance_correction_requests' AND column_name = 'target_attendance_version'
);
SET @sql = IF(@col_exists > 0, 'ALTER TABLE `attendance_correction_requests` DROP COLUMN `target_attendance_version`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'attendance' AND column_name = 'version'
);
SET @sql = IF(@col_exists > 0, 'ALTER TABLE `attendance` DROP COLUMN `version`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

