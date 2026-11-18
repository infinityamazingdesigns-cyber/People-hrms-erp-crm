-- Recruitment pipeline: requisitions (open positions after approval), candidates (one record per person, matched by
-- mobile / email), applications (candidate x position, or the talent pool) moving through stages, CV files in the
-- database, interviews with panel feedback, offers, and an append-only log (ids, fields and stages — never values).
-- Nothing is deleted: candidates and requisitions are switched off with a reason.

CREATE TABLE IF NOT EXISTS rc_requisition (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(20) NOT NULL,
  title VARCHAR(100) NOT NULL,
  department VARCHAR(80) NOT NULL DEFAULT '',
  branch_id INT NULL,
  openings SMALLINT NOT NULL DEFAULT 1,
  emp_type VARCHAR(20) NOT NULL DEFAULT 'permanent',
  reason VARCHAR(12) NOT NULL DEFAULT 'new',          -- new | replacement
  replaces VARCHAR(120) NOT NULL DEFAULT '',
  qual_level TINYINT NOT NULL DEFAULT 0,
  disciplines VARCHAR(200) NOT NULL DEFAULT '',
  exp_min DECIMAL(4,1) NULL, exp_max DECIMAL(4,1) NULL,
  skills VARCHAR(600) NOT NULL DEFAULT '',
  titles VARCHAR(200) NOT NULL DEFAULT '',            -- titles that fit (designation match)
  location VARCHAR(80) NOT NULL DEFAULT '',
  ctc_max DECIMAL(12,2) NULL,                         -- per month; pay data only
  notice_max SMALLINT NULL,
  hire_by DATE NULL,
  jd TEXT NOT NULL,
  weights VARCHAR(300) NOT NULL DEFAULT '',           -- JSON criterion => weight
  missing_zero TINYINT(1) NOT NULL DEFAULT 1,
  status VARCHAR(10) NOT NULL DEFAULT 'draft',        -- draft | approval | open | hold | filled | closed | rejected
  raised_by INT NOT NULL,
  approved_by INT NULL, approved_at DATETIME NULL, approve_note VARCHAR(300) NOT NULL DEFAULT '',
  status_note VARCHAR(300) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ref (ref), KEY k_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rc_candidate (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(20) NOT NULL DEFAULT '', phone_key VARCHAR(10) NOT NULL DEFAULT '',
  email VARCHAR(160) NOT NULL DEFAULT '',
  city VARCHAR(80) NOT NULL DEFAULT '', relocate VARCHAR(10) NOT NULL DEFAULT '',
  cur_title VARCHAR(100) NOT NULL DEFAULT '', cur_company VARCHAR(120) NOT NULL DEFAULT '',
  total_exp DECIMAL(4,1) NULL, rel_exp DECIMAL(4,1) NULL,
  qualification VARCHAR(160) NOT NULL DEFAULT '',
  notice_days SMALLINT NULL,
  cur_ctc DECIMAL(12,2) NULL, exp_ctc DECIMAL(12,2) NULL,  -- per month; pay data only
  skills VARCHAR(600) NOT NULL DEFAULT '',
  cv_text MEDIUMTEXT NULL,
  notes TEXT NOT NULL,
  source VARCHAR(40) NOT NULL DEFAULT '',
  consent TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  removed_at DATETIME NULL, removed_by INT NULL, removed_reason VARCHAR(300) NOT NULL DEFAULT '',
  KEY k_phone (phone_key), KEY k_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rc_application (
  id INT AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT NOT NULL,
  req_id INT NULL,                                    -- NULL = talent pool
  req_key INT NOT NULL DEFAULT 0,                     -- req_id or 0, for the unique key
  stage VARCHAR(12) NOT NULL DEFAULT 'new',
  stage_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  stage_note VARCHAR(300) NOT NULL DEFAULT '',
  rating TINYINT NULL,
  onb_id INT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_one (candidate_id, req_key), KEY k_req (req_id, stage)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rc_file (
  id CHAR(32) NOT NULL PRIMARY KEY,
  candidate_id INT NOT NULL,
  name VARCHAR(180) NOT NULL, type VARCHAR(120) NOT NULL, size INT NOT NULL,
  data LONGBLOB NOT NULL,
  uploaded_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_cand (candidate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rc_interview (
  id INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  round TINYINT NOT NULL DEFAULT 1,
  at DATETIME NOT NULL,
  mode VARCHAR(10) NOT NULL DEFAULT 'person',         -- person | video | phone
  place VARCHAR(300) NOT NULL DEFAULT '',
  panel VARCHAR(200) NOT NULL DEFAULT '',             -- user ids
  status VARCHAR(10) NOT NULL DEFAULT 'scheduled',    -- scheduled | done | cancelled | noshow
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_app (application_id), KEY k_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rc_feedback (
  id INT AUTO_INCREMENT PRIMARY KEY,
  interview_id INT NOT NULL,
  user_id INT NOT NULL,
  rating TINYINT NOT NULL,
  verdict VARCHAR(6) NOT NULL,                        -- yes | hold | no
  comments TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_one (interview_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rc_offer (
  id INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  ctc DECIMAL(12,2) NULL,                             -- per month; pay data only
  join_date DATE NULL, valid_till DATE NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'made',         -- made | accepted | declined | withdrawn
  note VARCHAR(300) NOT NULL DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME NULL,
  KEY k_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rc_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(12) NOT NULL, ref_id INT NOT NULL,
  action VARCHAR(30) NOT NULL, detail VARCHAR(300) NOT NULL DEFAULT '',
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_ref (kind, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
