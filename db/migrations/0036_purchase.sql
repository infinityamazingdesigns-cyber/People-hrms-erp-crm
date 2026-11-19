-- Purchase & procurement part 1: vendors (registration, approval by someone else, bank sealed), requisitions (several
-- lines, the requester's manager approves at or above a value), price comparisons with a quality-cost-delivery score,
-- purchase orders (approval, CEO at or above a limit, number given at issue, header frozen). Nothing is deleted.
CREATE TABLE IF NOT EXISTS pur_vendor (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(200) NOT NULL,
  vkey VARCHAR(200) NOT NULL,                       -- the name made comparable
  tax_id VARCHAR(30) NULL UNIQUE,
  tax_none TINYINT(1) NOT NULL DEFAULT 0,           -- not registered for tax: no tax on its orders
  region VARCHAR(20) NOT NULL DEFAULT '',
  address VARCHAR(400) NOT NULL DEFAULT '',
  city VARCHAR(80) NOT NULL DEFAULT '',
  contact VARCHAR(120) NOT NULL DEFAULT '',
  phone VARCHAR(20) NOT NULL DEFAULT '',
  email VARCHAR(160) NOT NULL DEFAULT '',
  pay_days INT NULL,
  small_business VARCHAR(10) NOT NULL DEFAULT '',   -- e.g. MSME category where the country has one
  bank_box TEXT NULL,                               -- sealed (account, bank code, holder)
  bank_hint VARCHAR(40) NOT NULL DEFAULT '',        -- masked, for lists
  status VARCHAR(10) NOT NULL DEFAULT 'new',        -- new, pending, approved, blocked
  status_note VARCHAR(300) NOT NULL DEFAULT '',
  submitted_by INT NULL, decided_by INT NULL, decided_at DATETIME NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_vkey (vkey), KEY k_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pur_req (
  id INT AUTO_INCREMENT PRIMARY KEY,
  no VARCHAR(40) NOT NULL UNIQUE,
  branch_id INT NOT NULL,
  d DATE NOT NULL,
  need_by DATE NULL,
  dept VARCHAR(80) NOT NULL DEFAULT '',
  job_ref VARCHAR(120) NOT NULL DEFAULT '',
  purpose VARCHAR(255) NOT NULL DEFAULT '',
  est_value DECIMAL(16,2) NOT NULL DEFAULT 0,
  unpriced INT NOT NULL DEFAULT 0,
  status VARCHAR(10) NOT NULL DEFAULT 'open',       -- hod, open, rejected, done, cancelled
  hod_user INT NULL, hod_by INT NULL, hod_at DATETIME NULL, hod_note VARCHAR(255) NOT NULL DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pur_req_line (
  id INT AUTO_INCREMENT PRIMARY KEY,
  req_id INT NOT NULL,
  line_no INT NOT NULL,
  item_id INT NULL,                                 -- stock item when known
  item VARCHAR(255) NOT NULL,
  uom VARCHAR(20) NOT NULL DEFAULT '',
  qty DECIMAL(14,3) NOT NULL,
  est_rate DECIMAL(14,2) NULL,
  last_rate DECIMAL(14,2) NULL,
  stock_qty DECIMAL(14,3) NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'open',       -- hod, open, ordered, cancelled
  order_id INT NULL,
  KEY k_req (req_id), KEY k_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pur_cmp (
  id INT AUTO_INCREMENT PRIMARY KEY,
  no VARCHAR(40) NOT NULL UNIQUE,
  req_line_id INT NULL,
  item VARCHAR(255) NOT NULL,
  qty DECIMAL(14,3) NOT NULL,
  uom VARCHAR(20) NOT NULL DEFAULT '',
  status VARCHAR(10) NOT NULL DEFAULT 'open',       -- open, done, cancelled
  chosen_quote INT NULL,
  reason VARCHAR(300) NOT NULL DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pur_quote (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cmp_id INT NOT NULL,
  vendor_id INT NOT NULL,
  rate DECIMAL(14,2) NOT NULL,
  freight DECIMAL(14,2) NOT NULL DEFAULT 0,         -- per unit
  delivery_days INT NULL,
  pay_days INT NULL,
  note VARCHAR(300) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_cmp (cmp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pur_order (
  id INT AUTO_INCREMENT PRIMARY KEY,
  no VARCHAR(40) NULL UNIQUE,                       -- given at issue only (no gaps in the issued series)
  kind VARCHAR(4) NOT NULL DEFAULT 'po',            -- po, jw (job work)
  branch_id INT NOT NULL,
  vendor_id INT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'draft',      -- draft, mgr, ceo, back, approved, issued, cancelled
  need_ceo TINYINT(1) NOT NULL DEFAULT 0,
  d DATE NULL,
  deliver_to VARCHAR(400) NOT NULL DEFAULT '',
  terms TEXT NULL,
  single_note VARCHAR(300) NOT NULL DEFAULT '',     -- why one source at or above the limit
  sub DECIMAL(16,2) NOT NULL DEFAULT 0,
  tax DECIMAL(16,2) NOT NULL DEFAULT 0,
  tax_parts VARCHAR(400) NOT NULL DEFAULT '[]',
  round_off DECIMAL(8,2) NOT NULL DEFAULT 0,
  total DECIMAL(16,2) NOT NULL DEFAULT 0,
  head TEXT NULL, vendor_snap TEXT NULL,
  created_by INT NOT NULL, sent_by INT NULL, sent_at DATETIME NULL,
  mgr_by INT NULL, mgr_at DATETIME NULL, mgr_note VARCHAR(255) NOT NULL DEFAULT '',
  ceo_by INT NULL, ceo_at DATETIME NULL, ceo_note VARCHAR(255) NOT NULL DEFAULT '',
  issued_by INT NULL, issued_at DATETIME NULL,
  cancelled_why VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_status (status), KEY k_vendor (vendor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pur_order_line (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  line_no INT NOT NULL,
  req_line_id INT NULL,
  cmp_id INT NULL,
  item_id INT NULL,
  item VARCHAR(255) NOT NULL,
  uom VARCHAR(20) NOT NULL DEFAULT '',
  qty DECIMAL(14,3) NOT NULL,
  rate DECIMAL(14,2) NOT NULL,
  tax_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  deliv_date DATE NULL,
  taxable DECIMAL(16,2) NOT NULL DEFAULT 0,
  tax DECIMAL(16,2) NOT NULL DEFAULT 0,
  qty_recv DECIMAL(14,3) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  KEY k_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pur_seq (
  prefix VARCHAR(40) NOT NULL PRIMARY KEY,
  n INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pur_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  by_user INT NULL,
  what VARCHAR(30) NOT NULL,
  ref VARCHAR(60) NOT NULL DEFAULT '',
  note VARCHAR(300) NOT NULL DEFAULT '',            -- never bank details
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
