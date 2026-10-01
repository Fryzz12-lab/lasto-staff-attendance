USE staff_attendance;
SET FOREIGN_KEY_CHECKS=0;
DELETE FROM attendance_audit_logs;
DELETE FROM attendance_correction_requests;
DELETE FROM leave_requests;
DELETE FROM break_records;
DELETE FROM attendance;
DELETE FROM schedule_assignments;
DELETE FROM users WHERE role='staff';
SET FOREIGN_KEY_CHECKS=1;

INSERT INTO users (employee_id, username, password_hash, full_name, email, phone, job_position, role, joining_date, is_active, weekly_off_day)
VALUES (
  'EMP002',
  'user',
  '$2y$10$p3IDFj/CJ/YIeJ7q7UsgQu4yn2OUmGV2EsbkSGX7CF6IdKeTgYM22',
  'Test User',
  'user@company.local',
  '012-1111111',
  'Sales Associate',
  'staff',
  '2024-03-15',
  1,
  0
);

INSERT INTO schedule_assignments (user_id, schedule_id, effective_from, effective_to, weekly_off_day)
SELECT id, (SELECT MIN(id) FROM work_schedules WHERE name='LASTO Standard Schedule'), COALESCE(joining_date, CURDATE()), NULL, weekly_off_day
FROM users WHERE username='user';

SELECT id, username, role, full_name FROM users;
