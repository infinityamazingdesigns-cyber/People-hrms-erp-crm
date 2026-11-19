-- Candidate portal: a private link per applicant (no password, no login). Only the SHA-256 of the link is kept.
-- The applicant sees only their own application: stage, interviews (attend / ask to reschedule), issued letters sent
-- to their email (answer them), their details and CV. Nothing is deleted; cand_log is append-only.
CREATE TABLE IF NOT EXISTS cand_access (
  id INT AUTO_INCREMENT PRIMARY KEY,
  candidate_id INT NOT NULL,
  application_id INT NOT NULL,
  email VARCHAR(160) NOT NULL,
  live_email VARCHAR(160) NULL,                      -- email while the access is live (one live access per email)
  token_hash CHAR(64) NULL,
  link_until DATE NULL,
  hr_message VARCHAR(600) NOT NULL DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen DATETIME NULL,
  ended_at DATETIME NULL, ended_by INT NULL, end_reason VARCHAR(300) NOT NULL DEFAULT '',
  UNIQUE KEY uk_live (live_email), UNIQUE KEY uk_token (token_hash), KEY k_app (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cand_iv_answer (
  id INT AUTO_INCREMENT PRIMARY KEY,
  access_id INT NOT NULL,
  interview_id INT NOT NULL,
  for_at DATETIME NOT NULL,                          -- the interview time the answer was given for
  answer VARCHAR(8) NOT NULL,                        -- attend | resched
  note VARCHAR(400) NOT NULL DEFAULT '',
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_iv (interview_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cand_detail (
  id INT AUTO_INCREMENT PRIMARY KEY,
  access_id INT NOT NULL,
  data TEXT NOT NULL,                                -- JSON of the fields sent; every version kept, newest counts
  used_by INT NULL, used_at DATETIME NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_acc (access_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cand_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  access_id INT NOT NULL,
  by_user INT NULL,                                  -- NULL = the candidate
  what VARCHAR(30) NOT NULL,
  detail VARCHAR(300) NOT NULL DEFAULT '',
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_acc (access_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
