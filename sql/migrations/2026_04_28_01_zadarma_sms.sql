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

SET @sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sms_messages' AND column_name = 'related_type'),
        'DO 1',
        'ALTER TABLE sms_messages ADD COLUMN related_type VARCHAR(40) NULL AFTER id'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sms_messages' AND column_name = 'related_id'),
        'DO 1',
        'ALTER TABLE sms_messages ADD COLUMN related_id INT NULL AFTER related_type'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sms_messages' AND column_name = 'sender'),
        'DO 1',
        'ALTER TABLE sms_messages ADD COLUMN sender VARCHAR(120) NULL AFTER content'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sms_messages' AND column_name = 'provider_response'),
        'DO 1',
        'ALTER TABLE sms_messages ADD COLUMN provider_response LONGTEXT NULL AFTER provider_message_id'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sms_messages' AND column_name = 'cost'),
        'DO 1',
        'ALTER TABLE sms_messages ADD COLUMN cost DECIMAL(12,4) NULL AFTER provider_response'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sms_messages' AND column_name = 'currency'),
        'DO 1',
        'ALTER TABLE sms_messages ADD COLUMN currency VARCHAR(10) NULL AFTER cost'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sms_messages' AND column_name = 'error_message'),
        'DO 1',
        'ALTER TABLE sms_messages ADD COLUMN error_message TEXT NULL AFTER currency'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sms_messages' AND column_name = 'sent_at'),
        'DO 1',
        'ALTER TABLE sms_messages ADD COLUMN sent_at DATETIME NULL AFTER created_at'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE sms_messages
SET related_type = entity_type
WHERE related_type IS NULL AND entity_type IS NOT NULL;

UPDATE sms_messages
SET related_id = entity_id
WHERE related_id IS NULL AND entity_id IS NOT NULL;

SET @sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'sms_messages' AND index_name = 'idx_sms_messages_status_created'),
        'DO 1',
        'CREATE INDEX idx_sms_messages_status_created ON sms_messages(status, created_at)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'sms_messages' AND index_name = 'idx_sms_messages_related_created'),
        'DO 1',
        'CREATE INDEX idx_sms_messages_related_created ON sms_messages(related_type, related_id, created_at)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'konfiguracja_systemu' AND column_name = 'zadarma_api_key'),
        'DO 1',
        'ALTER TABLE konfiguracja_systemu ADD COLUMN zadarma_api_key VARCHAR(255) NULL'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'konfiguracja_systemu' AND column_name = 'zadarma_api_secret'),
        'DO 1',
        'ALTER TABLE konfiguracja_systemu ADD COLUMN zadarma_api_secret VARCHAR(255) NULL'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'konfiguracja_systemu' AND column_name = 'zadarma_sms_sender'),
        'DO 1',
        'ALTER TABLE konfiguracja_systemu ADD COLUMN zadarma_sms_sender VARCHAR(120) NULL'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'konfiguracja_systemu' AND column_name = 'zadarma_api_base_url'),
        'DO 1',
        'ALTER TABLE konfiguracja_systemu ADD COLUMN zadarma_api_base_url VARCHAR(255) NOT NULL DEFAULT ''https://api.zadarma.com'''
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'konfiguracja_systemu' AND column_name = 'sms_dry_run'),
        'DO 1',
        'ALTER TABLE konfiguracja_systemu ADD COLUMN sms_dry_run TINYINT(1) NOT NULL DEFAULT 1'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE konfiguracja_systemu
SET zadarma_api_base_url = 'https://api.zadarma.com'
WHERE id = 1 AND (zadarma_api_base_url IS NULL OR TRIM(zadarma_api_base_url) = '');
