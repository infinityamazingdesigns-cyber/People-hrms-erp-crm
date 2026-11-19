-- CRM part 2: orders, readiness blockers, dispatch, consignments, tracking, customer tracking links.
-- Nothing here is deleted: cancelled orders stay, events and tracking lines are append-only, links are stopped.
CREATE TABLE IF NOT EXISTS crm_order (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(40) NOT NULL UNIQUE,
  request_id CHAR(36) NOT NULL UNIQUE,              -- a repeat submit returns the same order
  branch_id INT NULL,
  customer_id INT NOT NULL,
  contact_id INT NULL,
  ship_address_id INT NULL,
  po_number VARCHAR(60) NOT NULL DEFAULT '',
  po_date DATE NULL,
  source VARCHAR(60) NOT NULL DEFAULT '',
  source_ref VARCHAR(80) NOT NULL DEFAULT '',
  received_at DATETIME NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'Draft',
  dispatch_status VARCHAR(30) NOT NULL DEFAULT 'Not assigned',
  stage VARCHAR(20) NOT NULL DEFAULT '',            -- customer-facing stage, never steps back
  submitted_at DATETIME NULL,
  first_dispatch_at DATETIME NULL,
  closed_at DATETIME NULL,
  salesperson_id INT NOT NULL,
  created_by INT NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'INR',
  advance_status VARCHAR(30) NOT NULL DEFAULT 'Not required',
  advance_amount DECIMAL(14,2) NULL,
  payment_due DATE NULL,
  delivery_address VARCHAR(500) NOT NULL DEFAULT '',
  delivery_postcode VARCHAR(12) NOT NULL DEFAULT '',
  expected_dispatch DATE NULL,
  confirmed_dispatch DATE NULL,
  requirements VARCHAR(1000) NOT NULL DEFAULT '',
  internal_remarks VARCHAR(1000) NOT NULL DEFAULT '',
  customer_remarks VARCHAR(500) NOT NULL DEFAULT '',
  notify_email TINYINT(1) NOT NULL DEFAULT 1,
  order_value DECIMAL(16,2) NOT NULL DEFAULT 0,      -- before tax
  order_total DECIMAL(16,2) NOT NULL DEFAULT 0,      -- with tax
  version INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_status (status), KEY k_customer (customer_id), KEY k_branch (branch_id), KEY k_sales (salesperson_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_order_line (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  line_no INT NOT NULL,
  product_id INT NOT NULL,
  description VARCHAR(300) NOT NULL DEFAULT '',
  unit VARCHAR(12) NOT NULL DEFAULT 'pcs',
  qty DECIMAL(14,3) NOT NULL,
  rate DECIMAL(14,2) NOT NULL,
  discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  tax_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  line_value DECIMAL(16,2) NOT NULL DEFAULT 0,       -- before tax
  line_total DECIMAL(16,2) NOT NULL DEFAULT 0,       -- with tax
  qty_dispatched DECIMAL(14,3) NOT NULL DEFAULT 0,
  KEY k_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_order_event (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  by_user INT NULL,
  kind VARCHAR(20) NOT NULL,
  from_v VARCHAR(120) NULL,
  to_v VARCHAR(160) NULL,
  note VARCHAR(500) NOT NULL DEFAULT '',
  public TINYINT(1) NOT NULL DEFAULT 0,             -- shown on the customer tracking page
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_blocker (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  blocker VARCHAR(80) NOT NULL,
  customer_side TINYINT(1) NOT NULL DEFAULT 0,      -- waiting on the customer: left out of the same-day score
  owner_user INT NOT NULL,
  reason VARCHAR(500) NOT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL, resolved_by INT NULL, resolution VARCHAR(500) NOT NULL DEFAULT '',
  KEY k_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_dispatch_assign (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  assignee INT NOT NULL,
  assigned_by INT NOT NULL,
  note VARCHAR(300) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  accepted_at DATETIME NULL, released_at DATETIME NULL,
  KEY k_order (order_id), KEY k_assignee (assignee)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_carrier (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'Courier',
  ref_type VARCHAR(30) NOT NULL DEFAULT 'Tracking no.',
  website VARCHAR(300) NOT NULL DEFAULT '',
  track_url VARCHAR(300) NOT NULL DEFAULT '',       -- https; {ref} = the consignment number
  phone VARCHAR(40) NOT NULL DEFAULT '',
  notes VARCHAR(500) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_shipment (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  seq INT NOT NULL,
  request_id CHAR(36) NOT NULL UNIQUE,
  carrier_id INT NOT NULL,
  ref VARCHAR(60) NOT NULL,
  booking_date DATE NOT NULL,
  dispatch_date DATE NOT NULL,
  vehicle_no VARCHAR(20) NOT NULL DEFAULT '',
  transport_doc VARCHAR(40) NOT NULL DEFAULT '',     -- e-way bill / permit number where the country needs one
  packages INT NULL,
  weight_kg DECIMAL(10,2) NULL,
  remarks VARCHAR(500) NOT NULL DEFAULT '',
  status VARCHAR(30) NOT NULL DEFAULT 'Booked',
  status_at DATETIME NULL, delivered_at DATETIME NULL,
  pod_ref VARCHAR(120) NOT NULL DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_seq (order_id, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_shipment_line (
  shipment_id INT NOT NULL,
  line_id INT NOT NULL,
  qty DECIMAL(14,3) NOT NULL,
  PRIMARY KEY (shipment_id, line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_track (
  id INT AUTO_INCREMENT PRIMARY KEY,
  shipment_id INT NOT NULL,
  at DATETIME NOT NULL,
  status VARCHAR(30) NOT NULL,
  location VARCHAR(160) NOT NULL DEFAULT '',
  note VARCHAR(200) NOT NULL DEFAULT '',
  by_user INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_ship (shipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_track_token (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  token_hash BINARY(32) NOT NULL UNIQUE,            -- only the SHA-256 of the link is kept
  issued_by INT NOT NULL,
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL, revoked_by INT NULL,
  hits INT NOT NULL DEFAULT 0, last_hit DATETIME NULL,
  KEY k_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_track_hit (
  ip_hash CHAR(16) NOT NULL,
  minute CHAR(12) NOT NULL,
  n INT NOT NULL DEFAULT 0,
  PRIMARY KEY (ip_hash, minute)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_order_seq (
  prefix VARCHAR(40) NOT NULL PRIMARY KEY,
  n INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
