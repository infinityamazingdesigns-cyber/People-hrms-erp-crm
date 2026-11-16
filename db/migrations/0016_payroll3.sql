-- Payroll part 3: arrears carried from a locked month to a draft month, and a log of statutory return downloads.
-- Nothing is deleted: an arrear line is taken off with a reason; a locked month's snapshot is never changed.

CREATE TABLE IF NOT EXISTS pay_arrear (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ym CHAR(7) NOT NULL,                       -- the draft month that pays or recovers it
  from_ym CHAR(7) NOT NULL,                  -- the locked month it was worked out for
  from_ver INT NOT NULL,                     -- that month's snapshot version
  emp_code VARCHAR(32) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,             -- + paid, − recovered
  parts TEXT NOT NULL,                       -- JSON part => difference
  note VARCHAR(300) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  off_reason VARCHAR(300) NOT NULL DEFAULT '', off_by INT NULL, off_at DATETIME NULL,
  by_user INT NOT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_ym (ym), KEY k_from (from_ym, from_ver, emp_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pay_return_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ym CHAR(7) NOT NULL, kind VARCHAR(16) NOT NULL,
  lines_out INT NOT NULL DEFAULT 0, left_out INT NOT NULL DEFAULT 0,
  by_user INT NOT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_ym (ym)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
