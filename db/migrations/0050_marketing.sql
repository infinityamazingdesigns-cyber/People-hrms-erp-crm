-- Marketing & ROAS: campaigns (code MK0001), ad spend per day, leads, consent per number, order → campaign links,
-- content plan. Nothing is deleted: spend lines are switched off with a reason, consent / links / log are append-only.
CREATE TABLE IF NOT EXISTS mk_campaign (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ref VARCHAR(12) NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  channel VARCHAR(30) NOT NULL,
  objective VARCHAR(40) NOT NULL DEFAULT '',
  status VARCHAR(12) NOT NULL DEFAULT 'Planned',
  branch_id INT NULL,
  owner_user INT NOT NULL,
  start_date DATE NULL, end_date DATE NULL,
  budget DECIMAL(16,2) NULL,
  landing_url VARCHAR(500) NOT NULL DEFAULT '',
  utm_source VARCHAR(60) NOT NULL DEFAULT '', utm_medium VARCHAR(60) NOT NULL DEFAULT '', utm_campaign VARCHAR(80) NOT NULL DEFAULT '',
  ext_ids VARCHAR(500) NOT NULL DEFAULT '',
  match_names VARCHAR(500) NOT NULL DEFAULT '',
  notes VARCHAR(1000) NOT NULL DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY k_status (status), KEY k_owner (owner_user), KEY k_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mk_spend (
  id INT AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT NOT NULL,
  day DATE NOT NULL,
  src VARCHAR(10) NOT NULL DEFAULT 'manual',        -- manual / import
  amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  impressions INT NULL, clicks INT NULL, leads INT NULL,
  currency CHAR(3) NOT NULL DEFAULT '',
  note VARCHAR(300) NOT NULL DEFAULT '',
  uk VARCHAR(40) NULL UNIQUE,                       -- campaign|day|src while active (one import line a day)
  active TINYINT(1) NOT NULL DEFAULT 1,
  off_reason VARCHAR(300) NOT NULL DEFAULT '', off_by INT NULL, off_at DATETIME NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_cmp (campaign_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mk_lead (
  id INT AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT NULL,
  kind VARCHAR(12) NOT NULL DEFAULT 'form',
  name VARCHAR(160) NOT NULL DEFAULT '',
  phone VARCHAR(20) NOT NULL DEFAULT '', phone_key VARCHAR(12) NOT NULL DEFAULT '',
  email VARCHAR(160) NOT NULL DEFAULT '',
  seen VARCHAR(160) NOT NULL DEFAULT '',            -- campaign name / code as it came in
  customer_id INT NULL,
  enquiry_id INT NULL, enquiry_how VARCHAR(8) NOT NULL DEFAULT '',   -- new / noted
  via VARCHAR(8) NOT NULL DEFAULT 'staff',          -- staff / import / hook
  ext_ref VARCHAR(80) NULL UNIQUE,
  lead_at DATETIME NOT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_cmp (campaign_id), KEY k_phone (phone_key), KEY k_email (email), KEY k_cust (customer_id), KEY k_at (lead_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mk_consent (
  id INT AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(20) NOT NULL,
  answer VARCHAR(3) NOT NULL,                       -- yes / no
  how VARCHAR(40) NOT NULL,
  note VARCHAR(300) NOT NULL DEFAULT '',
  by_user INT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_phone (phone, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mk_attr (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  campaign_id INT NULL,                             -- NULL = no campaign
  how VARCHAR(6) NOT NULL,                          -- hand / auto
  by_user INT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_order (order_id, id), KEY k_cmp (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mk_post (
  id INT AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT NULL,
  channel VARCHAR(30) NOT NULL,
  title VARCHAR(200) NOT NULL,
  due_date DATE NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'Planned',
  owner_user INT NOT NULL,
  link VARCHAR(500) NOT NULL DEFAULT '',
  notes VARCHAR(1000) NOT NULL DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY k_due (due_date), KEY k_cmp (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mk_hook_key (
  id INT AUTO_INCREMENT PRIMARY KEY,
  key_hash CHAR(64) NOT NULL UNIQUE,
  hint VARCHAR(4) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  made_by INT NOT NULL,
  made_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mk_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity VARCHAR(12) NOT NULL, entity_id INT NOT NULL,
  action VARCHAR(30) NOT NULL,
  detail VARCHAR(300) NOT NULL DEFAULT '',
  by_user INT NULL,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_ent (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
