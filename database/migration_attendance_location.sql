-- Apply once to an existing database before deploying this feature.
ALTER TABLE users ADD COLUMN attendance_location_enabled TINYINT(1) DEFAULT NULL COMMENT 'NULL=undecided, 1=enabled, 0=disabled';

CREATE TABLE attendance_location_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attendance_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  event_type ENUM('clock_in','clock_out','wfh_start','wfh_end') NOT NULL,
  event_time DATETIME NOT NULL,
  latitude DECIMAL(10,7) DEFAULT NULL,
  longitude DECIMAL(10,7) DEFAULT NULL,
  accuracy_metres DECIMAL(10,2) DEFAULT NULL,
  location_status ENUM('captured','low_accuracy','permission_denied','timed_out','unavailable','unsupported','sharing_disabled') NOT NULL,
  client_ip VARCHAR(45) DEFAULT NULL,
  office_proximity ENUM('within','outside') DEFAULT NULL,
  office_network ENUM('office','other','unknown') NOT NULL DEFAULT 'unknown',
  capture_method VARCHAR(32) DEFAULT NULL,
  location_finalized_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_attendance_location_event (attendance_id, event_type),
  KEY idx_location_user (user_id),
  CONSTRAINT fk_location_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id),
  CONSTRAINT fk_location_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
