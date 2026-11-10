-- WorkNest HR — core schema. Applied by install.php and lib/migrate.php (each file once, recorded in schema_migrations).
-- Every table: utf8mb4. Nothing in the core is ever hard-deleted except sessions and throttle counters.

CREATE TABLE IF NOT EXISTS schema_migrations (
  name VARCHAR(120) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS org_setting (
  k VARCHAR(64) NOT NULL PRIMARY KEY,
  v TEXT NULL,
  updated_by INT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS branch (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(16) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  aliases VARCHAR(400) NOT NULL DEFAULT '',     -- other spellings that mean this branch (comma separated)
  state VARCHAR(60) NOT NULL DEFAULT '',        -- drives statutory rule packs (PT / LWF …)
  city VARCHAR(80) NOT NULL DEFAULT '',
  address VARCHAR(400) NOT NULL DEFAULT '',
  map_url VARCHAR(400) NOT NULL DEFAULT '',
  lat DECIMAL(9,6) NULL, lng DECIMAL(9,6) NULL, radius_m INT NULL,   -- attendance geofence (optional)
  shift_start CHAR(5) NULL, shift_end CHAR(5) NULL, weekoffs VARCHAR(40) NOT NULL DEFAULT 'Sun',
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS department (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS designation (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  grade VARCHAR(20) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL UNIQUE,
  email VARCHAR(160) NOT NULL DEFAULT '',
  name VARCHAR(120) NOT NULL,
  pass_hash VARCHAR(255) NOT NULL,
  emp_code VARCHAR(32) NULL,                    -- link to the employee master (one login per code)
  active TINYINT(1) NOT NULL DEFAULT 1,
  must_change TINYINT(1) NOT NULL DEFAULT 1,
  is_owner TINYINT(1) NOT NULL DEFAULT 0,       -- the permanent owner: always Super Admin, never removable
  failed INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login DATETIME NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_emp (emp_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_role (
  user_id INT NOT NULL,
  role_code VARCHAR(20) NOT NULL,
  given_by INT NULL,
  given_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_branch (
  user_id INT NOT NULL,
  branch_id INT NOT NULL,
  PRIMARY KEY (user_id, branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Module switch for the organisation + which roles may use it.
CREATE TABLE IF NOT EXISTS module_state (
  module_key VARCHAR(40) NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  roles VARCHAR(400) NOT NULL DEFAULT '',       -- comma separated role codes
  settings TEXT NULL,                           -- JSON, module-specific
  updated_by INT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-person exception: allow a module someone's roles do not open, or deny one they do.
CREATE TABLE IF NOT EXISTS module_person (
  user_id INT NOT NULL,
  module_key VARCHAR(40) NOT NULL,
  mode ENUM('allow','deny') NOT NULL,
  reason VARCHAR(300) NOT NULL DEFAULT '',
  set_by INT NULL,
  set_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, module_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit: plain rows + a SHA-256 hash chain for sensitive families (sign-in, users, modules, settings, pay, ID data).
CREATE TABLE IF NOT EXISTS audit (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(80) NOT NULL,
  detail VARCHAR(1000) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  chained TINYINT(1) NOT NULL DEFAULT 0,
  prev_hash CHAR(64) NULL,
  row_hash CHAR(64) NULL,
  KEY k_at (at), KEY k_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_throttle (
  ip VARCHAR(45) NOT NULL,
  window_start DATETIME NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  PRIMARY KEY (ip, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
