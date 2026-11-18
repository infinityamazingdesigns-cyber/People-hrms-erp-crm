-- Visitors: one record per visitor (more mobiles / emails beside it), one row per visit at a branch with host answer,
-- check-in / check-out on server time, expected visits, groups, corrections and detail edits that keep the earlier
-- value, a per-login lookup counter, and a record of retention backups. No visitor photographs or ID documents.

CREATE TABLE IF NOT EXISTS vis_category (
  id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(60) NOT NULL, sort INT NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO vis_category (label, sort) VALUES ('Customer / Client', 1), ('Supplier / Vendor', 2), ('Job applicant', 3), ('Service engineer', 4),
  ('Courier / Delivery', 5), ('Contractor', 6), ('Government / Official', 7), ('Employee guest', 8), ('Other', 9);

CREATE TABLE IF NOT EXISTS visitor (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  mobile VARCHAR(20) NULL, mobile_key VARCHAR(10) NULL,
  company VARCHAR(160) NULL, designation VARCHAR(120) NULL, email VARCHAR(160) NULL, address VARCHAR(400) NULL,
  created_by INT NOT NULL, updated_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  purged_at DATETIME NULL,
  KEY k_mobile (mobile_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS visitor_contact (
  id INT AUTO_INCREMENT PRIMARY KEY, visitor_id INT NOT NULL, kind VARCHAR(6) NOT NULL, value VARCHAR(160) NOT NULL,
  created_by INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_one (visitor_id, kind, value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS visit (
  id INT AUTO_INCREMENT PRIMARY KEY,
  visit_no VARCHAR(20) NULL,
  visitor_id INT NOT NULL,
  group_id INT NULL,
  category_id INT NOT NULL,
  purpose VARCHAR(300) NOT NULL DEFAULT '',
  branch_id INT NOT NULL,
  host_user_id INT NULL, host_name VARCHAR(120) NOT NULL, host_dept VARCHAR(80) NOT NULL DEFAULT '',
  approval VARCHAR(10) NOT NULL DEFAULT 'pending',   -- not_needed | pending | approved | rejected | no_answer
  status VARCHAR(10) NOT NULL DEFAULT 'waiting',     -- waiting | inside | out | cancelled | expected | no_show
  exp_on DATE NULL, exp_time TIME NULL, exp_added_at DATETIME NULL,
  check_in_at DATETIME NULL, check_out_at DATETIME NULL, duration_min INT NULL,
  checked_in_by INT NULL, checked_out_by INT NULL,
  remarks VARCHAR(500) NOT NULL DEFAULT '',
  open_key INT NULL,                                 -- visitor id while waiting / inside: one open visit per visitor
  form_token CHAR(32) NULL,
  created_by INT NOT NULL, updated_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_open (open_key), UNIQUE KEY uk_token (form_token), UNIQUE KEY uk_no (visit_no),
  KEY k_branch (branch_id, status, created_at), KEY k_visitor (visitor_id), KEY k_host (host_user_id, status), KEY k_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS visit_approval (
  id INT AUTO_INCREMENT PRIMARY KEY, visit_id INT NOT NULL, approval VARCHAR(10) NOT NULL, note VARCHAR(300) NOT NULL DEFAULT '',
  by_user INT NOT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_visit (visit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS visit_correction (
  id INT AUTO_INCREMENT PRIMARY KEY, visit_id INT NOT NULL, field VARCHAR(16) NOT NULL, old_value DATETIME NULL, new_value DATETIME NOT NULL,
  reason VARCHAR(300) NOT NULL, by_user INT NOT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_visit (visit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS visitor_edit (
  id INT AUTO_INCREMENT PRIMARY KEY, visitor_id INT NOT NULL, visit_id INT NULL, field VARCHAR(20) NOT NULL, old_value VARCHAR(400) NULL,
  reason VARCHAR(300) NOT NULL, by_user INT NOT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_visitor (visitor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS visitor_lookup (
  user_id INT NOT NULL, hour_start DATETIME NOT NULL, n INT NOT NULL DEFAULT 0, PRIMARY KEY (user_id, hour_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vis_backup (
  id INT AUTO_INCREMENT PRIMARY KEY, by_user INT NOT NULL, rows_n INT NOT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
