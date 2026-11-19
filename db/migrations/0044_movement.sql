-- Staff movement: a gate record of staff going out during working hours and coming back. No approval; nothing reaches
-- attendance or pay. One open entry per person (open_key), groups by group_id, corrections keep the original,
-- one alert per entry + kind + person.
CREATE TABLE IF NOT EXISTS mv_reason (
  id INT AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(80) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort SMALLINT NOT NULL DEFAULT 0,
  updated_by INT NULL,
  UNIQUE KEY uk_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mv_entry (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mv_no VARCHAR(20) NULL,
  group_id INT NULL,
  emp_code VARCHAR(32) NOT NULL,
  emp_name VARCHAR(120) NOT NULL,
  emp_dept VARCHAR(80) NOT NULL DEFAULT '',
  branch_id INT NULL,
  reason_id INT NULL,
  reason_other VARCHAR(200) NOT NULL DEFAULT '',
  destination VARCHAR(200) NOT NULL DEFAULT '',
  vehicle_no VARCHAR(20) NOT NULL DEFAULT '',
  material_out VARCHAR(300) NOT NULL DEFAULT '',
  material_back VARCHAR(8) NULL,
  material_note VARCHAR(300) NOT NULL DEFAULT '',
  status VARCHAR(4) NOT NULL DEFAULT 'out',
  out_at DATETIME NOT NULL,
  in_at DATETIME NULL,
  duration_min INT NULL,
  open_key VARCHAR(32) NULL,
  form_token CHAR(32) NULL,
  created_by INT NULL, in_by INT NULL, updated_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_open (open_key), UNIQUE KEY uk_token (form_token),
  KEY k_branch_out (branch_id, out_at), KEY k_code (emp_code), KEY k_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mv_correction (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entry_id INT NOT NULL,
  field VARCHAR(8) NOT NULL,
  old_value DATETIME NULL,
  new_value DATETIME NOT NULL,
  reason VARCHAR(300) NOT NULL,
  by_user INT NOT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_entry (entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mv_alert (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entry_id INT NOT NULL,
  kind VARCHAR(12) NOT NULL,
  user_id INT NOT NULL,
  mail_id INT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'queued',
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_one (entry_id, kind, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO mv_reason (label, sort) VALUES
  ('Customer site', 10), ('Material purchase (local)', 20), ('Vendor / job-work visit', 30), ('Dispatch / transport', 40),
  ('Bank / post / courier', 50), ('Government office', 60), ('Another branch', 70), ('Training / meeting outside', 80),
  ('Personal work', 90), ('Medical', 100);
