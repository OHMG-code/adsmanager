CREATE TABLE IF NOT EXISTS document_acceptance_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  document_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  recipient_email VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_document_acceptance_token_hash (token_hash),
  KEY idx_document_acceptance_tokens_document (document_id),
  KEY idx_document_acceptance_tokens_active (document_id, used_at, revoked_at, expires_at),
  KEY idx_document_acceptance_tokens_email (recipient_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS document_acceptance_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  document_id INT NOT NULL,
  token_id INT NULL,
  action VARCHAR(30) NOT NULL,
  recipient_email VARCHAR(255) NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_document_acceptance_log_document (document_id),
  KEY idx_document_acceptance_log_token (token_id),
  KEY idx_document_acceptance_log_action (action),
  KEY idx_document_acceptance_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
