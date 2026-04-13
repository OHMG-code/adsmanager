CREATE TABLE IF NOT EXISTS activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity_type ENUM('lead','klient','task') NOT NULL,
  entity_id INT NOT NULL,
  action VARCHAR(50) NOT NULL,
  message TEXT NULL,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_activity_log_entity (entity_type, entity_id, created_at),
  INDEX idx_activity_log_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
