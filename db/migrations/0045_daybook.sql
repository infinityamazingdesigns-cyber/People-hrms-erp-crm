-- Branch day book: one daily cash sheet per branch (bills, dispatch, money received, petty cash), closed with the cash
-- counted. Nothing is deleted: removals keep the row with why; every change is one append-only line in dbk_log.
-- Files (receipts, screenshots) by their bytes in the database only.
CREATE TABLE IF NOT EXISTS dbk_day (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  day DATE NOT NULL,
  state VARCHAR(8) NOT NULL DEFAULT 'open',
  opening DECIMAL(14,2) NULL,
  opening_src VARCHAR(6) NOT NULL DEFAULT 'auto',
  opening_note VARCHAR(300) NOT NULL DEFAULT '',
  opening_by INT NULL, opening_at DATETIME NULL,
  calc_close DECIMAL(14,2) NULL,
  counted DECIMAL(14,2) NULL,
  close_note VARCHAR(300) NOT NULL DEFAULT '',
  closed_by INT NULL, closed_at DATETIME NULL,
  checked_by INT NULL, checked_at DATETIME NULL,
  check_note VARCHAR(300) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_day (branch_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dbk_entry (
  id INT AUTO_INCREMENT PRIMARY KEY,
  day_id INT NOT NULL,
  branch_id INT NOT NULL,
  day DATE NOT NULL,
  kind VARCHAR(8) NOT NULL,
  ref_id INT NULL,
  party VARCHAR(120) NOT NULL DEFAULT '',
  city VARCHAR(80) NOT NULL DEFAULT '',
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  cash DECIMAL(14,2) NOT NULL DEFAULT 0,
  data TEXT NOT NULL,
  with_emp TINYINT NOT NULL DEFAULT 0,
  settled_at DATETIME NULL, settled_by INT NULL,
  settle_note VARCHAR(300) NOT NULL DEFAULT '',
  by_id INT NOT NULL,
  by_code VARCHAR(32) NOT NULL DEFAULT '',
  by_name VARCHAR(120) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL, updated_by INT NULL,
  removed_at DATETIME NULL, removed_by INT NULL,
  remove_reason VARCHAR(300) NOT NULL DEFAULT '',
  KEY k_day (branch_id, day), KEY k_by (by_id, day), KEY k_ref (ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dbk_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  by_id INT NOT NULL,
  by_name VARCHAR(120) NOT NULL DEFAULT '',
  branch_id INT NOT NULL,
  day DATE NULL,
  entry_id INT NULL,
  kind VARCHAR(8) NOT NULL DEFAULT '',
  act VARCHAR(10) NOT NULL,
  detail VARCHAR(300) NOT NULL DEFAULT '',
  before_j TEXT NULL, after_j TEXT NULL,
  KEY k_day (branch_id, day), KEY k_entry (entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dbk_file (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entry_id INT NOT NULL,
  by_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  type VARCHAR(60) NOT NULL,
  size INT NOT NULL,
  data MEDIUMBLOB NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_entry (entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dbk_party (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(12) NOT NULL,
  name VARCHAR(120) NOT NULL,
  city VARCHAR(80) NOT NULL DEFAULT '',
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_party (kind, name, city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
