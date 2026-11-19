-- Browser notifications: each device that turned them on (push subscription), every notice a login was given (kept,
-- so nothing is lost when a device is off), and the site's VAPID key pair (private key sealed).
CREATE TABLE IF NOT EXISTS push_sub (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  endpoint VARCHAR(700) NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  p256dh VARCHAR(200) NOT NULL DEFAULT '',
  auth VARCHAR(60) NOT NULL DEFAULT '',
  agent VARCHAR(120) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_ok DATETIME NULL,
  fails INT NOT NULL DEFAULT 0,
  UNIQUE KEY uk_ep (endpoint_hash), KEY k_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_notice (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  kind VARCHAR(30) NOT NULL DEFAULT '',
  title VARCHAR(120) NOT NULL,
  body VARCHAR(300) NOT NULL DEFAULT '',
  url VARCHAR(200) NOT NULL DEFAULT '',
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_user (user_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS push_key (
  id TINYINT PRIMARY KEY,
  pub VARCHAR(120) NOT NULL,
  pem_box TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
