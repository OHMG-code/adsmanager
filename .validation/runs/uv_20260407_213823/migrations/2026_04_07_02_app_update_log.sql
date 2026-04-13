CREATE TABLE IF NOT EXISTS app_update_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    update_run_id CHAR(36) NOT NULL,
    phase VARCHAR(32) NOT NULL,
    event_key VARCHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL,
    message VARCHAR(255) NOT NULL,
    actor_user_id INT NULL,
    actor_login VARCHAR(100) NULL,
    validation_json TEXT NULL,
    result_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_app_update_log_run_id (update_run_id, id),
    KEY idx_app_update_log_phase (phase, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE app_meta
    ADD COLUMN update_state VARCHAR(32) NULL AFTER last_manifest_url,
    ADD COLUMN update_run_id CHAR(36) NULL AFTER update_state,
    ADD COLUMN update_target_version VARCHAR(64) NULL AFTER update_run_id,
    ADD COLUMN update_started_at DATETIME NULL AFTER update_target_version,
    ADD COLUMN update_completed_at DATETIME NULL AFTER update_started_at,
    ADD COLUMN update_last_heartbeat_at DATETIME NULL AFTER update_completed_at,
    ADD COLUMN update_lock_expires_at DATETIME NULL AFTER update_last_heartbeat_at,
    ADD COLUMN update_lock_owner VARCHAR(120) NULL AFTER update_lock_expires_at,
    ADD COLUMN update_maintenance_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER update_lock_owner,
    ADD COLUMN update_backup_confirmed_at DATETIME NULL AFTER update_maintenance_mode,
    ADD COLUMN update_backup_confirmed_by VARCHAR(120) NULL AFTER update_backup_confirmed_at,
    ADD COLUMN update_total_migrations INT UNSIGNED NOT NULL DEFAULT 0 AFTER update_backup_confirmed_by,
    ADD COLUMN update_completed_migrations INT UNSIGNED NOT NULL DEFAULT 0 AFTER update_total_migrations,
    ADD COLUMN update_last_error VARCHAR(255) NULL AFTER update_completed_migrations;
