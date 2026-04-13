-- Stage 10B: optional foreign keys (idempotent)

SET @has_klienci := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'klienci'
);
SET @has_companies := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies'
);
SET @has_snapshots := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_snapshots'
);

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'klienci' AND COLUMN_NAME = 'company_id'
);
SET @has_fk := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'klienci' AND CONSTRAINT_NAME = 'fk_klienci_company'
);
SET @sql := IF(@has_klienci = 1 AND @has_companies = 1 AND @has_col = 1 AND @has_fk = 0,
    'ALTER TABLE klienci ADD CONSTRAINT fk_klienci_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_snapshots' AND COLUMN_NAME = 'company_id'
);
SET @has_fk := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_snapshots' AND CONSTRAINT_NAME = 'fk_gus_snapshots_company'
);
SET @sql := IF(@has_snapshots = 1 AND @has_companies = 1 AND @has_col = 1 AND @has_fk = 0,
    'ALTER TABLE gus_snapshots ADD CONSTRAINT fk_gus_snapshots_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
