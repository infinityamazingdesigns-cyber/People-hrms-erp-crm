-- Expense claims: a claim holds lines (money spent, money received), bills on each line, and its steps
-- draft → submitted (HR audit) → audited (approval) → approved (payment) → paid; returned / rejected / withdrawn.
-- Nothing is deleted: lines left out of a form are switched off, bills stay, ec_log is append-only.

CREATE TABLE IF NOT EXISTS ec_claim (
  id INT AUTO_INCREMENT PRIMARY KEY, claim_no VARCHAR(20) NOT NULL UNIQUE, user_id INT NOT NULL,
  title VARCHAR(300) NOT NULL DEFAULT '', claim_date DATE NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0, approved_amount DECIMAL(12,2) NULL, credit_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  status VARCHAR(12) NOT NULL DEFAULT 'draft', round INT NOT NULL DEFAULT 1, submitted_at DATETIME NULL,
  audit_by INT NULL, audit_at DATETIME NULL, audit_note VARCHAR(600) NOT NULL DEFAULT '',
  approve_by INT NULL, approve_at DATETIME NULL, approve_note VARCHAR(600) NOT NULL DEFAULT '',
  paid_by INT NULL, paid_at DATETIME NULL, paid_on DATE NULL, pay_mode VARCHAR(60) NOT NULL DEFAULT '', pay_ref VARCHAR(120) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY k_user (user_id), KEY k_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ec_line (
  id INT AUTO_INCREMENT PRIMARY KEY, claim_id INT NOT NULL, pos INT NOT NULL DEFAULT 0, kind CHAR(2) NOT NULL DEFAULT 'dr',
  line_date DATE NOT NULL, particulars VARCHAR(300) NOT NULL, category VARCHAR(80) NOT NULL, mode VARCHAR(40) NOT NULL,
  amount DECIMAL(12,2) NOT NULL, no_receipt TINYINT(1) NOT NULL DEFAULT 0, remarks VARCHAR(600) NOT NULL DEFAULT '', branch_id INT NULL,
  allowed TINYINT(1) NOT NULL DEFAULT 1, disallow_note VARCHAR(600) NOT NULL DEFAULT '', disallow_by INT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1, KEY k_claim (claim_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ec_file (
  id INT AUTO_INCREMENT PRIMARY KEY, claim_id INT NOT NULL, line_id INT NULL, kind VARCHAR(10) NOT NULL DEFAULT 'receipt',
  name VARCHAR(160) NOT NULL, mime VARCHAR(60) NOT NULL, size INT NOT NULL, sha CHAR(64) NOT NULL, bytes LONGBLOB NOT NULL,
  user_id INT NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_claim (claim_id), KEY k_sha (sha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ec_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, claim_id INT NOT NULL, user_id INT NULL, action VARCHAR(30) NOT NULL,
  note VARCHAR(600) NOT NULL DEFAULT '', at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_claim (claim_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
