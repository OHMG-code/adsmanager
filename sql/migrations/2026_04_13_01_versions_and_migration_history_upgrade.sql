-- Stage CRM 1.0 upgrade foundations:
-- - add explicit app_meta.db_version
-- - extend schema_migrations with execution audit fields

SET @has_app_meta := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'app_meta'
);

SET @has_app_meta_db_version := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'app_meta'
      AND COLUMN_NAME = 'db_version'
);

SET @sql := IF(
    @has_app_meta = 1 AND @has_app_meta_db_version = 0,
    'ALTER TABLE app_meta ADD COLUMN db_version VARCHAR(64) NULL AFTER installed_version',
    'DO 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @has_app_meta = 1,
    'UPDATE app_meta
     SET db_version = COALESCE(NULLIF(db_version, ''''), installed_version)
     WHERE (db_version IS NULL OR db_version = '''')
       AND installed_version IS NOT NULL',
    'DO 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_schema_migrations := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'schema_migrations'
);

SET @has_schema_migrations_migration_name := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'schema_migrations'
      AND COLUMN_NAME = 'migration_name'
);

SET @sql := IF(
    @has_schema_migrations = 1 AND @has_schema_migrations_migration_name = 0,
    'ALTER TABLE schema_migrations ADD COLUMN migration_name VARCHAR(255) NULL AFTER filename',
    'DO 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_schema_migrations_executed_at := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'schema_migrations'
      AND COLUMN_NAME = 'executed_at'
);

SET @sql := IF(
    @has_schema_migrations = 1 AND @has_schema_migrations_executed_at = 0,
    'ALTER TABLE schema_migrations ADD COLUMN executed_at DATETIME NULL AFTER applied_at',
    'DO 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_schema_migrations_success := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'schema_migrations'
      AND COLUMN_NAME = 'success'
);

SET @sql := IF(
    @has_schema_migrations = 1 AND @has_schema_migrations_success = 0,
    'ALTER TABLE schema_migrations ADD COLUMN success TINYINT(1) NOT NULL DEFAULT 1 AFTER executed_at',
    'DO 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_schema_migrations_execution_time_ms := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'schema_migrations'
      AND COLUMN_NAME = 'execution_time_ms'
);

SET @sql := IF(
    @has_schema_migrations = 1 AND @has_schema_migrations_execution_time_ms = 0,
    'ALTER TABLE schema_migrations ADD COLUMN execution_time_ms INT UNSIGNED NULL AFTER success',
    'DO 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_schema_migrations_notes := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'schema_migrations'
      AND COLUMN_NAME = 'notes'
);

SET @sql := IF(
    @has_schema_migrations = 1 AND @has_schema_migrations_notes = 0,
    'ALTER TABLE schema_migrations ADD COLUMN notes VARCHAR(255) NULL AFTER execution_time_ms',
    'DO 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @has_schema_migrations = 1,
    'UPDATE schema_migrations
     SET migration_name = filename
     WHERE migration_name IS NULL OR migration_name = ''''',
    'DO 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @has_schema_migrations = 1,
    'UPDATE schema_migrations
     SET executed_at = COALESCE(executed_at, applied_at, CURRENT_TIMESTAMP)
     WHERE executed_at IS NULL',
    'DO 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @has_schema_migrations = 1,
    'UPDATE schema_migrations
     SET success = 1
     WHERE success IS NULL',
    'DO 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_schema_migrations_migration_name := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'schema_migrations'
      AND COLUMN_NAME = 'migration_name'
);

SET @has_schema_migrations_uq_migration_name := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'schema_migrations'
      AND INDEX_NAME = 'uq_schema_migrations_migration_name'
);

SET @sql := IF(
    @has_schema_migrations = 1
    AND @has_schema_migrations_migration_name = 1
    AND @has_schema_migrations_uq_migration_name = 0,
    'ALTER TABLE schema_migrations ADD UNIQUE KEY uq_schema_migrations_migration_name (migration_name)',
    'DO 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
