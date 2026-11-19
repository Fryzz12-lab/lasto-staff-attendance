-- Phase 1: Correction Infrastructure Hardening Migration
-- Adds monotonic version tracking to attendance and baseline capture to attendance_correction_requests

SET NAMES utf8mb4;

-- 1. Add version column to attendance if not exists
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'attendance' AND column_name = 'version'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `attendance` ADD COLUMN `version` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `work_minutes`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Add target_attendance_version to attendance_correction_requests if not exists
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'attendance_correction_requests' AND column_name = 'target_attendance_version'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `attendance_correction_requests` ADD COLUMN `target_attendance_version` INT UNSIGNED DEFAULT NULL AFTER `attendance_id`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

