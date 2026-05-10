ALTER TABLE `konfiguracja_systemu`
  ADD COLUMN IF NOT EXISTS `ai_provider` VARCHAR(20) NOT NULL DEFAULT 'disabled',
  ADD COLUMN IF NOT EXISTS `ai_api_key_enc` TEXT NULL,
  ADD COLUMN IF NOT EXISTS `ai_model` VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS `ai_search_provider` VARCHAR(30) NOT NULL DEFAULT 'disabled',
  ADD COLUMN IF NOT EXISTS `google_places_api_key_enc` TEXT NULL,
  ADD COLUMN IF NOT EXISTS `ai_default_generation_limit` INT NOT NULL DEFAULT 20,
  ADD COLUMN IF NOT EXISTS `ai_max_generation_limit` INT NOT NULL DEFAULT 50,
  ADD COLUMN IF NOT EXISTS `ai_default_radius_km` INT NOT NULL DEFAULT 30;

ALTER TABLE `ai_leads_import`
  ADD COLUMN IF NOT EXISTS `external_id` VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS `recommended_package` VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS `opening_argument` TEXT NULL,
  ADD COLUMN IF NOT EXISTS `short_reason` TEXT NULL,
  ADD COLUMN IF NOT EXISTS `suggested_next_action` TEXT NULL,
  ADD COLUMN IF NOT EXISTS `enrichment_status` VARCHAR(30) NULL,
  ADD COLUMN IF NOT EXISTS `raw_source_data` LONGTEXT NULL,
  ADD INDEX IF NOT EXISTS `idx_ai_leads_import_external_id` (`external_id`);
