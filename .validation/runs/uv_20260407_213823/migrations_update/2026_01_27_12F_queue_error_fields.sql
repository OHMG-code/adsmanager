-- Stage 12F: queue error fields (MySQL/MariaDB friendly)

SET @has_queue := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_refresh_queue'
);

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_refresh_queue' AND COLUMN_NAME = 'error_class'
);
SET @sql := IF(@has_queue = 1 AND @has_col = 0,
    'ALTER TABLE gus_refresh_queue ADD COLUMN error_class VARCHAR(20) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_refresh_queue' AND COLUMN_NAME = 'error_code'
);
SET @sql := IF(@has_queue = 1 AND @has_col = 0,
    'ALTER TABLE gus_refresh_queue ADD COLUMN error_code VARCHAR(50) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_refresh_queue' AND COLUMN_NAME = 'error_message'
);
SET @sql := IF(@has_queue = 1 AND @has_col = 0,
    'ALTER TABLE gus_refresh_queue ADD COLUMN error_message TEXT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_refresh_queue' AND COLUMN_NAME = 'finished_at'
);
SET @sql := IF(@has_queue = 1 AND @has_col = 0,
    'ALTER TABLE gus_refresh_queue ADD COLUMN finished_at DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_refresh_queue' AND COLUMN_NAME = 'next_run_at'
);
SET @sql := IF(@has_queue = 1 AND @has_col = 1,
    'ALTER TABLE gus_refresh_queue MODIFY next_run_at DATETIME NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
