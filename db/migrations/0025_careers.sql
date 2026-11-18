-- Careers page: each requisition can be listed on the public job board or not; a reference for every application
-- sent from the page; a per-address hit counter (hashed address, never the address itself).

ALTER TABLE rc_requisition ADD COLUMN careers TINYINT(1) NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS careers_app (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(20) NOT NULL,
  candidate_id INT NOT NULL,
  application_id INT NULL,
  req_id INT NULL,
  dedupe CHAR(64) NOT NULL,                 -- sha256 of mobile / email key + position, for the 15-minute repeat check
  ip_hash CHAR(64) NOT NULL,
  extra TEXT NULL,                          -- JSON of the answers that have no column (date of birth, gender, why us …)
  consent_text VARCHAR(1000) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ref (ref), KEY k_dedupe (dedupe, created_at), KEY k_cand (candidate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS careers_hit (
  ip_hash CHAR(64) NOT NULL, kind VARCHAR(6) NOT NULL, hour_start DATETIME NOT NULL, n INT NOT NULL DEFAULT 0,
  PRIMARY KEY (ip_hash, kind, hour_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
