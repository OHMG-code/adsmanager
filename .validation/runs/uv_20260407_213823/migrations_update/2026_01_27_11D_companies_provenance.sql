-- Stage 11D: companies provenance columns (MySQL/MariaDB friendly)

SET @has_companies := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies'
);

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'name_source'
);
SET @sql := IF(@has_companies = 1 AND @has_col = 0,
    'ALTER TABLE companies ADD COLUMN name_source VARCHAR(10) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'address_source'
);
SET @sql := IF(@has_companies = 1 AND @has_col = 0,
    'ALTER TABLE companies ADD COLUMN address_source VARCHAR(10) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'identifiers_source'
);
SET @sql := IF(@has_companies = 1 AND @has_col = 0,
    'ALTER TABLE companies ADD COLUMN identifiers_source VARCHAR(10) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'name_updated_at'
);
SET @sql := IF(@has_companies = 1 AND @has_col = 0,
    'ALTER TABLE companies ADD COLUMN name_updated_at DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'address_updated_at'
);
SET @sql := IF(@has_companies = 1 AND @has_col = 0,
    'ALTER TABLE companies ADD COLUMN address_updated_at DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'identifiers_updated_at'
);
SET @sql := IF(@has_companies = 1 AND @has_col = 0,
    'ALTER TABLE companies ADD COLUMN identifiers_updated_at DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'last_manual_update_at'
);
SET @sql := IF(@has_companies = 1 AND @has_col = 0,
    'ALTER TABLE companies ADD COLUMN last_manual_update_at DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'last_gus_update_at'
);
SET @sql := IF(@has_companies = 1 AND @has_col = 0,
    'ALTER TABLE companies ADD COLUMN last_gus_update_at DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Optional checks (best effort)
SET @has_chk := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND CONSTRAINT_NAME = 'chk_companies_name_source'
);
SET @sql := IF(@has_companies = 1 AND @has_chk = 0,
    'ALTER TABLE companies ADD CONSTRAINT chk_companies_name_source CHECK (name_source IN (''manual'',''gus'') OR name_source IS NULL)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_chk := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND CONSTRAINT_NAME = 'chk_companies_address_source'
);
SET @sql := IF(@has_companies = 1 AND @has_chk = 0,
    'ALTER TABLE companies ADD CONSTRAINT chk_companies_address_source CHECK (address_source IN (''manual'',''gus'') OR address_source IS NULL)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_chk := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND CONSTRAINT_NAME = 'chk_companies_identifiers_source'
);
SET @sql := IF(@has_companies = 1 AND @has_chk = 0,
    'ALTER TABLE companies ADD CONSTRAINT chk_companies_identifiers_source CHECK (identifiers_source IN (''manual'',''gus'') OR identifiers_source IS NULL)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
