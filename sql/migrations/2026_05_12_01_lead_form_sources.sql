CREATE TABLE IF NOT EXISTS lead_form_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    public_key VARCHAR(80) NOT NULL,
    allowed_domains JSON NULL,
    default_source VARCHAR(120) NOT NULL DEFAULT 'external_form',
    consent_required TINYINT(1) NOT NULL DEFAULT 0,
    gus_lookup_enabled TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lead_form_sources_public_key (public_key),
    KEY idx_lead_form_sources_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lead_form_field_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_id INT NOT NULL,
    external_field VARCHAR(120) NOT NULL,
    crm_field VARCHAR(80) NOT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    KEY idx_lead_form_field_mappings_source (source_id),
    UNIQUE KEY uq_lead_form_mapping_field (source_id, external_field)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lead_form_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_id INT NULL,
    raw_payload JSON NULL,
    normalized_payload JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'received',
    error_message TEXT NULL,
    created_lead_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lead_form_submissions_source_created (source_id, created_at),
    KEY idx_lead_form_submissions_status (status),
    KEY idx_lead_form_submissions_lead (created_lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @has_external_source := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leady' AND COLUMN_NAME = 'external_source'
);
SET @sql := IF(@has_external_source = 0,
    'ALTER TABLE leady ADD COLUMN external_source VARCHAR(120) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_consent_required := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_form_sources' AND COLUMN_NAME = 'consent_required'
);
SET @sql := IF(@has_consent_required = 0,
    'ALTER TABLE lead_form_sources ADD COLUMN consent_required TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_gus_lookup_enabled := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_form_sources' AND COLUMN_NAME = 'gus_lookup_enabled'
);
SET @sql := IF(@has_gus_lookup_enabled = 0,
    'ALTER TABLE lead_form_sources ADD COLUMN gus_lookup_enabled TINYINT(1) NOT NULL DEFAULT 1',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_regon := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leady' AND COLUMN_NAME = 'regon'
);
SET @sql := IF(@has_regon = 0,
    'ALTER TABLE leady ADD COLUMN regon VARCHAR(20) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
