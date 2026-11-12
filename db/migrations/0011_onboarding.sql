-- Onboarding (checklist per joiner) and probation (check-ins, manager review, HR decision).
-- Nothing is deleted: checklist items and joiners are switched off with a reason, unticks keep the row (undone_at),
-- reviews keep every round.

CREATE TABLE IF NOT EXISTS onb_item (
  id INT AUTO_INCREMENT PRIMARY KEY,
  phase VARCHAR(8) NOT NULL,                 -- pre | day1 | wk1 | month1
  text VARCHAR(160) NOT NULL,
  owner VARCHAR(60) NOT NULL DEFAULT '',
  sort INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  off_reason VARCHAR(200) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A neutral starter checklist; HR changes it on Onboarding → Checklist.
INSERT INTO onb_item (phase, text, owner, sort)
SELECT * FROM (SELECT 'pre' p, 'Offer accepted in writing' t, 'HR' o, 10 s UNION ALL
  SELECT 'pre', 'Joining date confirmed', 'HR', 20 UNION ALL
  SELECT 'pre', 'Documents received (ID, education, experience, bank)', 'HR', 30 UNION ALL
  SELECT 'pre', 'Reference / background check done', 'HR', 40 UNION ALL
  SELECT 'pre', 'Workstation, email and system access requested', 'IT', 50 UNION ALL
  SELECT 'pre', 'Welcome call from the reporting manager', 'Manager', 60 UNION ALL
  SELECT 'day1', 'Appointment letter signed', 'HR', 10 UNION ALL
  SELECT 'day1', 'Employee code and ID card', 'HR', 20 UNION ALL
  SELECT 'day1', 'Assets issued and acknowledged', 'Admin', 30 UNION ALL
  SELECT 'day1', 'Statutory and nomination forms filled', 'HR', 40 UNION ALL
  SELECT 'day1', 'Office tour and safety briefing', 'Admin', 50 UNION ALL
  SELECT 'day1', 'Buddy assigned', 'Manager', 60 UNION ALL
  SELECT 'wk1', 'Induction: company, policies, attendance and leave', 'HR', 10 UNION ALL
  SELECT 'wk1', 'Login created', 'HR', 20 UNION ALL
  SELECT 'wk1', 'Role goals shared and explained', 'Manager', 30 UNION ALL
  SELECT 'wk1', 'Team introductions', 'Manager', 40 UNION ALL
  SELECT 'wk1', 'Added to payroll from the joining date', 'HR', 50) x
WHERE NOT EXISTS (SELECT 1 FROM onb_item);

CREATE TABLE IF NOT EXISTS onb_joiner (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  emp_code VARCHAR(32) NOT NULL DEFAULT '',
  designation VARCHAR(80) NOT NULL DEFAULT '',
  department VARCHAR(80) NOT NULL DEFAULT '',
  branch_id INT NULL,
  manager_code VARCHAR(32) NOT NULL DEFAULT '',
  join_date DATE NULL,
  email VARCHAR(160) NOT NULL DEFAULT '',
  phone VARCHAR(30) NOT NULL DEFAULT '',
  note VARCHAR(500) NOT NULL DEFAULT '',
  employee_id INT NULL,                      -- set once converted to the employee master
  active TINYINT(1) NOT NULL DEFAULT 1, off_reason VARCHAR(300) NOT NULL DEFAULT '',
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_code (emp_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS onb_tick (
  id INT AUTO_INCREMENT PRIMARY KEY,
  joiner_id INT NOT NULL,
  item_id INT NOT NULL,
  done_by INT NOT NULL, done_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  undone_by INT NULL, undone_at DATETIME NULL,
  KEY k_j (joiner_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS probation_checkin (
  id INT AUTO_INCREMENT PRIMARY KEY,
  emp_code VARCHAR(32) NOT NULL,
  month TINYINT NOT NULL,
  status VARCHAR(10) NOT NULL,               -- on_track | support | concern
  note VARCHAR(1500) NOT NULL,
  actions VARCHAR(1000) NOT NULL DEFAULT '',
  done_by INT NOT NULL, done_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_code (emp_code, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS probation_review (
  id INT AUTO_INCREMENT PRIMARY KEY,
  emp_code VARCHAR(32) NOT NULL,
  round TINYINT NOT NULL DEFAULT 1,
  due_date DATE NULL,
  status VARCHAR(8) NOT NULL,                -- manager | hr | done
  manager_id INT NULL,
  ratings TEXT NULL, strengths VARCHAR(1000) NOT NULL DEFAULT '', improve VARCHAR(1000) NOT NULL DEFAULT '',
  training TEXT NULL, training_note VARCHAR(1000) NOT NULL DEFAULT '',
  salary_rev TINYINT(1) NOT NULL DEFAULT 0, salary_pct DECIMAL(5,2) NULL, salary_from DATE NULL,
  role_change TINYINT(1) NOT NULL DEFAULT 0, role_note VARCHAR(500) NOT NULL DEFAULT '',
  reco VARCHAR(12) NULL, manager_comment VARCHAR(1500) NOT NULL DEFAULT '',
  manager_by INT NULL, manager_at DATETIME NULL,
  decision VARCHAR(12) NULL, decision_date DATE NULL, hr_note VARCHAR(1000) NOT NULL DEFAULT '',
  hr_by INT NULL, hr_at DATETIME NULL,
  opened_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_code (emp_code), KEY k_status (status, manager_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
