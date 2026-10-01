-- Seed / demo data for local development
-- WARNING: Change default passwords before production!

USE `staff_attendance`;

-- Default schedule: 10:00 AM to 8:00 PM; each employee has one arranged off day.
INSERT INTO `work_schedules`
(`name`, `start_time`, `end_time`, `is_flexible`, `work_days`, `target_work_minutes`, `is_default`, `is_active`, `notes`)
SELECT 'LASTO Standard Schedule', '10:00:00', '20:00:00', 0, '0,1,2,3,4,5,6', 450, 1, 1, 'Company policy; one agreed weekly off day per staff member'
WHERE NOT EXISTS (SELECT 1 FROM work_schedules WHERE name='LASTO Standard Schedule');

-- System settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
('company_name', 'LASTO TECHNOLOGY', 'general', 'Company display name'),
('default_language', 'en', 'general', 'Default UI language'),
('enable_leave_module', '1', 'modules', 'Enable day off / leave requests'),
('enable_early_leave_calc', '1', 'attendance', 'Calculate early leave minutes'),
('session_timeout_minutes', '480', 'security', 'Idle session timeout');

-- Users (only admin + one test staff)
-- Admin: admin / Admin@123
-- Staff: user / Staff@123
INSERT INTO `users`
(`employee_id`, `username`, `password_hash`, `full_name`, `email`, `phone`, `job_position`, `role`, `joining_date`, `is_active`, `weekly_off_day`)
VALUES
('EMP001', 'admin', '$2y$10$bJHVU.0AnRDOx3ysrpYKX.HkUVrQ5A9L6aL6/8Mh8Y3Cti4rAJwIS', 'System Administrator', 'admin@company.local', '012-0000001', 'Manager', 'admin', '2024-01-01', 1, 0),
('EMP002', 'user', '$2y$10$p3IDFj/CJ/YIeJ7q7UsgQu4yn2OUmGV2EsbkSGX7CF6IdKeTgYM22', 'Test User', 'user@company.local', '012-1111111', 'Sales Associate', 'staff', '2024-03-15', 1, 0);

-- Assign default schedule to staff
INSERT INTO `schedule_assignments` (`user_id`, `schedule_id`, `effective_from`, `effective_to`, `weekly_off_day`)
SELECT u.id, (SELECT MIN(id) FROM work_schedules WHERE name='LASTO Standard Schedule'), COALESCE(u.joining_date, CURDATE()), NULL, u.weekly_off_day
FROM `users` u
WHERE u.role = 'staff' AND NOT EXISTS (SELECT 1 FROM schedule_assignments sa WHERE sa.user_id=u.id);
