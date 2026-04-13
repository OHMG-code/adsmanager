-- Idempotent IMAP columns + mail_messages updates (MySQL/MariaDB friendly)
SET @has_users := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uzytkownicy'
);
SET @has_mail := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages'
);

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uzytkownicy' AND COLUMN_NAME = 'imap_host'
);
SET @sql := IF(@has_users = 1 AND @has_col = 0,
    'ALTER TABLE uzytkownicy ADD COLUMN imap_host VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uzytkownicy' AND COLUMN_NAME = 'imap_port'
);
SET @sql := IF(@has_users = 1 AND @has_col = 0,
    'ALTER TABLE uzytkownicy ADD COLUMN imap_port INT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uzytkownicy' AND COLUMN_NAME = 'imap_user'
);
SET @sql := IF(@has_users = 1 AND @has_col = 0,
    'ALTER TABLE uzytkownicy ADD COLUMN imap_user VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uzytkownicy' AND COLUMN_NAME = 'imap_pass_enc'
);
SET @sql := IF(@has_users = 1 AND @has_col = 0,
    'ALTER TABLE uzytkownicy ADD COLUMN imap_pass_enc TEXT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uzytkownicy' AND COLUMN_NAME = 'imap_secure'
);
SET @sql := IF(@has_users = 1 AND @has_col = 0,
    'ALTER TABLE uzytkownicy ADD COLUMN imap_secure VARCHAR(10) NOT NULL DEFAULT ''tls''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uzytkownicy' AND COLUMN_NAME = 'imap_enabled'
);
SET @sql := IF(@has_users = 1 AND @has_col = 0,
    'ALTER TABLE uzytkownicy ADD COLUMN imap_enabled TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uzytkownicy' AND COLUMN_NAME = 'imap_mailbox'
);
SET @sql := IF(@has_users = 1 AND @has_col = 0,
    'ALTER TABLE uzytkownicy ADD COLUMN imap_mailbox VARCHAR(255) NOT NULL DEFAULT ''INBOX''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uzytkownicy' AND COLUMN_NAME = 'imap_last_uid'
);
SET @sql := IF(@has_users = 1 AND @has_col = 0,
    'ALTER TABLE uzytkownicy ADD COLUMN imap_last_uid INT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uzytkownicy' AND COLUMN_NAME = 'imap_last_sync_at'
);
SET @sql := IF(@has_users = 1 AND @has_col = 0,
    'ALTER TABLE uzytkownicy ADD COLUMN imap_last_sync_at DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND COLUMN_NAME = 'direction'
);
SET @sql := IF(@has_mail = 1 AND @has_col = 1,
    'ALTER TABLE mail_messages MODIFY COLUMN direction ENUM(''OUT'',''IN'') NOT NULL',
    IF(@has_mail = 1 AND @has_col = 0,
        'ALTER TABLE mail_messages ADD COLUMN direction ENUM(''OUT'',''IN'') NOT NULL',
        'SELECT 1'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND COLUMN_NAME = 'status'
);
SET @sql := IF(@has_mail = 1 AND @has_col = 1,
    'ALTER TABLE mail_messages MODIFY COLUMN status ENUM(''SENT'',''ERROR'',''RECEIVED'') NOT NULL DEFAULT ''SENT''',
    IF(@has_mail = 1 AND @has_col = 0,
        'ALTER TABLE mail_messages ADD COLUMN status ENUM(''SENT'',''ERROR'',''RECEIVED'') NOT NULL DEFAULT ''SENT''',
        'SELECT 1'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

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
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND COLUMN_NAME = 'in_reply_to'
);
SET @sql := IF(@has_mail = 1 AND @has_col = 0,
    'ALTER TABLE mail_messages ADD COLUMN in_reply_to VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND COLUMN_NAME = 'references_header'
);
SET @sql := IF(@has_mail = 1 AND @has_col = 0,
    'ALTER TABLE mail_messages ADD COLUMN references_header TEXT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND COLUMN_NAME = 'imap_uid'
);
SET @sql := IF(@has_mail = 1 AND @has_col = 0,
    'ALTER TABLE mail_messages ADD COLUMN imap_uid INT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND COLUMN_NAME = 'imap_mailbox'
);
SET @sql := IF(@has_mail = 1 AND @has_col = 0,
    'ALTER TABLE mail_messages ADD COLUMN imap_mailbox VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND COLUMN_NAME = 'received_at'
);
SET @sql := IF(@has_mail = 1 AND @has_col = 0,
    'ALTER TABLE mail_messages ADD COLUMN received_at DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND INDEX_NAME = 'idx_mail_messages_owner_imap_uid'
);
SET @sql := IF(@has_mail = 1 AND @has_idx = 0,
    'CREATE INDEX idx_mail_messages_owner_imap_uid ON mail_messages(owner_user_id, imap_uid, imap_mailbox)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_messages' AND INDEX_NAME = 'idx_mail_messages_message_id'
);
SET @sql := IF(@has_mail = 1 AND @has_idx = 0,
    'CREATE INDEX idx_mail_messages_message_id ON mail_messages(message_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
