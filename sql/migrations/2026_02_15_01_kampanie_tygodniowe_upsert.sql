CREATE TABLE IF NOT EXISTS kampanie_tygodniowe (
    id INT AUTO_INCREMENT PRIMARY KEY,
    klient_nazwa VARCHAR(255) NOT NULL,
    nazwa_kampanii VARCHAR(255) NULL,
    dlugosc INT NOT NULL DEFAULT 20,
    data_start DATE NOT NULL,
    data_koniec DATE NOT NULL,
    sumy JSON NULL,
    produkty JSON NULL,
    netto_spoty DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    netto_dodatki DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    rabat DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    razem_po_rabacie DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    razem_brutto DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    siatka JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE kampanie_tygodniowe
    ADD COLUMN IF NOT EXISTS nazwa_kampanii VARCHAR(255) NULL AFTER klient_nazwa;

ALTER TABLE kampanie_tygodniowe
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL DEFAULT NULL AFTER created_at;
