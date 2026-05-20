CREATE TABLE IF NOT EXISTS document_campaign_sync_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  document_id INT NOT NULL,
  campaign_id INT NULL,
  action VARCHAR(40) NOT NULL,
  old_campaign_status VARCHAR(80) NULL,
  new_campaign_status VARCHAR(80) NULL,
  old_spot_status VARCHAR(255) NULL,
  new_spot_status VARCHAR(255) NULL,
  message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_document_campaign_sync_document (document_id),
  KEY idx_document_campaign_sync_campaign (campaign_id),
  KEY idx_document_campaign_sync_action (action),
  KEY idx_document_campaign_sync_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
