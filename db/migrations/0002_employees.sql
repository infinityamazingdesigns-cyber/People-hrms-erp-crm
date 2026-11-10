-- Employee master: one record per person. Records are never deleted — a person leaves through a last working day,
-- a wrong record is switched off with a reason. Every field change is kept in employee_log (append-only).

CREATE TABLE IF NOT EXISTS employee (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(32) NOT NULL,
  name VARCHAR(120) NOT NULL,
  gender VARCHAR(16) NOT NULL DEFAULT '',
  dob DATE NULL,
  department VARCHAR(80) NOT NULL DEFAULT '',
  designation VARCHAR(80) NOT NULL DEFAULT '',
  grade VARCHAR(20) NOT NULL DEFAULT '',
  category VARCHAR(30) NOT NULL DEFAULT '',
  branch_id INT NULL,
  manager_code VARCHAR(32) NOT NULL DEFAULT '',
  doj DATE NULL,
  confirm_date DATE NULL,
  resign_date DATE NULL,
  last_day DATE NULL,
  exit_type VARCHAR(30) NOT NULL DEFAULT '',
  exit_reason VARCHAR(300) NOT NULL DEFAULT '',
  work_email VARCHAR(160) NOT NULL DEFAULT '',
  work_phone VARCHAR(30) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  off_reason VARCHAR(300) NOT NULL DEFAULT '',
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_code (code),
  KEY k_branch (branch_id), KEY k_mgr (manager_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employee_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  emp_id INT NOT NULL,
  field VARCHAR(40) NOT NULL,
  old_value VARCHAR(400) NOT NULL DEFAULT '',
  new_value VARCHAR(400) NOT NULL DEFAULT '',
  kind VARCHAR(20) NOT NULL DEFAULT 'edit',      -- add | edit | import | recode | exit | off | on
  note VARCHAR(300) NOT NULL DEFAULT '',
  by_id INT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_emp (emp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
