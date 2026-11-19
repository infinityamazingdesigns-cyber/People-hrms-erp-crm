-- Aptitude test: HR's question bank (by section, job family and level range), each person's paper drawn from it by the
-- level's blueprint, a server deadline, scored on the server. The result is never shown to the person; HR sees it.
-- Nothing is deleted: questions switched off / taken out with a reason, papers voided with a reason; apt_log append-only.
CREATE TABLE IF NOT EXISTS apt_question (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sec VARCHAR(10) NOT NULL,
  tracks VARCHAR(200) NOT NULL DEFAULT '*',          -- '*' = every job family, else keys comma separated
  lvl_min TINYINT NOT NULL DEFAULT 1, lvl_max TINYINT NOT NULL DEFAULT 4,
  q VARCHAR(600) NOT NULL,
  opts TEXT NOT NULL,                                -- JSON list of 2–6 options
  ans TINYINT NOT NULL,
  keep_order TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  off_reason VARCHAR(300) NOT NULL DEFAULT '',
  created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_sec (sec, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS apt_person (
  emp_code VARCHAR(32) NOT NULL PRIMARY KEY,
  track VARCHAR(20) NOT NULL DEFAULT '',
  level TINYINT NULL,
  invited TINYINT(1) NOT NULL DEFAULT 0,
  retest TINYINT(1) NOT NULL DEFAULT 0,
  retest_reason VARCHAR(300) NOT NULL DEFAULT '',
  set_by INT NULL, set_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS apt_attempt (
  id INT AUTO_INCREMENT PRIMARY KEY,
  emp_code VARCHAR(32) NOT NULL,
  user_id INT NOT NULL,
  track VARCHAR(20) NOT NULL, level TINYINT NOT NULL,
  status VARCHAR(6) NOT NULL DEFAULT 'open',         -- open, done, void
  open_key VARCHAR(32) NULL,                         -- the code while open: one open paper per person
  started_at DATETIME NOT NULL, deadline DATETIME NOT NULL, minutes SMALLINT NOT NULL,
  items MEDIUMTEXT NOT NULL,                          -- the paper as given (questions as they were, option order, right option)
  answers TEXT NOT NULL,
  total SMALLINT NOT NULL DEFAULT 0, score SMALLINT NULL, pct DECIMAL(5,1) NULL, band VARCHAR(20) NOT NULL DEFAULT '', sections TEXT NULL,
  submitted_at DATETIME NULL, ended VARCHAR(10) NOT NULL DEFAULT '',  -- submitted, time
  leaves SMALLINT NOT NULL DEFAULT 0,
  hr_note VARCHAR(600) NOT NULL DEFAULT '',
  void_by INT NULL, void_reason VARCHAR(300) NOT NULL DEFAULT '',
  UNIQUE KEY uk_open (open_key), KEY k_code (emp_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS apt_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  by_user INT NULL, what VARCHAR(20) NOT NULL, emp_code VARCHAR(32) NOT NULL DEFAULT '', ref INT NULL, note VARCHAR(300) NOT NULL DEFAULT '',
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
