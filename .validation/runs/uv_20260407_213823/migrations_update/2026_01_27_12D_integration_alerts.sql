-- Throttle table for integrations alerts
CREATE TABLE IF NOT EXISTS integration_alerts (
    alert_key VARCHAR(80) PRIMARY KEY,
    last_logged_at DATETIME NOT NULL,
    last_payload TEXT NULL
);

-- Optional: finished_at for queue metrics (MySQL/MariaDB friendly)
SET @has_queue := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_refresh_queue'
);
SET @has_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gus_refresh_queue' AND COLUMN_NAME = 'finished_at'
);
SET @sql := IF(@has_queue = 1 AND @has_col = 0,
    'ALTER TABLE gus_refresh_queue ADD COLUMN finished_at DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
