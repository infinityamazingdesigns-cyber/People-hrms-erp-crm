-- Shifts & weekly offs: per-person rules with a start date. A rule wins over the branch's own
-- shift and weekly off from its start date. Rules are never deleted: replaced (old row kept) or ended with a reason.

CREATE TABLE IF NOT EXISTS shift_rule (
  id INT AUTO_INCREMENT PRIMARY KEY,
  emp_code VARCHAR(32) NOT NULL,
  from_day DATE NOT NULL,
  start_time CHAR(5) NULL,
  end_time CHAR(5) NULL,
  flexible TINYINT(1) NOT NULL DEFAULT 0,
  use_branch_shift TINYINT(1) NOT NULL DEFAULT 0,   -- keep the branch's hours, change only weekly offs
  weekoffs VARCHAR(40) NULL,                        -- null = the branch's weekly offs
  note VARCHAR(200) NOT NULL DEFAULT '',
  set_by INT NULL,
  set_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  removed_at DATETIME NULL, removed_by INT NULL, removed_reason VARCHAR(200) NOT NULL DEFAULT '',
  KEY k_code (emp_code, from_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
