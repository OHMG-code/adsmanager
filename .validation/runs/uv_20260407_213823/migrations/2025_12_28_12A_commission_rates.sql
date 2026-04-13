-- Sanity check (project schema):
-- users table: uzytkownicy (PK: id, role column: rola)
-- clients table: klienci
-- leads table: leady
-- campaigns table: kampanie (client link: klient_id; owner link present in app schema: owner_user_id)
-- No invoices table detected in repo.

-- Commission rate fields per user (MySQL/MariaDB compatible, idempotent)
-- Add columns only if missing (avoids ADD COLUMN IF NOT EXISTS syntax issues)
SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'uzytkownicy'
              AND column_name = 'prov_new_contract_pct'
        ),
        'SELECT 1',
        'ALTER TABLE `uzytkownicy` ADD COLUMN `prov_new_contract_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'uzytkownicy'
              AND column_name = 'prov_renewal_pct'
        ),
        'SELECT 1',
        'ALTER TABLE `uzytkownicy` ADD COLUMN `prov_renewal_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'uzytkownicy'
              AND column_name = 'prov_next_invoice_pct'
        ),
        'SELECT 1',
        'ALTER TABLE `uzytkownicy` ADD COLUMN `prov_next_invoice_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Optional dictionary for commission types (avoids ENUM lock-in)
CREATE TABLE IF NOT EXISTS commission_types (
    code VARCHAR(32) PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO commission_types (code, name, sort_order, is_active) VALUES
    ('NOWA_UMOWA', 'Nowa umowa', 1, 1),
    ('PRZEDLUZENIE', 'Przedluzenie', 2, 1),
    ('KOLEJNA_FAKTURA', 'Kolejna faktura', 3, 1);

-- If commission_types is not desired, drop the table and use ENUM in the future events table.
