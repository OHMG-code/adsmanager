-- PROD_BOOTSTRAP_companies.sql
-- README: Idempotent bootstrap for companies + dependencies (klienci.company_id, gus_snapshots.company_id, gus_refresh_queue). No destructive ops. MariaDB/MySQL compatible.

-- 1) Companies base table
CREATE TABLE IF NOT EXISTS companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nip VARCHAR(20) NULL,
    regon VARCHAR(20) NULL,
    krs VARCHAR(20) NULL,
    name_full VARCHAR(255) NULL,
    name_short VARCHAR(255) NULL,
    status VARCHAR(50) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    gus_last_refresh_at DATETIME NULL,
    gus_last_status VARCHAR(10) NULL,
    gus_last_error_code VARCHAR(50) NULL,
    gus_last_error_message TEXT NULL,
    gus_last_sid VARCHAR(100) NULL,
    street VARCHAR(150) NULL,
    building_no VARCHAR(30) NULL,
    apartment_no VARCHAR(30) NULL,
    postal_code VARCHAR(20) NULL,
    city VARCHAR(150) NULL,
    gmina VARCHAR(150) NULL,
    powiat VARCHAR(150) NULL,
    wojewodztwo VARCHAR(150) NULL,
    country VARCHAR(80) NULL,
    lock_name TINYINT(1) NOT NULL DEFAULT 0,
    lock_address TINYINT(1) NOT NULL DEFAULT 0,
    lock_identifiers TINYINT(1) NOT NULL DEFAULT 0,
    last_gus_check_at DATETIME NULL,
    last_gus_error_at DATETIME NULL,
    last_gus_error_message TEXT NULL,
    name_source VARCHAR(10) NULL,
    address_source VARCHAR(10) NULL,
    identifiers_source VARCHAR(10) NULL,
    name_updated_at DATETIME NULL,
    address_updated_at DATETIME NULL,
    identifiers_updated_at DATETIME NULL,
    last_manual_update_at DATETIME NULL,
    last_gus_update_at DATETIME NULL,
    gus_hold_until DATETIME NULL,
    gus_hold_reason VARCHAR(20) NULL,
    gus_not_found TINYINT(1) NOT NULL DEFAULT 0,
    gus_not_found_at DATETIME NULL,
    gus_fail_streak INT NOT NULL DEFAULT 0,
    gus_last_error_class VARCHAR(20) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Companies: ensure missing columns (safe for partial schemas)
