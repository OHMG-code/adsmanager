ALTER TABLE crm_zadania
    MODIFY COLUMN obiekt_typ ENUM('lead','klient','kampania') NOT NULL;

ALTER TABLE crm_zadania
    ADD COLUMN IF NOT EXISTS external_key VARCHAR(191) NULL AFTER status;

CREATE INDEX IF NOT EXISTS idx_crm_zadania_external_status
    ON crm_zadania (external_key, status);
