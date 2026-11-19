-- Group insurance: policies (health / accident / life / other) with their copy, one cover line per person per policy
-- (card, sum insured, covered from, insurer's ID, nominee), requests for the policy copy. Nothing is deleted:
-- policies are switched off, cover lines say "not covered", every change goes to ins_log (field names only).
CREATE TABLE IF NOT EXISTS ins_policy (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(10) NOT NULL DEFAULT 'health',
  name VARCHAR(120) NOT NULL,
  insurer VARCHAR(120) NOT NULL DEFAULT '',
  policy_no VARCHAR(60) NOT NULL DEFAULT '',
  cover_from DATE NULL,
  cover_till DATE NULL,
  sum_insured DECIMAL(14,2) NULL,
  basis VARCHAR(120) NOT NULL DEFAULT '',
  helpline VARCHAR(120) NOT NULL DEFAULT '',
  claims_email VARCHAR(160) NOT NULL DEFAULT '',
  cashless_email VARCHAR(160) NOT NULL DEFAULT '',
  note VARCHAR(500) NOT NULL DEFAULT '',
  doc LONGBLOB NULL, doc_name VARCHAR(160) NOT NULL DEFAULT '', doc_mime VARCHAR(60) NOT NULL DEFAULT '', doc_at DATETIME NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ins_member (
  policy_id INT NOT NULL,
  emp_code VARCHAR(32) NOT NULL,
  covered TINYINT(1) NOT NULL DEFAULT 1,
  card_no VARCHAR(40) NOT NULL DEFAULT '',
  sum_insured DECIMAL(14,2) NULL,
  since DATE NULL,
  ins_id VARCHAR(40) NOT NULL DEFAULT '',
  nom_name VARCHAR(80) NOT NULL DEFAULT '',
  nom_relation VARCHAR(20) NOT NULL DEFAULT '',
  nom_dob DATE NULL,
  nom_phone VARCHAR(20) NOT NULL DEFAULT '',
  updated_by INT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (policy_id, emp_code), KEY k_code (emp_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ins_request (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  emp_code VARCHAR(32) NOT NULL,
  policy_id INT NULL,
  note VARCHAR(500) NOT NULL DEFAULT '',
  status VARCHAR(10) NOT NULL DEFAULT 'hr',          -- hr, shared, closed, withdrawn
  hr_note VARCHAR(500) NOT NULL DEFAULT '',
  decided_by INT NULL, decided_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_user (user_id, status), KEY k_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ins_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  policy_id INT NULL,
  emp_code VARCHAR(32) NOT NULL DEFAULT '',
  what VARCHAR(30) NOT NULL,
  fields VARCHAR(300) NOT NULL DEFAULT '',
  note VARCHAR(300) NOT NULL DEFAULT '',
  by_user INT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_pol (policy_id), KEY k_code (emp_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
