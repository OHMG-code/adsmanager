CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    client_id INT NULL,
    source_lead_id INT NULL,
    owner_user_id INT NULL,
    value_netto DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    value_brutto DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status VARCHAR(30) NOT NULL DEFAULT 'won',
    won_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_transactions_campaign_id (campaign_id),
    INDEX idx_transactions_owner_won_at (owner_user_id, won_at),
    INDEX idx_transactions_status_won_at (status, won_at),
    INDEX idx_transactions_client_id (client_id),
    INDEX idx_transactions_source_lead_id (source_lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
