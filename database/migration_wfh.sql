USE staff_attendance;

ALTER TABLE leave_requests
  MODIFY leave_type ENUM('day_off','annual','emergency','medical','wfh','other') NOT NULL DEFAULT 'day_off';

ALTER TABLE attendance
  MODIFY status ENUM(
    'not_clocked_in','present','late','on_break','completed','absent','day_off','wfh','incomplete'
  ) NOT NULL DEFAULT 'not_clocked_in';

SHOW COLUMNS FROM leave_requests LIKE 'leave_type';
SHOW COLUMNS FROM attendance LIKE 'status';
