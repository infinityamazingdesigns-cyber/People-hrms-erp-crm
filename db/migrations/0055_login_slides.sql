-- Sign-in pictures: up to 5 background pictures on the sign-in page, kept in the database (deploys replace files).
CREATE TABLE IF NOT EXISTS login_slide (
  id CHAR(32) PRIMARY KEY, pos INT NOT NULL DEFAULT 0, tag VARCHAR(40) NOT NULL DEFAULT '', title VARCHAR(90) NOT NULL DEFAULT '',
  text VARCHAR(160) NOT NULL DEFAULT '', data MEDIUMBLOB NOT NULL, w INT NOT NULL, h INT NOT NULL, v INT NOT NULL,
  created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  removed_at DATETIME NULL, removed_by INT NULL, KEY k_pos (removed_at, pos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
