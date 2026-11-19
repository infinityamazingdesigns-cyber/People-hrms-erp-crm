-- Stock part 2: bills of materials (every save a new revision, the earlier kept switched off), production documents,
-- and links that post a CRM consignment off stock once (a reversal frees it again).
CREATE TABLE IF NOT EXISTS stk_bom (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  rev VARCHAR(4) NOT NULL,
  out_qty DECIMAL(14,3) NOT NULL DEFAULT 1,         -- the quantity these parts make
  note VARCHAR(300) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  off_why VARCHAR(120) NOT NULL DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_rev (item_id, rev), KEY k_item (item_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stk_bom_line (
  bom_id INT NOT NULL,
  line_no INT NOT NULL,
  item_id INT NOT NULL,
  qty DECIMAL(14,4) NOT NULL,
  scrap_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (bom_id, line_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE stk_doc ADD COLUMN out_item_id INT NULL, ADD COLUMN out_qty DECIMAL(14,3) NULL, ADD COLUMN bom_id INT NULL;

CREATE TABLE IF NOT EXISTS stk_link (
  id INT AUTO_INCREMENT PRIMARY KEY,
  src VARCHAR(10) NOT NULL,                         -- ship = a CRM consignment
  src_id INT NOT NULL,
  doc_id INT NOT NULL,
  live TINYINT(1) NULL DEFAULT 1,                   -- 1 while it counts; NULL once its document is reversed
  by_user INT NOT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_live (src, src_id, live), KEY k_doc (doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
