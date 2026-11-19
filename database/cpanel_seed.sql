-- cPanel policy seed. Import after cpanel_schema.sql into the SAME selected database.
-- Does not create users. Create the first admin with:
-- php cli/create_admin.php --username=... --name='...' --email=... (password entered via secure prompt)

SET NAMES utf8mb4;

INSERT INTO `work_schedules`
(`name`, `start_time`, `end_time`, `is_flexible`, `work_days`, `target_work_minutes`, `is_default`, `is_active`, `notes`)
SELECT 'LASTO Standard Schedule', '10:00:00', '20:00:00', 0, '0,1,2,3,4,5,6', 450, 1, 1, 'Company policy; one agreed weekly off day per staff member'
WHERE NOT EXISTS (SELECT 1 FROM work_schedules WHERE name='LASTO Standard Schedule');

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
('company_name', 'LASTO TECHNOLOGY', 'general', 'Company display name'),
('default_language', 'en', 'general', 'Default UI language'),
('enable_leave_module', '1', 'modules', 'Enable day off / leave requests'),
('enable_early_leave_calc', '1', 'attendance', 'Calculate early leave minutes'),
('session_timeout_minutes', '480', 'security', 'Idle session timeout');
