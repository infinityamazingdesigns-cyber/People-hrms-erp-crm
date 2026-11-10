-- Org chart: reporting lines live on employee.manager_code; this adds open positions (vacant seats).
-- Positions are never deleted — they are filled (linked to the person who joined) or closed with a reason.

CREATE TABLE IF NOT EXISTS org_position (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(80) NOT NULL,
  department VARCHAR(80) NOT NULL DEFAULT '',
  branch_id INT NULL,
  manager_code VARCHAR(32) NOT NULL DEFAULT '',
  replaces_code VARCHAR(32) NOT NULL DEFAULT '',   -- the leaver this seat replaces, if any
  status VARCHAR(10) NOT NULL DEFAULT 'open',       -- open | filled | closed
  filled_code VARCHAR(32) NOT NULL DEFAULT '',
  note VARCHAR(300) NOT NULL DEFAULT '',
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_by INT NULL,
  closed_at DATETIME NULL,
  KEY k_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
