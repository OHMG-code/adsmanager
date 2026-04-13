<?php
/**
 * Helpers to ensure DB schema for configuration and SMTP settings.
 */
require_once __DIR__ . '/db_schema.php';
function ensureKonfiguracjaColumns(PDO $pdo): void {
$columns = [
    'prime_hours'              => "ALTER TABLE konfiguracja_systemu ADD COLUMN prime_hours VARCHAR(255) NULL",
    'standard_hours'           => "ALTER TABLE konfiguracja_systemu ADD COLUMN standard_hours VARCHAR(255) NULL",
    'night_hours'              => "ALTER TABLE konfiguracja_systemu ADD COLUMN night_hours VARCHAR(255) NULL",
    'limit_prime_seconds_per_day'    => "ALTER TABLE konfiguracja_systemu ADD COLUMN limit_prime_seconds_per_day INT NOT NULL DEFAULT 3600",
    'limit_standard_seconds_per_day' => "ALTER TABLE konfiguracja_systemu ADD COLUMN limit_standard_seconds_per_day INT NOT NULL DEFAULT 3600",
    'limit_night_seconds_per_day'    => "ALTER TABLE konfiguracja_systemu ADD COLUMN limit_night_seconds_per_day INT NOT NULL DEFAULT 3600",
    'maintenance_last_run_at'        => "ALTER TABLE konfiguracja_systemu ADD COLUMN maintenance_last_run_at DATETIME NULL",
    'maintenance_interval_minutes'   => "ALTER TABLE konfiguracja_systemu ADD COLUMN maintenance_interval_minutes INT NOT NULL DEFAULT 10",
    'audio_upload_max_mb'            => "ALTER TABLE konfiguracja_systemu ADD COLUMN audio_upload_max_mb INT NOT NULL DEFAULT 50",
    'audio_allowed_ext'              => "ALTER TABLE konfiguracja_systemu ADD COLUMN audio_allowed_ext VARCHAR(100) NOT NULL DEFAULT 'wav,mp3'",
    'gus_enabled'                    => "ALTER TABLE konfiguracja_systemu ADD COLUMN gus_enabled TINYINT(1) NOT NULL DEFAULT 0",
    'gus_api_key'                    => "ALTER TABLE konfiguracja_systemu ADD COLUMN gus_api_key VARCHAR(255) NULL",
    'gus_environment'                => "ALTER TABLE konfiguracja_systemu ADD COLUMN gus_environment VARCHAR(20) NOT NULL DEFAULT 'prod'",
    'gus_cache_ttl_days'             => "ALTER TABLE konfiguracja_systemu ADD COLUMN gus_cache_ttl_days INT NOT NULL DEFAULT 30",
    'gus_auto_refresh_enabled'       => "ALTER TABLE konfiguracja_systemu ADD COLUMN gus_auto_refresh_enabled TINYINT(1) NOT NULL DEFAULT 0",
    'gus_auto_refresh_batch'         => "ALTER TABLE konfiguracja_systemu ADD COLUMN gus_auto_refresh_batch INT NOT NULL DEFAULT 20",
    'gus_auto_refresh_interval_days' => "ALTER TABLE konfiguracja_systemu ADD COLUMN gus_auto_refresh_interval_days INT NOT NULL DEFAULT 30",
    'gus_auto_refresh_backoff_minutes' => "ALTER TABLE konfiguracja_systemu ADD COLUMN gus_auto_refresh_backoff_minutes INT NOT NULL DEFAULT 60",
    'pdf_logo_path'            => "ALTER TABLE konfiguracja_systemu ADD COLUMN pdf_logo_path VARCHAR(255) NULL",
    'smtp_host'                => "ALTER TABLE konfiguracja_systemu ADD COLUMN smtp_host VARCHAR(255) NULL",
    'smtp_port'                => "ALTER TABLE konfiguracja_systemu ADD COLUMN smtp_port INT NULL",
        'smtp_secure'              => "ALTER TABLE konfiguracja_systemu ADD COLUMN smtp_secure VARCHAR(10) NULL",
        'smtp_auth'                => "ALTER TABLE konfiguracja_systemu ADD COLUMN smtp_auth TINYINT(1) NULL",
        'smtp_default_from_email'  => "ALTER TABLE konfiguracja_systemu ADD COLUMN smtp_default_from_email VARCHAR(255) NULL",
        'smtp_default_from_name'   => "ALTER TABLE konfiguracja_systemu ADD COLUMN smtp_default_from_name VARCHAR(255) NULL",
        'smtp_username'            => "ALTER TABLE konfiguracja_systemu ADD COLUMN smtp_username VARCHAR(255) NULL",
        'smtp_password'            => "ALTER TABLE konfiguracja_systemu ADD COLUMN smtp_password VARCHAR(255) NULL",
        'crm_archive_bcc_email'    => "ALTER TABLE konfiguracja_systemu ADD COLUMN crm_archive_bcc_email VARCHAR(255) NULL",
        'crm_archive_enabled'      => "ALTER TABLE konfiguracja_systemu ADD COLUMN crm_archive_enabled TINYINT(1) NOT NULL DEFAULT 0",
        'email_signature_template_html' => "ALTER TABLE konfiguracja_systemu ADD COLUMN email_signature_template_html LONGTEXT NULL",
        'company_name'             => "ALTER TABLE konfiguracja_systemu ADD COLUMN company_name VARCHAR(255) NULL",
        'company_address'          => "ALTER TABLE konfiguracja_systemu ADD COLUMN company_address VARCHAR(255) NULL",
        'company_nip'              => "ALTER TABLE konfiguracja_systemu ADD COLUMN company_nip VARCHAR(50) NULL",
        'company_email'            => "ALTER TABLE konfiguracja_systemu ADD COLUMN company_email VARCHAR(255) NULL",
        'company_phone'            => "ALTER TABLE konfiguracja_systemu ADD COLUMN company_phone VARCHAR(50) NULL",
        'documents_storage_path'   => "ALTER TABLE konfiguracja_systemu ADD COLUMN documents_storage_path VARCHAR(255) NULL",
        'documents_number_prefix'  => "ALTER TABLE konfiguracja_systemu ADD COLUMN documents_number_prefix VARCHAR(50) NOT NULL DEFAULT 'AM/'",
        'block_duration_seconds'   => "ALTER TABLE konfiguracja_systemu ADD COLUMN block_duration_seconds INT NOT NULL DEFAULT 45"
    ];

    foreach ($columns as $column => $sql) {
        try {
            $exists = $pdo->query("SHOW COLUMNS FROM konfiguracja_systemu LIKE " . $pdo->quote($column));
            if ($exists) {
                $found = $exists->fetch();
                $exists->closeCursor();
                if ($found) {
                    continue;
                }
            }
            $pdo->exec($sql);
        } catch (Throwable $e) {
            error_log('schema.php: cannot alter konfiguracja_systemu (' . $column . '): ' . $e->getMessage());
        }
    }
}

