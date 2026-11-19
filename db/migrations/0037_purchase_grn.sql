-- Purchase part 2: goods received against order lines, quality check by someone other than the receiver, accepted
-- quantity into stock once (stk_link src 'grn'), returns / debit notes, monthly vendor reviews.
CREATE TABLE IF NOT EXISTS pur_grn (
  id INT AUTO_INCREMENT PRIMARY KEY,
  no VARCHAR(40) NOT NULL,
  request_id CHAR(36) NOT NULL,
  order_id INT NOT NULL,
  order_line_id INT NOT NULL,
  branch_id INT NOT NULL,
  vendor_id INT NULL,
  d DATE NOT NULL,
  challan VARCHAR(60) NOT NULL DEFAULT '',
  qty DECIMAL(14,3) NOT NULL,
  note VARCHAR(300) NOT NULL DEFAULT '',
  status VARCHAR(10) NOT NULL DEFAULT 'qc',          -- qc (waiting for the check), done, cancelled
  recv_by INT NOT NULL,
  recv_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  acc_qty DECIMAL(14,3) NULL,
  rej_qty DECIMAL(14,3) NULL,
  rej_reason VARCHAR(40) NOT NULL DEFAULT '',
  tc VARCHAR(3) NOT NULL DEFAULT '',                 -- yes, no, na (test certificate)
  qc_note VARCHAR(300) NOT NULL DEFAULT '',
  qc_by INT NULL, qc_at DATETIME NULL,
  ret_no VARCHAR(60) NOT NULL DEFAULT '',
  dn_no VARCHAR(60) NOT NULL DEFAULT '',
  cancel_why VARCHAR(255) NOT NULL DEFAULT '',
  UNIQUE KEY uk_req (request_id), KEY k_line (order_line_id), KEY k_vendor (vendor_id), KEY k_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pur_vreview (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT NOT NULL,
  ym CHAR(7) NOT NULL,
  grade CHAR(1) NOT NULL,
  note VARCHAR(300) NOT NULL DEFAULT '',
  by_user INT NOT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_vendor (vendor_id, ym)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
