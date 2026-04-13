ALTER TABLE app_meta
    ADD COLUMN baseline_id VARCHAR(128) NULL AFTER installed_at,
    ADD COLUMN release_channel VARCHAR(32) NULL AFTER baseline_id,
    ADD COLUMN last_update_check_at DATETIME NULL AFTER release_channel,
    ADD COLUMN last_update_check_status VARCHAR(32) NULL AFTER last_update_check_at,
    ADD COLUMN last_update_check_error TEXT NULL AFTER last_update_check_status,
    ADD COLUMN last_available_version VARCHAR(64) NULL AFTER last_update_check_error,
    ADD COLUMN last_available_published_at DATETIME NULL AFTER last_available_version,
    ADD COLUMN last_available_notes_url VARCHAR(255) NULL AFTER last_available_published_at,
    ADD COLUMN last_manifest_url VARCHAR(255) NULL AFTER last_available_notes_url;
