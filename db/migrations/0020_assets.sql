-- Asset register: categories, assets, allotments (issue / return), issues people report, yearly audits, invoices.
-- Nothing is deleted: an asset is proposed for scrap and only a confirmer scraps it; categories are switched off;
-- every change is a line in asset_log (before → after), append-only.

CREATE TABLE IF NOT EXISTS asset_cat (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(60) NOT NULL UNIQUE, code VARCHAR(6) NOT NULL,
  kind VARCHAR(8) NOT NULL DEFAULT 'general',   -- computer | phone | sim | card | general : which details are asked
  active TINYINT(1) NOT NULL DEFAULT 1, sort INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO asset_cat (name, code, kind, sort) VALUES ('Laptop', 'LAP', 'computer', 1), ('Desktop', 'DSK', 'computer', 2),
  ('Mobile phone', 'MOB', 'phone', 3), ('SIM card', 'SIM', 'sim', 4), ('Monitor', 'MON', 'general', 5), ('ID / access card', 'IDC', 'general', 6),
  ('Fuel / company card', 'CRD', 'card', 7), ('Tools', 'TLS', 'general', 8), ('Furniture', 'FUR', 'general', 9), ('Other', 'OTH', 'general', 99);

CREATE TABLE IF NOT EXISTS asset (
  id INT AUTO_INCREMENT PRIMARY KEY, tag VARCHAR(40) NOT NULL UNIQUE, cat_id INT NOT NULL,
  make VARCHAR(80) NOT NULL DEFAULT '', model VARCHAR(80) NOT NULL DEFAULT '', serial VARCHAR(80) NOT NULL DEFAULT '',
  imei1 VARCHAR(20) NOT NULL DEFAULT '', imei2 VARCHAR(20) NOT NULL DEFAULT '', sim_no VARCHAR(30) NOT NULL DEFAULT '',
  operator VARCHAR(40) NOT NULL DEFAULT '', card_last4 CHAR(4) NOT NULL DEFAULT '',
  bought_on DATE NULL, vendor VARCHAR(120) NOT NULL DEFAULT '', invoice_no VARCHAR(60) NOT NULL DEFAULT '', cost DECIMAL(12,2) NULL,
  warranty_till DATE NULL, branch_id INT NULL, status VARCHAR(12) NOT NULL DEFAULT 'stock', scrap_reason VARCHAR(300) NOT NULL DEFAULT '',
  notes VARCHAR(600) NOT NULL DEFAULT '', created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY k_cat (cat_id), KEY k_status (status), KEY k_serial (serial), KEY k_imei (imei1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS asset_allot (
  id INT AUTO_INCREMENT PRIMARY KEY, asset_id INT NOT NULL, emp_code VARCHAR(32) NOT NULL,
  given_on DATE NOT NULL, accessories VARCHAR(300) NOT NULL DEFAULT '', given_note VARCHAR(300) NOT NULL DEFAULT '', given_by INT NULL,
  returned_on DATE NULL, return_condition VARCHAR(20) NOT NULL DEFAULT '', return_note VARCHAR(300) NOT NULL DEFAULT '', received_by INT NULL,
  ack_at DATETIME NULL, open_key INT NULL UNIQUE,          -- = asset_id while out, so one asset has one open allotment
  KEY k_emp (emp_code), KEY k_asset (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS asset_issue (
  id INT AUTO_INCREMENT PRIMARY KEY, allot_id INT NOT NULL, asset_id INT NOT NULL, emp_code VARCHAR(32) NOT NULL, user_id INT NOT NULL,
  type VARCHAR(40) NOT NULL, happened_on DATE NULL, description VARCHAR(600) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'open', outcome VARCHAR(20) NOT NULL DEFAULT '', outcome_note VARCHAR(300) NOT NULL DEFAULT '',
  resolved_by INT NULL, resolved_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_asset (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS asset_audit (
  id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(80) NOT NULL, started_on DATE NOT NULL, due_on DATE NULL,
  closed_at DATETIME NULL, result VARCHAR(300) NOT NULL DEFAULT '', by_user INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS asset_file (
  id INT AUTO_INCREMENT PRIMARY KEY, asset_id INT NOT NULL, kind VARCHAR(10) NOT NULL DEFAULT 'invoice', name VARCHAR(160) NOT NULL,
  mime VARCHAR(60) NOT NULL, size INT NOT NULL, bytes LONGBLOB NOT NULL, by_user INT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_asset (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS asset_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, asset_id INT NOT NULL, what VARCHAR(30) NOT NULL, before_json TEXT NULL, after_json TEXT NULL,
  by_user INT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_asset (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
