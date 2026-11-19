-- Stock & inventory: one item list, an insert-only ledger (stk_move) and a running balance per branch and item
-- (stk_bal) changed in the same transaction under a row lock. Corrections are reversal documents or stock counts —
-- nothing in the ledger is updated or deleted.
CREATE TABLE IF NOT EXISTS stk_item (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  kind VARCHAR(4) NOT NULL DEFAULT 'FG',
  grp VARCHAR(80) NOT NULL DEFAULT '',
  uom VARCHAR(12) NOT NULL DEFAULT 'NOS',
  tax_code VARCHAR(12) NOT NULL DEFAULT '',
  std_cost DECIMAL(14,2) NULL,
  product_id INT NULL UNIQUE,                       -- the CRM product it is, when sold
  notes VARCHAR(500) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  off_why VARCHAR(255) NOT NULL DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stk_level (
  branch_id INT NOT NULL, item_id INT NOT NULL,
  min_qty DECIMAL(14,3) NOT NULL DEFAULT 0, reorder_qty DECIMAL(14,3) NOT NULL DEFAULT 0, bin VARCHAR(40) NOT NULL DEFAULT '',
  updated_by INT NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (branch_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stk_bal (
  branch_id INT NOT NULL, item_id INT NOT NULL, qty DECIMAL(14,3) NOT NULL DEFAULT 0,
  PRIMARY KEY (branch_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stk_doc (
  id INT AUTO_INCREMENT PRIMARY KEY,
  doc_no VARCHAR(40) NOT NULL UNIQUE,
  request_id CHAR(36) NOT NULL UNIQUE,              -- a double tap returns the same document
  kind VARCHAR(4) NOT NULL,                         -- IN, OUT, OPN, XFR, CNT, REV
  sub VARCHAR(10) NOT NULL DEFAULT '',
  branch_id INT NOT NULL,
  to_branch_id INT NULL,
  d DATE NOT NULL,
  ref_no VARCHAR(120) NOT NULL DEFAULT '',
  party VARCHAR(160) NOT NULL DEFAULT '',
  note VARCHAR(500) NOT NULL DEFAULT '',
  status VARCHAR(10) NOT NULL DEFAULT 'posted',     -- posted, transit, received, short, reversed
  rev_of INT NULL, reverse_doc INT NULL, reversed_by INT NULL, reversed_at DATETIME NULL,
  recv_by INT NULL, recv_at DATETIME NULL, recv_d DATE NULL, recv_note VARCHAR(500) NOT NULL DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_branch (branch_id, d), KEY k_to (to_branch_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stk_move (
  id INT AUTO_INCREMENT PRIMARY KEY,
  doc_id INT NOT NULL,
  line_no INT NOT NULL,
  branch_id INT NOT NULL,
  item_id INT NOT NULL,
  d DATE NOT NULL,
  qty DECIMAL(14,3) NOT NULL,                       -- + in, - out
  kind VARCHAR(10) NOT NULL,
  rate DECIMAL(14,2) NULL,
  note VARCHAR(300) NOT NULL DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_item (item_id, branch_id, d), KEY k_doc (doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stk_xfer_line (
  doc_id INT NOT NULL, item_id INT NOT NULL, sent DECIMAL(14,3) NOT NULL, received DECIMAL(14,3) NOT NULL DEFAULT 0,
  PRIMARY KEY (doc_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stk_count_line (
  doc_id INT NOT NULL, item_id INT NOT NULL, system_qty DECIMAL(14,3) NOT NULL, counted DECIMAL(14,3) NOT NULL,
  PRIMARY KEY (doc_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stk_seq (
  prefix VARCHAR(40) NOT NULL PRIMARY KEY,
  n INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
