SET @sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'konfiguracja_systemu' AND column_name = 'ui_theme'),
        'DO 1',
        'ALTER TABLE konfiguracja_systemu ADD COLUMN ui_theme VARCHAR(20) NOT NULL DEFAULT ''light'''
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `konfiguracja_systemu`
SET `ui_theme` = 'light'
WHERE `ui_theme` NOT IN ('light', 'blue', 'dark') OR `ui_theme` IS NULL;
