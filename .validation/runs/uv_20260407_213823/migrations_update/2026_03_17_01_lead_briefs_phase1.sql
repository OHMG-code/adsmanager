CREATE TABLE IF NOT EXISTS lead_briefs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    lead_id INT NULL,
    token CHAR(64) NOT NULL,
    status ENUM('draft', 'sent', 'submitted', 'approved_internal') NOT NULL DEFAULT 'draft',
    is_customer_editable TINYINT(1) NOT NULL DEFAULT 1,
    spot_length_seconds INT NULL,
    lector_count INT NULL,
    target_group TEXT NULL,
    main_message TEXT NULL,
    additional_info TEXT NULL,
    contact_details TEXT NULL,
    tone_style TEXT NULL,
    sound_effects TEXT NULL,
    notes TEXT NULL,
    production_owner_user_id INT NULL,
    production_external_studio VARCHAR(255) NULL,
    submitted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lead_briefs_campaign_id (campaign_id),
    UNIQUE KEY uq_lead_briefs_token (token),
    KEY idx_lead_briefs_lead_id (lead_id),
    KEY idx_lead_briefs_status (status),
    KEY idx_lead_briefs_production_owner (production_owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE kampanie
    ADD COLUMN IF NOT EXISTS realization_status VARCHAR(50) NULL DEFAULT NULL;

UPDATE kampanie
SET realization_status = CASE
    WHEN LOWER(TRIM(COALESCE(status, ''))) IN ('zamowiona', 'zamówiona') THEN 'brief_oczekuje'
    WHEN realization_status IS NULL OR TRIM(COALESCE(realization_status, '')) = '' THEN NULL
    ELSE realization_status
END
WHERE realization_status IS NULL OR TRIM(COALESCE(realization_status, '')) = '';

CREATE INDEX IF NOT EXISTS idx_kampanie_realization_status ON kampanie(realization_status);

ALTER TABLE spot_audio_files
    ADD COLUMN IF NOT EXISTS is_final TINYINT(1) NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_spot_audio_files_spot_final ON spot_audio_files(spot_id, is_final);
