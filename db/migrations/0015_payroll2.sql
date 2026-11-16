-- Payroll part 2: loans and advances with recoveries, payslip PIN, bank file log.
-- Nothing is deleted: a loan is closed with a reason; recoveries belong to a month's snapshot version (a reopened
-- month's earlier recoveries stay, the newer version counts); PIN changes keep a log without the PIN.

CREATE TABLE IF NOT EXISTS pay_loan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  emp_code VARCHAR(32) NOT NULL,
  kind VARCHAR(8) NOT NULL DEFAULT 'loan',     -- loan | advance
  amount DECIMAL(12,2) NOT NULL,
  emi DECIMAL(12,2) NOT NULL,
  from_ym CHAR(7) NOT NULL,
  note VARCHAR(300) NOT NULL DEFAULT '',
  closed_at DATETIME NULL, closed_by INT NULL, close_reason VARCHAR(300) NOT NULL DEFAULT '',
  by_user INT NOT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_code (emp_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pay_loan_rec (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loan_id INT NOT NULL, ym CHAR(7) NOT NULL, ver INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  UNIQUE KEY u_rec (loan_id, ym, ver)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pay_pin (
  user_id INT NOT NULL PRIMARY KEY,
  pin_hash VARCHAR(255) NOT NULL,
  wrong TINYINT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  set_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pay_pin_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL, what VARCHAR(12) NOT NULL,   -- set | wrong | locked | reset
  by_user INT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
