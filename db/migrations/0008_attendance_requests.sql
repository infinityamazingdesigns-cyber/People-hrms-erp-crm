-- Attendance (part 2): correction requests (reporting manager → HR), month close for payroll.
-- Requests are never deleted (withdrawn / rejected keep their row); a closed month keeps its history on reopen.

CREATE TABLE IF NOT EXISTS attendance_request (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,                  -- who it is for (the login), 0 when HR adds for someone without a login
  emp_code VARCHAR(32) NOT NULL,
  branch_id INT NULL,
  day DATE NOT NULL,
  in_time CHAR(5) NULL,
  out_time CHAR(5) NULL,
  reason VARCHAR(20) NOT NULL,
  note VARCHAR(300) NOT NULL DEFAULT '',
  status VARCHAR(10) NOT NULL,           -- manager | hr | approved | rejected | withdrawn
  manager_id INT NULL,                   -- the manager's login when it went to them
  mgr_by INT NULL, mgr_at DATETIME NULL, mgr_note VARCHAR(300) NOT NULL DEFAULT '', mgr_skipped TINYINT(1) NOT NULL DEFAULT 0,
  hr_by INT NULL, hr_at DATETIME NULL, hr_note VARCHAR(300) NOT NULL DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_status (status), KEY k_day (day), KEY k_code (emp_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance_month_close (
  ym CHAR(7) NOT NULL PRIMARY KEY,
  closed_by INT NULL, closed_at DATETIME NULL, close_note VARCHAR(300) NOT NULL DEFAULT '',
  reopened_by INT NULL, reopened_at DATETIME NULL, reopen_note VARCHAR(300) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
