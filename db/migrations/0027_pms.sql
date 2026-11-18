-- Performance (KRA): role sheets (weighted KRAs, each with KPIs, as JSON) with every earlier version kept, holders
-- (employee code + share + from month), one evaluation per person, sheet and review period (the sheet as it was is
-- kept with it), requests for a sheet, and an append-only log (ids, steps — never scores or comments).

CREATE TABLE IF NOT EXISTS pms_sheet (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,
  title VARCHAR(120) NOT NULL,
  department VARCHAR(80) NOT NULL DEFAULT '',
  grade VARCHAR(20) NOT NULL DEFAULT '',
  summary TEXT NOT NULL,
  body MEDIUMTEXT NOT NULL,                 -- JSON: kras [{name, weight, kpis [{id, name, measure, unit, target, polarity, w}]}]
  version INT NOT NULL DEFAULT 1,
  status VARCHAR(8) NOT NULL DEFAULT 'draft',   -- draft | active | retired
  created_by INT NOT NULL, updated_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pms_sheet_rev (
  id INT AUTO_INCREMENT PRIMARY KEY, sheet_id INT NOT NULL, version INT NOT NULL, body MEDIUMTEXT NOT NULL,
  by_user INT NOT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_sheet (sheet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pms_assign (
  id INT AUTO_INCREMENT PRIMARY KEY, sheet_id INT NOT NULL, emp_code VARCHAR(32) NOT NULL,
  share TINYINT NOT NULL DEFAULT 100, from_ym CHAR(7) NOT NULL,
  created_by INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  removed_at DATETIME NULL, removed_by INT NULL,
  KEY k_emp (emp_code), KEY k_sheet (sheet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pms_eval (
  id INT AUTO_INCREMENT PRIMARY KEY,
  emp_code VARCHAR(32) NOT NULL, sheet_id INT NOT NULL, period VARCHAR(12) NOT NULL,
  body MEDIUMTEXT NOT NULL, version INT NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'draft',  -- draft | submitted | returned | reviewed | closed
  self TEXT NULL, self_note TEXT NULL, self_at DATETIME NULL, self_score DECIMAL(5,1) NULL,
  mgr_code VARCHAR(32) NOT NULL DEFAULT '',
  mgr TEXT NULL, mgr_note TEXT NULL, mgr_by INT NULL, mgr_at DATETIME NULL, mgr_score DECIMAL(5,1) NULL,
  hr_note TEXT NULL, hr_by INT NULL, hr_at DATETIME NULL,
  return_note VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_one (emp_code, sheet_id, period), KEY k_status (period, status), KEY k_mgr (mgr_code, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pms_request (
  id INT AUTO_INCREMENT PRIMARY KEY, emp_code VARCHAR(32) NOT NULL, user_id INT NOT NULL, note VARCHAR(300) NOT NULL DEFAULT '',
  status VARCHAR(6) NOT NULL DEFAULT 'open', closed_by INT NULL, closed_at DATETIME NULL, close_note VARCHAR(300) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pms_log (
  id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(8) NOT NULL, ref_id INT NOT NULL, action VARCHAR(30) NOT NULL,
  user_id INT NOT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_ref (kind, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
