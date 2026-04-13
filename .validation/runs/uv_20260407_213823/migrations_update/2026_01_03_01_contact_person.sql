-- Stage 2026_01_03_01: contact person fields (idempotent, MySQL/MariaDB friendly)

SET @has_leady := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leady'
);
SET @has_klienci := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'klienci'
);

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leady' AND COLUMN_NAME = 'kontakt_imie_nazwisko'
);
SET @sql := IF(@has_leady = 1 AND @has_col = 0,
    'ALTER TABLE leady ADD COLUMN kontakt_imie_nazwisko VARCHAR(120) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leady' AND COLUMN_NAME = 'kontakt_stanowisko'
);
SET @sql := IF(@has_leady = 1 AND @has_col = 0,
    'ALTER TABLE leady ADD COLUMN kontakt_stanowisko VARCHAR(120) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leady' AND COLUMN_NAME = 'kontakt_telefon'
);
SET @sql := IF(@has_leady = 1 AND @has_col = 0,
    'ALTER TABLE leady ADD COLUMN kontakt_telefon VARCHAR(60) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leady' AND COLUMN_NAME = 'kontakt_email'
);
SET @sql := IF(@has_leady = 1 AND @has_col = 0,
    'ALTER TABLE leady ADD COLUMN kontakt_email VARCHAR(120) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leady' AND COLUMN_NAME = 'kontakt_preferencja'
);
SET @sql := IF(@has_leady = 1 AND @has_col = 0,
    'ALTER TABLE leady ADD COLUMN kontakt_preferencja ENUM(''telefon'',''email'',''sms'','''') NOT NULL DEFAULT ''''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'klienci' AND COLUMN_NAME = 'kontakt_imie_nazwisko'
);
SET @sql := IF(@has_klienci = 1 AND @has_col = 0,
    'ALTER TABLE klienci ADD COLUMN kontakt_imie_nazwisko VARCHAR(120) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'klienci' AND COLUMN_NAME = 'kontakt_stanowisko'
);
SET @sql := IF(@has_klienci = 1 AND @has_col = 0,
    'ALTER TABLE klienci ADD COLUMN kontakt_stanowisko VARCHAR(120) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'klienci' AND COLUMN_NAME = 'kontakt_telefon'
);
SET @sql := IF(@has_klienci = 1 AND @has_col = 0,
    'ALTER TABLE klienci ADD COLUMN kontakt_telefon VARCHAR(60) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'klienci' AND COLUMN_NAME = 'kontakt_email'
);
SET @sql := IF(@has_klienci = 1 AND @has_col = 0,
    'ALTER TABLE klienci ADD COLUMN kontakt_email VARCHAR(120) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'klienci' AND COLUMN_NAME = 'kontakt_preferencja'
);
SET @sql := IF(@has_klienci = 1 AND @has_col = 0,
    'ALTER TABLE klienci ADD COLUMN kontakt_preferencja ENUM(''telefon'',''email'',''sms'','''') NOT NULL DEFAULT ''''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