SET @has_companies := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies'
);

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'nip');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN nip VARCHAR(20) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'regon');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN regon VARCHAR(20) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'krs');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN krs VARCHAR(20) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'name_full');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN name_full VARCHAR(255) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'name_short');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN name_short VARCHAR(255) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'status');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN status VARCHAR(50) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'is_active');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'gus_last_refresh_at');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN gus_last_refresh_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'gus_last_status');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN gus_last_status VARCHAR(10) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'gus_last_error_code');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN gus_last_error_code VARCHAR(50) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'gus_last_error_message');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN gus_last_error_message TEXT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'gus_last_sid');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN gus_last_sid VARCHAR(100) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'street');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN street VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'building_no');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN building_no VARCHAR(30) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'apartment_no');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN apartment_no VARCHAR(30) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'postal_code');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN postal_code VARCHAR(20) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'city');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN city VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'gmina');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN gmina VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'powiat');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN powiat VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'wojewodztwo');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN wojewodztwo VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'country');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN country VARCHAR(80) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'lock_name');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN lock_name TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'lock_address');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN lock_address TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'lock_identifiers');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN lock_identifiers TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'last_gus_check_at');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN last_gus_check_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'last_gus_error_at');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN last_gus_error_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'last_gus_error_message');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN last_gus_error_message TEXT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'name_source');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN name_source VARCHAR(10) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'address_source');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN address_source VARCHAR(10) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'identifiers_source');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN identifiers_source VARCHAR(10) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'name_updated_at');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN name_updated_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'address_updated_at');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN address_updated_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'identifiers_updated_at');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN identifiers_updated_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'last_manual_update_at');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN last_manual_update_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'last_gus_update_at');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN last_gus_update_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'gus_hold_until');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN gus_hold_until DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'gus_hold_reason');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN gus_hold_reason VARCHAR(20) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'gus_not_found');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN gus_not_found TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'gus_not_found_at');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN gus_not_found_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'gus_fail_streak');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN gus_fail_streak INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'gus_last_error_class');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN gus_last_error_class VARCHAR(20) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'created_at');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@has_companies = 1 AND @has_col = 0, 'ALTER TABLE companies ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) Companies: indexes
SET @has_idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND INDEX_NAME = 'idx_companies_nip');
SET @sql := IF(@has_companies = 1 AND @has_idx = 0, 'CREATE INDEX idx_companies_nip ON companies(nip)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND INDEX_NAME = 'idx_companies_regon');
SET @sql := IF(@has_companies = 1 AND @has_idx = 0, 'CREATE INDEX idx_companies_regon ON companies(regon)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND INDEX_NAME = 'idx_companies_krs');
SET @sql := IF(@has_companies = 1 AND @has_idx = 0, 'CREATE INDEX idx_companies_krs ON companies(krs)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND INDEX_NAME = 'idx_companies_gus_hold_until');
SET @sql := IF(@has_companies = 1 AND @has_idx = 0, 'CREATE INDEX idx_companies_gus_hold_until ON companies(gus_hold_until)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND INDEX_NAME = 'idx_companies_gus_not_found');
SET @sql := IF(@has_companies = 1 AND @has_idx = 0, 'CREATE INDEX idx_companies_gus_not_found ON companies(gus_not_found)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) klienci: company_id link
SET @has_klienci := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'klienci'
);
SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'klienci' AND COLUMN_NAME = 'company_id');
SET @sql := IF(@has_klienci = 1 AND @has_col = 0, 'ALTER TABLE klienci ADD COLUMN company_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'klienci' AND INDEX_NAME = 'idx_klienci_company_id');
SET @sql := IF(@has_klienci = 1 AND @has_idx = 0, 'CREATE INDEX idx_klienci_company_id ON klienci(company_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5) gus_snapshots: company link + index (only if table exists)
SET @has_gus_snapshots := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_snapshots'
);
SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_snapshots' AND COLUMN_NAME = 'company_id');
SET @sql := IF(@has_gus_snapshots = 1 AND @has_col = 0, 'ALTER TABLE gus_snapshots ADD COLUMN company_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_snapshots' AND INDEX_NAME = 'idx_gus_snapshots_company');
SET @sql := IF(@has_gus_snapshots = 1 AND @has_idx = 0, 'CREATE INDEX idx_gus_snapshots_company ON gus_snapshots(company_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_snapshots' AND INDEX_NAME = 'idx_gus_snapshots_company_created');
SET @sql := IF(@has_gus_snapshots = 1 AND @has_idx = 0, 'CREATE INDEX idx_gus_snapshots_company_created ON gus_snapshots(company_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6) GUS refresh queue (used by GUS status screens)
CREATE TABLE IF NOT EXISTS gus_refresh_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    priority TINYINT NOT NULL DEFAULT 5,
    reason VARCHAR(50) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 3,
    next_run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    locked_by VARCHAR(50) NULL,
    error_class VARCHAR(20) NULL,
    last_error_code VARCHAR(50) NULL,
    last_error_message TEXT NULL,
    error_code VARCHAR(50) NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    finished_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @has_queue := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_refresh_queue'
);
SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_refresh_queue' AND COLUMN_NAME = 'error_class');
SET @sql := IF(@has_queue = 1 AND @has_col = 0, 'ALTER TABLE gus_refresh_queue ADD COLUMN error_class VARCHAR(20) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_refresh_queue' AND COLUMN_NAME = 'error_code');
SET @sql := IF(@has_queue = 1 AND @has_col = 0, 'ALTER TABLE gus_refresh_queue ADD COLUMN error_code VARCHAR(50) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_refresh_queue' AND COLUMN_NAME = 'error_message');
SET @sql := IF(@has_queue = 1 AND @has_col = 0, 'ALTER TABLE gus_refresh_queue ADD COLUMN error_message TEXT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_refresh_queue' AND COLUMN_NAME = 'finished_at');
SET @sql := IF(@has_queue = 1 AND @has_col = 0, 'ALTER TABLE gus_refresh_queue ADD COLUMN finished_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_refresh_queue' AND INDEX_NAME = 'idx_queue_status_next');
SET @sql := IF(@has_queue = 1 AND @has_idx = 0, 'CREATE INDEX idx_queue_status_next ON gus_refresh_queue(status, next_run_at, priority)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_refresh_queue' AND INDEX_NAME = 'idx_queue_company');
SET @sql := IF(@has_queue = 1 AND @has_idx = 0, 'CREATE INDEX idx_queue_company ON gus_refresh_queue(company_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_refresh_queue' AND INDEX_NAME = 'idx_queue_company_status');
SET @sql := IF(@has_queue = 1 AND @has_idx = 0, 'CREATE INDEX idx_queue_company_status ON gus_refresh_queue(company_id, status)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
