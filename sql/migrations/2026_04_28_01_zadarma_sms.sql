CREATE TABLE IF NOT EXISTS sms_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('lead','client') NULL,
    entity_id INT NULL,
    user_id INT NULL,
    direction ENUM('in','out') NOT NULL,
    phone VARCHAR(40) NOT NULL,
    content TEXT NOT NULL,
    provider VARCHAR(50) NULL,
    provider_message_id VARCHAR(120) NULL,
    status VARCHAR(40) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sms_messages_entity_created (entity_type, entity_id, created_at),
    INDEX idx_sms_messages_phone_created (phone, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE sms_messages ADD COLUMN IF NOT EXISTS related_type VARCHAR(40) NULL AFTER id;
ALTER TABLE sms_messages ADD COLUMN IF NOT EXISTS related_id INT NULL AFTER related_type;
ALTER TABLE sms_messages ADD COLUMN IF NOT EXISTS sender VARCHAR(120) NULL AFTER content;
ALTER TABLE sms_messages ADD COLUMN IF NOT EXISTS provider_response LONGTEXT NULL AFTER provider_message_id;
ALTER TABLE sms_messages ADD COLUMN IF NOT EXISTS cost DECIMAL(12,4) NULL AFTER provider_response;
ALTER TABLE sms_messages ADD COLUMN IF NOT EXISTS currency VARCHAR(10) NULL AFTER cost;
ALTER TABLE sms_messages ADD COLUMN IF NOT EXISTS error_message TEXT NULL AFTER currency;
ALTER TABLE sms_messages ADD COLUMN IF NOT EXISTS sent_at DATETIME NULL AFTER created_at;

UPDATE sms_messages
SET related_type = entity_type
WHERE related_type IS NULL AND entity_type IS NOT NULL;

UPDATE sms_messages
SET related_id = entity_id
WHERE related_id IS NULL AND entity_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_sms_messages_status_created ON sms_messages(status, created_at);
CREATE INDEX IF NOT EXISTS idx_sms_messages_related_created ON sms_messages(related_type, related_id, created_at);

ALTER TABLE konfiguracja_systemu ADD COLUMN IF NOT EXISTS zadarma_api_key VARCHAR(255) NULL;
ALTER TABLE konfiguracja_systemu ADD COLUMN IF NOT EXISTS zadarma_api_secret VARCHAR(255) NULL;
ALTER TABLE konfiguracja_systemu ADD COLUMN IF NOT EXISTS zadarma_sms_sender VARCHAR(120) NULL;
ALTER TABLE konfiguracja_systemu ADD COLUMN IF NOT EXISTS zadarma_api_base_url VARCHAR(255) NOT NULL DEFAULT 'https://api.zadarma.com';
ALTER TABLE konfiguracja_systemu ADD COLUMN IF NOT EXISTS sms_dry_run TINYINT(1) NOT NULL DEFAULT 1;

UPDATE konfiguracja_systemu
SET zadarma_api_base_url = 'https://api.zadarma.com'
WHERE id = 1 AND (zadarma_api_base_url IS NULL OR TRIM(zadarma_api_base_url) = '');
