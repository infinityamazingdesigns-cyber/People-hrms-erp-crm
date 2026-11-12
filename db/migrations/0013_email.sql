-- Email & 2-step: outbox with retries, sign-in codes (HMAC only), per-login 2-step choice (with an append-only log),
-- and a small key/value store for job heartbeats. Secrets (mail passwords, service-account keys) live in config.php only.

CREATE TABLE IF NOT EXISTS mail_out (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(12) NOT NULL DEFAULT 'notice',  -- letter | code | test | notice
  ref VARCHAR(60) NOT NULL DEFAULT '',
  to_addr VARCHAR(160) NOT NULL,
  cc_addr VARCHAR(500) NOT NULL DEFAULT '',
  subject VARCHAR(250) NOT NULL,
  body_text MEDIUMTEXT NULL, body_html MEDIUMTEXT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'queued', -- queued | sending | sent | failed
  tries INT NOT NULL DEFAULT 0, next_try_at DATETIME NULL,
  service VARCHAR(10) NOT NULL DEFAULT '', provider_id VARCHAR(160) NOT NULL DEFAULT '', error VARCHAR(500) NOT NULL DEFAULT '',
  created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, sent_at DATETIME NULL,
  KEY k_due (status, next_try_at), KEY k_ref (kind, ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tfa_code (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  code_hash CHAR(64) NOT NULL,
  sent_to VARCHAR(160) NOT NULL,
  tries TINYINT NOT NULL DEFAULT 0,
  used TINYINT(1) NOT NULL DEFAULT 0,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  KEY k_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tfa_user (
  user_id INT NOT NULL PRIMARY KEY,
  off TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tfa_user_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL, off TINYINT(1) NOT NULL, note VARCHAR(300) NOT NULL,
  by_user INT NOT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_kv (
  k VARCHAR(60) NOT NULL PRIMARY KEY,
  v TEXT NOT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
