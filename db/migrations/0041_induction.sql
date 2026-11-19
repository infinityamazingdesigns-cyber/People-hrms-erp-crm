-- Induction academy: HR's levels (reading + optional video link + a quiz), taken in order by every login linked to an
-- employee record. Quiz answers are graded on the server; the right answers never reach the browser. Every attempt is
-- kept (append-only). Levels and questions are switched off with a reason, never deleted.
CREATE TABLE IF NOT EXISTS ind_level (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sort INT NOT NULL DEFAULT 0,
  title VARCHAR(160) NOT NULL,
  summary VARCHAR(300) NOT NULL DEFAULT '',
  body MEDIUMTEXT NULL,
  video_url VARCHAR(400) NOT NULL DEFAULT '',
  department VARCHAR(80) NOT NULL DEFAULT '',        -- blank = everyone
  pass_pct TINYINT NULL,                             -- NULL = the setting
  active TINYINT(1) NOT NULL DEFAULT 1,
  off_reason VARCHAR(200) NOT NULL DEFAULT '',
  created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ind_question (
  id INT AUTO_INCREMENT PRIMARY KEY,
  level_id INT NOT NULL,
  sort INT NOT NULL DEFAULT 0,
  q VARCHAR(500) NOT NULL,
  opts TEXT NOT NULL,                                -- JSON list of 2–6 options
  correct TINYINT NOT NULL,                          -- index of the right option
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_level (level_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ind_attempt (
  id INT AUTO_INCREMENT PRIMARY KEY,
  emp_code VARCHAR(32) NOT NULL,
  user_id INT NOT NULL,
  level_id INT NOT NULL,
  score INT NOT NULL, total INT NOT NULL, pct TINYINT NOT NULL,
  passed TINYINT(1) NOT NULL,
  answers TEXT NOT NULL,                             -- JSON question id => option index chosen
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_code (emp_code, level_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ind_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  level_id INT NOT NULL, what VARCHAR(20) NOT NULL, note VARCHAR(300) NOT NULL DEFAULT '',
  by_user INT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_level (level_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
