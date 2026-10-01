-- Apply once when upgrading an existing installation. No attendance history is deleted.
USE staff_attendance;
START TRANSACTION;
UPDATE work_schedules SET start_time='10:00:00', end_time='20:00:00',
    target_work_minutes=450, is_flexible=0, work_days='0,1,2,3,4,5,6';
UPDATE work_schedules SET name='Standard Shift', notes='Fixed company hours; individual weekly off day'
    WHERE name='Flexible Shift';
DELETE FROM system_settings WHERE setting_key='break_pool_total_minutes';
DELETE FROM system_settings WHERE setting_key IN (
    'late_grace_minutes','enable_other_break','lunch_allowed_minutes','solat_allowed_minutes',
    'solat_max_count','short_allowed_minutes','short_max_count','other_allowed_minutes',
    'break_pool_total_minutes','allow_staff_edit_email','allow_staff_edit_phone',
    'allow_staff_edit_photo','timezone','enable_totp_password_reset'
);
-- In-progress sessions use the new per-session allowance; completed history stays intact.
UPDATE break_records SET allowed_minutes=CASE break_type
    WHEN 'lunch' THEN 60 WHEN 'solat' THEN 20 WHEN 'short' THEN 10 END
WHERE end_time IS NULL AND break_type IN ('lunch','solat','short');
COMMIT;
