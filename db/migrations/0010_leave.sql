-- Leave: types set by HR, applications (day / half / time permission) reporting manager → HR, withdrawals, and balance
-- adjustments. Nothing is deleted: applications keep every status, types are switched off, adjustments are append-only.

CREATE TABLE IF NOT EXISTS leave_type (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(8) NOT NULL UNIQUE,
  name VARCHAR(60) NOT NULL,
  paid TINYINT(1) NOT NULL DEFAULT 1,
  per_year DECIMAL(5,1) NULL,                -- null = no limit (e.g. without pay)
  prorate TINYINT(1) NOT NULL DEFAULT 1,     -- pro-rated from the date it starts (joining / confirmation) in the year
  after_confirm TINYINT(1) NOT NULL DEFAULT 0,  -- only once confirmed (probation over)
  on_day VARCHAR(12) NOT NULL DEFAULT '',    -- '' | birthday : only on that day
  apply_choice TINYINT(1) NOT NULL DEFAULT 1,   -- the person may ask for it
  sort INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  off_reason VARCHAR(200) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO leave_type (code, name, paid, per_year, prorate, apply_choice, sort) VALUES ('LOP', 'Without pay', 0, NULL, 0, 1, 99);

CREATE TABLE IF NOT EXISTS leave_request (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,                      -- 0 = HR applied for someone without a login
  emp_code VARCHAR(32) NOT NULL,
  branch_id INT NULL,
  from_date DATE NOT NULL, from_session VARCHAR(8) NOT NULL,
  to_date DATE NOT NULL, to_session VARCHAR(8) NOT NULL,
  days DECIMAL(5,1) NOT NULL,
  time_from CHAR(5) NULL, time_to CHAR(5) NULL, minutes SMALLINT NULL,
  req_type VARCHAR(8) NULL,                  -- what the person asked for
  leave_type VARCHAR(8) NULL,                -- HR's final category
  reason VARCHAR(500) NOT NULL,
  handover_user_id INT NULL,
  status VARCHAR(10) NOT NULL,               -- manager | hr | approved | rejected | cancelled | withdrawn
  manager_id INT NULL,
  mgr_by INT NULL, mgr_at DATETIME NULL, mgr_note VARCHAR(500) NOT NULL DEFAULT '', mgr_skipped TINYINT(1) NOT NULL DEFAULT 0,
  hr_by INT NULL, hr_at DATETIME NULL, hr_note VARCHAR(500) NOT NULL DEFAULT '',
  wd_status VARCHAR(10) NULL,                -- manager | hr | rejected | done
  wd_reason VARCHAR(500) NOT NULL DEFAULT '', wd_at DATETIME NULL, wd_by INT NULL, wd_manager_id INT NULL,
  wd_mgr_by INT NULL, wd_mgr_at DATETIME NULL, wd_mgr_note VARCHAR(500) NOT NULL DEFAULT '',
  wd_hr_by INT NULL, wd_hr_at DATETIME NULL, wd_hr_note VARCHAR(500) NOT NULL DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_code (emp_code, from_date), KEY k_status (status), KEY k_mgr (manager_id, status), KEY k_dates (from_date, to_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leave_adjust (
  id INT AUTO_INCREMENT PRIMARY KEY,
  emp_code VARCHAR(32) NOT NULL,
  type_code VARCHAR(8) NOT NULL,
  year_from DATE NOT NULL,                   -- the leave year it belongs to
  days DECIMAL(5,1) NOT NULL,                -- + or −
  note VARCHAR(300) NOT NULL,
  by_user INT NOT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_code (emp_code, year_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
