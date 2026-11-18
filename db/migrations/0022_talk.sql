-- Team chat: direct chats and groups, members with admin and read / delivered marks, messages, files (in the database),
-- and an append-only log of whole chats deleted (names and counts, never message text).

CREATE TABLE IF NOT EXISTS talk_chat (
  id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(5) NOT NULL, dm_key VARCHAR(30) NULL UNIQUE,
  name VARCHAR(60) NOT NULL DEFAULT '', descr VARCHAR(140) NOT NULL DEFAULT '', mode VARCHAR(4) NOT NULL DEFAULT 'two',
  created_by INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS talk_member (
  chat_id INT NOT NULL, user_id INT NOT NULL, is_admin TINYINT(1) NOT NULL DEFAULT 0,
  last_read INT NOT NULL DEFAULT 0, last_delivered INT NOT NULL DEFAULT 0,
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, left_at DATETIME NULL,
  PRIMARY KEY (chat_id, user_id), KEY k_user (user_id, left_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS talk_msg (
  id INT AUTO_INCREMENT PRIMARY KEY, chat_id INT NOT NULL, user_id INT NOT NULL, sys TINYINT(1) NOT NULL DEFAULT 0,
  body TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL, deleted_by INT NULL,
  KEY k_chat (chat_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS talk_file (
  id CHAR(32) PRIMARY KEY, chat_id INT NOT NULL, msg_id INT NULL, uploader_id INT NOT NULL,
  name VARCHAR(190) NOT NULL, type VARCHAR(120) NOT NULL, size INT NOT NULL, is_image TINYINT(1) NOT NULL DEFAULT 0, data MEDIUMBLOB NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_msg (msg_id), KEY k_chat (chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS talk_delete_log (
  id INT AUTO_INCREMENT PRIMARY KEY, chat_id INT NOT NULL, kind VARCHAR(5) NOT NULL, name VARCHAR(190) NOT NULL, members TEXT NOT NULL,
  msg_count INT NOT NULL DEFAULT 0, file_count INT NOT NULL DEFAULT 0, chat_created_at DATETIME NULL, last_msg_at DATETIME NULL,
  reason VARCHAR(300) NOT NULL, deleted_by INT NOT NULL, deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
