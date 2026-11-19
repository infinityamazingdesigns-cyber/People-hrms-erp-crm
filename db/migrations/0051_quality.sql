-- Complaints & CAPA: one case per occurrence (customer or internal), its timeline (insert-only), evidence files (in the
-- database), repeat links, one CAPA per case (5 whys, root cause, actions, effectiveness check), reminders sent.
-- Nothing is deleted: files and actions are switched off / dropped with a reason.
CREATE TABLE IF NOT EXISTS qc_seq (
  branch_id INT NOT NULL, fy VARCHAR(10) NOT NULL, n INT NOT NULL DEFAULT 0,
  PRIMARY KEY (branch_id, fy)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qc_case (
  id INT AUTO_INCREMENT PRIMARY KEY,
  case_no VARCHAR(40) NOT NULL UNIQUE,
  request_id CHAR(36) NOT NULL UNIQUE,              -- a repeat save of a new case returns the same case
  kind VARCHAR(12) NOT NULL DEFAULT 'customer',
  status VARCHAR(24) NOT NULL DEFAULT 'Draft',
  severity VARCHAR(10) NOT NULL DEFAULT 'Medium',
  safety TINYINT(1) NOT NULL DEFAULT 0,
  branch_id INT NOT NULL,
  customer_id INT NULL, customer_text VARCHAR(200) NOT NULL DEFAULT '',
  contact_name VARCHAR(120) NOT NULL DEFAULT '', contact_phone VARCHAR(20) NOT NULL DEFAULT '',
  product_id INT NULL, product_text VARCHAR(200) NOT NULL DEFAULT '',
  serial_no VARCHAR(80) NOT NULL DEFAULT '', batch VARCHAR(80) NOT NULL DEFAULT '',
  qty_scope VARCHAR(8) NOT NULL DEFAULT '', qty DECIMAL(12,3) NULL, lot_qty DECIMAL(12,3) NULL,
  order_id INT NULL, invoice_no VARCHAR(40) NOT NULL DEFAULT '',
  category VARCHAR(80) NOT NULL DEFAULT '',
  title VARCHAR(160) NOT NULL DEFAULT '',
  description TEXT NOT NULL,
  first_seen DATE NULL, reported_on DATE NOT NULL,
  containment VARCHAR(1000) NOT NULL DEFAULT '',
  owner_id INT NULL, dept VARCHAR(60) NOT NULL DEFAULT '',
  target_close DATE NULL,
  root_id INT NULL,
  submitted_at DATETIME NULL, first_response_at DATETIME NULL, closed_at DATETIME NULL, last_event_at DATETIME NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  version INT NOT NULL DEFAULT 1,
  KEY k_status (status), KEY k_branch (branch_id), KEY k_owner (owner_id), KEY k_root (root_id), KEY k_serial (serial_no), KEY k_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qc_event (
  id INT AUTO_INCREMENT PRIMARY KEY,
  case_id INT NOT NULL,
  kind VARCHAR(16) NOT NULL,                        -- status / edit / note / assign / link / capa / action / file
  from_status VARCHAR(24) NULL, to_status VARCHAR(24) NULL,
  note TEXT NULL,
  before_text TEXT NULL,                            -- the description as it was before an edit
  by_user INT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_case (case_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qc_file (
  id INT AUTO_INCREMENT PRIMARY KEY,
  case_id INT NOT NULL, action_id INT NULL,
  name VARCHAR(160) NOT NULL, mime VARCHAR(40) NOT NULL, size INT NOT NULL, sha256 CHAR(64) NOT NULL,
  data LONGBLOB NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1, off_reason VARCHAR(300) NOT NULL DEFAULT '', off_by INT NULL,
  by_user INT NOT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qc_link (
  id INT AUTO_INCREMENT PRIMARY KEY,
  a_id INT NOT NULL, b_id INT NOT NULL,             -- a_id < b_id
  type VARCHAR(10) NOT NULL,                        -- repeat / related / not_same
  state VARCHAR(10) NOT NULL DEFAULT 'confirmed',   -- suggested (by the reporter) / confirmed / declined
  note VARCHAR(500) NOT NULL DEFAULT '',
  by_user INT NOT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_by INT NULL, decided_at DATETIME NULL,
  UNIQUE KEY uk_pair (a_id, b_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qc_capa (
  id INT AUTO_INCREMENT PRIMARY KEY,
  case_id INT NOT NULL UNIQUE,
  method VARCHAR(10) NOT NULL DEFAULT '5why',
  statement VARCHAR(3000) NOT NULL DEFAULT '',
  contributing VARCHAR(3000) NOT NULL DEFAULT '',
  root_cause VARCHAR(3000) NOT NULL DEFAULT '',
  rc_by INT NULL, rc_at DATETIME NULL,
  eff_criteria VARCHAR(1000) NOT NULL DEFAULT '',
  eff_review_on DATE NULL,
  eff_result VARCHAR(8) NOT NULL DEFAULT '',        -- '' / passed / failed
  eff_note VARCHAR(1000) NOT NULL DEFAULT '', eff_by INT NULL, eff_at DATETIME NULL,
  created_by INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  version INT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qc_why (
  capa_id INT NOT NULL, step TINYINT NOT NULL, text VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (capa_id, step)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qc_action (
  id INT AUTO_INCREMENT PRIMARY KEY,
  capa_id INT NOT NULL,
  type VARCHAR(12) NOT NULL,                        -- containment / corrective / preventive
  text VARCHAR(500) NOT NULL,
  owner_id INT NOT NULL,
  priority VARCHAR(8) NOT NULL DEFAULT 'Medium',
  due DATE NOT NULL,
  status VARCHAR(8) NOT NULL DEFAULT 'open',        -- open / done / dropped
  done_at DATETIME NULL, done_note VARCHAR(1000) NOT NULL DEFAULT '',
  created_by INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_capa (capa_id), KEY k_owner (owner_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qc_alert (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item VARCHAR(40) NOT NULL, day DATE NOT NULL, user_id INT NOT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_once (item, day, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
