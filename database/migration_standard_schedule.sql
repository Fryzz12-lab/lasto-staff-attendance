-- Run once after deployment; safe to repeat. Attendance snapshots are not changed.
USE staff_attendance;
SET time_zone = '+08:00';
START TRANSACTION;
UPDATE work_schedules SET id=id;
INSERT INTO work_schedules (name,start_time,end_time,is_flexible,work_days,target_work_minutes,is_default,is_active,notes)
SELECT 'LASTO Standard Schedule','10:00:00','20:00:00',0,'0,1,2,3,4,5,6',450,1,1,'Company policy; one agreed weekly off day per staff member'
WHERE NOT EXISTS (SELECT 1 FROM work_schedules WHERE name='LASTO Standard Schedule');
SET @standard_schedule = (SELECT MIN(id) FROM work_schedules WHERE name='LASTO Standard Schedule');
SET @effective_date = CURDATE();

-- Split assignments spanning the change date, retaining the earlier segment.
INSERT INTO schedule_assignments (user_id,schedule_id,effective_from,effective_to,weekly_off_day)
SELECT sa.user_id,@standard_schedule,@effective_date,sa.effective_to,COALESCE(sa.weekly_off_day,u.weekly_off_day)
FROM schedule_assignments sa JOIN users u ON u.id=sa.user_id
WHERE u.role='staff' AND sa.schedule_id<>@standard_schedule
AND sa.effective_from<@effective_date AND (sa.effective_to IS NULL OR sa.effective_to>=@effective_date);
UPDATE schedule_assignments sa JOIN users u ON u.id=sa.user_id
SET sa.effective_to=DATE_SUB(@effective_date,INTERVAL 1 DAY)
WHERE u.role='staff' AND sa.schedule_id<>@standard_schedule
AND sa.effective_from<@effective_date AND (sa.effective_to IS NULL OR sa.effective_to>=@effective_date);
UPDATE schedule_assignments sa JOIN users u ON u.id=sa.user_id
SET sa.schedule_id=@standard_schedule
WHERE u.role='staff' AND sa.effective_from>=@effective_date;

-- Fill missing current assignments without overlapping a future assignment.
INSERT INTO schedule_assignments (user_id,schedule_id,effective_from,effective_to,weekly_off_day)
SELECT u.id,@standard_schedule,@effective_date,
  (SELECT DATE_SUB(MIN(sa.effective_from),INTERVAL 1 DAY) FROM schedule_assignments sa WHERE sa.user_id=u.id AND sa.effective_from>@effective_date),
  u.weekly_off_day
FROM users u WHERE u.role='staff' AND NOT EXISTS
  (SELECT 1 FROM schedule_assignments sa WHERE sa.user_id=u.id AND sa.effective_from<=@effective_date AND (sa.effective_to IS NULL OR sa.effective_to>=@effective_date));
UPDATE work_schedules SET is_active=(id=@standard_schedule),is_default=(id=@standard_schedule);
COMMIT;
