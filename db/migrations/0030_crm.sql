-- CRM masters: customers (one record per business or person; contacts, numbers, emails, addresses, notes), products,
-- "not the same" pairs for the duplicate check, and a change history (field, old → new). Nothing is deleted:
-- customers are archived, products switched off, merged records point at the kept one.

CREATE TABLE IF NOT EXISTS crm_customer (
  id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(12) NULL,
  name VARCHAR(200) NOT NULL, kind VARCHAR(10) NOT NULL DEFAULT 'company',
  tax_id VARCHAR(20) NULL, category VARCHAR(80) NOT NULL DEFAULT '', industry VARCHAR(80) NOT NULL DEFAULT '', source VARCHAR(60) NOT NULL DEFAULT '',
  branch_id INT NULL, owner_user INT NULL, website VARCHAR(200) NOT NULL DEFAULT '',
  status VARCHAR(10) NOT NULL DEFAULT 'active', archived_reason VARCHAR(300) NOT NULL DEFAULT '', merged_into INT NULL,
  created_by INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_code (code), UNIQUE KEY uk_tax (tax_id), KEY k_owner (owner_user), KEY k_branch (branch_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_contact (
  id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, name VARCHAR(120) NOT NULL, designation VARCHAR(80) NOT NULL DEFAULT '',
  department VARCHAR(80) NOT NULL DEFAULT '', is_primary TINYINT(1) NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_cust (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_phone (
  id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, contact_id INT NULL, number VARCHAR(16) NOT NULL, label VARCHAR(40) NOT NULL DEFAULT '',
  whatsapp TINYINT(1) NOT NULL DEFAULT 1, is_primary TINYINT(1) NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_one (customer_id, number), KEY k_number (number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_email (
  id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, contact_id INT NULL, email VARCHAR(160) NOT NULL, label VARCHAR(40) NOT NULL DEFAULT '',
  is_primary TINYINT(1) NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1, UNIQUE KEY uk_one (customer_id, email), KEY k_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_address (
  id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, label VARCHAR(40) NOT NULL DEFAULT '', line1 VARCHAR(200) NOT NULL, line2 VARCHAR(200) NOT NULL DEFAULT '',
  city VARCHAR(80) NOT NULL DEFAULT '', state VARCHAR(60) NOT NULL DEFAULT '', postcode VARCHAR(12) NOT NULL DEFAULT '', country VARCHAR(60) NOT NULL DEFAULT '',
  ship_default TINYINT(1) NOT NULL DEFAULT 0, bill_default TINYINT(1) NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1, KEY k_cust (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_note (
  id INT AUTO_INCREMENT PRIMARY KEY, entity VARCHAR(12) NOT NULL, entity_id INT NOT NULL, body VARCHAR(2000) NOT NULL, by_user INT NOT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_ent (entity, entity_id, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_product (
  id INT AUTO_INCREMENT PRIMARY KEY, sku VARCHAR(60) NOT NULL, name VARCHAR(200) NOT NULL, category VARCHAR(80) NOT NULL DEFAULT '',
  unit VARCHAR(12) NOT NULL DEFAULT 'pcs', tax_code VARCHAR(12) NOT NULL DEFAULT '', tax_pct DECIMAL(5,2) NOT NULL DEFAULT 0, rate DECIMAL(14,2) NULL,
  notes VARCHAR(500) NOT NULL DEFAULT '', active TINYINT(1) NOT NULL DEFAULT 1, merged_into INT NULL, off_reason VARCHAR(300) NOT NULL DEFAULT '',
  created_by INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uk_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_dup_pair (
  kind VARCHAR(8) NOT NULL, a INT NOT NULL, b INT NOT NULL, by_user INT NOT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (kind, a, b)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_change (
  id INT AUTO_INCREMENT PRIMARY KEY, entity VARCHAR(12) NOT NULL, entity_id INT NOT NULL, field VARCHAR(40) NOT NULL,
  old_value VARCHAR(500) NULL, new_value VARCHAR(500) NULL, reason VARCHAR(300) NOT NULL DEFAULT '', by_user INT NOT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_ent (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
