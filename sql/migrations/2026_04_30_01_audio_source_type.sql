ALTER TABLE spoty ADD COLUMN IF NOT EXISTS audio_source_type VARCHAR(32) NOT NULL DEFAULT 'produced_by_radio';
ALTER TABLE spoty ADD COLUMN IF NOT EXISTS client_audio_status VARCHAR(32) NOT NULL DEFAULT 'oczekuje_na_plik';
CREATE INDEX IF NOT EXISTS idx_spoty_audio_source_type ON spoty(audio_source_type);

ALTER TABLE spot_audio_files ADD COLUMN IF NOT EXISTS audio_format VARCHAR(16) NULL;
ALTER TABLE spot_audio_files ADD COLUMN IF NOT EXISTS duration_seconds DECIMAL(10,3) NULL;
ALTER TABLE spot_audio_files ADD COLUMN IF NOT EXISTS bitrate INT NULL;
ALTER TABLE spot_audio_files ADD COLUMN IF NOT EXISTS sample_rate INT NULL;
ALTER TABLE spot_audio_files ADD COLUMN IF NOT EXISTS channels INT NULL;
ALTER TABLE spot_audio_files ADD COLUMN IF NOT EXISTS client_audio_status VARCHAR(32) NULL;
