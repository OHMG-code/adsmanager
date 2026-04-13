-- Stage 10B: run only after duplicates are resolved (idempotent)

SET @has_companies := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies'
);
SET @has_idx := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND INDEX_NAME = 'uq_companies_nip'
);
SET @sql := IF(@has_companies = 1 AND @has_idx = 0,
    'CREATE UNIQUE INDEX uq_companies_nip ON companies(nip)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
