CREATE TABLE IF NOT EXISTS document_audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  document_id INT NOT NULL,
  user_id INT NULL,
  event_type VARCHAR(80) NOT NULL,
  event_label VARCHAR(255) NOT NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  metadata_json JSON NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_document_audit_document (document_id),
  KEY idx_document_audit_user (user_id),
  KEY idx_document_audit_type (event_type),
  KEY idx_document_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
