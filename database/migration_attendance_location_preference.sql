-- Apply once to databases that already ran the first attendance-location migration.
-- Existing 1 values remain enabled. Old 0 values could mean either the implicit
-- default or an explicit Off choice; the old schema did not record which.
-- Treat all old 0 values as undecided so staff are informed at their next clock action.
ALTER TABLE users MODIFY COLUMN attendance_location_enabled TINYINT(1) DEFAULT NULL COMMENT 'NULL=undecided, 1=enabled, 0=disabled';
START TRANSACTION;
UPDATE users SET attendance_location_enabled=NULL
WHERE attendance_location_enabled=0
  AND NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key='attendance_location_preference_migrated');
INSERT INTO system_settings (setting_key, setting_value, setting_group)
VALUES ('attendance_location_preference_migrated', '1', 'attendance')
ON DUPLICATE KEY UPDATE setting_value=setting_value;
COMMIT;
