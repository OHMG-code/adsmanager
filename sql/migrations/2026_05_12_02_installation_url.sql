SET @has_installation_url := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'konfiguracja_systemu' AND COLUMN_NAME = 'installation_url'
);
SET @sql := IF(@has_installation_url = 0,
    'ALTER TABLE konfiguracja_systemu ADD COLUMN installation_url VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
