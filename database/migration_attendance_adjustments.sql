-- Attendance adjustment ledger and historical off-day snapshot.
ALTER TABLE attendance
  ADD COLUMN was_scheduled_off_day TINYINT(1) NOT NULL DEFAULT 0 AFTER is_day_off,
  ADD COLUMN scheduled_off_weekday TINYINT UNSIGNED DEFAULT NULL AFTER was_scheduled_off_day;

UPDATE attendance a
LEFT JOIN schedule_assignments sa ON sa.id = (
  SELECT sa2.id FROM schedule_assignments sa2
  WHERE sa2.user_id = a.user_id AND sa2.effective_from <= a.work_date
    AND (sa2.effective_to IS NULL OR sa2.effective_to >= a.work_date)
  ORDER BY sa2.effective_from DESC, sa2.id DESC LIMIT 1
)
LEFT JOIN users u ON u.id = a.user_id
SET a.was_scheduled_off_day = IF(COALESCE(sa.weekly_off_day, u.weekly_off_day) = DAYOFWEEK(a.work_date) - 1, 1, 0),
    a.scheduled_off_weekday = COALESCE(sa.weekly_off_day, u.weekly_off_day)
WHERE a.was_scheduled_off_day = 0;

-- Apply database/schema.sql after this migration for new installations.

CREATE TABLE IF NOT EXISTS offday_adjustment_reviews (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, employee_id INT UNSIGNED NOT NULL, attendance_id INT UNSIGNED NOT NULL,
  status ENUM('pending','decided','reversed','cancelled') NOT NULL DEFAULT 'pending',
  decision ENUM('replacement_off','settle_cuti_ganti','no_adjustment') DEFAULT NULL, amount DECIMAL(5,2) DEFAULT NULL,
  admin_id INT UNSIGNED DEFAULT NULL, admin_note VARCHAR(500) DEFAULT NULL, decided_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uk_offday_review_attendance (attendance_id), KEY idx_offday_review_status (status),
  CONSTRAINT fk_offday_review_employee FOREIGN KEY (employee_id) REFERENCES users (id),
  CONSTRAINT fk_offday_review_attendance FOREIGN KEY (attendance_id) REFERENCES attendance (id) ON DELETE CASCADE,
  CONSTRAINT fk_offday_review_admin FOREIGN KEY (admin_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leave_replacement_reviews (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, leave_request_id INT UNSIGNED NOT NULL, employee_id INT UNSIGNED NOT NULL,
  status ENUM('active','reversed') NOT NULL DEFAULT 'active', requires_replacement TINYINT(1) NOT NULL,
  amount DECIMAL(5,2) NOT NULL DEFAULT 0.00, admin_id INT UNSIGNED NOT NULL, admin_note VARCHAR(500) DEFAULT NULL,
  reversal_of_id INT UNSIGNED DEFAULT NULL, decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_leave_repl_request (leave_request_id),
  KEY idx_leave_repl_status (status), CONSTRAINT fk_leave_repl_request FOREIGN KEY (leave_request_id) REFERENCES leave_requests (id) ON DELETE CASCADE,
  CONSTRAINT fk_leave_repl_employee FOREIGN KEY (employee_id) REFERENCES users (id), CONSTRAINT fk_leave_repl_admin FOREIGN KEY (admin_id) REFERENCES users (id),
  CONSTRAINT fk_leave_repl_reversal FOREIGN KEY (reversal_of_id) REFERENCES leave_replacement_reviews (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_balance_ledger (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, employee_id INT UNSIGNED NOT NULL,
  balance_type ENUM('replacement_off','cuti_perlu_ganti') NOT NULL, amount DECIMAL(5,2) NOT NULL,
  source_type ENUM('offday_attendance','leave','manual','reversal') NOT NULL, source_id INT UNSIGNED DEFAULT NULL,
  review_id INT UNSIGNED DEFAULT NULL, decision VARCHAR(50) DEFAULT NULL, reversal_of_id INT UNSIGNED DEFAULT NULL,
  admin_id INT UNSIGNED NOT NULL, admin_note VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_balance_employee_type (employee_id,balance_type),
  CONSTRAINT fk_balance_employee FOREIGN KEY (employee_id) REFERENCES users (id), CONSTRAINT fk_balance_admin FOREIGN KEY (admin_id) REFERENCES users (id),
  CONSTRAINT fk_balance_review FOREIGN KEY (review_id) REFERENCES offday_adjustment_reviews (id), CONSTRAINT fk_balance_reversal FOREIGN KEY (reversal_of_id) REFERENCES staff_balance_ledger (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
