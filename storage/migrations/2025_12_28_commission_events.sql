-- Commission rates per user
ALTER TABLE uzytkownicy
    ADD COLUMN IF NOT EXISTS prov_new_contract_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS prov_renewal_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS prov_next_invoice_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00;

-- Commission events (MVP)
CREATE TABLE IF NOT EXISTS prowizje_zdarzenia (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kampania_id INT NOT NULL,
    faktura_id INT NULL,
    user_id INT NOT NULL,
    client_id INT NULL,
    event_type ENUM('NOWA_UMOWA','PRZEDLUZENIE','KOLEJNA_FAKTURA') NOT NULL,
    base_netto DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pct_applied DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    commission_netto DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    event_type_override ENUM('NOWA_UMOWA','PRZEDLUZENIE','KOLEJNA_FAKTURA') NULL,
    pct_override DECIMAL(5,2) NULL,
    override_reason VARCHAR(255) NULL,
    override_by_user_id INT NULL,
    override_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_prowizje_zdarzenia_user_id (user_id),
    INDEX idx_prowizje_zdarzenia_kampania_id (kampania_id),
    INDEX idx_prowizje_zdarzenia_event_type (event_type),
    INDEX idx_prowizje_zdarzenia_created_at (created_at),
    INDEX idx_prowizje_zdarzenia_override_by (override_by_user_id),
    INDEX idx_prowizje_zdarzenia_client_id (client_id),
    CONSTRAINT fk_prowizje_zdarzenia_kampania
        FOREIGN KEY (kampania_id) REFERENCES kampanie(id) ON DELETE CASCADE,
    CONSTRAINT fk_prowizje_zdarzenia_user
        FOREIGN KEY (user_id) REFERENCES uzytkownicy(id) ON DELETE RESTRICT,
    CONSTRAINT fk_prowizje_zdarzenia_client
        FOREIGN KEY (client_id) REFERENCES klienci(id) ON DELETE SET NULL,
    CONSTRAINT fk_prowizje_zdarzenia_override_user
        FOREIGN KEY (override_by_user_id) REFERENCES uzytkownicy(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: consider future uniqueness on (kampania_id, user_id, event_type, YEAR(created_at), MONTH(created_at))
