-- Recruitment intelligence: hiring contacts (institutes / placement officers, recruitment consultants, labour
-- contractors), companies (our own, clients, outside firms watched for poaching), recruitment projects and vacancies
-- with a job code (outside firms' openings are "observed only"), contact-list imports. Business data only — never a
-- candidate's details. Nothing is deleted: rows are switched off with a reason; ri_log is append-only.
CREATE TABLE IF NOT EXISTS ri_contact (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(12) NOT NULL,
  org VARCHAR(200) NOT NULL DEFAULT '', person VARCHAR(120) NOT NULL DEFAULT '',
  phone VARCHAR(20) NOT NULL DEFAULT '', phone2 VARCHAR(80) NOT NULL DEFAULT '', email VARCHAR(160) NOT NULL DEFAULT '',
  website VARCHAR(300) NOT NULL DEFAULT '', city VARCHAR(80) NOT NULL DEFAULT '', region VARCHAR(60) NOT NULL DEFAULT '',
  type VARCHAR(80) NOT NULL DEFAULT '', focus VARCHAR(300) NOT NULL DEFAULT '', status VARCHAR(160) NOT NULL DEFAULT '',
  terms VARCHAR(200) NOT NULL DEFAULT '', remarks VARCHAR(1000) NOT NULL DEFAULT '',
  src VARCHAR(120) NOT NULL DEFAULT '', match_key CHAR(40) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1, off_reason VARCHAR(200) NOT NULL DEFAULT '',
  created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY k_kind (kind, active), KEY k_match (match_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ri_import (
  id INT AUTO_INCREMENT PRIMARY KEY,
  file_name VARCHAR(200) NOT NULL, sha256 CHAR(64) NOT NULL, source VARCHAR(200) NOT NULL DEFAULT '', kind VARCHAR(12) NOT NULL,
  added INT NOT NULL DEFAULT 0, filled INT NOT NULL DEFAULT 0, same INT NOT NULL DEFAULT 0, skipped INT NOT NULL DEFAULT 0,
  by_user INT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ri_company (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL, aliases VARCHAR(400) NOT NULL DEFAULT '', type VARCHAR(8) NOT NULL,
  industry VARCHAR(120) NOT NULL DEFAULT '', city VARCHAR(80) NOT NULL DEFAULT '', watch TINYINT(1) NOT NULL DEFAULT 0,
  match_key CHAR(40) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1, off_reason VARCHAR(200) NOT NULL DEFAULT '',
  created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY k_match (match_key), KEY k_type (type, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ri_project (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL, company_id INT NOT NULL, site VARCHAR(120) NOT NULL DEFAULT '',
  owner_id INT NULL, start_on DATE NULL, target_on DATE NULL, status VARCHAR(8) NOT NULL DEFAULT 'planned',
  notes VARCHAR(1000) NOT NULL DEFAULT '', created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY k_company (company_id), KEY k_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ri_vacancy (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL, project_id INT NULL, req_id INT NULL,
  title VARCHAR(160) NOT NULL, dept VARCHAR(80) NOT NULL DEFAULT '', location VARCHAR(80) NOT NULL DEFAULT '',
  openings INT NOT NULL DEFAULT 1, exp_min DECIMAL(4,1) NULL, exp_max DECIMAL(4,1) NULL, pay_min DECIMAL(14,2) NULL, pay_max DECIMAL(14,2) NULL,
  posted_on DATE NULL, closes_on DATE NULL, status VARCHAR(10) NOT NULL DEFAULT 'open',
  src VARCHAR(120) NOT NULL DEFAULT '', notes VARCHAR(1000) NOT NULL DEFAULT '',
  created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY k_company (company_id), KEY k_status (status), KEY k_req (req_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ri_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity VARCHAR(10) NOT NULL, entity_id INT NOT NULL DEFAULT 0, what VARCHAR(20) NOT NULL,
  fields VARCHAR(400) NOT NULL DEFAULT '', note VARCHAR(300) NOT NULL DEFAULT '',
  by_user INT NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_ent (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
