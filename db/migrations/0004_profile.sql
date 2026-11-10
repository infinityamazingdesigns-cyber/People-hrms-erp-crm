-- My profile: each person's own details (approved copy) and their change requests, which HR verifies field by field.
-- Nothing changes on the approved copy until HR accepts it. Change requests are never deleted.

CREATE TABLE IF NOT EXISTS employee_profile (
  emp_code VARCHAR(32) NOT NULL PRIMARY KEY,
  data TEXT NOT NULL,                      -- JSON field => value (approved)
  updated_by INT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employee_profile_change (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  emp_code VARCHAR(32) NOT NULL,
  branch_id INT NULL,
  fields TEXT NOT NULL,                    -- JSON field => [old, new]
  note VARCHAR(500) NOT NULL DEFAULT '',   -- the person's note (e.g. an employment detail looks wrong)
  status VARCHAR(10) NOT NULL DEFAULT 'hr',-- hr | approved | partly | rejected | withdrawn
  accepted TEXT NULL,                      -- JSON list of accepted fields
  hr_note VARCHAR(500) NOT NULL DEFAULT '',
  decided_by INT NULL,
  decided_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_status (status), KEY k_user (user_id), KEY k_code (emp_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
