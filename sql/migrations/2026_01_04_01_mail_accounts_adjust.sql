-- Adjust mail_accounts schema (idempotent, MySQL/MariaDB friendly)

SET @has_accounts := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_accounts'
);

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_accounts' AND COLUMN_NAME = 'imap_mailbox'
);
SET @sql := IF(@has_accounts = 1 AND @has_col = 0,
    'ALTER TABLE mail_accounts ADD COLUMN imap_mailbox VARCHAR(255) NOT NULL DEFAULT ''INBOX''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_accounts' AND COLUMN_NAME = 'smtp_from_name'
);
SET @sql := IF(@has_accounts = 1 AND @has_col = 0,
    'ALTER TABLE mail_accounts ADD COLUMN smtp_from_name VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_accounts' AND COLUMN_NAME = 'password_enc'
);
SET @sql := IF(@has_accounts = 1 AND @has_col = 1,
    'ALTER TABLE mail_accounts MODIFY COLUMN password_enc TEXT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_accounts' AND INDEX_NAME = 'uniq_mail_user'
);
SET @sql := IF(@has_accounts = 1 AND @has_idx = 0,
    'ALTER TABLE mail_accounts ADD UNIQUE KEY uniq_mail_user (user_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
