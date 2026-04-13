-- Cleanup: normalize schema_migrations.applied_at default for legacy environments

SET @has_schema_migrations := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'schema_migrations'
);

SET @needs_applied_at_default_fix := (
    SELECT CASE
        WHEN COUNT(*) = 0 THEN 0
        WHEN LOWER(COALESCE(MAX(COLUMN_DEFAULT), '')) LIKE 'current_timestamp%' THEN 0
        ELSE 1
    END
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'schema_migrations'
      AND COLUMN_NAME = 'applied_at'
);

SET @sql := IF(
    @has_schema_migrations = 1 AND @needs_applied_at_default_fix = 1,
    'ALTER TABLE schema_migrations MODIFY applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'DO 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
