-- KYC & bank: ID numbers and bank details sealed at rest (AES-256-GCM, config seal_key), masked hints for lists,
-- per-field verification, change requests verified by HR, and documents kept in the database (never in a folder).
-- Nothing is deleted: documents are switched off with a reason, change requests keep their status.

CREATE TABLE IF NOT EXISTS employee_kyc (
  emp_code VARCHAR(32) NOT NULL PRIMARY KEY,
  box TEXT NOT NULL,                 -- seal(JSON field => value)
  hints TEXT NOT NULL,               -- JSON field => masked value (safe for lists)
  verified TEXT NULL,                -- JSON field => {by, at}
  updated_by INT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employee_kyc_change (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  emp_code VARCHAR(32) NOT NULL,
  branch_id INT NULL,
  box TEXT NOT NULL,                 -- seal(JSON field => new value)
  hints TEXT NOT NULL,               -- JSON field => masked new value
  status VARCHAR(10) NOT NULL DEFAULT 'hr',
  accepted TEXT NULL,
  hr_note VARCHAR(500) NOT NULL DEFAULT '',
  decided_by INT NULL,
  decided_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_status (status), KEY k_user (user_id), KEY k_code (emp_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employee_doc (
  id INT AUTO_INCREMENT PRIMARY KEY,
  emp_code VARCHAR(32) NOT NULL,
  kind VARCHAR(30) NOT NULL,
  mime VARCHAR(40) NOT NULL,
  bytes LONGBLOB NOT NULL,
  sha256 CHAR(64) NOT NULL,
  size INT NOT NULL,
  change_id INT NULL,
  added_by INT NULL,
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  removed_at DATETIME NULL,
  removed_reason VARCHAR(300) NOT NULL DEFAULT '',
  KEY k_code (emp_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
