-- Video meetings: browser to browser (WebRTC); this site keeps the meeting record and passes connection messages
-- (handed over once, then deleted). Chat, transcript lines and minutes are read only by people who were in it.
CREATE TABLE IF NOT EXISTS meet_room (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code CHAR(12) NOT NULL,
  title VARCHAR(120) NOT NULL,
  agenda TEXT NULL,
  host_id INT NOT NULL,
  open_join TINYINT(1) NOT NULL DEFAULT 0,
  locked TINYINT(1) NOT NULL DEFAULT 0,
  auto_minutes TINYINT(1) NOT NULL DEFAULT 1,
  ver INT NOT NULL DEFAULT 1,
  created_at INT NOT NULL,
  started_at INT NULL,
  ended_at INT NULL,
  minutes MEDIUMTEXT NULL,
  minutes_at INT NULL,
  UNIQUE KEY uk_code (code), KEY k_host (host_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meet_member (
  room_id INT NOT NULL,
  user_id INT NOT NULL,
  role VARCHAR(6) NOT NULL DEFAULT 'member',
  state VARCHAR(8) NOT NULL DEFAULT 'invited',
  mic TINYINT(1) NOT NULL DEFAULT 1,
  cam TINYINT(1) NOT NULL DEFAULT 1,
  hand TINYINT(1) NOT NULL DEFAULT 0,
  sharing TINYINT(1) NOT NULL DEFAULT 0,
  mute_req TINYINT(1) NOT NULL DEFAULT 0,
  ring_at INT NULL, rung_by INT NULL,
  joined_at INT NULL, in_at INT NULL, left_at INT NULL, seen_at INT NULL,
  PRIMARY KEY (room_id, user_id), KEY k_user (user_id, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meet_signal (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_id INT NOT NULL,
  from_id INT NOT NULL,
  to_id INT NOT NULL,
  data MEDIUMTEXT NOT NULL,
  created_at INT NOT NULL,
  KEY k_to (room_id, to_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meet_line (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_id INT NOT NULL,
  user_id INT NOT NULL,
  kind VARCHAR(5) NOT NULL,
  body TEXT NOT NULL,
  lang VARCHAR(8) NOT NULL DEFAULT '',
  created_at INT NOT NULL,
  KEY k_room (room_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meet_presence (
  user_id INT PRIMARY KEY,
  seen_at INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
