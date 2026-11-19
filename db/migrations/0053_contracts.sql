-- Third parties & contracts: the party register (tax / registration numbers sealed, with a keyed hash for the duplicate
-- check), contacts, agreements with every PDF kept as a version (SHA-256), approvals bound to the exact file, compliance
-- documents with expiry and verification by someone other than the uploader, expiry reminders. Nothing is deleted.
CREATE TABLE IF NOT EXISTS ct_party (
  id INT AUTO_INCREMENT PRIMARY KEY, ref VARCHAR(30) NOT NULL UNIQUE,
  legal_name VARCHAR(160) NOT NULL, trade_name VARCHAR(160) NOT NULL DEFAULT '', name_key VARCHAR(160) NOT NULL,
  entity_type VARCHAR(20) NOT NULL, category VARCHAR(20) NOT NULL, purpose VARCHAR(500) NOT NULL DEFAULT '',
  address VARCHAR(400) NOT NULL DEFAULT '', city VARCHAR(60) NOT NULL DEFAULT '', state VARCHAR(60) NOT NULL DEFAULT '', postcode VARCHAR(12) NOT NULL DEFAULT '',
  tax_box TEXT NULL, tax_hash CHAR(64) NULL UNIQUE, tax_tail VARCHAR(4) NOT NULL DEFAULT '',
  reg_box TEXT NULL, reg_hash CHAR(64) NULL UNIQUE, reg_tail VARCHAR(4) NOT NULL DEFAULT '',
  branch_id INT NULL, owner_id INT NULL, onboarded_on DATE NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'prospect', risk VARCHAR(10) NOT NULL DEFAULT 'medium', dd_status VARCHAR(12) NOT NULL DEFAULT 'not_started',
  created_by INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY k_name (name_key), KEY k_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ct_contact (
  id INT AUTO_INCREMENT PRIMARY KEY, party_id INT NOT NULL, name VARCHAR(120) NOT NULL, role VARCHAR(80) NOT NULL DEFAULT '',
  email VARCHAR(160) NOT NULL DEFAULT '', phone VARCHAR(20) NOT NULL DEFAULT '', signatory TINYINT(1) NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_party (party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ct_seq (k VARCHAR(40) NOT NULL PRIMARY KEY, n INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ct_contract (
  id INT AUTO_INCREMENT PRIMARY KEY, ref VARCHAR(40) NOT NULL UNIQUE, party_id INT NOT NULL, parent_id INT NULL,
  type VARCHAR(6) NOT NULL, title VARCHAR(200) NOT NULL, scope VARCHAR(2000) NOT NULL DEFAULT '',
  value_minor BIGINT NULL, terms VARCHAR(1000) NOT NULL DEFAULT '', owner_id INT NULL,
  effective_from DATE NULL, expires_on DATE NULL, notice_days INT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'draft', current_version INT NULL, approved_version INT NULL, executed_version INT NULL,
  executed_on DATE NULL, how_signed VARCHAR(10) NOT NULL DEFAULT '', cancel_note VARCHAR(500) NOT NULL DEFAULT '',
  created_by INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_party (party_id), KEY k_status (status), KEY k_exp (expires_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ct_version (
  id INT AUTO_INCREMENT PRIMARY KEY, contract_id INT NOT NULL, no INT NOT NULL, file LONGBLOB NOT NULL, file_name VARCHAR(160) NOT NULL,
  size INT NOT NULL, sha256 CHAR(64) NOT NULL, signed TINYINT(1) NOT NULL DEFAULT 0, note VARCHAR(500) NOT NULL DEFAULT '',
  uploaded_by INT NOT NULL, uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uk_no (contract_id, no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ct_approval (
  id INT AUTO_INCREMENT PRIMARY KEY, contract_id INT NOT NULL, version_id INT NOT NULL, sha256 CHAR(64) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'pending', requested_by INT NOT NULL, requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note VARCHAR(500) NOT NULL DEFAULT '', decided_by INT NULL, decided_at DATETIME NULL, decision_note VARCHAR(500) NOT NULL DEFAULT '',
  KEY k_contract (contract_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ct_doc (
  id INT AUTO_INCREMENT PRIMARY KEY, party_id INT NOT NULL, kind VARCHAR(20) NOT NULL, file LONGBLOB NOT NULL, file_name VARCHAR(160) NOT NULL,
  mime VARCHAR(40) NOT NULL, size INT NOT NULL, sha256 CHAR(64) NOT NULL, number_tail VARCHAR(4) NOT NULL DEFAULT '',
  valid_from DATE NULL, expires_on DATE NULL, status VARCHAR(10) NOT NULL DEFAULT 'uploaded', remark VARCHAR(300) NOT NULL DEFAULT '',
  replaced_by INT NULL, uploaded_by INT NOT NULL, uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, checked_by INT NULL, checked_at DATETIME NULL,
  KEY k_party (party_id), KEY k_exp (expires_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ct_alert (
  entity VARCHAR(10) NOT NULL, entity_id INT NOT NULL, expires_on DATE NOT NULL, days INT NOT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (entity, entity_id, expires_on, days)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ct_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, entity VARCHAR(10) NOT NULL, entity_id INT NOT NULL, party_id INT NULL,
  action VARCHAR(40) NOT NULL, detail VARCHAR(1000) NOT NULL DEFAULT '', at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_ent (entity, entity_id), KEY k_party (party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
