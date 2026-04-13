-- 11B: allow legacy firm columns to be NULL (idempotent)

SET @has_klienci := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'klienci'
);

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'klienci' AND COLUMN_NAME = 'nip'
);
SET @sql := IF(@has_klienci = 1 AND @has_col = 1,
    'ALTER TABLE klienci MODIFY nip VARCHAR(20) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'klienci' AND COLUMN_NAME = 'nazwa_firmy'
);
SET @sql := IF(@has_klienci = 1 AND @has_col = 1,
    'ALTER TABLE klienci MODIFY nazwa_firmy VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'klienci' AND COLUMN_NAME = 'regon'
);
SET @sql := IF(@has_klienci = 1 AND @has_col = 1,
    'ALTER TABLE klienci MODIFY regon VARCHAR(50) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'klienci' AND COLUMN_NAME = 'adres'
);
SET @sql := IF(@has_klienci = 1 AND @has_col = 1,
    'ALTER TABLE klienci MODIFY adres VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
