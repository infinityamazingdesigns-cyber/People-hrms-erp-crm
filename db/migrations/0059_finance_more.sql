-- Finance part 2: ledger mapping for feeds, records brought in from other modules (one voucher per source record, ever),
-- bank statements and their lines for reconciliation. Nothing is deleted: a statement line is matched, unmatched or left
-- out with a reason; fin_feed rows stay.
CREATE TABLE IF NOT EXISTS fin_map (
  map_key VARCHAR(32) NOT NULL PRIMARY KEY, ledger_id INT NULL,
  updated_by INT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fin_feed (
  id INT AUTO_INCREMENT PRIMARY KEY, source VARCHAR(12) NOT NULL, source_ref VARCHAR(40) NOT NULL, voucher_id INT NOT NULL,
  made_by INT NULL, made_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_src (source, source_ref), KEY k_v (voucher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fin_stmt (
  id INT AUTO_INCREMENT PRIMARY KEY, ledger_id INT NOT NULL, file_name VARCHAR(160) NOT NULL DEFAULT '',
  added INT NOT NULL DEFAULT 0, skipped INT NOT NULL DEFAULT 0, created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_l (ledger_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fin_stmt_line (
  id INT AUTO_INCREMENT PRIMARY KEY, stmt_id INT NOT NULL, ledger_id INT NOT NULL, line_date DATE NOT NULL,
  narration VARCHAR(300) NOT NULL DEFAULT '', ref VARCHAR(80) NOT NULL DEFAULT '',
  out_minor BIGINT NOT NULL DEFAULT 0, in_minor BIGINT NOT NULL DEFAULT 0, line_hash CHAR(64) NOT NULL,
  state VARCHAR(8) NOT NULL DEFAULT 'open', voucher_id INT NULL, note VARCHAR(300) NOT NULL DEFAULT '',
  decided_by INT NULL, decided_at DATETIME NULL, draft_id INT NULL,
  UNIQUE KEY uk_hash (ledger_id, line_hash), KEY k_state (ledger_id, state, line_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
