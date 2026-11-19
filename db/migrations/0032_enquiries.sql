-- Enquiry register: leads → enquiries → follow-ups → won (an order) / lost (a reason). Nothing is deleted: a wrong entry
-- is removed with a reason and stays in the table; notes are append-only.
CREATE TABLE IF NOT EXISTS enq_enquiry (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(40) NOT NULL UNIQUE,
  enquiry_date DATE NOT NULL,
  customer_name VARCHAR(200) NOT NULL,
  company VARCHAR(200) NOT NULL DEFAULT '',
  phone VARCHAR(20) NOT NULL DEFAULT '',            -- digits
  phone_key VARCHAR(12) NOT NULL DEFAULT '',        -- local part, for the "already open" check
  email VARCHAR(160) NOT NULL DEFAULT '',
  customer_id INT NULL,                             -- CRM customer when linked
  category VARCHAR(80) NOT NULL DEFAULT '',
  product VARCHAR(160) NOT NULL DEFAULT '',
  source VARCHAR(80) NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT 'new',
  lost_reason VARCHAR(80) NOT NULL DEFAULT '',
  quote_no VARCHAR(60) NOT NULL DEFAULT '',
  quote_date DATE NULL,
  basic DECIMAL(16,2) NULL,
  tax_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  follow_up DATE NULL,
  assigned_user INT NULL,
  branch_id INT NULL,
  order_id INT NULL,                                -- CRM order when won
  removed_at DATETIME NULL, removed_by INT NULL, removed_reason VARCHAR(300) NOT NULL DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY k_status (status), KEY k_follow (follow_up), KEY k_phone (phone_key), KEY k_email (email), KEY k_assigned (assigned_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS enq_note (
  id INT AUTO_INCREMENT PRIMARY KEY,
  enquiry_id INT NOT NULL,
  body VARCHAR(1000) NOT NULL,
  sys TINYINT(1) NOT NULL DEFAULT 0,               -- written by the system (status moves, quotes)
  by_user INT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_enq (enquiry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS enq_seq (
  fy VARCHAR(10) NOT NULL PRIMARY KEY,
  n INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
