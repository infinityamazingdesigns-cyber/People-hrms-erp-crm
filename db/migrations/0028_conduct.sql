-- Feedback & conduct: feedback notes, monthly check-ins, conduct cases (memo → warning → final warning, show-cause for
-- serious matters) with HR review, approval for serious matters, issue, the person's reply, close, appeal; evidence
-- files in the database; the code of conduct typed by HR with each login's acceptance. Nothing is deleted.

CREATE TABLE IF NOT EXISTS dc_note (
  id INT AUTO_INCREMENT PRIMARY KEY, emp_code VARCHAR(32) NOT NULL, by_user INT NOT NULL,
  kind VARCHAR(12) NOT NULL, text TEXT NOT NULL, action VARCHAR(500) NOT NULL DEFAULT '',
  share_board TINYINT(1) NOT NULL DEFAULT 0, board_post_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  removed_at DATETIME NULL, removed_by INT NULL, removed_reason VARCHAR(300) NOT NULL DEFAULT '',
  KEY k_emp (emp_code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dc_checkin (
  id INT AUTO_INCREMENT PRIMARY KEY, emp_code VARCHAR(32) NOT NULL, ym CHAR(7) NOT NULL, by_user INT NOT NULL,
  state VARCHAR(10) NOT NULL, note TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_one (emp_code, ym)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dc_case (
  id INT AUTO_INCREMENT PRIMARY KEY, ref VARCHAR(20) NULL, emp_code VARCHAR(32) NOT NULL,
  category VARCHAR(16) NOT NULL, severity VARCHAR(10) NOT NULL, step VARCHAR(10) NOT NULL,
  inc_date DATE NOT NULL, what TEXT NOT NULL, expected TEXT NOT NULL, clauses TEXT NULL,
  raised_by INT NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'review_hr',
  hr_by INT NULL, hr_at DATETIME NULL, hr_note VARCHAR(500) NOT NULL DEFAULT '',
  appr_by INT NULL, appr_at DATETIME NULL, appr_note VARCHAR(500) NOT NULL DEFAULT '',
  issued_at DATETIME NULL, ack_at DATETIME NULL,
  reply TEXT NULL, reply_at DATETIME NULL, reply_late TINYINT(1) NOT NULL DEFAULT 0, reply_by INT NULL,
  outcome VARCHAR(500) NOT NULL DEFAULT '', closed_by INT NULL, closed_at DATETIME NULL,
  appeal TEXT NULL, appeal_at DATETIME NULL, appeal_result VARCHAR(10) NULL, appeal_note VARCHAR(500) NOT NULL DEFAULT '', appeal_by INT NULL, appeal_done_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ref (ref), KEY k_emp (emp_code), KEY k_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dc_file (
  id CHAR(32) NOT NULL PRIMARY KEY, case_id INT NOT NULL, name VARCHAR(180) NOT NULL, type VARCHAR(60) NOT NULL, size INT NOT NULL,
  data LONGBLOB NOT NULL, note VARCHAR(300) NOT NULL DEFAULT '', uploaded_by INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dc_code (
  id INT AUTO_INCREMENT PRIMARY KEY, no VARCHAR(10) NOT NULL, title VARCHAR(120) NOT NULL, text TEXT NOT NULL,
  sort INT NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1, updated_by INT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dc_code_ack (
  user_id INT NOT NULL, version CHAR(12) NOT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dc_log (
  id INT AUTO_INCREMENT PRIMARY KEY, case_id INT NOT NULL, action VARCHAR(30) NOT NULL, user_id INT NOT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
