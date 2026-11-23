-- Privacy notice and terms: each save is a new version (newest is shown); earlier versions are kept.
CREATE TABLE IF NOT EXISTS legal_version (
  id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(10) NOT NULL, body MEDIUMTEXT NOT NULL, note VARCHAR(200) NOT NULL DEFAULT '',
  created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY k_kind (kind, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
