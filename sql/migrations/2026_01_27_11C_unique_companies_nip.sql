SET @has_companies := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies'
);

-- Normalize invalid NIP placeholders (if companies exists)
SET @sql := IF(@has_companies = 1,
    'UPDATE companies SET nip = NULL WHERE nip IS NOT NULL AND (TRIM(nip) = '''' OR TRIM(nip) = ''0000000000'' OR CHAR_LENGTH(nip) <> 10)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Drop old unique if present (MySQL/MariaDB friendly)
SET @has_idx := (
    SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND INDEX_NAME = 'uq_companies_nip'
    LIMIT 1
);
SET @sql := IF(@has_companies = 1 AND @has_idx = 1, 'DROP INDEX uq_companies_nip ON companies', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
    SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND INDEX_NAME = 'uq_companies_nip'
    LIMIT 1
);
SET @sql := IF(@has_companies = 1 AND @has_idx = 0, 'CREATE UNIQUE INDEX uq_companies_nip ON companies(nip)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
