-- Idempotent columns for SMTP + archive settings (MySQL/MariaDB friendly)
SET @has_users := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uzytkownicy'
);

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uzytkownicy' AND COLUMN_NAME = 'smtp_pass_enc'
);
SET @sql := IF(@has_users = 1 AND @has_col = 0,
    'ALTER TABLE uzytkownicy ADD COLUMN smtp_pass_enc TEXT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uzytkownicy' AND COLUMN_NAME = 'smtp_host'
);
SET @sql := IF(@has_users = 1 AND @has_col = 0,
    'ALTER TABLE uzytkownicy ADD COLUMN smtp_host VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uzytkownicy' AND COLUMN_NAME = 'smtp_port'
);
SET @sql := IF(@has_users = 1 AND @has_col = 0,
    'ALTER TABLE uzytkownicy ADD COLUMN smtp_port INT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uzytkownicy' AND COLUMN_NAME = 'smtp_secure'
);
SET @sql := IF(@has_users = 1 AND @has_col = 0,
    "ALTER TABLE uzytkownicy ADD COLUMN smtp_secure VARCHAR(10) NOT NULL DEFAULT 'tls'",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uzytkownicy' AND COLUMN_NAME = 'smtp_enabled'
);
SET @sql := IF(@has_users = 1 AND @has_col = 0,
    'ALTER TABLE uzytkownicy ADD COLUMN smtp_enabled TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_conf := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'konfiguracja_systemu'
);

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'konfiguracja_systemu' AND COLUMN_NAME = 'crm_archive_bcc_email'
);
SET @sql := IF(@has_conf = 1 AND @has_col = 0,
    'ALTER TABLE konfiguracja_systemu ADD COLUMN crm_archive_bcc_email VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'konfiguracja_systemu' AND COLUMN_NAME = 'crm_archive_enabled'
);
SET @sql := IF(@has_conf = 1 AND @has_col = 0,
    'ALTER TABLE konfiguracja_systemu ADD COLUMN crm_archive_enabled TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS mail_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NULL,
    lead_id INT NULL,
    campaign_id INT NULL,
    owner_user_id INT NOT NULL,
    direction ENUM('OUT') NOT NULL,
    from_email VARCHAR(255) NULL,
    from_name VARCHAR(255) NULL,
    to_email TEXT NULL,
    cc_email TEXT NULL,
    bcc_email TEXT NULL,
    subject VARCHAR(255) NULL,
    body_html MEDIUMTEXT NULL,
    body_text MEDIUMTEXT NULL,
    status ENUM('SENT','ERROR') NOT NULL DEFAULT 'SENT',
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mail_messages_owner_created (owner_user_id, created_at),
    INDEX idx_mail_messages_client_created (client_id, created_at),
    INDEX idx_mail_messages_campaign_created (campaign_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mail_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mail_message_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    stored_path VARCHAR(512) NOT NULL,
    mime_type VARCHAR(100) NULL,
    size_bytes INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mail_attachments_message (mail_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
