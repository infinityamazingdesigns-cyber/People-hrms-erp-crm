-- Attendance (part 1): phone punches with a live selfie and a precise GPS fix; office geofence from the branch.
-- Punches are never edited or deleted; corrections (part 2) are separate approved requests.
-- Selfies live in the database with the punch (a deploy wipes upload folders).

CREATE TABLE IF NOT EXISTS attendance_punch (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  emp_code VARCHAR(32) NULL,
  branch_id INT NULL,
  day DATE NOT NULL,
  time VARCHAR(8) NOT NULL,          -- HH:MM:SS; a flexible-hours punch after midnight may read 25:10:00
  type CHAR(3) NOT NULL,             -- IN | OUT
  no TINYINT NOT NULL,
  lat DECIMAL(9,6) NOT NULL, lng DECIMAL(9,6) NOT NULL, accuracy_m INT NOT NULL,
  office_id INT NULL, distance_m INT NULL,
  geofence VARCHAR(8) NOT NULL,      -- inside | outside | none
  remarks VARCHAR(200) NULL,
  selfie_id CHAR(32) NOT NULL,
  device VARCHAR(10) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_punch (user_id, day, no),
  KEY k_day (day), KEY k_code (emp_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance_selfie (
  id CHAR(32) NOT NULL PRIMARY KEY,
  user_id INT NOT NULL,
  bytes MEDIUMBLOB NOT NULL,
  size INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
