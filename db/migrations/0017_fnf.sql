-- Full & final settlement for leavers. A settlement is a draft until checked, approved and paid; only a draft changes.
-- Nothing is deleted: a settlement no longer needed is switched off with a reason; the log is append-only.

CREATE TABLE IF NOT EXISTS fnf (
  id INT AUTO_INCREMENT PRIMARY KEY,
  emp_code VARCHAR(32) NOT NULL,
  name VARCHAR(160) NOT NULL DEFAULT '',
  lwd DATE NOT NULL,                          -- last working day
  status VARCHAR(10) NOT NULL DEFAULT 'draft', -- draft | checked | approved | paid
  body MEDIUMTEXT NOT NULL,                   -- JSON: the figures and details as entered
  prepared_by INT NULL, prepared_at DATETIME NULL,
  checked_by INT NULL, checked_at DATETIME NULL,
  approved_by INT NULL, approved_at DATETIME NULL,
  paid_by INT NULL, paid_at DATETIME NULL, paid_ref VARCHAR(160) NOT NULL DEFAULT '',
  back_note VARCHAR(255) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1, off_reason VARCHAR(255) NOT NULL DEFAULT '',
  updated_at DATETIME NULL,
  KEY k_code (emp_code), KEY k_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fnf_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fnf_id INT NOT NULL,
  text VARCHAR(300) NOT NULL,
  by_user INT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_fnf (fnf_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
