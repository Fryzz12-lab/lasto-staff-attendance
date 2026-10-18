-- Migration: Multi-Branch & Office Locations Management
-- Applies to MySQL / MariaDB

SET NAMES utf8mb4;

-- 1. Create offices table
CREATE TABLE IF NOT EXISTS `offices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `code` VARCHAR(32) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `latitude` DECIMAL(10,7) DEFAULT NULL,
  `longitude` DECIMAL(10,7) DEFAULT NULL,
  `radius_meters` INT UNSIGNED NOT NULL DEFAULT 100,
  `public_ip` VARCHAR(45) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_offices_name` (`name`),
  KEY `idx_offices_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Add office_id to users if not present
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'office_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `users` ADD COLUMN `office_id` INT UNSIGNED DEFAULT NULL AFTER `job_position`, ADD CONSTRAINT `fk_users_office` FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Add office_id and distance_metres to attendance_location_events if not present
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'attendance_location_events' AND column_name = 'office_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `attendance_location_events` ADD COLUMN `office_id` INT UNSIGNED DEFAULT NULL AFTER `user_id`, ADD CONSTRAINT `fk_location_office` FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'attendance_location_events' AND column_name = 'distance_metres');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `attendance_location_events` ADD COLUMN `distance_metres` DECIMAL(10,2) DEFAULT NULL AFTER `office_proximity`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Initial seed & migration from system_settings if offices table is empty
INSERT INTO `offices` (`name`, `code`, `latitude`, `longitude`, `radius_meters`, `public_ip`, `is_active`)
SELECT 
  COALESCE(NULLIF((SELECT setting_value FROM system_settings WHERE setting_key = 'office_name' LIMIT 1), ''), 'LASTO HQ') AS `name`,
  'HQ' AS `code`,
  NULLIF((SELECT setting_value FROM system_settings WHERE setting_key = 'office_latitude' LIMIT 1), '') AS `latitude`,
  NULLIF((SELECT setting_value FROM system_settings WHERE setting_key = 'office_longitude' LIMIT 1), '') AS `longitude`,
  COALESCE(NULLIF((SELECT setting_value FROM system_settings WHERE setting_key = 'office_radius_metres' LIMIT 1), ''), 100) AS `radius_meters`,
  NULLIF((SELECT setting_value FROM system_settings WHERE setting_key = 'office_public_ip' LIMIT 1), '') AS `public_ip`,
  1 AS `is_active`
WHERE NOT EXISTS (SELECT 1 FROM `offices` LIMIT 1);

-- 5. Link existing users to the primary office if unassigned
UPDATE `users` SET `office_id` = (SELECT `id` FROM `offices` ORDER BY `id` ASC LIMIT 1) WHERE `office_id` IS NULL;
