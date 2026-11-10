-- Holiday calendar: one row per holiday date, with the branches it applies to (none = every branch).
-- The one source attendance, payroll and the announcement board read. Never deleted: switched off with a reason.

CREATE TABLE IF NOT EXISTS holiday (
  id INT AUTO_INCREMENT PRIMARY KEY,
  day DATE NOT NULL,
  name VARCHAR(80) NOT NULL,
  kind VARCHAR(10) NOT NULL DEFAULT 'holiday',   -- holiday | optional
  branches VARCHAR(400) NOT NULL DEFAULT '',     -- comma separated branch ids; empty = every branch
  active TINYINT(1) NOT NULL DEFAULT 1,
  off_reason VARCHAR(300) NOT NULL DEFAULT '',
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY k_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A person whose holidays follow another branch's calendar (e.g. works from a different office). Only holidays —
-- shift, weekly off and statutory state keep following their own branch.
CREATE TABLE IF NOT EXISTS holiday_branch_override (
  emp_code VARCHAR(32) NOT NULL PRIMARY KEY,
  branch_id INT NOT NULL,
  until_day DATE NULL,
  reason VARCHAR(300) NOT NULL DEFAULT '',
  set_by INT NULL,
  set_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
