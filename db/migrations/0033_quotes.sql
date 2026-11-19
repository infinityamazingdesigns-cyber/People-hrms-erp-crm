-- Quotations: numbered per branch and year, drafts changed freely, a sent one is locked (its header kept as it was),
-- revisions keep the number with -R1, -R2 …; won → a CRM order, lost → a reason. Nothing is deleted (archived with a reason).
CREATE TABLE IF NOT EXISTS qt_quote (
  id INT AUTO_INCREMENT PRIMARY KEY,
  quote_no VARCHAR(40) NOT NULL,
  rev INT NOT NULL DEFAULT 0,
  parent_id INT NULL,
  request_id CHAR(36) NOT NULL UNIQUE,
  branch_id INT NULL,
  customer_id INT NOT NULL,
  contact_id INT NULL,
  enquiry_id INT NULL,
  bill_to VARCHAR(500) NOT NULL DEFAULT '',
  ship_to VARCHAR(500) NOT NULL DEFAULT '',
  seller_region VARCHAR(20) NOT NULL DEFAULT '',
  place_region VARCHAR(20) NOT NULL DEFAULT '',
  place_hand TINYINT(1) NOT NULL DEFAULT 0,
  quote_date DATE NOT NULL,
  valid_till DATE NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'Draft',
  lost_reason VARCHAR(80) NOT NULL DEFAULT '',
  status_note VARCHAR(500) NOT NULL DEFAULT '',
  payment_terms VARCHAR(200) NOT NULL DEFAULT '',
  delivery_terms VARCHAR(200) NOT NULL DEFAULT '',
  note VARCHAR(1000) NOT NULL DEFAULT '',
  show_intro TINYINT(1) NOT NULL DEFAULT 1,
  currency CHAR(3) NOT NULL DEFAULT 'INR',
  taxable DECIMAL(16,2) NOT NULL DEFAULT 0,
  discount DECIMAL(16,2) NOT NULL DEFAULT 0,
  charges DECIMAL(16,2) NOT NULL DEFAULT 0,
  tax_total DECIMAL(16,2) NOT NULL DEFAULT 0,
  tax_parts VARCHAR(400) NOT NULL DEFAULT '[]',      -- [[label, amount], …]
  round_off DECIMAL(8,2) NOT NULL DEFAULT 0,
  total DECIMAL(16,2) NOT NULL DEFAULT 0,
  head TEXT NULL,                                   -- the header as it was when sent
  salesperson_id INT NOT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL, sent_by INT NULL, decided_at DATETIME NULL,
  order_id INT NULL,
  archived_at DATETIME NULL, archived_by INT NULL, archive_reason VARCHAR(300) NOT NULL DEFAULT '',
  version INT NOT NULL DEFAULT 1,
  UNIQUE KEY uk_no (quote_no, rev), KEY k_customer (customer_id), KEY k_status (status), KEY k_enq (enquiry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qt_line (
  id INT AUTO_INCREMENT PRIMARY KEY,
  quote_id INT NOT NULL,
  line_no INT NOT NULL,
  product_id INT NULL,                              -- NULL = an other charge (freight, packing …)
  kind VARCHAR(40) NOT NULL DEFAULT '',             -- charge kind
  sku VARCHAR(60) NOT NULL DEFAULT '',
  name VARCHAR(200) NOT NULL,
  description VARCHAR(600) NOT NULL DEFAULT '',
  tax_code VARCHAR(12) NOT NULL DEFAULT '',
  unit VARCHAR(12) NOT NULL DEFAULT '',
  qty DECIMAL(14,3) NOT NULL DEFAULT 1,
  rate DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  tax_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  taxable DECIMAL(16,2) NOT NULL DEFAULT 0,
  tax DECIMAL(16,2) NOT NULL DEFAULT 0,
  KEY k_quote (quote_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qt_head (
  branch_id INT NOT NULL PRIMARY KEY,               -- 0 = the company; a branch row fills only what differs
  fields TEXT NOT NULL,
  updated_by INT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qt_head_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  changed VARCHAR(400) NOT NULL,                    -- field names only
  by_user INT NOT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qt_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  quote_id INT NOT NULL,
  what VARCHAR(20) NOT NULL,
  note VARCHAR(500) NOT NULL DEFAULT '',
  by_user INT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_quote (quote_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qt_seq (
  prefix VARCHAR(40) NOT NULL PRIMARY KEY,
  n INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
