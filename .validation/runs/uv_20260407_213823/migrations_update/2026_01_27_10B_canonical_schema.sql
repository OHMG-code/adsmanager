-- Stage 10B: canonical companies schema adjustments (idempotent)

SET @has_companies := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies'
);
SET @has_klienci := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'klienci'
);
SET @has_gus := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_snapshots'
);

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'gus_last_sid'
);
SET @sql := IF(@has_companies = 1 AND @has_col = 0,
    'ALTER TABLE companies ADD COLUMN gus_last_sid VARCHAR(100) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND INDEX_NAME = 'idx_companies_regon'
);
SET @sql := IF(@has_companies = 1 AND @has_idx = 0,
    'CREATE INDEX idx_companies_regon ON companies(regon)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND INDEX_NAME = 'idx_companies_krs'
);
SET @sql := IF(@has_companies = 1 AND @has_idx = 0,
    'CREATE INDEX idx_companies_krs ON companies(krs)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'klienci' AND COLUMN_NAME = 'company_id'
);
SET @sql := IF(@has_klienci = 1 AND @has_col = 0,
    'ALTER TABLE klienci ADD COLUMN company_id INT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'klienci' AND INDEX_NAME = 'idx_klienci_company_id'
);
SET @sql := IF(@has_klienci = 1 AND @has_idx = 0,
    'CREATE INDEX idx_klienci_company_id ON klienci(company_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_snapshots' AND INDEX_NAME = 'idx_gus_snapshots_company_created'
);
SET @sql := IF(@has_gus = 1 AND @has_idx = 0,
    'CREATE INDEX idx_gus_snapshots_company_created ON gus_snapshots(company_id, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
