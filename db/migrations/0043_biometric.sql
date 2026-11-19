-- Biometric machines: sources of punches of any make — machine direct (ADMS / "Cloud server" push, by serial number)
-- or an API key (vendor software, a script). Only the attendance time is kept (never templates, photos or user lists).
-- Keys are kept only as a SHA-256 hash and shown once. Punches are kept once per person and time.
CREATE TABLE IF NOT EXISTS bio_source (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  make VARCHAR(20) NOT NULL DEFAULT 'other',
  model VARCHAR(80) NOT NULL DEFAULT '',
  way VARCHAR(4) NOT NULL,                           -- adms | key
  serial VARCHAR(40) NULL,
  branch_id INT NULL,
  key_hash CHAR(64) NULL, key_hint VARCHAR(8) NOT NULL DEFAULT '',
  code_prefix VARCHAR(12) NOT NULL DEFAULT '',
  code_map TEXT NULL,                                -- JSON machine id => employee code
  allowed_ips VARCHAR(400) NOT NULL DEFAULT '',
  last_ip VARCHAR(45) NOT NULL DEFAULT '', last_seen DATETIME NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  off_reason VARCHAR(200) NOT NULL DEFAULT '',
  created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_serial (serial), UNIQUE KEY uk_key (key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bio_punch (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  source_id INT NOT NULL,
  emp_code VARCHAR(32) NOT NULL,
  punch_at DATETIME NOT NULL,
  device VARCHAR(40) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_one (emp_code, punch_at), KEY k_at (punch_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bio_seen (
  serial VARCHAR(40) NOT NULL PRIMARY KEY,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  hits INT NOT NULL DEFAULT 1,
  first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bio_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  source_id INT NULL, what VARCHAR(20) NOT NULL, note VARCHAR(300) NOT NULL DEFAULT '',
  by_user INT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