function ensureUserSmtpColumns(PDO $pdo): void {
    $columns = [
        'imie'            => "ALTER TABLE uzytkownicy ADD COLUMN imie VARCHAR(100) NULL",
        'nazwisko'        => "ALTER TABLE uzytkownicy ADD COLUMN nazwisko VARCHAR(100) NULL",
        'telefon'         => "ALTER TABLE uzytkownicy ADD COLUMN telefon VARCHAR(50) NULL",
        'email'           => "ALTER TABLE uzytkownicy ADD COLUMN email VARCHAR(255) NULL",
        'funkcja'         => "ALTER TABLE uzytkownicy ADD COLUMN funkcja VARCHAR(150) NULL",
        'aktywny'         => "ALTER TABLE uzytkownicy ADD COLUMN aktywny TINYINT(1) NOT NULL DEFAULT 1",
        'rola'            => "ALTER TABLE uzytkownicy ADD COLUMN rola VARCHAR(50) NOT NULL DEFAULT 'Handlowiec'",
        'smtp_user'       => "ALTER TABLE uzytkownicy ADD COLUMN smtp_user VARCHAR(255) NULL",
        'smtp_pass'       => "ALTER TABLE uzytkownicy ADD COLUMN smtp_pass VARCHAR(255) NULL",
        'smtp_pass_enc'   => "ALTER TABLE uzytkownicy ADD COLUMN smtp_pass_enc TEXT NULL",
        'smtp_host'       => "ALTER TABLE uzytkownicy ADD COLUMN smtp_host VARCHAR(255) NULL",
        'smtp_port'       => "ALTER TABLE uzytkownicy ADD COLUMN smtp_port INT NULL",
        'smtp_secure'     => "ALTER TABLE uzytkownicy ADD COLUMN smtp_secure VARCHAR(10) NOT NULL DEFAULT 'tls'",
        'smtp_enabled'    => "ALTER TABLE uzytkownicy ADD COLUMN smtp_enabled TINYINT(1) NOT NULL DEFAULT 0",
        'smtp_from_email' => "ALTER TABLE uzytkownicy ADD COLUMN smtp_from_email VARCHAR(255) NULL",
        'smtp_from_name'  => "ALTER TABLE uzytkownicy ADD COLUMN smtp_from_name VARCHAR(255) NULL",
        'imap_host'       => "ALTER TABLE uzytkownicy ADD COLUMN imap_host VARCHAR(255) NULL",
        'imap_port'       => "ALTER TABLE uzytkownicy ADD COLUMN imap_port INT NULL",
        'imap_user'       => "ALTER TABLE uzytkownicy ADD COLUMN imap_user VARCHAR(255) NULL",
        'imap_pass_enc'   => "ALTER TABLE uzytkownicy ADD COLUMN imap_pass_enc TEXT NULL",
        'imap_secure'     => "ALTER TABLE uzytkownicy ADD COLUMN imap_secure VARCHAR(10) NOT NULL DEFAULT 'tls'",
        'imap_enabled'    => "ALTER TABLE uzytkownicy ADD COLUMN imap_enabled TINYINT(1) NOT NULL DEFAULT 0",
        'imap_mailbox'    => "ALTER TABLE uzytkownicy ADD COLUMN imap_mailbox VARCHAR(255) NOT NULL DEFAULT 'INBOX'",
        'imap_last_uid'   => "ALTER TABLE uzytkownicy ADD COLUMN imap_last_uid INT NULL",
        'imap_last_sync_at' => "ALTER TABLE uzytkownicy ADD COLUMN imap_last_sync_at DATETIME NULL",
        'email_signature' => "ALTER TABLE uzytkownicy ADD COLUMN email_signature LONGTEXT NULL",
        'use_system_smtp' => "ALTER TABLE uzytkownicy ADD COLUMN use_system_smtp TINYINT(1) NOT NULL DEFAULT 0"
    ];

    foreach ($columns as $column => $sql) {
        try {
            $exists = $pdo->query("SHOW COLUMNS FROM uzytkownicy LIKE " . $pdo->quote($column));
            if ($exists) {
                $found = $exists->fetch();
                $exists->closeCursor();
                if ($found) {
                    continue;
                }
            }
            $pdo->exec($sql);
        } catch (Throwable $e) {
            error_log('schema.php: cannot alter uzytkownicy (' . $column . '): ' . $e->getMessage());
        }
    }
}

function ensureMailHistoryTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS historia_maili_ofert (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kampania_id INT NOT NULL,
            klient_id INT NULL,
            lead_id INT NULL,
            user_id INT NOT NULL,
            to_email VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL,
            error_msg TEXT NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (kampania_id),
            INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cols = [];
        try {
            $cols = getTableColumns($pdo, 'historia_maili_ofert');
        } catch (Throwable $e) {
            $cols = [];
        }
        if ($cols && !hasColumn($cols, 'lead_id')) {
            $pdo->exec("ALTER TABLE historia_maili_ofert ADD COLUMN lead_id INT NULL");
        }
    } catch (Throwable $e) {
        error_log('schema.php: cannot create historia_maili_ofert: ' . $e->getMessage());
    }
}
