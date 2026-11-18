-- Announcement board: announcements and picture posts (files in the database), daily challenges for everyone /
-- branches / departments / chosen people, and each person's own "done" mark with optional photos.
-- Posts and challenges are switched off, never deleted.

CREATE TABLE IF NOT EXISTS board_post (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(8) NOT NULL,                  -- news | photos
  title VARCHAR(140) NOT NULL,
  body TEXT NOT NULL,
  pinned TINYINT(1) NOT NULL DEFAULT 0,
  author_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  removed_at DATETIME NULL, removed_by INT NULL,
  KEY k_list (removed_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS board_file (
  id CHAR(32) NOT NULL PRIMARY KEY,
  post_id INT NULL, challenge_done_id INT NULL,
  uploader_id INT NOT NULL,
  name VARCHAR(180) NOT NULL, type VARCHAR(120) NOT NULL, size INT NOT NULL, is_image TINYINT(1) NOT NULL DEFAULT 0,
  ord INT NOT NULL DEFAULT 0,
  data LONGBLOB NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_post (post_id, ord), KEY k_done (challenge_done_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS board_challenge (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(90) NOT NULL,
  note VARCHAR(300) NOT NULL DEFAULT '',
  from_day DATE NOT NULL, to_day DATE NOT NULL,
  aud_branches VARCHAR(400) NOT NULL DEFAULT '',  -- branch ids
  aud_depts VARCHAR(600) NOT NULL DEFAULT '',     -- department names, | separated
  aud_people VARCHAR(1000) NOT NULL DEFAULT '',   -- employee codes, comma separated
  photo VARCHAR(8) NOT NULL DEFAULT 'none',       -- none | optional | required
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  removed_at DATETIME NULL, removed_by INT NULL,
  KEY k_days (from_day, to_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS board_challenge_done (
  id INT AUTO_INCREMENT PRIMARY KEY,
  challenge_id INT NOT NULL,
  user_id INT NOT NULL,
  done_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_one (challenge_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
