CREATE TABLE IF NOT EXISTS mail_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    imap_host VARCHAR(255) NOT NULL,
    imap_port INT NOT NULL DEFAULT 993,
    imap_encryption ENUM('ssl','tls','none') NOT NULL DEFAULT 'ssl',
    smtp_host VARCHAR(255) NOT NULL,
    smtp_port INT NOT NULL DEFAULT 587,
    smtp_encryption ENUM('ssl','tls','none') NOT NULL DEFAULT 'tls',
    email_address VARCHAR(255) NOT NULL,
    username VARCHAR(255) NOT NULL,
    password_enc TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_sync_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_mail_accounts_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mail_threads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('lead','client') NOT NULL,
    entity_id INT NOT NULL,
    subject VARCHAR(255) NULL,
    subject_hash CHAR(64) NOT NULL,
    last_message_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mail_threads_entity_last (entity_type, entity_id, last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

-- Idempotent mail_messages updates (MySQL/MariaDB friendly)
SET @has_mail := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages'
);
SET @has_attachments := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_attachments'
);
SET @has_accounts := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_accounts'
);
SET @has_crm := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_aktywnosci'
);

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND COLUMN_NAME = 'message_id'
);
SET @sql := IF(@has_mail = 1 AND @has_col = 0,
    'ALTER TABLE mail_messages ADD COLUMN message_id VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND COLUMN_NAME = 'mail_account_id'
);
SET @sql := IF(@has_mail = 1 AND @has_col = 0,
    'ALTER TABLE mail_messages ADD COLUMN mail_account_id INT NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND COLUMN_NAME = 'thread_id'
);
SET @sql := IF(@has_mail = 1 AND @has_col = 0,
    'ALTER TABLE mail_messages ADD COLUMN thread_id INT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND COLUMN_NAME = 'to_emails'
);
SET @sql := IF(@has_mail = 1 AND @has_col = 0,
    'ALTER TABLE mail_messages ADD COLUMN to_emails TEXT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND COLUMN_NAME = 'cc_emails'
);
SET @sql := IF(@has_mail = 1 AND @has_col = 0,
    'ALTER TABLE mail_messages ADD COLUMN cc_emails TEXT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND COLUMN_NAME = 'sent_at'
);
SET @sql := IF(@has_mail = 1 AND @has_col = 0,
    'ALTER TABLE mail_messages ADD COLUMN sent_at DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND COLUMN_NAME = 'has_attachments'
);
SET @sql := IF(@has_mail = 1 AND @has_col = 0,
    'ALTER TABLE mail_messages ADD COLUMN has_attachments TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND COLUMN_NAME = 'entity_type'
);
SET @sql := IF(@has_mail = 1 AND @has_col = 0,
    'ALTER TABLE mail_messages ADD COLUMN entity_type ENUM(''lead'',''client'') NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND COLUMN_NAME = 'entity_id'
);
SET @sql := IF(@has_mail = 1 AND @has_col = 0,
    'ALTER TABLE mail_messages ADD COLUMN entity_id INT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND COLUMN_NAME = 'created_by_user_id'
);
SET @sql := IF(@has_mail = 1 AND @has_col = 0,
    'ALTER TABLE mail_messages ADD COLUMN created_by_user_id INT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND COLUMN_NAME = 'direction'
);
SET @sql := IF(@has_mail = 1 AND @has_col = 1,
    'ALTER TABLE mail_messages MODIFY COLUMN direction ENUM(''in'',''out'',''IN'',''OUT'') NOT NULL',
    IF(@has_mail = 1 AND @has_col = 0,
        'ALTER TABLE mail_messages ADD COLUMN direction ENUM(''in'',''out'',''IN'',''OUT'') NOT NULL',
        'SELECT 1'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_attachments' AND COLUMN_NAME = 'storage_path'
);
SET @sql := IF(@has_attachments = 1 AND @has_col = 0,
    'ALTER TABLE mail_attachments ADD COLUMN storage_path VARCHAR(500) NOT NULL DEFAULT ''''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_mail = 1 AND @has_accounts = 1,
    'UPDATE mail_messages m LEFT JOIN mail_accounts ma ON ma.user_id = m.owner_user_id SET m.mail_account_id = IFNULL(ma.id, 0) WHERE m.mail_account_id IS NULL OR m.mail_account_id = 0',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_mail = 1,
    'UPDATE mail_messages SET message_id = CONCAT(''legacy-'', id, ''@local'') WHERE message_id IS NULL OR message_id = ''''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_mail = 1,
    'UPDATE mail_messages SET to_emails = to_email WHERE to_emails IS NULL AND to_email IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_mail = 1,
    'UPDATE mail_messages SET cc_emails = cc_email WHERE cc_emails IS NULL AND cc_email IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_mail = 1,
    'UPDATE mail_messages SET entity_type = ''client'', entity_id = client_id WHERE entity_type IS NULL AND client_id IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_mail = 1,
    'UPDATE mail_messages SET entity_type = ''lead'', entity_id = lead_id WHERE entity_type IS NULL AND lead_id IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_mail = 1 AND @has_attachments = 1,
    'UPDATE mail_messages SET has_attachments = 1 WHERE id IN (SELECT mail_message_id FROM mail_attachments)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_attachments = 1,
    'UPDATE mail_attachments SET storage_path = stored_path WHERE storage_path = '''' OR storage_path IS NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND COLUMN_NAME = 'message_id'
);
SET @sql := IF(@has_mail = 1 AND @has_col = 1,
    'ALTER TABLE mail_messages MODIFY COLUMN message_id VARCHAR(255) NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND INDEX_NAME = 'uniq_mail_messages_account_message'
);
SET @sql := IF(@has_mail = 1 AND @has_idx = 0,
    'CREATE UNIQUE INDEX uniq_mail_messages_account_message ON mail_messages(mail_account_id, message_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND INDEX_NAME = 'idx_mail_messages_entity_created'
);
SET @sql := IF(@has_mail = 1 AND @has_idx = 0,
    'CREATE INDEX idx_mail_messages_entity_created ON mail_messages(entity_type, entity_id, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND INDEX_NAME = 'idx_mail_messages_thread_created'
);
SET @sql := IF(@has_mail = 1 AND @has_idx = 0,
    'CREATE INDEX idx_mail_messages_thread_created ON mail_messages(thread_id, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_aktywnosci' AND COLUMN_NAME = 'typ'
);
SET @sql := IF(@has_crm = 1 AND @has_col = 1,
    'ALTER TABLE crm_aktywnosci MODIFY COLUMN typ ENUM(''status'',''notatka'',''mail'',''system'',''email_in'',''email_out'',''sms_in'',''sms_out'') NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
