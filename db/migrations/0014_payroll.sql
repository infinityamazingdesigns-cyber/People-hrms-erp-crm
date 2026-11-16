-- Payroll part 1: rules by month, pay profiles, CTC history, month run, snapshots at confirmation, log.
-- Nothing is deleted: rules and CTC are new rows from a month, a reopened month keeps its earlier snapshot (ver).

CREATE TABLE IF NOT EXISTS pay_rule (
  id INT AUTO_INCREMENT PRIMARY KEY,
  from_ym CHAR(7) NOT NULL,
  rules MEDIUMTEXT NOT NULL,                 -- JSON
  reason VARCHAR(300) NOT NULL,
  by_user INT NOT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_from (from_ym)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pay_emp (
  emp_code VARCHAR(32) NOT NULL PRIMARY KEY,
  pf_on TINYINT(1) NOT NULL DEFAULT 1,
  pf_full TINYINT(1) NOT NULL DEFAULT 0,      -- PF on full wages above the ceiling
  esic_on TINYINT(1) NULL,                    -- null = by the ceiling
  pt_exempt TINYINT(1) NOT NULL DEFAULT 0,
  pay_mode VARCHAR(8) NOT NULL DEFAULT 'bank',
  active TINYINT(1) NOT NULL DEFAULT 1, off_reason VARCHAR(300) NOT NULL DEFAULT '',
  updated_by INT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pay_ctc (
  id INT AUTO_INCREMENT PRIMARY KEY,
  emp_code VARCHAR(32) NOT NULL,
  from_ym CHAR(7) NOT NULL,
  ctc DECIMAL(12,2) NOT NULL,                 -- monthly
  note VARCHAR(300) NOT NULL DEFAULT '',
  by_user INT NOT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_code (emp_code, from_ym)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pay_month (
  ym CHAR(7) NOT NULL PRIMARY KEY,
  status VARCHAR(10) NOT NULL DEFAULT 'draft',   -- draft | checked | confirmed | released
  ver INT NOT NULL DEFAULT 0,
  checked_by INT NULL, checked_at DATETIME NULL,
  confirmed_by INT NULL, confirmed_at DATETIME NULL,
  released_by INT NULL, released_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pay_input (
  ym CHAR(7) NOT NULL, emp_code VARCHAR(32) NOT NULL,
  other_earn DECIMAL(12,2) NOT NULL DEFAULT 0, other_ded DECIMAL(12,2) NOT NULL DEFAULT 0, tds DECIMAL(12,2) NOT NULL DEFAULT 0,
  note VARCHAR(300) NOT NULL DEFAULT '',
  hold TINYINT(1) NOT NULL DEFAULT 0, hold_reason VARCHAR(300) NOT NULL DEFAULT '',
  by_user INT NOT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ym, emp_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pay_snapshot (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ym CHAR(7) NOT NULL, ver INT NOT NULL, emp_code VARCHAR(32) NOT NULL,
  data MEDIUMTEXT NOT NULL,                   -- JSON line as confirmed
  UNIQUE KEY u_line (ym, ver, emp_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pay_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ym CHAR(7) NOT NULL, text VARCHAR(300) NOT NULL,
  by_user INT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_ym (ym)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
