-- Vendor bills paid from head office (rent, electricity, internet …): entered per branch, checked, approved, paid with
-- a transaction reference or slip. Repeating bills make a monthly draft. Nothing is deleted; vb_log is append-only.
CREATE TABLE IF NOT EXISTS vb_bill (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(20) NOT NULL,
  branch_id INT NOT NULL,
  kind VARCHAR(60) NOT NULL,
  vendor VARCHAR(160) NOT NULL DEFAULT '',
  vendor_bill_no VARCHAR(60) NOT NULL DEFAULT '',
  bill_date DATE NULL,
  period CHAR(7) NOT NULL DEFAULT '',
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  due_date DATE NULL,
  pay_to VARCHAR(300) NOT NULL DEFAULT '',
  note VARCHAR(600) NOT NULL DEFAULT '',
  no_bill TINYINT(1) NOT NULL DEFAULT 0,
  no_bill_why VARCHAR(300) NOT NULL DEFAULT '',
  status VARCHAR(12) NOT NULL DEFAULT 'draft',
  round INT NOT NULL DEFAULT 0,
  repeat_id INT NULL,
  entered_by INT NOT NULL, entered_name VARCHAR(120) NOT NULL DEFAULT '',
  submitted_at DATETIME NULL,
  check_by INT NULL, check_at DATETIME NULL, check_note VARCHAR(600) NOT NULL DEFAULT '',
  approve_by INT NULL, approve_at DATETIME NULL, approve_note VARCHAR(600) NOT NULL DEFAULT '',
  paid_by INT NULL, paid_at DATETIME NULL, paid_on DATE NULL,
  pay_mode VARCHAR(60) NOT NULL DEFAULT '', pay_ref VARCHAR(120) NOT NULL DEFAULT '', pay_note VARCHAR(600) NOT NULL DEFAULT '',
  due_told DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ref (ref), UNIQUE KEY uk_repeat (repeat_id, period),
  KEY k_status (status), KEY k_branch (branch_id, period), KEY k_by (entered_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vb_file (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bill_id INT NOT NULL,
  kind VARCHAR(8) NOT NULL,
  name VARCHAR(160) NOT NULL,
  mime VARCHAR(60) NOT NULL,
  size INT NOT NULL,
  sha CHAR(64) NOT NULL,
  bytes LONGBLOB NOT NULL,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_bill (bill_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vb_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  bill_id INT NOT NULL,
  user_id INT NULL,
  action VARCHAR(30) NOT NULL,
  note VARCHAR(600) NOT NULL DEFAULT '',
  detail TEXT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_bill (bill_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vb_repeat (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  kind VARCHAR(60) NOT NULL,
  vendor VARCHAR(160) NOT NULL DEFAULT '',
  amount DECIMAL(14,2) NULL,
  bill_day TINYINT NOT NULL DEFAULT 1,
  due_day TINYINT NULL,
  pay_to VARCHAR(300) NOT NULL DEFAULT '',
  note VARCHAR(600) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  stopped_by INT NULL, stopped_at DATETIME NULL,
  last_month CHAR(7) NOT NULL DEFAULT '',
  KEY k_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vb_seq (
  yr SMALLINT PRIMARY KEY,
  n INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
