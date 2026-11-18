-- Tasks: a login's own tasks and tasks given to others; a given task goes back to its giver for approval; reminders
-- (once or again till done), repeats, notices. Nothing is deleted: a task is removed with removed_at; task_log is append-only.

CREATE TABLE IF NOT EXISTS task (
  id INT AUTO_INCREMENT PRIMARY KEY, owner_id INT NOT NULL, assignee_id INT NOT NULL, grp INT NULL,
  title VARCHAR(200) NOT NULL, notes TEXT NULL, label VARCHAR(30) NOT NULL DEFAULT '', prio TINYINT NOT NULL DEFAULT 2,
  due_date DATE NULL, due_time TIME NULL, remind VARCHAR(4) NOT NULL DEFAULT '', remind_at DATETIME NULL, reminded_at DATETIME NULL,
  rep VARCHAR(8) NOT NULL DEFAULT '', rep_day TINYINT NULL, nag VARCHAR(3) NOT NULL DEFAULT '', next_id INT NULL,
  review VARCHAR(8) NOT NULL DEFAULT '', review_round INT NOT NULL DEFAULT 0, review_note VARCHAR(500) NOT NULL DEFAULT '', submitted_at DATETIME NULL,
  reply VARCHAR(300) NOT NULL DEFAULT '', peer TINYINT(1) NOT NULL DEFAULT 0,
  done_at DATETIME NULL, done_by INT NULL, removed_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_owner (owner_id), KEY k_assignee (assignee_id), KEY k_remind (remind_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, task_id INT NOT NULL, round INT NOT NULL DEFAULT 0, act VARCHAR(12) NOT NULL,
  by_id INT NOT NULL, note VARCHAR(500) NOT NULL DEFAULT '', at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_notice (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, task_id INT NOT NULL, title VARCHAR(160) NOT NULL,
  seen_at DATETIME NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_user (user_id, seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
