<?php
declare(strict_types=1);

require_once __DIR__ . '/db_utils.php';

function dbDriverName(PDO $pdo): string
{
    try {
        return strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    } catch (Throwable $e) {
        return '';
    }
}

function isSqliteDriver(PDO $pdo): bool
{
    return dbDriverName($pdo) === 'sqlite';
}

/**
 * Returns a lowercase-keyed map of columns present in a table.
 *
 * @return array<string,string> Map: lowercase column name => original column name.
 */
function getTableColumns(PDO $pdo, string $table, bool $forceRefresh = false): array {
    static $cache = [];
    $cacheKey = dbDriverName($pdo) . ':' . strtolower($table);
    $normalized = strtolower($table);
    if (!$forceRefresh && isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return $cache[$cacheKey] = [];
    }

    try {
        if (isSqliteDriver($pdo)) {
            $stmt = $pdo->query(sprintf("PRAGMA table_info('%s')", str_replace("'", "''", $table)));
        } else {
            $stmt = $pdo->query(sprintf('SHOW COLUMNS FROM `%s`', $table));
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot inspect columns for table ' . $table . ': ' . $e->getMessage());
        return $cache[$cacheKey] = [];
    }

    $columns = [];
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columnName = '';
            if (isset($row['Field']) && $row['Field'] !== '') {
                $columnName = (string)$row['Field'];
            } elseif (isset($row['name']) && $row['name'] !== '') {
                $columnName = (string)$row['name'];
            }
            if ($columnName !== '') {
                $columns[strtolower($columnName)] = $columnName;
            }
        }
        $stmt->closeCursor();
    }
    return $cache[$cacheKey] = $columns;
}

function hasColumn(array $cols, string $col): bool {
    return array_key_exists(strtolower($col), $cols);
}

/**
 * Builds a SELECT expression for a potentially-missing column.
 */
function selectOrEmpty(string $alias, string $col, array $cols): string {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $col)) {
        throw new InvalidArgumentException('Invalid column name supplied to selectOrEmpty.');
    }
    $tableAlias = trim($alias);
    if ($tableAlias !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $tableAlias)) {
        throw new InvalidArgumentException('Invalid table alias supplied to selectOrEmpty.');
    }

    $physicalCol = $cols[strtolower($col)] ?? $col;
    $outputAlias = sprintf('`%s`', $col);

    if (hasColumn($cols, $col)) {
        $columnExpr = $tableAlias !== ''
            ? sprintf('%s.`%s`', $tableAlias, $physicalCol)
            : sprintf('`%s`', $physicalCol);
        return sprintf('%s AS %s', $columnExpr, $outputAlias);
    }

    return sprintf("'' AS %s", $outputAlias);
}

function ensureUserColumns(PDO $pdo): void {
    $columns = [
        'imie'             => "ALTER TABLE uzytkownicy ADD COLUMN imie VARCHAR(100) NULL",
        'nazwisko'         => "ALTER TABLE uzytkownicy ADD COLUMN nazwisko VARCHAR(100) NULL",
        'email'            => "ALTER TABLE uzytkownicy ADD COLUMN email VARCHAR(255) NULL",
        'rola'             => "ALTER TABLE uzytkownicy ADD COLUMN rola VARCHAR(32) NOT NULL DEFAULT 'Handlowiec'",
        'telefon'          => "ALTER TABLE uzytkownicy ADD COLUMN telefon VARCHAR(50) NULL",
        'imap_host'        => "ALTER TABLE uzytkownicy ADD COLUMN imap_host VARCHAR(255) NULL",
        'imap_port'        => "ALTER TABLE uzytkownicy ADD COLUMN imap_port INT NULL",
        'imap_user'        => "ALTER TABLE uzytkownicy ADD COLUMN imap_user VARCHAR(255) NULL",
        'imap_pass_enc'    => "ALTER TABLE uzytkownicy ADD COLUMN imap_pass_enc TEXT NULL",
        'imap_secure'      => "ALTER TABLE uzytkownicy ADD COLUMN imap_secure VARCHAR(10) NOT NULL DEFAULT 'tls'",
        'imap_enabled'     => "ALTER TABLE uzytkownicy ADD COLUMN imap_enabled TINYINT(1) NOT NULL DEFAULT 0",
        'imap_mailbox'     => "ALTER TABLE uzytkownicy ADD COLUMN imap_mailbox VARCHAR(255) NOT NULL DEFAULT 'INBOX'",
        'imap_last_uid'    => "ALTER TABLE uzytkownicy ADD COLUMN imap_last_uid INT NULL",
        'imap_last_sync_at' => "ALTER TABLE uzytkownicy ADD COLUMN imap_last_sync_at DATETIME NULL",
        'smtp_user'        => "ALTER TABLE uzytkownicy ADD COLUMN smtp_user VARCHAR(255) NULL",
        'smtp_pass'        => "ALTER TABLE uzytkownicy ADD COLUMN smtp_pass VARCHAR(255) NULL",
        'smtp_pass_enc'    => "ALTER TABLE uzytkownicy ADD COLUMN smtp_pass_enc TEXT NULL",
        'smtp_host'        => "ALTER TABLE uzytkownicy ADD COLUMN smtp_host VARCHAR(255) NULL",
        'smtp_port'        => "ALTER TABLE uzytkownicy ADD COLUMN smtp_port INT NULL",
        'smtp_secure'      => "ALTER TABLE uzytkownicy ADD COLUMN smtp_secure VARCHAR(10) NOT NULL DEFAULT 'tls'",
        'smtp_enabled'     => "ALTER TABLE uzytkownicy ADD COLUMN smtp_enabled TINYINT(1) NOT NULL DEFAULT 0",
        'smtp_from_email'  => "ALTER TABLE uzytkownicy ADD COLUMN smtp_from_email VARCHAR(255) NULL",
        'smtp_from_name'   => "ALTER TABLE uzytkownicy ADD COLUMN smtp_from_name VARCHAR(255) NULL",
        'email_signature'  => "ALTER TABLE uzytkownicy ADD COLUMN email_signature TEXT NULL",
        'use_system_smtp'  => "ALTER TABLE uzytkownicy ADD COLUMN use_system_smtp TINYINT(1) NOT NULL DEFAULT 0",
        'commission_enabled' => "ALTER TABLE uzytkownicy ADD COLUMN commission_enabled TINYINT(1) NOT NULL DEFAULT 0",
        'commission_rate_percent' => "ALTER TABLE uzytkownicy ADD COLUMN commission_rate_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00",
    ];

    ensureTableColumns($pdo, 'uzytkownicy', $columns);
}

function ensureCanonicalUserRoleColumn(PDO $pdo): void {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `uzytkownicy` LIKE 'rola'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($stmt) {
            $stmt->closeCursor();
        }
        if (!$column) {
            return;
        }
        $type = strtolower((string)($column['Type'] ?? ''));
        $default = $column['Default'] ?? null;
        if (strpos($type, 'enum(') === 0) {
            $pdo->exec("ALTER TABLE `uzytkownicy` MODIFY COLUMN `rola` VARCHAR(32) NOT NULL DEFAULT 'Handlowiec'");
            return;
        }
        if (preg_match('/^varchar\\(/', $type) && ($default === null || strtolower((string)$default) === 'user' || $default === '')) {
            $pdo->exec("ALTER TABLE `uzytkownicy` ALTER COLUMN `rola` SET DEFAULT 'Handlowiec'");
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot normalize rola column: ' . $e->getMessage());
    }
}

function normalizeUserRoleData(PDO $pdo): void {
    try {
        $pdo->exec("UPDATE `uzytkownicy` SET `rola` = 'Administrator' WHERE LOWER(TRIM(`login`)) = 'admin'");
        $pdo->exec("UPDATE `uzytkownicy` SET `rola` = 'Administrator' WHERE LOWER(TRIM(`rola`)) IN ('admin','administrator')");
        $pdo->exec("UPDATE `uzytkownicy` SET `rola` = 'Manager' WHERE LOWER(TRIM(`rola`)) = 'manager'");
        $pdo->exec(
            "UPDATE `uzytkownicy`
             SET `rola` = 'Handlowiec'
             WHERE (`rola` IS NULL OR TRIM(`rola`) = '' OR LOWER(TRIM(`rola`)) IN ('uzytkownik','user','handlowiec'))
               AND LOWER(TRIM(`login`)) <> 'admin'"
        );
    } catch (Throwable $e) {
        error_log('db_schema: cannot normalize rola data: ' . $e->getMessage());
    }
}

function ensureSystemConfigColumns(PDO $pdo): void {
    $columns = [
        'prime_hours'             => "ALTER TABLE konfiguracja_systemu ADD COLUMN prime_hours VARCHAR(255) NULL",
        'standard_hours'          => "ALTER TABLE konfiguracja_systemu ADD COLUMN standard_hours VARCHAR(255) NULL",
        'night_hours'             => "ALTER TABLE konfiguracja_systemu ADD COLUMN night_hours VARCHAR(255) NULL",
        'limit_prime_seconds_per_day'    => "ALTER TABLE konfiguracja_systemu ADD COLUMN limit_prime_seconds_per_day INT NOT NULL DEFAULT 3600",
        'limit_standard_seconds_per_day' => "ALTER TABLE konfiguracja_systemu ADD COLUMN limit_standard_seconds_per_day INT NOT NULL DEFAULT 3600",
        'limit_night_seconds_per_day'    => "ALTER TABLE konfiguracja_systemu ADD COLUMN limit_night_seconds_per_day INT NOT NULL DEFAULT 3600",
        'maintenance_last_run_at'        => "ALTER TABLE konfiguracja_systemu ADD COLUMN maintenance_last_run_at DATETIME NULL",
        'maintenance_interval_minutes'   => "ALTER TABLE konfiguracja_systemu ADD COLUMN maintenance_interval_minutes INT NOT NULL DEFAULT 10",
        'audio_upload_max_mb'            => "ALTER TABLE konfiguracja_systemu ADD COLUMN audio_upload_max_mb INT NOT NULL DEFAULT 50",
        'audio_allowed_ext'              => "ALTER TABLE konfiguracja_systemu ADD COLUMN audio_allowed_ext VARCHAR(100) NOT NULL DEFAULT 'wav,mp3'",
        'gus_enabled'                    => "ALTER TABLE konfiguracja_systemu ADD COLUMN gus_enabled TINYINT(1) NOT NULL DEFAULT 0",
        'gus_api_key'                    => "ALTER TABLE konfiguracja_systemu ADD COLUMN gus_api_key VARCHAR(255) NULL",
        'google_maps_api_key'            => "ALTER TABLE konfiguracja_systemu ADD COLUMN google_maps_api_key VARCHAR(255) NULL",
        'ai_provider'                    => "ALTER TABLE konfiguracja_systemu ADD COLUMN ai_provider VARCHAR(20) NOT NULL DEFAULT 'disabled'",
        'ai_api_key_enc'                 => "ALTER TABLE konfiguracja_systemu ADD COLUMN ai_api_key_enc TEXT NULL",
        'ai_model'                       => "ALTER TABLE konfiguracja_systemu ADD COLUMN ai_model VARCHAR(120) NULL",
        'ai_search_provider'             => "ALTER TABLE konfiguracja_systemu ADD COLUMN ai_search_provider VARCHAR(30) NOT NULL DEFAULT 'disabled'",
        'google_places_api_key_enc'      => "ALTER TABLE konfiguracja_systemu ADD COLUMN google_places_api_key_enc TEXT NULL",
        'ai_default_generation_limit'    => "ALTER TABLE konfiguracja_systemu ADD COLUMN ai_default_generation_limit INT NOT NULL DEFAULT 20",
        'ai_max_generation_limit'        => "ALTER TABLE konfiguracja_systemu ADD COLUMN ai_max_generation_limit INT NOT NULL DEFAULT 50",
        'ai_default_radius_km'           => "ALTER TABLE konfiguracja_systemu ADD COLUMN ai_default_radius_km INT NOT NULL DEFAULT 30",
        'gus_environment'                => "ALTER TABLE konfiguracja_systemu ADD COLUMN gus_environment VARCHAR(20) NOT NULL DEFAULT 'prod'",
        'gus_cache_ttl_days'             => "ALTER TABLE konfiguracja_systemu ADD COLUMN gus_cache_ttl_days INT NOT NULL DEFAULT 30",
        'gus_auto_refresh_enabled'       => "ALTER TABLE konfiguracja_systemu ADD COLUMN gus_auto_refresh_enabled TINYINT(1) NOT NULL DEFAULT 0",
        'gus_auto_refresh_batch'         => "ALTER TABLE konfiguracja_systemu ADD COLUMN gus_auto_refresh_batch INT NOT NULL DEFAULT 20",
        'gus_auto_refresh_interval_days' => "ALTER TABLE konfiguracja_systemu ADD COLUMN gus_auto_refresh_interval_days INT NOT NULL DEFAULT 30",
        'gus_auto_refresh_backoff_minutes' => "ALTER TABLE konfiguracja_systemu ADD COLUMN gus_auto_refresh_backoff_minutes INT NOT NULL DEFAULT 60",
        'pdf_logo_path'           => "ALTER TABLE konfiguracja_systemu ADD COLUMN pdf_logo_path VARCHAR(255) NULL",
        'smtp_host'               => "ALTER TABLE konfiguracja_systemu ADD COLUMN smtp_host VARCHAR(255) NULL",
        'smtp_port'               => "ALTER TABLE konfiguracja_systemu ADD COLUMN smtp_port INT NULL",
        'smtp_secure'             => "ALTER TABLE konfiguracja_systemu ADD COLUMN smtp_secure VARCHAR(10) NULL",
        'smtp_auth'               => "ALTER TABLE konfiguracja_systemu ADD COLUMN smtp_auth TINYINT(1) NULL",
        'smtp_default_from_email' => "ALTER TABLE konfiguracja_systemu ADD COLUMN smtp_default_from_email VARCHAR(255) NULL",
        'smtp_default_from_name'  => "ALTER TABLE konfiguracja_systemu ADD COLUMN smtp_default_from_name VARCHAR(255) NULL",
        'smtp_username'           => "ALTER TABLE konfiguracja_systemu ADD COLUMN smtp_username VARCHAR(255) NULL",
        'smtp_password'           => "ALTER TABLE konfiguracja_systemu ADD COLUMN smtp_password VARCHAR(255) NULL",
        'crm_archive_bcc_email'   => "ALTER TABLE konfiguracja_systemu ADD COLUMN crm_archive_bcc_email VARCHAR(255) NULL",
        'crm_archive_enabled'     => "ALTER TABLE konfiguracja_systemu ADD COLUMN crm_archive_enabled TINYINT(1) NOT NULL DEFAULT 0",
        'company_name'            => "ALTER TABLE konfiguracja_systemu ADD COLUMN company_name VARCHAR(255) NULL",
        'company_address'         => "ALTER TABLE konfiguracja_systemu ADD COLUMN company_address VARCHAR(255) NULL",
        'company_nip'             => "ALTER TABLE konfiguracja_systemu ADD COLUMN company_nip VARCHAR(50) NULL",
        'company_email'           => "ALTER TABLE konfiguracja_systemu ADD COLUMN company_email VARCHAR(255) NULL",
        'company_phone'           => "ALTER TABLE konfiguracja_systemu ADD COLUMN company_phone VARCHAR(50) NULL",
        'documents_storage_path'  => "ALTER TABLE konfiguracja_systemu ADD COLUMN documents_storage_path VARCHAR(255) NULL",
        'documents_number_prefix' => "ALTER TABLE konfiguracja_systemu ADD COLUMN documents_number_prefix VARCHAR(50) NOT NULL DEFAULT 'AM/'",
        'block_duration_seconds'  => "ALTER TABLE konfiguracja_systemu ADD COLUMN block_duration_seconds INT NOT NULL DEFAULT 45",
        'ui_theme'                => "ALTER TABLE konfiguracja_systemu ADD COLUMN ui_theme VARCHAR(20) NOT NULL DEFAULT 'light'",
        'zadarma_api_key'         => "ALTER TABLE konfiguracja_systemu ADD COLUMN zadarma_api_key VARCHAR(255) NULL",
        'zadarma_api_secret'      => "ALTER TABLE konfiguracja_systemu ADD COLUMN zadarma_api_secret VARCHAR(255) NULL",
        'zadarma_sms_sender'      => "ALTER TABLE konfiguracja_systemu ADD COLUMN zadarma_sms_sender VARCHAR(120) NULL",
        'zadarma_api_base_url'    => "ALTER TABLE konfiguracja_systemu ADD COLUMN zadarma_api_base_url VARCHAR(255) NOT NULL DEFAULT 'https://api.zadarma.com'",
        'sms_dry_run'             => "ALTER TABLE konfiguracja_systemu ADD COLUMN sms_dry_run TINYINT(1) NOT NULL DEFAULT 1",
    ];

    ensureTableColumns($pdo, 'konfiguracja_systemu', $columns);
}

function ensureTableColumns(PDO $pdo, string $table, array $definitions): void {
    $existing = getTableColumns($pdo, $table);
    $schemaChanged = false;
    foreach ($definitions as $column => $alterSql) {
        if (hasColumn($existing, $column)) {
            continue;
        }
        try {
            $pdo->exec($alterSql);
            $existing[strtolower($column)] = $column;
            $schemaChanged = true;
        } catch (Throwable $e) {
            error_log(sprintf('db_schema: cannot alter %s (%s): %s', $table, $column, $e->getMessage()));
        }
    }
    if ($schemaChanged) {
        getTableColumns($pdo, $table, true);
    }
}

function ensureMailHistoryTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS historia_maili_ofert (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kampania_id INT NOT NULL,
            klient_id INT NULL,
            user_id INT NOT NULL,
            to_email VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL,
            error_msg TEXT NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (kampania_id),
            INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create historia_maili_ofert: ' . $e->getMessage());
    }
}

function ensureCrmMailTables(PDO $pdo): void {
    ensureCrmMailAccountsTable($pdo);
    ensureCrmMailThreadsTable($pdo);
    ensureCrmSmsTables($pdo);
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS mail_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NULL,
            lead_id INT NULL,
            campaign_id INT NULL,
            owner_user_id INT NOT NULL,
            mail_account_id INT NOT NULL DEFAULT 0,
            thread_id INT NULL,
            direction ENUM('in','out','IN','OUT') NOT NULL,
            from_email VARCHAR(255) NULL,
            from_name VARCHAR(255) NULL,
            to_email TEXT NULL,
            to_emails TEXT NULL,
            cc_email TEXT NULL,
            cc_emails TEXT NULL,
            bcc_email TEXT NULL,
            subject VARCHAR(255) NULL,
            body_html MEDIUMTEXT NULL,
            body_text MEDIUMTEXT NULL,
            status ENUM('SENT','ERROR','RECEIVED') NOT NULL DEFAULT 'SENT',
            error_message TEXT NULL,
            message_id VARCHAR(255) NULL,
            in_reply_to VARCHAR(255) NULL,
            references_header TEXT NULL,
            imap_uid INT NULL,
            imap_mailbox VARCHAR(255) NULL,
            received_at DATETIME NULL,
            sent_at DATETIME NULL,
            has_attachments TINYINT(1) NOT NULL DEFAULT 0,
            entity_type ENUM('lead','client') NULL,
            entity_id INT NULL,
            created_by_user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create mail_messages: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS mail_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mail_message_id INT NOT NULL,
            filename VARCHAR(255) NOT NULL,
            stored_path VARCHAR(512) NOT NULL,
            storage_path VARCHAR(500) NOT NULL DEFAULT '',
            mime_type VARCHAR(100) NULL,
            size_bytes INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create mail_attachments: ' . $e->getMessage());
    }

    $attachmentColumns = [
        'storage_path' => "ALTER TABLE mail_attachments ADD COLUMN storage_path VARCHAR(500) NOT NULL DEFAULT ''",
    ];
    ensureTableColumns($pdo, 'mail_attachments', $attachmentColumns);

    ensureMailMessageColumns($pdo);
    ensureIndexExists($pdo, 'mail_messages', 'idx_mail_messages_owner_created', 'CREATE INDEX idx_mail_messages_owner_created ON mail_messages(owner_user_id, created_at)');
    ensureIndexExists($pdo, 'mail_messages', 'idx_mail_messages_client_created', 'CREATE INDEX idx_mail_messages_client_created ON mail_messages(client_id, created_at)');
    ensureIndexExists($pdo, 'mail_messages', 'idx_mail_messages_campaign_created', 'CREATE INDEX idx_mail_messages_campaign_created ON mail_messages(campaign_id, created_at)');
    ensureIndexExists($pdo, 'mail_messages', 'idx_mail_messages_owner_imap_uid', 'CREATE INDEX idx_mail_messages_owner_imap_uid ON mail_messages(owner_user_id, imap_uid, imap_mailbox)');
    ensureIndexExists($pdo, 'mail_messages', 'idx_mail_messages_message_id', 'CREATE INDEX idx_mail_messages_message_id ON mail_messages(message_id)');
    ensureIndexExists($pdo, 'mail_messages', 'idx_mail_messages_entity_created', 'CREATE INDEX idx_mail_messages_entity_created ON mail_messages(entity_type, entity_id, created_at)');
    ensureIndexExists($pdo, 'mail_messages', 'idx_mail_messages_thread_created', 'CREATE INDEX idx_mail_messages_thread_created ON mail_messages(thread_id, created_at)');
    ensureIndexExists($pdo, 'mail_attachments', 'idx_mail_attachments_message', 'CREATE INDEX idx_mail_attachments_message ON mail_attachments(mail_message_id)');
    ensureIndexExists($pdo, 'mail_messages', 'uniq_mail_messages_account_message', 'CREATE UNIQUE INDEX uniq_mail_messages_account_message ON mail_messages(mail_account_id, message_id)');
}

function ensureMailMessageColumns(PDO $pdo): void {
    $columns = [
        'mail_account_id' => "ALTER TABLE mail_messages ADD COLUMN mail_account_id INT NOT NULL DEFAULT 0",
        'thread_id' => "ALTER TABLE mail_messages ADD COLUMN thread_id INT NULL",
        'message_id' => "ALTER TABLE mail_messages ADD COLUMN message_id VARCHAR(255) NULL",
        'in_reply_to' => "ALTER TABLE mail_messages ADD COLUMN in_reply_to VARCHAR(255) NULL",
        'references_header' => "ALTER TABLE mail_messages ADD COLUMN references_header TEXT NULL",
        'imap_uid' => "ALTER TABLE mail_messages ADD COLUMN imap_uid INT NULL",
        'imap_mailbox' => "ALTER TABLE mail_messages ADD COLUMN imap_mailbox VARCHAR(255) NULL",
        'received_at' => "ALTER TABLE mail_messages ADD COLUMN received_at DATETIME NULL",
        'sent_at' => "ALTER TABLE mail_messages ADD COLUMN sent_at DATETIME NULL",
        'to_emails' => "ALTER TABLE mail_messages ADD COLUMN to_emails TEXT NULL",
        'cc_emails' => "ALTER TABLE mail_messages ADD COLUMN cc_emails TEXT NULL",
        'has_attachments' => "ALTER TABLE mail_messages ADD COLUMN has_attachments TINYINT(1) NOT NULL DEFAULT 0",
        'entity_type' => "ALTER TABLE mail_messages ADD COLUMN entity_type ENUM('lead','client') NULL",
        'entity_id' => "ALTER TABLE mail_messages ADD COLUMN entity_id INT NULL",
        'created_by_user_id' => "ALTER TABLE mail_messages ADD COLUMN created_by_user_id INT NULL",
    ];
    ensureTableColumns($pdo, 'mail_messages', $columns);

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `mail_messages` LIKE 'direction'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($stmt) {
            $stmt->closeCursor();
        }
        if ($column && isset($column['Type'])) {
            $type = strtolower((string)$column['Type']);
            if (strpos($type, "enum(") === 0 && (strpos($type, "'in'") === false || strpos($type, "'out'") === false)) {
                $pdo->exec("ALTER TABLE `mail_messages` MODIFY COLUMN `direction` ENUM('in','out','IN','OUT') NOT NULL");
            }
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot normalize mail_messages.direction: ' . $e->getMessage());
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `mail_messages` LIKE 'status'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($stmt) {
            $stmt->closeCursor();
        }
        if ($column && isset($column['Type'])) {
            $type = strtolower((string)$column['Type']);
            if (strpos($type, "enum(") === 0 && strpos($type, "'received'") === false) {
                $pdo->exec("ALTER TABLE `mail_messages` MODIFY COLUMN `status` ENUM('SENT','ERROR','RECEIVED') NOT NULL DEFAULT 'SENT'");
            }
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot normalize mail_messages.status: ' . $e->getMessage());
    }
}

function ensureCrmMailAccountsTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS mail_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            imap_host VARCHAR(255) NOT NULL,
            imap_port INT NOT NULL DEFAULT 993,
            imap_encryption ENUM('ssl','tls','none') NOT NULL DEFAULT 'ssl',
            imap_mailbox VARCHAR(255) NOT NULL DEFAULT 'INBOX',
            smtp_host VARCHAR(255) NOT NULL,
            smtp_port INT NOT NULL DEFAULT 587,
            smtp_encryption ENUM('ssl','tls','none') NOT NULL DEFAULT 'tls',
            email_address VARCHAR(255) NOT NULL,
            username VARCHAR(255) NOT NULL,
            smtp_from_name VARCHAR(255) NULL,
            password_enc TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_sync_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mail_accounts_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create mail_accounts: ' . $e->getMessage());
    }
    ensureIndexExists($pdo, 'mail_accounts', 'uniq_mail_accounts_user',
        'CREATE UNIQUE INDEX uniq_mail_accounts_user ON mail_accounts(user_id)');
    ensureIndexExists($pdo, 'mail_accounts', 'uniq_mail_user',
        'CREATE UNIQUE INDEX uniq_mail_user ON mail_accounts(user_id)');

    $columns = getTableColumns($pdo, 'mail_accounts');
    if ($columns) {
        if (!hasColumn($columns, 'imap_mailbox')) {
            $pdo->exec("ALTER TABLE mail_accounts ADD COLUMN imap_mailbox VARCHAR(255) NOT NULL DEFAULT 'INBOX'");
        }
        if (!hasColumn($columns, 'smtp_from_name')) {
            $pdo->exec("ALTER TABLE mail_accounts ADD COLUMN smtp_from_name VARCHAR(255) NULL");
        }
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `mail_accounts` LIKE 'password_enc'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($stmt) {
            $stmt->closeCursor();
        }
        if ($column && isset($column['Null']) && strtoupper((string)$column['Null']) === 'NO') {
            $pdo->exec("ALTER TABLE mail_accounts MODIFY COLUMN password_enc TEXT NULL");
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot relax mail_accounts.password_enc: ' . $e->getMessage());
    }
}

function ensureCrmMailThreadsTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS mail_threads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entity_type ENUM('lead','client') NOT NULL,
            entity_id INT NOT NULL,
            subject VARCHAR(255) NULL,
            subject_hash CHAR(64) NOT NULL,
            last_message_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mail_threads_entity_last (entity_type, entity_id, last_message_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create mail_threads: ' . $e->getMessage());
    }
    ensureIndexExists($pdo, 'mail_threads', 'idx_mail_threads_entity_last',
        'CREATE INDEX idx_mail_threads_entity_last ON mail_threads(entity_type, entity_id, last_message_at)');
}

function ensureCrmSmsTables(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sms_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entity_type ENUM('lead','client') NULL,
            entity_id INT NULL,
            user_id INT NULL,
            direction ENUM('in','out') NOT NULL,
            phone VARCHAR(40) NOT NULL,
            content TEXT NOT NULL,
            provider VARCHAR(50) NULL,
            provider_message_id VARCHAR(120) NULL,
            status VARCHAR(40) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sms_messages_entity_created (entity_type, entity_id, created_at),
            INDEX idx_sms_messages_phone_created (phone, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        ensureTableColumns($pdo, 'sms_messages', [
            'related_type' => 'ALTER TABLE sms_messages ADD COLUMN related_type VARCHAR(40) NULL AFTER id',
            'related_id' => 'ALTER TABLE sms_messages ADD COLUMN related_id INT NULL AFTER related_type',
            'sender' => 'ALTER TABLE sms_messages ADD COLUMN sender VARCHAR(120) NULL AFTER content',
            'provider_response' => 'ALTER TABLE sms_messages ADD COLUMN provider_response LONGTEXT NULL AFTER provider_message_id',
            'cost' => 'ALTER TABLE sms_messages ADD COLUMN cost DECIMAL(12,4) NULL AFTER provider_response',
            'currency' => 'ALTER TABLE sms_messages ADD COLUMN currency VARCHAR(10) NULL AFTER cost',
            'error_message' => 'ALTER TABLE sms_messages ADD COLUMN error_message TEXT NULL AFTER currency',
            'sent_at' => 'ALTER TABLE sms_messages ADD COLUMN sent_at DATETIME NULL AFTER created_at',
        ]);
        ensureIndexExists($pdo, 'sms_messages', 'idx_sms_messages_status_created',
            'CREATE INDEX idx_sms_messages_status_created ON sms_messages(status, created_at)');
        ensureIndexExists($pdo, 'sms_messages', 'idx_sms_messages_related_created',
            'CREATE INDEX idx_sms_messages_related_created ON sms_messages(related_type, related_id, created_at)');
    } catch (Throwable $e) {
        error_log('db_schema: cannot create sms_messages: ' . $e->getMessage());
    }
}

function ensureIndexExists(PDO $pdo, string $table, string $indexName, string $createSql): void
{
    try {
        if (isSqliteDriver($pdo)) {
            $stmt = $pdo->query(sprintf("PRAGMA index_list('%s')", str_replace("'", "''", $table)));
            $found = false;
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $name = strtolower((string)($row['name'] ?? ''));
                    if ($name === strtolower($indexName)) {
                        $found = true;
                        break;
                    }
                }
                $stmt->closeCursor();
            }
            if ($found) {
                return;
            }
        } else {
            $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = :idx");
            $stmt->execute([':idx' => $indexName]);
            $found = $stmt->fetch();
            $stmt->closeCursor();
            if ($found) {
                return;
            }
        }
        $pdo->exec($createSql);
    } catch (Throwable $e) {
        error_log('db_schema: index error: ' . $e->getMessage());
    }
}

function ensureCheckConstraint(PDO $pdo, string $table, string $constraintName, string $checkSql): void
{
    try {
        $stmt = $pdo->prepare(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :table AND CONSTRAINT_NAME = :name"
        );
        $stmt->execute([':table' => $table, ':name' => $constraintName]);
        if ($stmt->fetch()) {
            return;
        }
    } catch (Throwable $e) {
        // ignore lookup errors
    }

    try {
        $pdo->exec("ALTER TABLE `$table` ADD CONSTRAINT `$constraintName` $checkSql");
    } catch (Throwable $e) {
        // ignore if unsupported (e.g. MySQL < 8 or SQL mode)
    }
}

function ensureLeadColumns(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS leady (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nazwa_firmy VARCHAR(255) NOT NULL,
            nip VARCHAR(20) NULL,
            telefon VARCHAR(50) NULL,
            email VARCHAR(255) NULL,
            kod_pocztowy VARCHAR(12) NULL,
            miasto VARCHAR(120) NULL,
            ulica VARCHAR(180) NULL,
            nr_budynku VARCHAR(30) NULL,
            nr_lokalu VARCHAR(30) NULL,
            zrodlo ENUM('telefon','email','formularz_www','maps_api','polecenie','inne') NOT NULL DEFAULT 'inne',
            przypisany_handlowiec VARCHAR(255) NULL,
            status ENUM('nowy','w_kontakcie','analiza_potrzeb','oferta_przygotowywana','oferta_wyslana','oferta_zaakceptowana','odrzucony','zakonczony','skonwertowany') NOT NULL DEFAULT 'nowy',
            notatki TEXT NULL,
            kontakt_imie_nazwisko VARCHAR(120) NULL,
            kontakt_stanowisko VARCHAR(120) NULL,
            kontakt_telefon VARCHAR(60) NULL,
            kontakt_email VARCHAR(120) NULL,
            kontakt_preferencja ENUM('telefon','email','sms','') NOT NULL DEFAULT '',
            next_action_date DATE NULL,
            owner_user_id INT NULL,
            assigned_user_id INT NULL,
            client_id INT NULL,
            converted_at DATETIME NULL,
            converted_by_user_id INT NULL,
            priority VARCHAR(20) NOT NULL DEFAULT 'Średni',
            next_action VARCHAR(255) NULL,
            next_action_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_leady_owner_user_id (owner_user_id),
            INDEX idx_leady_assigned_user_id (assigned_user_id),
            INDEX idx_leady_client_id (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create leady table: ' . $e->getMessage());
    }

    $columns = [
        'owner_user_id' => "ALTER TABLE leady ADD COLUMN owner_user_id INT NULL",
        'assigned_user_id' => "ALTER TABLE leady ADD COLUMN assigned_user_id INT NULL",
        'client_id' => "ALTER TABLE leady ADD COLUMN client_id INT NULL",
        'converted_at' => "ALTER TABLE leady ADD COLUMN converted_at DATETIME NULL",
        'converted_by_user_id' => "ALTER TABLE leady ADD COLUMN converted_by_user_id INT NULL",
        'priority' => "ALTER TABLE leady ADD COLUMN priority VARCHAR(20) NOT NULL DEFAULT 'Średni'",
        'next_action' => "ALTER TABLE leady ADD COLUMN next_action VARCHAR(255) NULL",
        'next_action_at' => "ALTER TABLE leady ADD COLUMN next_action_at DATETIME NULL",
        'kontakt_imie_nazwisko' => "ALTER TABLE leady ADD COLUMN kontakt_imie_nazwisko VARCHAR(120) NULL",
        'kontakt_stanowisko' => "ALTER TABLE leady ADD COLUMN kontakt_stanowisko VARCHAR(120) NULL",
        'kontakt_telefon' => "ALTER TABLE leady ADD COLUMN kontakt_telefon VARCHAR(60) NULL",
        'kontakt_email' => "ALTER TABLE leady ADD COLUMN kontakt_email VARCHAR(120) NULL",
        'kontakt_preferencja' => "ALTER TABLE leady ADD COLUMN kontakt_preferencja ENUM('telefon','email','sms','') NOT NULL DEFAULT ''",
        'kod_pocztowy' => "ALTER TABLE leady ADD COLUMN kod_pocztowy VARCHAR(12) NULL",
        'miasto' => "ALTER TABLE leady ADD COLUMN miasto VARCHAR(120) NULL",
        'ulica' => "ALTER TABLE leady ADD COLUMN ulica VARCHAR(180) NULL",
        'nr_budynku' => "ALTER TABLE leady ADD COLUMN nr_budynku VARCHAR(30) NULL",
        'nr_lokalu' => "ALTER TABLE leady ADD COLUMN nr_lokalu VARCHAR(30) NULL",
    ];
    ensureTableColumns($pdo, 'leady', $columns);

    try {
        $pdo->exec("ALTER TABLE leady MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'nowy'");
    } catch (Throwable $e) {
        error_log('db_schema: cannot modify leady.status: ' . $e->getMessage());
    }

    // Normalize legacy/human-readable statuses to canonical workflow keys.
    try {
        $pdo->exec("UPDATE leady SET status = 'nowy' WHERE LOWER(TRIM(COALESCE(status, ''))) IN ('nowy', 'nowy lead', 'lead pozyskany')");
        $pdo->exec("UPDATE leady SET status = 'w_kontakcie' WHERE LOWER(TRIM(COALESCE(status, ''))) IN ('w kontakcie', 'kontakt podjęty', 'kontakt podjety', 'kontakt', 'potrzeba potwierdzona', 'negocjacje')");
        $pdo->exec("UPDATE leady SET status = 'oferta_wyslana' WHERE LOWER(TRIM(COALESCE(status, ''))) IN ('oferta wysłana', 'oferta wyslana', 'oferta_wyslana', 'wyslano oferte')");
        $pdo->exec("UPDATE leady SET status = 'oferta_zaakceptowana' WHERE LOWER(TRIM(COALESCE(status, ''))) IN ('wygrana', 'media plan zaakceptowany', 'oferta zaakceptowana', 'oferta_zaakceptowana')");
        $pdo->exec("UPDATE leady SET status = 'odrzucony' WHERE LOWER(TRIM(COALESCE(status, ''))) IN ('odrzucony', 'przegrana')");
        $pdo->exec("UPDATE leady SET status = 'zakonczony' WHERE LOWER(TRIM(COALESCE(status, ''))) IN ('zakonczony', 'zakończony', 'zamrożony', 'zamrozony', 'wstrzymany')");
        $pdo->exec("UPDATE leady SET status = 'skonwertowany' WHERE LOWER(TRIM(COALESCE(status, ''))) IN ('skonwertowany', 'skonwertowany klient', 'klient')");
        if (hasColumn(getTableColumns($pdo, 'leady'), 'client_id')) {
            $pdo->exec("UPDATE leady SET status = 'skonwertowany' WHERE client_id IS NOT NULL AND client_id > 0");
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot normalize leady.status: ' . $e->getMessage());
    }

    ensureIndexExists($pdo, 'leady', 'idx_leady_owner_user_id', 'CREATE INDEX idx_leady_owner_user_id ON leady(owner_user_id)');
    ensureIndexExists($pdo, 'leady', 'idx_leady_assigned_user_id', 'CREATE INDEX idx_leady_assigned_user_id ON leady(assigned_user_id)');
    ensureIndexExists($pdo, 'leady', 'idx_leady_client_id', 'CREATE INDEX idx_leady_client_id ON leady(client_id)');
    $leadCols = getTableColumns($pdo, 'leady');
    if (hasColumn($leadCols, 'created_at')) {
        ensureIndexExists($pdo, 'leady', 'idx_leady_created_at', 'CREATE INDEX idx_leady_created_at ON leady(created_at)');
    }
    if (hasColumn($leadCols, 'status')) {
        ensureIndexExists($pdo, 'leady', 'idx_leady_status', 'CREATE INDEX idx_leady_status ON leady(status)');
    }
    if (hasColumn($leadCols, 'next_action_at')) {
        ensureIndexExists($pdo, 'leady', 'idx_leady_next_action_at', 'CREATE INDEX idx_leady_next_action_at ON leady(next_action_at)');
    }
    if (hasColumn($leadCols, 'converted_at')) {
        ensureIndexExists($pdo, 'leady', 'idx_leady_converted_at', 'CREATE INDEX idx_leady_converted_at ON leady(converted_at)');
    }
}

function ensureLeadActivityTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS leady_aktywnosci (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            user_id INT NOT NULL,
            typ VARCHAR(30) NOT NULL,
            opis TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (lead_id),
            INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create leady_aktywnosci: ' . $e->getMessage());
    }
}

function ensureAiLeadTables(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_leads_import (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_name VARCHAR(255) NOT NULL,
            city VARCHAR(120) NOT NULL,
            phone VARCHAR(60) NULL,
            email VARCHAR(255) NULL,
            website VARCHAR(255) NULL,
            industry VARCHAR(255) NOT NULL,
            score INT NOT NULL DEFAULT 0,
            source VARCHAR(80) NOT NULL DEFAULT 'ai_generated',
            status ENUM('new','duplicate','reviewed','accepted','rejected') NOT NULL DEFAULT 'new',
            assigned_user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ai_leads_import_status (status),
            INDEX idx_ai_leads_import_assigned_user (assigned_user_id),
            INDEX idx_ai_leads_import_created_at (created_at),
            INDEX idx_ai_leads_import_company_city (company_name, city)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create ai_leads_import: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_leads_duplicates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ai_lead_id INT NOT NULL,
            matched_type ENUM('lead','client') NOT NULL,
            matched_id INT NOT NULL,
            match_score INT NOT NULL,
            reason VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ai_leads_duplicates_ai_lead (ai_lead_id),
            INDEX idx_ai_leads_duplicates_match (matched_type, matched_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create ai_leads_duplicates: ' . $e->getMessage());
    }

    ensureIndexExists($pdo, 'ai_leads_import', 'idx_ai_leads_import_status', 'CREATE INDEX idx_ai_leads_import_status ON ai_leads_import(status)');
    ensureIndexExists($pdo, 'ai_leads_import', 'idx_ai_leads_import_assigned_user', 'CREATE INDEX idx_ai_leads_import_assigned_user ON ai_leads_import(assigned_user_id)');
    ensureIndexExists($pdo, 'ai_leads_import', 'idx_ai_leads_import_created_at', 'CREATE INDEX idx_ai_leads_import_created_at ON ai_leads_import(created_at)');
    ensureTableColumns($pdo, 'ai_leads_import', [
        'external_id' => "ALTER TABLE ai_leads_import ADD COLUMN external_id VARCHAR(255) NULL",
        'recommended_package' => "ALTER TABLE ai_leads_import ADD COLUMN recommended_package VARCHAR(255) NULL",
        'opening_argument' => "ALTER TABLE ai_leads_import ADD COLUMN opening_argument TEXT NULL",
        'short_reason' => "ALTER TABLE ai_leads_import ADD COLUMN short_reason TEXT NULL",
        'suggested_next_action' => "ALTER TABLE ai_leads_import ADD COLUMN suggested_next_action TEXT NULL",
        'enrichment_status' => "ALTER TABLE ai_leads_import ADD COLUMN enrichment_status VARCHAR(30) NULL",
        'raw_source_data' => "ALTER TABLE ai_leads_import ADD COLUMN raw_source_data LONGTEXT NULL",
    ]);
    ensureIndexExists($pdo, 'ai_leads_import', 'idx_ai_leads_import_external_id', 'CREATE INDEX idx_ai_leads_import_external_id ON ai_leads_import(external_id)');
    ensureIndexExists($pdo, 'ai_leads_duplicates', 'idx_ai_leads_duplicates_ai_lead', 'CREATE INDEX idx_ai_leads_duplicates_ai_lead ON ai_leads_duplicates(ai_lead_id)');
    ensureIndexExists($pdo, 'ai_leads_duplicates', 'idx_ai_leads_duplicates_match', 'CREATE INDEX idx_ai_leads_duplicates_match ON ai_leads_duplicates(matched_type, matched_id)');
}

function ensureCrmStatusTables(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS crm_statusy (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nazwa VARCHAR(80) NOT NULL,
            aktywny TINYINT(1) NOT NULL DEFAULT 1,
            dotyczy ENUM('lead','klient','oba') NOT NULL DEFAULT 'oba',
            sort INT NOT NULL DEFAULT 100,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create crm_statusy: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS crm_aktywnosci (
            id INT AUTO_INCREMENT PRIMARY KEY,
            obiekt_typ ENUM('lead','klient') NOT NULL,
            obiekt_id INT NOT NULL,
            typ ENUM('status','notatka','mail','system','email_in','email_out','sms_in','sms_out') NOT NULL,
            status_id INT NULL,
            temat VARCHAR(255) NULL,
            tresc MEDIUMTEXT NULL,
            user_id INT NOT NULL,
            meta_json JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create crm_aktywnosci: ' . $e->getMessage());
    }

    try {
        $pdo->exec("ALTER TABLE crm_statusy MODIFY COLUMN nazwa VARCHAR(80) NOT NULL");
    } catch (Throwable $e) {
        error_log('db_schema: cannot modify crm_statusy.nazwa: ' . $e->getMessage());
    }
    try {
        $pdo->exec("ALTER TABLE crm_statusy MODIFY COLUMN sort INT NOT NULL DEFAULT 100");
    } catch (Throwable $e) {
        error_log('db_schema: cannot modify crm_statusy.sort: ' . $e->getMessage());
    }

    $activityColumns = [
        'temat' => "ALTER TABLE crm_aktywnosci ADD COLUMN temat VARCHAR(255) NULL",
        'meta_json' => "ALTER TABLE crm_aktywnosci ADD COLUMN meta_json JSON NULL",
    ];
    ensureTableColumns($pdo, 'crm_aktywnosci', $activityColumns);

    try {
        $pdo->exec("ALTER TABLE crm_aktywnosci MODIFY COLUMN tresc MEDIUMTEXT NULL");
    } catch (Throwable $e) {
        error_log('db_schema: cannot modify crm_aktywnosci.tresc: ' . $e->getMessage());
    }
    try {
        $pdo->exec("ALTER TABLE crm_aktywnosci MODIFY COLUMN typ ENUM('status','notatka','mail','system','email_in','email_out','sms_in','sms_out') NOT NULL");
    } catch (Throwable $e) {
        error_log('db_schema: cannot modify crm_aktywnosci.typ: ' . $e->getMessage());
    }

    ensureIndexExists($pdo, 'crm_aktywnosci', 'idx_crm_aktywnosci_obiekt',
        'CREATE INDEX idx_crm_aktywnosci_obiekt ON crm_aktywnosci(obiekt_typ, obiekt_id, created_at)');
    ensureIndexExists($pdo, 'crm_aktywnosci', 'idx_crm_aktywnosci_user',
        'CREATE INDEX idx_crm_aktywnosci_user ON crm_aktywnosci(user_id, created_at)');
    ensureIndexExists($pdo, 'crm_aktywnosci', 'idx_crm_aktywnosci_status',
        'CREATE INDEX idx_crm_aktywnosci_status ON crm_aktywnosci(status_id)');

    seedCrmStatusValues($pdo);
}

function ensureCrmTasksTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS crm_zadania (
            id INT AUTO_INCREMENT PRIMARY KEY,
            obiekt_typ ENUM('lead','klient','kampania') NOT NULL,
            obiekt_id INT NOT NULL,
            owner_user_id INT NOT NULL,
            typ ENUM('telefon','email','sms','spotkanie','inne') NOT NULL,
            tytul VARCHAR(160) NOT NULL,
            opis TEXT NULL,
            due_at DATETIME NOT NULL,
            status ENUM('OPEN','DONE','CANCELLED') NOT NULL DEFAULT 'OPEN',
            external_key VARCHAR(191) NULL,
            done_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_crm_zadania_owner_status_due (owner_user_id, status, due_at),
            INDEX idx_crm_zadania_obiekt_due (obiekt_typ, obiekt_id, due_at),
            INDEX idx_crm_zadania_external_status (external_key, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create crm_zadania: ' . $e->getMessage());
    }

    $columns = [
        'external_key' => "ALTER TABLE crm_zadania ADD COLUMN external_key VARCHAR(191) NULL AFTER status",
    ];
    ensureTableColumns($pdo, 'crm_zadania', $columns);

    try {
        $pdo->exec("ALTER TABLE crm_zadania MODIFY COLUMN obiekt_typ ENUM('lead','klient','kampania') NOT NULL");
    } catch (Throwable $e) {
        error_log('db_schema: cannot modify crm_zadania.obiekt_typ: ' . $e->getMessage());
    }

    ensureIndexExists($pdo, 'crm_zadania', 'idx_crm_zadania_owner_status_due',
        'CREATE INDEX idx_crm_zadania_owner_status_due ON crm_zadania(owner_user_id, status, due_at)');
    ensureIndexExists($pdo, 'crm_zadania', 'idx_crm_zadania_obiekt_due',
        'CREATE INDEX idx_crm_zadania_obiekt_due ON crm_zadania(obiekt_typ, obiekt_id, due_at)');
    ensureIndexExists($pdo, 'crm_zadania', 'idx_crm_zadania_external_status',
        'CREATE INDEX idx_crm_zadania_external_status ON crm_zadania(external_key, status)');
}

function ensureActivityLogTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entity_type ENUM('lead','klient','task') NOT NULL,
            entity_id INT NOT NULL,
            action VARCHAR(50) NOT NULL,
            message TEXT NULL,
            user_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_activity_log_entity (entity_type, entity_id, created_at),
            INDEX idx_activity_log_user (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create activity_log: ' . $e->getMessage());
    }

    ensureIndexExists($pdo, 'activity_log', 'idx_activity_log_entity',
        'CREATE INDEX idx_activity_log_entity ON activity_log(entity_type, entity_id, created_at)');
    ensureIndexExists($pdo, 'activity_log', 'idx_activity_log_user',
        'CREATE INDEX idx_activity_log_user ON activity_log(user_id, created_at)');
}

function seedCrmStatusValues(PDO $pdo): void {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM crm_statusy");
        $count = (int)($stmt ? $stmt->fetchColumn() : 0);
        if ($count > 0) {
            return;
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot count crm_statusy: ' . $e->getMessage());
        return;
    }

    $defaults = [
        ['Nowy', 1, 'oba', 10],
        ['Do kontaktu', 1, 'oba', 20],
        ['Wysłano ofertę', 1, 'oba', 30],
        ['Negocjacje', 1, 'oba', 40],
        ['Wygrany', 1, 'oba', 50],
        ['Przegrany', 1, 'oba', 60],
    ];

    try {
        $stmt = $pdo->prepare('INSERT INTO crm_statusy (nazwa, aktywny, dotyczy, sort) VALUES (:nazwa, :aktywny, :dotyczy, :sort)');
        foreach ($defaults as [$nazwa, $aktywny, $dotyczy, $sort]) {
            $stmt->execute([
                ':nazwa' => $nazwa,
                ':aktywny' => $aktywny,
                ':dotyczy' => $dotyczy,
                ':sort' => $sort,
            ]);
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot seed crm_statusy: ' . $e->getMessage());
    }
}

function ensureClientLeadColumns(PDO $pdo): void {
    if (!tableExists($pdo, 'klienci')) {
        return;
    }
    $kontaktPreferencjaSql = isSqliteDriver($pdo)
        ? "ALTER TABLE klienci ADD COLUMN kontakt_preferencja TEXT NOT NULL DEFAULT ''"
        : "ALTER TABLE klienci ADD COLUMN kontakt_preferencja ENUM('telefon','email','sms','') NOT NULL DEFAULT ''";
    $columns = [
        'owner_user_id' => "ALTER TABLE klienci ADD COLUMN owner_user_id INT NULL",
        'assigned_user_id' => "ALTER TABLE klienci ADD COLUMN assigned_user_id INT NULL",
        'source_lead_id' => "ALTER TABLE klienci ADD COLUMN source_lead_id INT NULL",
        'company_id' => "ALTER TABLE klienci ADD COLUMN company_id INT NULL",
        'kontakt_imie_nazwisko' => "ALTER TABLE klienci ADD COLUMN kontakt_imie_nazwisko VARCHAR(120) NULL",
        'kontakt_stanowisko' => "ALTER TABLE klienci ADD COLUMN kontakt_stanowisko VARCHAR(120) NULL",
        'kontakt_telefon' => "ALTER TABLE klienci ADD COLUMN kontakt_telefon VARCHAR(60) NULL",
        'kontakt_email' => "ALTER TABLE klienci ADD COLUMN kontakt_email VARCHAR(120) NULL",
        'kontakt_preferencja' => $kontaktPreferencjaSql,
    ];
    ensureTableColumns($pdo, 'klienci', $columns);
    ensureIndexExists($pdo, 'klienci', 'idx_klienci_owner_user_id', 'CREATE INDEX idx_klienci_owner_user_id ON klienci(owner_user_id)');
    ensureIndexExists($pdo, 'klienci', 'idx_klienci_assigned_user_id', 'CREATE INDEX idx_klienci_assigned_user_id ON klienci(assigned_user_id)');
    ensureIndexExists($pdo, 'klienci', 'idx_klienci_source_lead_id', 'CREATE INDEX idx_klienci_source_lead_id ON klienci(source_lead_id)');
    ensureIndexExists($pdo, 'klienci', 'idx_klienci_company_id', 'CREATE INDEX idx_klienci_company_id ON klienci(company_id)');
}

function ensureKampanieOwnershipColumns(PDO $pdo): void {
    if (!tableExists($pdo, 'kampanie')) {
        return;
    }

    $columns = [
        'owner_user_id' => "ALTER TABLE kampanie ADD COLUMN owner_user_id INT NULL",
        'status'        => "ALTER TABLE kampanie ADD COLUMN status VARCHAR(50) NOT NULL DEFAULT 'W realizacji'",
        'propozycja'    => "ALTER TABLE kampanie ADD COLUMN propozycja TINYINT(1) NOT NULL DEFAULT 0",
        'source_lead_id' => "ALTER TABLE kampanie ADD COLUMN source_lead_id INT NULL",
        'realization_status' => "ALTER TABLE kampanie ADD COLUMN realization_status VARCHAR(50) NULL DEFAULT NULL",
    ];

    ensureTableColumns($pdo, 'kampanie', $columns);

    $kampaniaCols = getTableColumns($pdo, 'kampanie');
    if (hasColumn($kampaniaCols, 'owner_user_id')) {
        ensureIndexExists($pdo, 'kampanie', 'idx_kampanie_owner_user_id', 'CREATE INDEX idx_kampanie_owner_user_id ON kampanie(owner_user_id)');
    }
    if (hasColumn($kampaniaCols, 'created_at')) {
        ensureIndexExists($pdo, 'kampanie', 'idx_kampanie_created_at', 'CREATE INDEX idx_kampanie_created_at ON kampanie(created_at)');
    }
    if (hasColumn($kampaniaCols, 'status')) {
        ensureIndexExists($pdo, 'kampanie', 'idx_kampanie_status', 'CREATE INDEX idx_kampanie_status ON kampanie(status)');
    }
    if (hasColumn($kampaniaCols, 'realization_status')) {
        ensureIndexExists($pdo, 'kampanie', 'idx_kampanie_realization_status', 'CREATE INDEX idx_kampanie_realization_status ON kampanie(realization_status)');
    }
    if (hasColumn($kampaniaCols, 'data_start')) {
        ensureIndexExists($pdo, 'kampanie', 'idx_kampanie_data_start', 'CREATE INDEX idx_kampanie_data_start ON kampanie(data_start)');
    }
    if (hasColumn($kampaniaCols, 'source_lead_id')) {
        ensureIndexExists($pdo, 'kampanie', 'idx_kampanie_source_lead_id', 'CREATE INDEX idx_kampanie_source_lead_id ON kampanie(source_lead_id)');
    }

    try {
        $pdo->exec("UPDATE kampanie
            SET realization_status = 'brief_oczekuje'
            WHERE (realization_status IS NULL OR TRIM(COALESCE(realization_status, '')) = '')
              AND LOWER(TRIM(COALESCE(status, ''))) IN ('zamowiona', 'zamówiona')");
    } catch (Throwable $e) {
        error_log('db_schema: cannot normalize kampanie.realization_status: ' . $e->getMessage());
    }
}

function ensureKampanieSalesValueColumn(PDO $pdo): void {
    if (!tableExists($pdo, 'kampanie')) {
        return;
    }
    $columns = [
        'wartosc_netto' => "ALTER TABLE kampanie ADD COLUMN wartosc_netto DECIMAL(12,2) NOT NULL DEFAULT 0",
    ];
    ensureTableColumns($pdo, 'kampanie', $columns);
}

function ensureTransactionsTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT NOT NULL,
            client_id INT NULL,
            source_lead_id INT NULL,
            owner_user_id INT NULL,
            value_netto DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            value_brutto DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(30) NOT NULL DEFAULT 'won',
            won_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_transactions_campaign_id (campaign_id),
            INDEX idx_transactions_owner_won_at (owner_user_id, won_at),
            INDEX idx_transactions_status_won_at (status, won_at),
            INDEX idx_transactions_client_id (client_id),
            INDEX idx_transactions_source_lead_id (source_lead_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create transactions: ' . $e->getMessage());
    }

    $columns = [
        'campaign_id' => "ALTER TABLE transactions ADD COLUMN campaign_id INT NOT NULL",
        'client_id' => "ALTER TABLE transactions ADD COLUMN client_id INT NULL",
        'source_lead_id' => "ALTER TABLE transactions ADD COLUMN source_lead_id INT NULL",
        'owner_user_id' => "ALTER TABLE transactions ADD COLUMN owner_user_id INT NULL",
        'value_netto' => "ALTER TABLE transactions ADD COLUMN value_netto DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        'value_brutto' => "ALTER TABLE transactions ADD COLUMN value_brutto DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        'status' => "ALTER TABLE transactions ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'won'",
        'won_at' => "ALTER TABLE transactions ADD COLUMN won_at DATETIME NULL",
        'created_at' => "ALTER TABLE transactions ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE transactions ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    ensureTableColumns($pdo, 'transactions', $columns);
    ensureIndexExists($pdo, 'transactions', 'uq_transactions_campaign_id',
        'CREATE UNIQUE INDEX uq_transactions_campaign_id ON transactions(campaign_id)');
    ensureIndexExists($pdo, 'transactions', 'idx_transactions_owner_won_at',
        'CREATE INDEX idx_transactions_owner_won_at ON transactions(owner_user_id, won_at)');
    ensureIndexExists($pdo, 'transactions', 'idx_transactions_status_won_at',
        'CREATE INDEX idx_transactions_status_won_at ON transactions(status, won_at)');
    ensureIndexExists($pdo, 'transactions', 'idx_transactions_client_id',
        'CREATE INDEX idx_transactions_client_id ON transactions(client_id)');
    ensureIndexExists($pdo, 'transactions', 'idx_transactions_source_lead_id',
        'CREATE INDEX idx_transactions_source_lead_id ON transactions(source_lead_id)');
}

function ensureCommunicationEventsTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS communication_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(100) NOT NULL,
            idempotency_key VARCHAR(191) NOT NULL,
            direction ENUM('outbound_client', 'internal', 'system') NOT NULL DEFAULT 'system',
            status ENUM('logged', 'sent', 'error', 'skipped_duplicate') NOT NULL DEFAULT 'logged',
            recipient TEXT NULL,
            subject VARCHAR(255) NULL,
            body MEDIUMTEXT NULL,
            meta_json JSON NULL,
            lead_id INT NULL,
            client_id INT NULL,
            campaign_id INT NULL,
            brief_id INT NULL,
            dispatch_id INT NULL,
            spot_audio_file_id INT NULL,
            created_by_user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_communication_events_idempotency (idempotency_key),
            INDEX idx_communication_events_campaign_created (campaign_id, created_at),
            INDEX idx_communication_events_client_created (client_id, created_at),
            INDEX idx_communication_events_lead_created (lead_id, created_at),
            INDEX idx_communication_events_type_created (event_type, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create communication_events: ' . $e->getMessage());
    }

    $columns = [
        'event_type' => "ALTER TABLE communication_events ADD COLUMN event_type VARCHAR(100) NOT NULL",
        'idempotency_key' => "ALTER TABLE communication_events ADD COLUMN idempotency_key VARCHAR(191) NOT NULL",
        'direction' => "ALTER TABLE communication_events ADD COLUMN direction ENUM('outbound_client', 'internal', 'system') NOT NULL DEFAULT 'system'",
        'status' => "ALTER TABLE communication_events ADD COLUMN status ENUM('logged', 'sent', 'error', 'skipped_duplicate') NOT NULL DEFAULT 'logged'",
        'recipient' => "ALTER TABLE communication_events ADD COLUMN recipient TEXT NULL",
        'subject' => "ALTER TABLE communication_events ADD COLUMN subject VARCHAR(255) NULL",
        'body' => "ALTER TABLE communication_events ADD COLUMN body MEDIUMTEXT NULL",
        'meta_json' => "ALTER TABLE communication_events ADD COLUMN meta_json JSON NULL",
        'lead_id' => "ALTER TABLE communication_events ADD COLUMN lead_id INT NULL",
        'client_id' => "ALTER TABLE communication_events ADD COLUMN client_id INT NULL",
        'campaign_id' => "ALTER TABLE communication_events ADD COLUMN campaign_id INT NULL",
        'brief_id' => "ALTER TABLE communication_events ADD COLUMN brief_id INT NULL",
        'dispatch_id' => "ALTER TABLE communication_events ADD COLUMN dispatch_id INT NULL",
        'spot_audio_file_id' => "ALTER TABLE communication_events ADD COLUMN spot_audio_file_id INT NULL",
        'created_by_user_id' => "ALTER TABLE communication_events ADD COLUMN created_by_user_id INT NULL",
        'created_at' => "ALTER TABLE communication_events ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    ensureTableColumns($pdo, 'communication_events', $columns);

    ensureIndexExists($pdo, 'communication_events', 'uq_communication_events_idempotency',
        'CREATE UNIQUE INDEX uq_communication_events_idempotency ON communication_events(idempotency_key)');
    ensureIndexExists($pdo, 'communication_events', 'idx_communication_events_campaign_created',
        'CREATE INDEX idx_communication_events_campaign_created ON communication_events(campaign_id, created_at)');
    ensureIndexExists($pdo, 'communication_events', 'idx_communication_events_client_created',
        'CREATE INDEX idx_communication_events_client_created ON communication_events(client_id, created_at)');
    ensureIndexExists($pdo, 'communication_events', 'idx_communication_events_lead_created',
        'CREATE INDEX idx_communication_events_lead_created ON communication_events(lead_id, created_at)');
    ensureIndexExists($pdo, 'communication_events', 'idx_communication_events_type_created',
        'CREATE INDEX idx_communication_events_type_created ON communication_events(event_type, created_at)');
}

function ensureSalesTargetsTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cele_sprzedazowe (
            id INT AUTO_INCREMENT PRIMARY KEY,
            year SMALLINT NOT NULL,
            month TINYINT NOT NULL,
            user_id INT NOT NULL,
            target_netto DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_by_user_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cele_sprzedazowe_period_user (year, month, user_id),
            INDEX idx_cele_sprzedazowe_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create cele_sprzedazowe: ' . $e->getMessage());
    }
}

function ensureSystemLogsTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            user_id INT NOT NULL,
            action VARCHAR(50) NOT NULL,
            message TEXT NOT NULL,
            INDEX idx_system_logs_user (user_id),
            INDEX idx_system_logs_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create system_logs: ' . $e->getMessage());
    }
}

function ensureCommissionSettlementsTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS prowizje_rozliczenia (
            id INT AUTO_INCREMENT PRIMARY KEY,
            year SMALLINT NOT NULL,
            month TINYINT NOT NULL,
            user_id INT NOT NULL,
            rate_percent DECIMAL(5,2) NOT NULL,
            base_netto DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            commission_netto DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(20) NOT NULL DEFAULT 'Należne',
            calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at DATETIME NULL,
            note VARCHAR(255) NULL,
            UNIQUE KEY uq_prowizje_rozliczenia_period_user (year, month, user_id),
            INDEX idx_prowizje_rozliczenia_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create prowizje_rozliczenia: ' . $e->getMessage());
    }
}

function ensureSpotColumns(PDO $pdo): void {
    if (!tableExists($pdo, 'spoty')) {
        return;
    }

    $columns = [
        'kampania_id' => "ALTER TABLE spoty ADD COLUMN kampania_id INT NULL",
        'klient_id'   => "ALTER TABLE spoty ADD COLUMN klient_id INT NULL",
        'dlugosc_s'   => "ALTER TABLE spoty ADD COLUMN dlugosc_s INT NOT NULL DEFAULT 30",
        'data_start'  => "ALTER TABLE spoty ADD COLUMN data_start DATE NULL",
        'data_koniec' => "ALTER TABLE spoty ADD COLUMN data_koniec DATE NULL",
        'status'      => "ALTER TABLE spoty ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Aktywny'",
        'audio_source_type' => "ALTER TABLE spoty ADD COLUMN audio_source_type VARCHAR(32) NOT NULL DEFAULT 'produced_by_radio'",
        'client_audio_status' => "ALTER TABLE spoty ADD COLUMN client_audio_status VARCHAR(32) NOT NULL DEFAULT 'oczekuje_na_plik'",
        'rotation_group' => "ALTER TABLE spoty ADD COLUMN rotation_group VARCHAR(1) NULL",
        'rotation_mode'  => "ALTER TABLE spoty ADD COLUMN rotation_mode VARCHAR(30) NULL",
    ];

    ensureTableColumns($pdo, 'spoty', $columns);

    // Ensure nullable fields match expectations
    try {
        $pdo->exec("ALTER TABLE spoty MODIFY COLUMN klient_id INT NULL");
    } catch (Throwable $e) {
        error_log('db_schema: cannot make spoty.klient_id nullable: ' . $e->getMessage());
    }
    foreach (['data_start', 'data_koniec'] as $dateCol) {
        try {
            $pdo->exec("ALTER TABLE spoty MODIFY COLUMN {$dateCol} DATE NULL");
        } catch (Throwable $e) {
            error_log(sprintf('db_schema: cannot relax NULL on spoty.%s: %s', $dateCol, $e->getMessage()));
        }
    }

    $spotCols = getTableColumns($pdo, 'spoty');
    if (hasColumn($spotCols, 'kampania_id')) {
        ensureIndexExists($pdo, 'spoty', 'idx_spoty_kampania_id', 'CREATE INDEX idx_spoty_kampania_id ON spoty(kampania_id)');
    }
    if (hasColumn($spotCols, 'klient_id')) {
        ensureIndexExists($pdo, 'spoty', 'idx_spoty_klient_id', 'CREATE INDEX idx_spoty_klient_id ON spoty(klient_id)');
    }
    if (hasColumn($spotCols, 'audio_source_type')) {
        ensureIndexExists($pdo, 'spoty', 'idx_spoty_audio_source_type', 'CREATE INDEX idx_spoty_audio_source_type ON spoty(audio_source_type)');
    }
}

function ensureEmisjeSpotowTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS emisje_spotow (
            id INT AUTO_INCREMENT PRIMARY KEY,
            spot_id INT NOT NULL,
            dow TINYINT NOT NULL,
            godzina TIME NOT NULL,
            liczba INT NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_emisje_spotow_spot_id (spot_id),
            INDEX idx_emisje_spotow_dow (dow),
            INDEX idx_emisje_spotow_godzina (godzina),
            CONSTRAINT fk_emisje_spotow_spot FOREIGN KEY (spot_id) REFERENCES spoty(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create emisje_spotow: ' . $e->getMessage());
    }
}

function ensureSpotAudioFilesTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS spot_audio_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            spot_id INT NOT NULL,
            version_no INT NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            original_filename VARCHAR(255) NOT NULL,
            stored_filename VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NULL,
            file_size INT NULL,
            audio_format VARCHAR(16) NULL,
            duration_seconds DECIMAL(10,3) NULL,
            bitrate INT NULL,
            sample_rate INT NULL,
            channels INT NULL,
            sha256 CHAR(64) NULL,
            production_status VARCHAR(30) NOT NULL DEFAULT 'Do akceptacji',
            client_audio_status VARCHAR(32) NULL,
            approved_by_user_id INT NULL,
            approved_at DATETIME NULL,
            rejection_reason VARCHAR(255) NULL,
            is_final TINYINT(1) NOT NULL DEFAULT 0,
            uploaded_by_user_id INT NOT NULL,
            upload_note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_spot_audio_files_spot_id (spot_id),
            INDEX idx_spot_audio_files_uploaded_by (uploaded_by_user_id),
            INDEX idx_spot_audio_files_spot_final (spot_id, is_final),
            UNIQUE KEY uq_spot_audio_files_stored (stored_filename)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create spot_audio_files: ' . $e->getMessage());
    }

    $columns = [
        'production_status' => "ALTER TABLE spot_audio_files ADD COLUMN production_status VARCHAR(30) NOT NULL DEFAULT 'Do akceptacji'",
        'approved_by_user_id' => "ALTER TABLE spot_audio_files ADD COLUMN approved_by_user_id INT NULL",
        'approved_at' => "ALTER TABLE spot_audio_files ADD COLUMN approved_at DATETIME NULL",
        'rejection_reason' => "ALTER TABLE spot_audio_files ADD COLUMN rejection_reason VARCHAR(255) NULL",
        'is_final' => "ALTER TABLE spot_audio_files ADD COLUMN is_final TINYINT(1) NOT NULL DEFAULT 0",
        'audio_format' => "ALTER TABLE spot_audio_files ADD COLUMN audio_format VARCHAR(16) NULL",
        'duration_seconds' => "ALTER TABLE spot_audio_files ADD COLUMN duration_seconds DECIMAL(10,3) NULL",
        'bitrate' => "ALTER TABLE spot_audio_files ADD COLUMN bitrate INT NULL",
        'sample_rate' => "ALTER TABLE spot_audio_files ADD COLUMN sample_rate INT NULL",
        'channels' => "ALTER TABLE spot_audio_files ADD COLUMN channels INT NULL",
        'client_audio_status' => "ALTER TABLE spot_audio_files ADD COLUMN client_audio_status VARCHAR(32) NULL",
    ];
    ensureTableColumns($pdo, 'spot_audio_files', $columns);
    ensureIndexExists($pdo, 'spot_audio_files', 'idx_spot_audio_files_spot_final',
        'CREATE INDEX idx_spot_audio_files_spot_final ON spot_audio_files(spot_id, is_final)');
    ensureIndexExists($pdo, 'spot_audio_files', 'idx_spot_audio_files_spot_active_status',
        'CREATE INDEX idx_spot_audio_files_spot_active_status ON spot_audio_files(spot_id, is_active, production_status)');
}

function ensureSpotAudioDispatchesTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS spot_audio_dispatches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT NOT NULL,
            spot_id INT NOT NULL,
            dispatched_by_user_id INT NOT NULL,
            dispatched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            channel VARCHAR(30) NOT NULL DEFAULT 'manual',
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_spot_audio_dispatches_campaign (campaign_id),
            INDEX idx_spot_audio_dispatches_spot (spot_id),
            INDEX idx_spot_audio_dispatches_dispatched_at (dispatched_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create spot_audio_dispatches: ' . $e->getMessage());
    }

    $columns = [
        'campaign_id' => "ALTER TABLE spot_audio_dispatches ADD COLUMN campaign_id INT NOT NULL",
        'spot_id' => "ALTER TABLE spot_audio_dispatches ADD COLUMN spot_id INT NOT NULL",
        'dispatched_by_user_id' => "ALTER TABLE spot_audio_dispatches ADD COLUMN dispatched_by_user_id INT NOT NULL",
        'dispatched_at' => "ALTER TABLE spot_audio_dispatches ADD COLUMN dispatched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'channel' => "ALTER TABLE spot_audio_dispatches ADD COLUMN channel VARCHAR(30) NOT NULL DEFAULT 'manual'",
        'note' => "ALTER TABLE spot_audio_dispatches ADD COLUMN note VARCHAR(255) NULL",
        'created_at' => "ALTER TABLE spot_audio_dispatches ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    ensureTableColumns($pdo, 'spot_audio_dispatches', $columns);
    ensureIndexExists($pdo, 'spot_audio_dispatches', 'idx_spot_audio_dispatches_campaign',
        'CREATE INDEX idx_spot_audio_dispatches_campaign ON spot_audio_dispatches(campaign_id)');
    ensureIndexExists($pdo, 'spot_audio_dispatches', 'idx_spot_audio_dispatches_spot',
        'CREATE INDEX idx_spot_audio_dispatches_spot ON spot_audio_dispatches(spot_id)');
    ensureIndexExists($pdo, 'spot_audio_dispatches', 'idx_spot_audio_dispatches_dispatched_at',
        'CREATE INDEX idx_spot_audio_dispatches_dispatched_at ON spot_audio_dispatches(dispatched_at)');
}

function ensureSpotAudioDispatchItemsTable(PDO $pdo): void {
    ensureSpotAudioDispatchesTable($pdo);
    ensureSpotAudioFilesTable($pdo);

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS spot_audio_dispatch_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dispatch_id INT NOT NULL,
            spot_audio_file_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_spot_audio_dispatch_items_unique (dispatch_id, spot_audio_file_id),
            INDEX idx_spot_audio_dispatch_items_dispatch (dispatch_id),
            INDEX idx_spot_audio_dispatch_items_audio (spot_audio_file_id),
            CONSTRAINT fk_spot_audio_dispatch_items_dispatch
                FOREIGN KEY (dispatch_id) REFERENCES spot_audio_dispatches(id) ON DELETE CASCADE,
            CONSTRAINT fk_spot_audio_dispatch_items_audio
                FOREIGN KEY (spot_audio_file_id) REFERENCES spot_audio_files(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create spot_audio_dispatch_items: ' . $e->getMessage());
    }

    $columns = [
        'dispatch_id' => "ALTER TABLE spot_audio_dispatch_items ADD COLUMN dispatch_id INT NOT NULL",
        'spot_audio_file_id' => "ALTER TABLE spot_audio_dispatch_items ADD COLUMN spot_audio_file_id INT NOT NULL",
        'created_at' => "ALTER TABLE spot_audio_dispatch_items ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    ensureTableColumns($pdo, 'spot_audio_dispatch_items', $columns);
    ensureIndexExists($pdo, 'spot_audio_dispatch_items', 'idx_spot_audio_dispatch_items_dispatch',
        'CREATE INDEX idx_spot_audio_dispatch_items_dispatch ON spot_audio_dispatch_items(dispatch_id)');
    ensureIndexExists($pdo, 'spot_audio_dispatch_items', 'idx_spot_audio_dispatch_items_audio',
        'CREATE INDEX idx_spot_audio_dispatch_items_audio ON spot_audio_dispatch_items(spot_audio_file_id)');
    ensureIndexExists($pdo, 'spot_audio_dispatch_items', 'uq_spot_audio_dispatch_items_unique',
        'CREATE UNIQUE INDEX uq_spot_audio_dispatch_items_unique ON spot_audio_dispatch_items(dispatch_id, spot_audio_file_id)');
}

function ensureLeadBriefsTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS lead_briefs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT NOT NULL,
            lead_id INT NULL,
            token CHAR(64) NOT NULL,
            status ENUM('draft','sent','submitted','revision_requested','approved_internal','closed') NOT NULL DEFAULT 'draft',
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create lead_briefs: ' . $e->getMessage());
    }

    $columns = [
        'campaign_id' => "ALTER TABLE lead_briefs ADD COLUMN campaign_id INT NOT NULL",
        'lead_id' => "ALTER TABLE lead_briefs ADD COLUMN lead_id INT NULL",
        'token' => "ALTER TABLE lead_briefs ADD COLUMN token CHAR(64) NOT NULL",
        'status' => "ALTER TABLE lead_briefs ADD COLUMN status ENUM('draft','sent','submitted','revision_requested','approved_internal','closed') NOT NULL DEFAULT 'draft'",
        'is_customer_editable' => "ALTER TABLE lead_briefs ADD COLUMN is_customer_editable TINYINT(1) NOT NULL DEFAULT 1",
        'spot_length_seconds' => "ALTER TABLE lead_briefs ADD COLUMN spot_length_seconds INT NULL",
        'lector_count' => "ALTER TABLE lead_briefs ADD COLUMN lector_count INT NULL",
        'target_group' => "ALTER TABLE lead_briefs ADD COLUMN target_group TEXT NULL",
        'main_message' => "ALTER TABLE lead_briefs ADD COLUMN main_message TEXT NULL",
        'additional_info' => "ALTER TABLE lead_briefs ADD COLUMN additional_info TEXT NULL",
        'contact_details' => "ALTER TABLE lead_briefs ADD COLUMN contact_details TEXT NULL",
        'tone_style' => "ALTER TABLE lead_briefs ADD COLUMN tone_style TEXT NULL",
        'sound_effects' => "ALTER TABLE lead_briefs ADD COLUMN sound_effects TEXT NULL",
        'notes' => "ALTER TABLE lead_briefs ADD COLUMN notes TEXT NULL",
        'production_owner_user_id' => "ALTER TABLE lead_briefs ADD COLUMN production_owner_user_id INT NULL",
        'production_external_studio' => "ALTER TABLE lead_briefs ADD COLUMN production_external_studio VARCHAR(255) NULL",
        'submitted_at' => "ALTER TABLE lead_briefs ADD COLUMN submitted_at DATETIME NULL",
        'created_at' => "ALTER TABLE lead_briefs ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE lead_briefs ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    ensureTableColumns($pdo, 'lead_briefs', $columns);

    ensureIndexExists($pdo, 'lead_briefs', 'uq_lead_briefs_campaign_id',
        'CREATE UNIQUE INDEX uq_lead_briefs_campaign_id ON lead_briefs(campaign_id)');
    ensureIndexExists($pdo, 'lead_briefs', 'uq_lead_briefs_token',
        'CREATE UNIQUE INDEX uq_lead_briefs_token ON lead_briefs(token)');
    ensureIndexExists($pdo, 'lead_briefs', 'idx_lead_briefs_lead_id',
        'CREATE INDEX idx_lead_briefs_lead_id ON lead_briefs(lead_id)');
    ensureIndexExists($pdo, 'lead_briefs', 'idx_lead_briefs_status',
        'CREATE INDEX idx_lead_briefs_status ON lead_briefs(status)');
    ensureIndexExists($pdo, 'lead_briefs', 'idx_lead_briefs_production_owner',
        'CREATE INDEX idx_lead_briefs_production_owner ON lead_briefs(production_owner_user_id)');
}

function ensureGusCacheTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS gus_cache (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nip VARCHAR(20) NULL,
            regon VARCHAR(20) NULL,
            data_json LONGTEXT NOT NULL,
            fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            source VARCHAR(20) NOT NULL DEFAULT 'gus',
            INDEX idx_gus_cache_nip (nip),
            INDEX idx_gus_cache_regon (regon)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create gus_cache: ' . $e->getMessage());
    }
}

function ensureIntegrationsLogsTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS integrations_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            log_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            user_id INT NULL,
            type VARCHAR(30) NOT NULL,
            request_id VARCHAR(100) NULL,
            message TEXT NOT NULL,
            INDEX idx_integrations_logs_type (type),
            INDEX idx_integrations_logs_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create integrations_logs: ' . $e->getMessage());
    }
}

function ensureGusSnapshotsTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS gus_snapshots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            env VARCHAR(10) NOT NULL,
            request_type VARCHAR(10) NOT NULL,
            request_value VARCHAR(32) NOT NULL,
            report_type VARCHAR(80) NULL,
            http_code INT NULL,
            ok TINYINT(1) NOT NULL DEFAULT 0,
            error_code VARCHAR(50) NULL,
            error_message TEXT NULL,
            fault_code VARCHAR(100) NULL,
            fault_string TEXT NULL,
            raw_request LONGTEXT NULL,
            raw_response LONGTEXT NULL,
            raw_parsed JSON NULL,
            correlation_id VARCHAR(100) NULL,
            attempt_no INT NULL,
            latency_ms INT NULL,
            error_class VARCHAR(20) NULL,
            INDEX idx_gus_snapshots_company (company_id),
            INDEX idx_gus_snapshots_env (env),
            INDEX idx_gus_snapshots_request_value (request_value),
            INDEX idx_gus_snapshots_created_at (created_at),
            INDEX idx_gus_snapshots_ok (ok)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create gus_snapshots: ' . $e->getMessage());
    }

    $columns = [
        'company_id' => "ALTER TABLE gus_snapshots ADD COLUMN company_id INT NULL",
        'attempt_no' => "ALTER TABLE gus_snapshots ADD COLUMN attempt_no INT NULL",
        'latency_ms' => "ALTER TABLE gus_snapshots ADD COLUMN latency_ms INT NULL",
        'error_class' => "ALTER TABLE gus_snapshots ADD COLUMN error_class VARCHAR(20) NULL",
        'fault_code' => "ALTER TABLE gus_snapshots ADD COLUMN fault_code VARCHAR(100) NULL",
        'fault_string' => "ALTER TABLE gus_snapshots ADD COLUMN fault_string TEXT NULL",
    ];
    ensureTableColumns($pdo, 'gus_snapshots', $columns);
    ensureIndexExists($pdo, 'gus_snapshots', 'idx_gus_snapshots_company', 'CREATE INDEX idx_gus_snapshots_company ON gus_snapshots(company_id)');
    ensureIndexExists($pdo, 'gus_snapshots', 'idx_gus_snapshots_company_created', 'CREATE INDEX idx_gus_snapshots_company_created ON gus_snapshots(company_id, created_at)');
}

function ensureCompaniesTable(PDO $pdo): void {
    try {
        if (isSqliteDriver($pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS companies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nip TEXT NULL,
                regon TEXT NULL,
                krs TEXT NULL,
                name_full TEXT NULL,
                name_short TEXT NULL,
                status TEXT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                gus_last_refresh_at DATETIME NULL,
                gus_last_status TEXT NULL,
                gus_last_error_code TEXT NULL,
                gus_last_error_message TEXT NULL,
                gus_last_sid TEXT NULL,
                street TEXT NULL,
                building_no TEXT NULL,
                apartment_no TEXT NULL,
                postal_code TEXT NULL,
                city TEXT NULL,
                gmina TEXT NULL,
                powiat TEXT NULL,
                wojewodztwo TEXT NULL,
                country TEXT NULL,
                lock_name INTEGER NOT NULL DEFAULT 0,
                lock_address INTEGER NOT NULL DEFAULT 0,
                lock_identifiers INTEGER NOT NULL DEFAULT 0,
                last_gus_check_at DATETIME NULL,
                last_gus_error_at DATETIME NULL,
                last_gus_error_message TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (nip)
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS companies (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nip VARCHAR(20) NULL,
                regon VARCHAR(20) NULL,
                krs VARCHAR(20) NULL,
                name_full VARCHAR(255) NULL,
                name_short VARCHAR(255) NULL,
                status VARCHAR(50) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                gus_last_refresh_at DATETIME NULL,
                gus_last_status VARCHAR(10) NULL,
                gus_last_error_code VARCHAR(50) NULL,
                gus_last_error_message TEXT NULL,
                gus_last_sid VARCHAR(100) NULL,
                street VARCHAR(150) NULL,
                building_no VARCHAR(30) NULL,
                apartment_no VARCHAR(30) NULL,
                postal_code VARCHAR(20) NULL,
                city VARCHAR(150) NULL,
                gmina VARCHAR(150) NULL,
                powiat VARCHAR(150) NULL,
                wojewodztwo VARCHAR(150) NULL,
                country VARCHAR(80) NULL,
                lock_name TINYINT(1) NOT NULL DEFAULT 0,
                lock_address TINYINT(1) NOT NULL DEFAULT 0,
                lock_identifiers TINYINT(1) NOT NULL DEFAULT 0,
                last_gus_check_at DATETIME NULL,
                last_gus_error_at DATETIME NULL,
                last_gus_error_message TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_companies_nip (nip)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot create companies: ' . $e->getMessage());
    }

    $columns = [
        'nip' => "ALTER TABLE companies ADD COLUMN nip VARCHAR(20) NULL",
        'regon' => "ALTER TABLE companies ADD COLUMN regon VARCHAR(20) NULL",
        'krs' => "ALTER TABLE companies ADD COLUMN krs VARCHAR(20) NULL",
        'name_full' => "ALTER TABLE companies ADD COLUMN name_full VARCHAR(255) NULL",
        'name_short' => "ALTER TABLE companies ADD COLUMN name_short VARCHAR(255) NULL",
        'status' => "ALTER TABLE companies ADD COLUMN status VARCHAR(50) NULL",
        'is_active' => "ALTER TABLE companies ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1",
        'gus_last_refresh_at' => "ALTER TABLE companies ADD COLUMN gus_last_refresh_at DATETIME NULL",
        'gus_last_status' => "ALTER TABLE companies ADD COLUMN gus_last_status VARCHAR(10) NULL",
        'gus_last_error_code' => "ALTER TABLE companies ADD COLUMN gus_last_error_code VARCHAR(50) NULL",
        'gus_last_error_message' => "ALTER TABLE companies ADD COLUMN gus_last_error_message TEXT NULL",
        'gus_last_sid' => "ALTER TABLE companies ADD COLUMN gus_last_sid VARCHAR(100) NULL",
        'street' => "ALTER TABLE companies ADD COLUMN street VARCHAR(150) NULL",
        'building_no' => "ALTER TABLE companies ADD COLUMN building_no VARCHAR(30) NULL",
        'apartment_no' => "ALTER TABLE companies ADD COLUMN apartment_no VARCHAR(30) NULL",
        'postal_code' => "ALTER TABLE companies ADD COLUMN postal_code VARCHAR(20) NULL",
        'city' => "ALTER TABLE companies ADD COLUMN city VARCHAR(150) NULL",
        'gmina' => "ALTER TABLE companies ADD COLUMN gmina VARCHAR(150) NULL",
        'powiat' => "ALTER TABLE companies ADD COLUMN powiat VARCHAR(150) NULL",
        'wojewodztwo' => "ALTER TABLE companies ADD COLUMN wojewodztwo VARCHAR(150) NULL",
        'country' => "ALTER TABLE companies ADD COLUMN country VARCHAR(80) NULL",
        'lock_name' => "ALTER TABLE companies ADD COLUMN lock_name TINYINT(1) NOT NULL DEFAULT 0",
        'lock_address' => "ALTER TABLE companies ADD COLUMN lock_address TINYINT(1) NOT NULL DEFAULT 0",
        'lock_identifiers' => "ALTER TABLE companies ADD COLUMN lock_identifiers TINYINT(1) NOT NULL DEFAULT 0",
        'last_gus_check_at' => "ALTER TABLE companies ADD COLUMN last_gus_check_at DATETIME NULL",
        'last_gus_error_at' => "ALTER TABLE companies ADD COLUMN last_gus_error_at DATETIME NULL",
        'last_gus_error_message' => "ALTER TABLE companies ADD COLUMN last_gus_error_message TEXT NULL",
    ];
    ensureTableColumns($pdo, 'companies', $columns);
    try {
        $dupStmt = $pdo->query("SELECT nip FROM companies WHERE nip IS NOT NULL AND nip <> '' GROUP BY nip HAVING COUNT(*) > 1 LIMIT 1");
        $hasDup = (bool)($dupStmt && $dupStmt->fetchColumn());
    } catch (Throwable $e) {
        $hasDup = false;
    }
    if (!$hasDup) {
        ensureIndexExists($pdo, 'companies', 'uq_companies_nip', 'CREATE UNIQUE INDEX uq_companies_nip ON companies(nip)');
    }
    ensureIndexExists($pdo, 'companies', 'idx_companies_regon', 'CREATE INDEX idx_companies_regon ON companies(regon)');
    ensureIndexExists($pdo, 'companies', 'idx_companies_krs', 'CREATE INDEX idx_companies_krs ON companies(krs)');

    ensureCheckConstraint($pdo, 'companies', 'chk_companies_nip_len', "CHECK (nip IS NULL OR CHAR_LENGTH(nip) = 10)");
    ensureCheckConstraint($pdo, 'companies', 'chk_companies_regon_len', "CHECK (regon IS NULL OR CHAR_LENGTH(regon) IN (9,14))");
    ensureCheckConstraint($pdo, 'companies', 'chk_companies_krs_len', "CHECK (krs IS NULL OR CHAR_LENGTH(krs) = 10)");
}

function ensureCompanyChangeLogTable(PDO $pdo): void {
    try {
        if (isSqliteDriver($pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS company_change_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                company_id INT NOT NULL,
                user_id INT NULL,
                source TEXT NOT NULL DEFAULT 'gus',
                changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                changed_fields TEXT NULL,
                diff TEXT NULL
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS company_change_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NOT NULL,
                user_id INT NULL,
                source VARCHAR(30) NOT NULL DEFAULT 'gus',
                changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                changed_fields JSON NULL,
                diff JSON NULL,
                INDEX idx_company_change_log_company (company_id),
                INDEX idx_company_change_log_changed_at (changed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot create company_change_log: ' . $e->getMessage());
    }
    ensureIndexExists($pdo, 'company_change_log', 'idx_company_change_log_company', 'CREATE INDEX idx_company_change_log_company ON company_change_log(company_id)');
    ensureIndexExists($pdo, 'company_change_log', 'idx_company_change_log_changed_at', 'CREATE INDEX idx_company_change_log_changed_at ON company_change_log(changed_at)');
}

function ensureCompanyProfileTable(PDO $pdo): void {
    try {
        if (isSqliteDriver($pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS company_profile (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                company_name TEXT NOT NULL,
                short_name TEXT NULL,
                nip TEXT NULL,
                regon TEXT NULL,
                krs TEXT NULL,
                address_street TEXT NULL,
                address_postal_code TEXT NULL,
                address_city TEXT NULL,
                email TEXT NULL,
                phone TEXT NULL,
                website TEXT NULL,
                bank_account TEXT NULL,
                bank_name TEXT NULL,
                representative_name TEXT NULL,
                representative_role TEXT NULL,
                logo_path TEXT NULL,
                stamp_path TEXT NULL,
                signature_path TEXT NULL,
                default_vat_rate NUMERIC NOT NULL DEFAULT 23.00,
                default_payment_days INTEGER NOT NULL DEFAULT 14,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS company_profile (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_name VARCHAR(255) NOT NULL,
                short_name VARCHAR(120) NULL,
                nip VARCHAR(20) NULL,
                regon VARCHAR(20) NULL,
                krs VARCHAR(20) NULL,
                address_street VARCHAR(255) NULL,
                address_postal_code VARCHAR(20) NULL,
                address_city VARCHAR(120) NULL,
                email VARCHAR(255) NULL,
                phone VARCHAR(50) NULL,
                website VARCHAR(255) NULL,
                bank_account VARCHAR(80) NULL,
                bank_name VARCHAR(160) NULL,
                representative_name VARCHAR(160) NULL,
                representative_role VARCHAR(120) NULL,
                logo_path VARCHAR(255) NULL,
                stamp_path VARCHAR(255) NULL,
                signature_path VARCHAR(255) NULL,
                default_vat_rate DECIMAL(5,2) NOT NULL DEFAULT 23.00,
                default_payment_days INT NOT NULL DEFAULT 14,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_company_profile_nip (nip)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot create company_profile: ' . $e->getMessage());
    }

    $columns = [
        'company_name' => "ALTER TABLE company_profile ADD COLUMN company_name VARCHAR(255) NOT NULL",
        'short_name' => "ALTER TABLE company_profile ADD COLUMN short_name VARCHAR(120) NULL",
        'nip' => "ALTER TABLE company_profile ADD COLUMN nip VARCHAR(20) NULL",
        'regon' => "ALTER TABLE company_profile ADD COLUMN regon VARCHAR(20) NULL",
        'krs' => "ALTER TABLE company_profile ADD COLUMN krs VARCHAR(20) NULL",
        'address_street' => "ALTER TABLE company_profile ADD COLUMN address_street VARCHAR(255) NULL",
        'address_postal_code' => "ALTER TABLE company_profile ADD COLUMN address_postal_code VARCHAR(20) NULL",
        'address_city' => "ALTER TABLE company_profile ADD COLUMN address_city VARCHAR(120) NULL",
        'email' => "ALTER TABLE company_profile ADD COLUMN email VARCHAR(255) NULL",
        'phone' => "ALTER TABLE company_profile ADD COLUMN phone VARCHAR(50) NULL",
        'website' => "ALTER TABLE company_profile ADD COLUMN website VARCHAR(255) NULL",
        'bank_account' => "ALTER TABLE company_profile ADD COLUMN bank_account VARCHAR(80) NULL",
        'bank_name' => "ALTER TABLE company_profile ADD COLUMN bank_name VARCHAR(160) NULL",
        'representative_name' => "ALTER TABLE company_profile ADD COLUMN representative_name VARCHAR(160) NULL",
        'representative_role' => "ALTER TABLE company_profile ADD COLUMN representative_role VARCHAR(120) NULL",
        'logo_path' => "ALTER TABLE company_profile ADD COLUMN logo_path VARCHAR(255) NULL",
        'stamp_path' => "ALTER TABLE company_profile ADD COLUMN stamp_path VARCHAR(255) NULL",
        'signature_path' => "ALTER TABLE company_profile ADD COLUMN signature_path VARCHAR(255) NULL",
        'default_vat_rate' => "ALTER TABLE company_profile ADD COLUMN default_vat_rate DECIMAL(5,2) NOT NULL DEFAULT 23.00",
        'default_payment_days' => "ALTER TABLE company_profile ADD COLUMN default_payment_days INT NOT NULL DEFAULT 14",
        'created_at' => "ALTER TABLE company_profile ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE company_profile ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    ensureTableColumns($pdo, 'company_profile', $columns);
    ensureIndexExists($pdo, 'company_profile', 'idx_company_profile_nip', 'CREATE INDEX idx_company_profile_nip ON company_profile(nip)');

    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM company_profile")->fetchColumn();
        if ($count > 0) {
            return;
        }

        $configCols = getTableColumns($pdo, 'konfiguracja_systemu');
        if ($configCols === []) {
            return;
        }

        $stmt = $pdo->query("SELECT company_name, company_address, company_nip, company_email, company_phone, pdf_logo_path FROM konfiguracja_systemu WHERE id = 1 LIMIT 1");
        $legacy = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        if ($stmt) {
            $stmt->closeCursor();
        }

        $hasLegacyData = trim((string)($legacy['company_name'] ?? '')) !== ''
            || trim((string)($legacy['company_nip'] ?? '')) !== ''
            || trim((string)($legacy['company_email'] ?? '')) !== ''
            || trim((string)($legacy['pdf_logo_path'] ?? '')) !== '';
        if (!$hasLegacyData) {
            return;
        }

        $insert = $pdo->prepare("INSERT INTO company_profile
            (company_name, nip, address_street, email, phone, logo_path, default_vat_rate, default_payment_days)
            VALUES (:company_name, :nip, :address_street, :email, :phone, :logo_path, 23.00, 14)");
        $insert->execute([
            ':company_name' => trim((string)($legacy['company_name'] ?? '')) ?: 'Firma',
            ':nip' => trim((string)($legacy['company_nip'] ?? '')) ?: null,
            ':address_street' => trim((string)($legacy['company_address'] ?? '')) ?: null,
            ':email' => trim((string)($legacy['company_email'] ?? '')) ?: null,
            ':phone' => trim((string)($legacy['company_phone'] ?? '')) ?: null,
            ':logo_path' => trim((string)($legacy['pdf_logo_path'] ?? '')) ?: null,
        ]);
    } catch (Throwable $e) {
        error_log('db_schema: cannot seed company_profile: ' . $e->getMessage());
    }
}

function ensureDocumentNumberingSettingsTable(PDO $pdo): void {
    try {
        if (isSqliteDriver($pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_numbering_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_type TEXT NOT NULL,
                prefix TEXT NOT NULL,
                numbering_pattern TEXT NOT NULL DEFAULT '{PREFIX}/{YEAR}/{MONTH}/{NUMBER}',
                current_year INTEGER NULL,
                current_month INTEGER NULL,
                last_number INTEGER NOT NULL DEFAULT 0,
                reset_period TEXT NOT NULL DEFAULT 'yearly',
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (document_type)
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_numbering_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_type VARCHAR(30) NOT NULL,
                prefix VARCHAR(20) NOT NULL,
                numbering_pattern VARCHAR(120) NOT NULL DEFAULT '{PREFIX}/{YEAR}/{MONTH}/{NUMBER}',
                current_year INT NULL,
                current_month INT NULL,
                last_number INT NOT NULL DEFAULT 0,
                reset_period VARCHAR(20) NOT NULL DEFAULT 'yearly',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_document_numbering_type (document_type),
                KEY idx_document_numbering_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot create document_numbering_settings: ' . $e->getMessage());
    }

    $columns = [
        'document_type' => "ALTER TABLE document_numbering_settings ADD COLUMN document_type VARCHAR(30) NOT NULL",
        'prefix' => "ALTER TABLE document_numbering_settings ADD COLUMN prefix VARCHAR(20) NOT NULL",
        'numbering_pattern' => "ALTER TABLE document_numbering_settings ADD COLUMN numbering_pattern VARCHAR(120) NOT NULL DEFAULT '{PREFIX}/{YEAR}/{MONTH}/{NUMBER}'",
        'current_year' => "ALTER TABLE document_numbering_settings ADD COLUMN current_year INT NULL",
        'current_month' => "ALTER TABLE document_numbering_settings ADD COLUMN current_month INT NULL",
        'last_number' => "ALTER TABLE document_numbering_settings ADD COLUMN last_number INT NOT NULL DEFAULT 0",
        'reset_period' => "ALTER TABLE document_numbering_settings ADD COLUMN reset_period VARCHAR(20) NOT NULL DEFAULT 'yearly'",
        'is_active' => "ALTER TABLE document_numbering_settings ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1",
        'created_at' => "ALTER TABLE document_numbering_settings ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE document_numbering_settings ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    ensureTableColumns($pdo, 'document_numbering_settings', $columns);
    ensureIndexExists($pdo, 'document_numbering_settings', 'idx_document_numbering_active', 'CREATE INDEX idx_document_numbering_active ON document_numbering_settings(is_active)');

    try {
        $defaults = [
            ['order', 'ZL', 'ZL/{YEAR}/{MONTH}/{NUMBER}', 'monthly'],
            ['annex', 'AN', 'AN/{YEAR}/{MONTH}/{NUMBER}', 'monthly'],
        ];
        $stmt = $pdo->prepare("INSERT INTO document_numbering_settings
            (document_type, prefix, numbering_pattern, reset_period, is_active)
            SELECT :document_type, :prefix, :numbering_pattern, :reset_period, 1
            WHERE NOT EXISTS (
                SELECT 1 FROM document_numbering_settings WHERE document_type = :document_type_check
            )");
        foreach ($defaults as [$type, $prefix, $pattern, $resetPeriod]) {
            $stmt->execute([
                ':document_type' => $type,
                ':prefix' => $prefix,
                ':numbering_pattern' => $pattern,
                ':reset_period' => $resetPeriod,
                ':document_type_check' => $type,
            ]);
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot seed document_numbering_settings: ' . $e->getMessage());
    }
}

function ensureSalesDocumentsTable(PDO $pdo): void {
    ensureCompanyProfileTable($pdo);

    try {
        if (isSqliteDriver($pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS documents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_type TEXT NOT NULL,
                document_number TEXT NOT NULL,
                related_document_id INTEGER NULL,
                client_id INTEGER NULL,
                campaign_id INTEGER NULL,
                company_profile_id INTEGER NULL,
                issue_date DATE NULL,
                valid_from DATE NULL,
                valid_to DATE NULL,
                status TEXT NOT NULL DEFAULT 'draft',
                title TEXT NULL,
                net_value NUMERIC NOT NULL DEFAULT 0.00,
                vat_rate NUMERIC NOT NULL DEFAULT 23.00,
                vat_value NUMERIC NOT NULL DEFAULT 0.00,
                gross_value NUMERIC NOT NULL DEFAULT 0.00,
                currency TEXT NOT NULL DEFAULT 'PLN',
                pdf_path TEXT NULL,
                created_by INTEGER NULL,
                accepted_at DATETIME NULL,
                accepted_by_name TEXT NULL,
                accepted_by_email TEXT NULL,
                acceptance_ip TEXT NULL,
                acceptance_user_agent TEXT NULL,
                notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (document_number)
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS documents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_type VARCHAR(30) NOT NULL,
                document_number VARCHAR(80) NOT NULL,
                related_document_id INT NULL,
                client_id INT NULL,
                campaign_id INT NULL,
                company_profile_id INT NULL,
                issue_date DATE NULL,
                valid_from DATE NULL,
                valid_to DATE NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'draft',
                title VARCHAR(255) NULL,
                net_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                vat_rate DECIMAL(5,2) NOT NULL DEFAULT 23.00,
                vat_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                gross_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                currency CHAR(3) NOT NULL DEFAULT 'PLN',
                pdf_path VARCHAR(255) NULL,
                created_by INT NULL,
                accepted_at DATETIME NULL,
                accepted_by_name VARCHAR(160) NULL,
                accepted_by_email VARCHAR(255) NULL,
                acceptance_ip VARCHAR(45) NULL,
                acceptance_user_agent VARCHAR(255) NULL,
                notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_documents_number (document_number),
                KEY idx_documents_type_status (document_type, status),
                KEY idx_documents_client (client_id),
                KEY idx_documents_campaign (campaign_id),
                KEY idx_documents_company_profile (company_profile_id),
                KEY idx_documents_related (related_document_id),
                KEY idx_documents_created_by (created_by),
                KEY idx_documents_issue_date (issue_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot create documents: ' . $e->getMessage());
    }

    $columns = [
        'document_type' => "ALTER TABLE documents ADD COLUMN document_type VARCHAR(30) NOT NULL",
        'document_number' => "ALTER TABLE documents ADD COLUMN document_number VARCHAR(80) NOT NULL",
        'related_document_id' => "ALTER TABLE documents ADD COLUMN related_document_id INT NULL",
        'client_id' => "ALTER TABLE documents ADD COLUMN client_id INT NULL",
        'campaign_id' => "ALTER TABLE documents ADD COLUMN campaign_id INT NULL",
        'company_profile_id' => "ALTER TABLE documents ADD COLUMN company_profile_id INT NULL",
        'issue_date' => "ALTER TABLE documents ADD COLUMN issue_date DATE NULL",
        'valid_from' => "ALTER TABLE documents ADD COLUMN valid_from DATE NULL",
        'valid_to' => "ALTER TABLE documents ADD COLUMN valid_to DATE NULL",
        'status' => "ALTER TABLE documents ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'draft'",
        'title' => "ALTER TABLE documents ADD COLUMN title VARCHAR(255) NULL",
        'net_value' => "ALTER TABLE documents ADD COLUMN net_value DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        'vat_rate' => "ALTER TABLE documents ADD COLUMN vat_rate DECIMAL(5,2) NOT NULL DEFAULT 23.00",
        'vat_value' => "ALTER TABLE documents ADD COLUMN vat_value DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        'gross_value' => "ALTER TABLE documents ADD COLUMN gross_value DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        'currency' => "ALTER TABLE documents ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'PLN'",
        'pdf_path' => "ALTER TABLE documents ADD COLUMN pdf_path VARCHAR(255) NULL",
        'created_by' => "ALTER TABLE documents ADD COLUMN created_by INT NULL",
        'accepted_at' => "ALTER TABLE documents ADD COLUMN accepted_at DATETIME NULL",
        'accepted_by_name' => "ALTER TABLE documents ADD COLUMN accepted_by_name VARCHAR(160) NULL",
        'accepted_by_email' => "ALTER TABLE documents ADD COLUMN accepted_by_email VARCHAR(255) NULL",
        'acceptance_ip' => "ALTER TABLE documents ADD COLUMN acceptance_ip VARCHAR(45) NULL",
        'acceptance_user_agent' => "ALTER TABLE documents ADD COLUMN acceptance_user_agent VARCHAR(255) NULL",
        'notes' => "ALTER TABLE documents ADD COLUMN notes TEXT NULL",
        'created_at' => "ALTER TABLE documents ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE documents ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    ensureTableColumns($pdo, 'documents', $columns);
    ensureIndexExists($pdo, 'documents', 'uq_documents_number', 'CREATE UNIQUE INDEX uq_documents_number ON documents(document_number)');
    ensureIndexExists($pdo, 'documents', 'idx_documents_type_status', 'CREATE INDEX idx_documents_type_status ON documents(document_type, status)');
    ensureIndexExists($pdo, 'documents', 'idx_documents_client', 'CREATE INDEX idx_documents_client ON documents(client_id)');
    ensureIndexExists($pdo, 'documents', 'idx_documents_campaign', 'CREATE INDEX idx_documents_campaign ON documents(campaign_id)');
    ensureIndexExists($pdo, 'documents', 'idx_documents_company_profile', 'CREATE INDEX idx_documents_company_profile ON documents(company_profile_id)');
    ensureIndexExists($pdo, 'documents', 'idx_documents_related', 'CREATE INDEX idx_documents_related ON documents(related_document_id)');
    ensureIndexExists($pdo, 'documents', 'idx_documents_created_by', 'CREATE INDEX idx_documents_created_by ON documents(created_by)');
    ensureIndexExists($pdo, 'documents', 'idx_documents_issue_date', 'CREATE INDEX idx_documents_issue_date ON documents(issue_date)');
}

function ensureDocumentOrderDetailsTable(PDO $pdo): void {
    ensureSalesDocumentsTable($pdo);

    try {
        if (isSqliteDriver($pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_order_details (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_id INTEGER NOT NULL,
                spot_source TEXT NOT NULL,
                material_deadline DATE NULL,
                spot_length_seconds INTEGER NOT NULL DEFAULT 0,
                emission_count INTEGER NOT NULL DEFAULT 0,
                technical_notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_order_details (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                spot_source VARCHAR(30) NOT NULL,
                material_deadline DATE NULL,
                spot_length_seconds INT NOT NULL DEFAULT 0,
                emission_count INT NOT NULL DEFAULT 0,
                technical_notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_document_order_details_document (document_id),
                KEY idx_document_order_details_spot_source (spot_source)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot create document_order_details: ' . $e->getMessage());
    }

    $columns = [
        'document_id' => "ALTER TABLE document_order_details ADD COLUMN document_id INT NOT NULL",
        'spot_source' => "ALTER TABLE document_order_details ADD COLUMN spot_source VARCHAR(30) NOT NULL",
        'material_deadline' => "ALTER TABLE document_order_details ADD COLUMN material_deadline DATE NULL",
        'spot_length_seconds' => "ALTER TABLE document_order_details ADD COLUMN spot_length_seconds INT NOT NULL DEFAULT 0",
        'emission_count' => "ALTER TABLE document_order_details ADD COLUMN emission_count INT NOT NULL DEFAULT 0",
        'technical_notes' => "ALTER TABLE document_order_details ADD COLUMN technical_notes TEXT NULL",
        'created_at' => "ALTER TABLE document_order_details ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE document_order_details ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    ensureTableColumns($pdo, 'document_order_details', $columns);
    ensureIndexExists($pdo, 'document_order_details', 'uq_document_order_details_document', 'CREATE UNIQUE INDEX uq_document_order_details_document ON document_order_details(document_id)');
    ensureIndexExists($pdo, 'document_order_details', 'idx_document_order_details_spot_source', 'CREATE INDEX idx_document_order_details_spot_source ON document_order_details(spot_source)');
}

function ensureDocumentAnnexDetailsTable(PDO $pdo): void {
    ensureSalesDocumentsTable($pdo);

    try {
        if (isSqliteDriver($pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_annex_details (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_id INTEGER NOT NULL,
                base_document_id INTEGER NOT NULL,
                change_description TEXT NOT NULL,
                old_valid_from DATE NULL,
                old_valid_to DATE NULL,
                new_valid_from DATE NULL,
                new_valid_to DATE NULL,
                old_net_value NUMERIC NOT NULL DEFAULT 0.00,
                old_gross_value NUMERIC NOT NULL DEFAULT 0.00,
                new_net_value NUMERIC NOT NULL DEFAULT 0.00,
                new_gross_value NUMERIC NOT NULL DEFAULT 0.00,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_annex_details (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                base_document_id INT NOT NULL,
                change_description TEXT NOT NULL,
                old_valid_from DATE NULL,
                old_valid_to DATE NULL,
                new_valid_from DATE NULL,
                new_valid_to DATE NULL,
                old_net_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                old_gross_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                new_net_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                new_gross_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_document_annex_details_document (document_id),
                KEY idx_document_annex_details_base (base_document_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot create document_annex_details: ' . $e->getMessage());
    }

    $columns = [
        'document_id' => "ALTER TABLE document_annex_details ADD COLUMN document_id INT NOT NULL",
        'base_document_id' => "ALTER TABLE document_annex_details ADD COLUMN base_document_id INT NOT NULL",
        'change_description' => "ALTER TABLE document_annex_details ADD COLUMN change_description TEXT NOT NULL",
        'old_valid_from' => "ALTER TABLE document_annex_details ADD COLUMN old_valid_from DATE NULL",
        'old_valid_to' => "ALTER TABLE document_annex_details ADD COLUMN old_valid_to DATE NULL",
        'new_valid_from' => "ALTER TABLE document_annex_details ADD COLUMN new_valid_from DATE NULL",
        'new_valid_to' => "ALTER TABLE document_annex_details ADD COLUMN new_valid_to DATE NULL",
        'old_net_value' => "ALTER TABLE document_annex_details ADD COLUMN old_net_value DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        'old_gross_value' => "ALTER TABLE document_annex_details ADD COLUMN old_gross_value DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        'new_net_value' => "ALTER TABLE document_annex_details ADD COLUMN new_net_value DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        'new_gross_value' => "ALTER TABLE document_annex_details ADD COLUMN new_gross_value DECIMAL(12,2) NOT NULL DEFAULT 0.00",
        'created_at' => "ALTER TABLE document_annex_details ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE document_annex_details ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    ensureTableColumns($pdo, 'document_annex_details', $columns);
    ensureIndexExists($pdo, 'document_annex_details', 'uq_document_annex_details_document', 'CREATE UNIQUE INDEX uq_document_annex_details_document ON document_annex_details(document_id)');
    ensureIndexExists($pdo, 'document_annex_details', 'idx_document_annex_details_base', 'CREATE INDEX idx_document_annex_details_base ON document_annex_details(base_document_id)');
}

function ensureDocumentEmailLogTable(PDO $pdo): void {
    ensureSalesDocumentsTable($pdo);

    try {
        if (isSqliteDriver($pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_email_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_id INTEGER NOT NULL,
                recipient_email TEXT NOT NULL,
                subject TEXT NOT NULL,
                body TEXT NOT NULL,
                attachment_path TEXT NULL,
                sent_by INTEGER NULL,
                sent_at DATETIME NULL,
                status TEXT NOT NULL,
                error_message TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_email_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                recipient_email VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                attachment_path VARCHAR(255) NULL,
                sent_by INT NULL,
                sent_at DATETIME NULL,
                status VARCHAR(30) NOT NULL,
                error_message TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_document_email_log_document (document_id),
                KEY idx_document_email_log_status (status),
                KEY idx_document_email_log_sent_at (sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot create document_email_log: ' . $e->getMessage());
    }

    $columns = [
        'document_id' => "ALTER TABLE document_email_log ADD COLUMN document_id INT NOT NULL",
        'recipient_email' => "ALTER TABLE document_email_log ADD COLUMN recipient_email VARCHAR(255) NOT NULL",
        'subject' => "ALTER TABLE document_email_log ADD COLUMN subject VARCHAR(255) NOT NULL",
        'body' => "ALTER TABLE document_email_log ADD COLUMN body TEXT NOT NULL",
        'attachment_path' => "ALTER TABLE document_email_log ADD COLUMN attachment_path VARCHAR(255) NULL",
        'sent_by' => "ALTER TABLE document_email_log ADD COLUMN sent_by INT NULL",
        'sent_at' => "ALTER TABLE document_email_log ADD COLUMN sent_at DATETIME NULL",
        'status' => "ALTER TABLE document_email_log ADD COLUMN status VARCHAR(30) NOT NULL",
        'error_message' => "ALTER TABLE document_email_log ADD COLUMN error_message TEXT NULL",
        'created_at' => "ALTER TABLE document_email_log ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    ensureTableColumns($pdo, 'document_email_log', $columns);
    ensureIndexExists($pdo, 'document_email_log', 'idx_document_email_log_document', 'CREATE INDEX idx_document_email_log_document ON document_email_log(document_id)');
    ensureIndexExists($pdo, 'document_email_log', 'idx_document_email_log_status', 'CREATE INDEX idx_document_email_log_status ON document_email_log(status)');
    ensureIndexExists($pdo, 'document_email_log', 'idx_document_email_log_sent_at', 'CREATE INDEX idx_document_email_log_sent_at ON document_email_log(sent_at)');
}

function ensureDocumentCampaignSyncLogTable(PDO $pdo): void {
    ensureSalesDocumentsTable($pdo);

    try {
        if (isSqliteDriver($pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_campaign_sync_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_id INTEGER NOT NULL,
                campaign_id INTEGER NULL,
                action TEXT NOT NULL,
                old_campaign_status TEXT NULL,
                new_campaign_status TEXT NULL,
                old_spot_status TEXT NULL,
                new_spot_status TEXT NULL,
                message TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_campaign_sync_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                campaign_id INT NULL,
                action VARCHAR(40) NOT NULL,
                old_campaign_status VARCHAR(80) NULL,
                new_campaign_status VARCHAR(80) NULL,
                old_spot_status VARCHAR(255) NULL,
                new_spot_status VARCHAR(255) NULL,
                message TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_document_campaign_sync_document (document_id),
                KEY idx_document_campaign_sync_campaign (campaign_id),
                KEY idx_document_campaign_sync_action (action),
                KEY idx_document_campaign_sync_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot create document_campaign_sync_log: ' . $e->getMessage());
    }

    $columns = [
        'document_id' => "ALTER TABLE document_campaign_sync_log ADD COLUMN document_id INT NOT NULL",
        'campaign_id' => "ALTER TABLE document_campaign_sync_log ADD COLUMN campaign_id INT NULL",
        'action' => "ALTER TABLE document_campaign_sync_log ADD COLUMN action VARCHAR(40) NOT NULL",
        'old_campaign_status' => "ALTER TABLE document_campaign_sync_log ADD COLUMN old_campaign_status VARCHAR(80) NULL",
        'new_campaign_status' => "ALTER TABLE document_campaign_sync_log ADD COLUMN new_campaign_status VARCHAR(80) NULL",
        'old_spot_status' => "ALTER TABLE document_campaign_sync_log ADD COLUMN old_spot_status VARCHAR(255) NULL",
        'new_spot_status' => "ALTER TABLE document_campaign_sync_log ADD COLUMN new_spot_status VARCHAR(255) NULL",
        'message' => "ALTER TABLE document_campaign_sync_log ADD COLUMN message TEXT NULL",
        'created_at' => "ALTER TABLE document_campaign_sync_log ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    ensureTableColumns($pdo, 'document_campaign_sync_log', $columns);
    ensureIndexExists($pdo, 'document_campaign_sync_log', 'idx_document_campaign_sync_document', 'CREATE INDEX idx_document_campaign_sync_document ON document_campaign_sync_log(document_id)');
    ensureIndexExists($pdo, 'document_campaign_sync_log', 'idx_document_campaign_sync_campaign', 'CREATE INDEX idx_document_campaign_sync_campaign ON document_campaign_sync_log(campaign_id)');
    ensureIndexExists($pdo, 'document_campaign_sync_log', 'idx_document_campaign_sync_action', 'CREATE INDEX idx_document_campaign_sync_action ON document_campaign_sync_log(action)');
    ensureIndexExists($pdo, 'document_campaign_sync_log', 'idx_document_campaign_sync_created', 'CREATE INDEX idx_document_campaign_sync_created ON document_campaign_sync_log(created_at)');
}

function ensureDocumentAcceptanceTables(PDO $pdo): void {
    ensureSalesDocumentsTable($pdo);

    try {
        if (isSqliteDriver($pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_acceptance_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_id INTEGER NOT NULL,
                token_hash TEXT NOT NULL,
                recipient_email TEXT NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                revoked_at DATETIME NULL,
                created_by INTEGER NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (token_hash)
            )");
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_acceptance_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_id INTEGER NOT NULL,
                token_id INTEGER NULL,
                action TEXT NOT NULL,
                recipient_email TEXT NULL,
                ip_address TEXT NULL,
                user_agent TEXT NULL,
                note TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_acceptance_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                token_hash CHAR(64) NOT NULL,
                recipient_email VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                revoked_at DATETIME NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_document_acceptance_token_hash (token_hash),
                KEY idx_document_acceptance_tokens_document (document_id),
                KEY idx_document_acceptance_tokens_active (document_id, used_at, revoked_at, expires_at),
                KEY idx_document_acceptance_tokens_email (recipient_email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_acceptance_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                token_id INT NULL,
                action VARCHAR(30) NOT NULL,
                recipient_email VARCHAR(255) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                note TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_document_acceptance_log_document (document_id),
                KEY idx_document_acceptance_log_token (token_id),
                KEY idx_document_acceptance_log_action (action),
                KEY idx_document_acceptance_log_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot create document acceptance tables: ' . $e->getMessage());
    }

    ensureTableColumns($pdo, 'document_acceptance_tokens', [
        'document_id' => "ALTER TABLE document_acceptance_tokens ADD COLUMN document_id INT NOT NULL",
        'token_hash' => "ALTER TABLE document_acceptance_tokens ADD COLUMN token_hash CHAR(64) NOT NULL",
        'recipient_email' => "ALTER TABLE document_acceptance_tokens ADD COLUMN recipient_email VARCHAR(255) NOT NULL",
        'expires_at' => "ALTER TABLE document_acceptance_tokens ADD COLUMN expires_at DATETIME NOT NULL",
        'used_at' => "ALTER TABLE document_acceptance_tokens ADD COLUMN used_at DATETIME NULL",
        'revoked_at' => "ALTER TABLE document_acceptance_tokens ADD COLUMN revoked_at DATETIME NULL",
        'created_by' => "ALTER TABLE document_acceptance_tokens ADD COLUMN created_by INT NULL",
        'created_at' => "ALTER TABLE document_acceptance_tokens ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ]);
    ensureIndexExists($pdo, 'document_acceptance_tokens', 'uq_document_acceptance_token_hash', 'CREATE UNIQUE INDEX uq_document_acceptance_token_hash ON document_acceptance_tokens(token_hash)');
    ensureIndexExists($pdo, 'document_acceptance_tokens', 'idx_document_acceptance_tokens_document', 'CREATE INDEX idx_document_acceptance_tokens_document ON document_acceptance_tokens(document_id)');
    ensureIndexExists($pdo, 'document_acceptance_tokens', 'idx_document_acceptance_tokens_active', 'CREATE INDEX idx_document_acceptance_tokens_active ON document_acceptance_tokens(document_id, used_at, revoked_at, expires_at)');
    ensureIndexExists($pdo, 'document_acceptance_tokens', 'idx_document_acceptance_tokens_email', 'CREATE INDEX idx_document_acceptance_tokens_email ON document_acceptance_tokens(recipient_email)');

    ensureTableColumns($pdo, 'document_acceptance_log', [
        'document_id' => "ALTER TABLE document_acceptance_log ADD COLUMN document_id INT NOT NULL",
        'token_id' => "ALTER TABLE document_acceptance_log ADD COLUMN token_id INT NULL",
        'action' => "ALTER TABLE document_acceptance_log ADD COLUMN action VARCHAR(30) NOT NULL",
        'recipient_email' => "ALTER TABLE document_acceptance_log ADD COLUMN recipient_email VARCHAR(255) NULL",
        'ip_address' => "ALTER TABLE document_acceptance_log ADD COLUMN ip_address VARCHAR(45) NULL",
        'user_agent' => "ALTER TABLE document_acceptance_log ADD COLUMN user_agent VARCHAR(255) NULL",
        'note' => "ALTER TABLE document_acceptance_log ADD COLUMN note TEXT NULL",
        'created_at' => "ALTER TABLE document_acceptance_log ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ]);
    ensureIndexExists($pdo, 'document_acceptance_log', 'idx_document_acceptance_log_document', 'CREATE INDEX idx_document_acceptance_log_document ON document_acceptance_log(document_id)');
    ensureIndexExists($pdo, 'document_acceptance_log', 'idx_document_acceptance_log_token', 'CREATE INDEX idx_document_acceptance_log_token ON document_acceptance_log(token_id)');
    ensureIndexExists($pdo, 'document_acceptance_log', 'idx_document_acceptance_log_action', 'CREATE INDEX idx_document_acceptance_log_action ON document_acceptance_log(action)');
    ensureIndexExists($pdo, 'document_acceptance_log', 'idx_document_acceptance_log_created', 'CREATE INDEX idx_document_acceptance_log_created ON document_acceptance_log(created_at)');
}

function ensureDocumentAuditLogTable(PDO $pdo): void {
    ensureSalesDocumentsTable($pdo);

    try {
        if (isSqliteDriver($pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_id INTEGER NOT NULL,
                user_id INTEGER NULL,
                event_type TEXT NOT NULL,
                event_label TEXT NOT NULL,
                old_value TEXT NULL,
                new_value TEXT NULL,
                metadata_json TEXT NULL,
                ip_address TEXT NULL,
                user_agent TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_audit_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                user_id INT NULL,
                event_type VARCHAR(80) NOT NULL,
                event_label VARCHAR(255) NOT NULL,
                old_value TEXT NULL,
                new_value TEXT NULL,
                metadata_json JSON NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_document_audit_document (document_id),
                KEY idx_document_audit_user (user_id),
                KEY idx_document_audit_type (event_type),
                KEY idx_document_audit_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot create document_audit_log: ' . $e->getMessage());
    }

    ensureTableColumns($pdo, 'document_audit_log', [
        'document_id' => "ALTER TABLE document_audit_log ADD COLUMN document_id INT NOT NULL",
        'user_id' => "ALTER TABLE document_audit_log ADD COLUMN user_id INT NULL",
        'event_type' => "ALTER TABLE document_audit_log ADD COLUMN event_type VARCHAR(80) NOT NULL",
        'event_label' => "ALTER TABLE document_audit_log ADD COLUMN event_label VARCHAR(255) NOT NULL",
        'old_value' => "ALTER TABLE document_audit_log ADD COLUMN old_value TEXT NULL",
        'new_value' => "ALTER TABLE document_audit_log ADD COLUMN new_value TEXT NULL",
        'metadata_json' => "ALTER TABLE document_audit_log ADD COLUMN metadata_json JSON NULL",
        'ip_address' => "ALTER TABLE document_audit_log ADD COLUMN ip_address VARCHAR(45) NULL",
        'user_agent' => "ALTER TABLE document_audit_log ADD COLUMN user_agent VARCHAR(255) NULL",
        'created_at' => "ALTER TABLE document_audit_log ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ]);
    ensureIndexExists($pdo, 'document_audit_log', 'idx_document_audit_document', 'CREATE INDEX idx_document_audit_document ON document_audit_log(document_id)');
    ensureIndexExists($pdo, 'document_audit_log', 'idx_document_audit_user', 'CREATE INDEX idx_document_audit_user ON document_audit_log(user_id)');
    ensureIndexExists($pdo, 'document_audit_log', 'idx_document_audit_type', 'CREATE INDEX idx_document_audit_type ON document_audit_log(event_type)');
    ensureIndexExists($pdo, 'document_audit_log', 'idx_document_audit_created', 'CREATE INDEX idx_document_audit_created ON document_audit_log(created_at)');
}

function ensureDocumentPdfVersionsTable(PDO $pdo): void {
    ensureSalesDocumentsTable($pdo);

    try {
        if (isSqliteDriver($pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_pdf_versions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_id INTEGER NOT NULL,
                version_number INTEGER NOT NULL,
                pdf_path TEXT NOT NULL,
                file_name TEXT NOT NULL,
                file_size INTEGER NULL,
                checksum_sha256 TEXT NULL,
                generated_by INTEGER NULL,
                generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                is_current INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (document_id, version_number)
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_pdf_versions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                version_number INT NOT NULL,
                pdf_path VARCHAR(255) NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_size BIGINT NULL,
                checksum_sha256 CHAR(64) NULL,
                generated_by INT NULL,
                generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                is_current TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_document_pdf_versions_number (document_id, version_number),
                KEY idx_document_pdf_versions_document (document_id),
                KEY idx_document_pdf_versions_current (document_id, is_current),
                KEY idx_document_pdf_versions_generated_by (generated_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot create document_pdf_versions: ' . $e->getMessage());
    }

    ensureTableColumns($pdo, 'document_pdf_versions', [
        'document_id' => "ALTER TABLE document_pdf_versions ADD COLUMN document_id INT NOT NULL",
        'version_number' => "ALTER TABLE document_pdf_versions ADD COLUMN version_number INT NOT NULL",
        'pdf_path' => "ALTER TABLE document_pdf_versions ADD COLUMN pdf_path VARCHAR(255) NOT NULL",
        'file_name' => "ALTER TABLE document_pdf_versions ADD COLUMN file_name VARCHAR(255) NOT NULL",
        'file_size' => "ALTER TABLE document_pdf_versions ADD COLUMN file_size BIGINT NULL",
        'checksum_sha256' => "ALTER TABLE document_pdf_versions ADD COLUMN checksum_sha256 CHAR(64) NULL",
        'generated_by' => "ALTER TABLE document_pdf_versions ADD COLUMN generated_by INT NULL",
        'generated_at' => "ALTER TABLE document_pdf_versions ADD COLUMN generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'is_current' => "ALTER TABLE document_pdf_versions ADD COLUMN is_current TINYINT(1) NOT NULL DEFAULT 0",
        'created_at' => "ALTER TABLE document_pdf_versions ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ]);
    ensureIndexExists($pdo, 'document_pdf_versions', 'uq_document_pdf_versions_number', 'CREATE UNIQUE INDEX uq_document_pdf_versions_number ON document_pdf_versions(document_id, version_number)');
    ensureIndexExists($pdo, 'document_pdf_versions', 'idx_document_pdf_versions_document', 'CREATE INDEX idx_document_pdf_versions_document ON document_pdf_versions(document_id)');
    ensureIndexExists($pdo, 'document_pdf_versions', 'idx_document_pdf_versions_current', 'CREATE INDEX idx_document_pdf_versions_current ON document_pdf_versions(document_id, is_current)');
    ensureIndexExists($pdo, 'document_pdf_versions', 'idx_document_pdf_versions_generated_by', 'CREATE INDEX idx_document_pdf_versions_generated_by ON document_pdf_versions(generated_by)');

    try {
        $stmt = $pdo->query("SELECT id, pdf_path FROM documents WHERE pdf_path IS NOT NULL AND TRIM(pdf_path) <> ''");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $publicDir = dirname(__DIR__);
        foreach ($rows as $row) {
            $documentId = (int)($row['id'] ?? 0);
            $pdfPath = trim((string)($row['pdf_path'] ?? ''));
            if ($documentId <= 0 || $pdfPath === '') {
                continue;
            }
            $existsStmt = $pdo->prepare('SELECT id FROM document_pdf_versions WHERE document_id = :document_id LIMIT 1');
            $existsStmt->execute([':document_id' => $documentId]);
            if ($existsStmt->fetchColumn()) {
                continue;
            }
            $fullPath = realpath($publicDir . '/' . ltrim($pdfPath, '/\\'));
            $uploadsDir = realpath($publicDir . '/uploads/documents');
            if (!$fullPath || !$uploadsDir || strpos($fullPath, $uploadsDir) !== 0 || !is_file($fullPath)) {
                continue;
            }
            $insert = $pdo->prepare("INSERT INTO document_pdf_versions
                (document_id, version_number, pdf_path, file_name, file_size, checksum_sha256, generated_by, generated_at, is_current, created_at)
                VALUES (:document_id, 1, :pdf_path, :file_name, :file_size, :checksum_sha256, NULL, CURRENT_TIMESTAMP, 1, CURRENT_TIMESTAMP)");
            $insert->execute([
                ':document_id' => $documentId,
                ':pdf_path' => $pdfPath,
                ':file_name' => basename($fullPath),
                ':file_size' => filesize($fullPath) ?: null,
                ':checksum_sha256' => hash_file('sha256', $fullPath) ?: null,
            ]);
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot backfill document_pdf_versions: ' . $e->getMessage());
    }
}

function ensureEmailTemplatesTable(PDO $pdo): void {
    try {
        if (isSqliteDriver($pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS email_templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                template_key TEXT NOT NULL,
                name TEXT NOT NULL,
                subject_template TEXT NOT NULL,
                body_template TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_by INTEGER NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (template_key)
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS email_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                template_key VARCHAR(80) NOT NULL,
                name VARCHAR(160) NOT NULL,
                subject_template VARCHAR(255) NOT NULL,
                body_template TEXT NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_email_templates_key (template_key),
                KEY idx_email_templates_active (is_active),
                KEY idx_email_templates_created_by (created_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot create email_templates: ' . $e->getMessage());
    }

    ensureTableColumns($pdo, 'email_templates', [
        'template_key' => "ALTER TABLE email_templates ADD COLUMN template_key VARCHAR(80) NOT NULL",
        'name' => "ALTER TABLE email_templates ADD COLUMN name VARCHAR(160) NOT NULL",
        'subject_template' => "ALTER TABLE email_templates ADD COLUMN subject_template VARCHAR(255) NOT NULL",
        'body_template' => "ALTER TABLE email_templates ADD COLUMN body_template TEXT NOT NULL",
        'is_active' => "ALTER TABLE email_templates ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1",
        'created_by' => "ALTER TABLE email_templates ADD COLUMN created_by INT NULL",
        'created_at' => "ALTER TABLE email_templates ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE email_templates ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ]);
    ensureIndexExists($pdo, 'email_templates', 'uq_email_templates_key', 'CREATE UNIQUE INDEX uq_email_templates_key ON email_templates(template_key)');
    ensureIndexExists($pdo, 'email_templates', 'idx_email_templates_active', 'CREATE INDEX idx_email_templates_active ON email_templates(is_active)');
    ensureIndexExists($pdo, 'email_templates', 'idx_email_templates_created_by', 'CREATE INDEX idx_email_templates_created_by ON email_templates(created_by)');

    try {
        $defaults = [
            [
                'document_order_send',
                'Wysyłka zlecenia do klienta',
                'Zlecenie emisji reklamy {DOCUMENT_NUMBER}',
                "Dzień dobry,\n\nw załączeniu przesyłamy dokument {DOCUMENT_NUMBER} dotyczący emisji reklamy.\nProsimy o zapoznanie się z dokumentem i potwierdzenie akceptacji.\n\nLink do pobrania i akceptacji dokumentu:\n{ACCEPTANCE_LINK}\n\nPozdrawiamy,\n{COMPANY_NAME}",
            ],
            [
                'document_annex_send',
                'Wysyłka aneksu do klienta',
                'Aneks do zlecenia {DOCUMENT_NUMBER}',
                "Dzień dobry,\n\nw załączeniu przesyłamy aneks {DOCUMENT_NUMBER} dotyczący emisji reklamy.\nProsimy o zapoznanie się z dokumentem i potwierdzenie akceptacji.\n\nLink do pobrania i akceptacji dokumentu:\n{ACCEPTANCE_LINK}\n\nPozdrawiamy,\n{COMPANY_NAME}",
            ],
            [
                'document_acceptance_reminder',
                'Przypomnienie o akceptacji dokumentu',
                'Przypomnienie o akceptacji dokumentu {DOCUMENT_NUMBER}',
                "Dzień dobry,\n\nprzypominamy o dokumencie {DOCUMENT_NUMBER} oczekującym na akceptację.\n\nLink do pobrania i akceptacji dokumentu:\n{ACCEPTANCE_LINK}\n\nPozdrawiamy,\n{COMPANY_NAME}",
            ],
        ];
        $stmt = $pdo->prepare("INSERT INTO email_templates
            (template_key, name, subject_template, body_template, is_active, created_at, updated_at)
            SELECT :template_key, :name, :subject_template, :body_template, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            WHERE NOT EXISTS (SELECT 1 FROM email_templates WHERE template_key = :template_key_check)");
        foreach ($defaults as [$key, $name, $subject, $body]) {
            $stmt->execute([
                ':template_key' => $key,
                ':name' => $name,
                ':subject_template' => $subject,
                ':body_template' => $body,
                ':template_key_check' => $key,
            ]);
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot seed email_templates: ' . $e->getMessage());
    }
}

function ensureDocumentPdfTemplatesTable(PDO $pdo): void {
    try {
        if (isSqliteDriver($pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_pdf_templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_type TEXT NOT NULL,
                name TEXT NOT NULL,
                version TEXT NOT NULL,
                html_template TEXT NOT NULL,
                css_template TEXT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_by INTEGER NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_pdf_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_type VARCHAR(30) NOT NULL,
                name VARCHAR(160) NOT NULL,
                version VARCHAR(30) NOT NULL,
                html_template MEDIUMTEXT NOT NULL,
                css_template MEDIUMTEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_document_pdf_templates_type_active (document_type, is_active),
                KEY idx_document_pdf_templates_created_by (created_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot create document_pdf_templates: ' . $e->getMessage());
    }

    ensureTableColumns($pdo, 'document_pdf_templates', [
        'document_type' => "ALTER TABLE document_pdf_templates ADD COLUMN document_type VARCHAR(30) NOT NULL",
        'name' => "ALTER TABLE document_pdf_templates ADD COLUMN name VARCHAR(160) NOT NULL",
        'version' => "ALTER TABLE document_pdf_templates ADD COLUMN version VARCHAR(30) NOT NULL",
        'html_template' => "ALTER TABLE document_pdf_templates ADD COLUMN html_template MEDIUMTEXT NOT NULL",
        'css_template' => "ALTER TABLE document_pdf_templates ADD COLUMN css_template MEDIUMTEXT NULL",
        'is_active' => "ALTER TABLE document_pdf_templates ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1",
        'created_by' => "ALTER TABLE document_pdf_templates ADD COLUMN created_by INT NULL",
        'created_at' => "ALTER TABLE document_pdf_templates ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE document_pdf_templates ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ]);
    ensureIndexExists($pdo, 'document_pdf_templates', 'idx_document_pdf_templates_type_active', 'CREATE INDEX idx_document_pdf_templates_type_active ON document_pdf_templates(document_type, is_active)');
    ensureIndexExists($pdo, 'document_pdf_templates', 'idx_document_pdf_templates_created_by', 'CREATE INDEX idx_document_pdf_templates_created_by ON document_pdf_templates(created_by)');

    try {
        $css = "@page { margin: 28px 32px 44px; }\n"
            . "body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }\n"
            . ".footer { position: fixed; left: 0; right: 0; bottom: -28px; font-size: 9px; color: #64748b; border-top: 1px solid #dbe3ef; padding-top: 7px; }\n"
            . ".header { border-bottom: 3px solid #0b2b5c; padding-bottom: 16px; margin-bottom: 18px; }\n"
            . ".header-table, .grid, .kv, .values, .signatures { width: 100%; border-collapse: collapse; }\n"
            . ".owner-name { font-size: 18px; font-weight: 700; color: #0b2b5c; margin-bottom: 4px; }\n"
            . ".doc-title { font-size: 20px; font-weight: 700; color: #0b2b5c; margin: 0 0 5px; text-align: right; }\n"
            . ".doc-meta { text-align: right; line-height: 1.6; }\n"
            . ".section { margin-top: 16px; }\n"
            . ".section-title { background: #0b2b5c; color: #fff; padding: 7px 10px; font-weight: 700; font-size: 12px; }\n"
            . ".box { border: 1px solid #d7dfec; padding: 10px; }\n"
            . ".grid td { vertical-align: top; width: 50%; }\n"
            . ".kv th { width: 35%; text-align: left; background: #f2f6fb; color: #0b2b5c; }\n"
            . ".kv th, .kv td, .values th, .values td { border: 1px solid #d7dfec; padding: 8px; vertical-align: top; }\n"
            . ".values th { background: #f2f6fb; color: #0b2b5c; text-align: left; }\n"
            . ".num { text-align: right; }\n"
            . ".highlight, .note { border: 2px solid #0b2b5c; background: #eef5ff; padding: 9px; font-weight: 700; margin: 8px 0; }\n"
            . ".owz li { margin-bottom: 5px; }\n"
            . ".signatures { margin-top: 34px; }\n"
            . ".signatures td { width: 50%; text-align: center; padding-top: 36px; }\n"
            . ".line { border-top: 1px solid #1f2937; padding-top: 7px; width: 80%; margin: 0 auto; }";
        $orderHtml = '<div class="footer">Dokument {DOCUMENT_NUMBER}</div><div class="header"><table class="header-table"><tr><td style="width:52%;"><div class="owner-name">{COMPANY_NAME}</div><div>{COMPANY_ADDRESS}</div><div>NIP: {COMPANY_NIP}</div><div>Email: {COMPANY_EMAIL}</div><div>Telefon: {COMPANY_PHONE}</div></td><td style="width:48%;"><div class="doc-title">Zlecenie emisji reklamy</div><div class="doc-meta"><strong>Numer:</strong> {DOCUMENT_NUMBER}<br><strong>Data wystawienia:</strong> {ISSUE_DATE}</div></td></tr></table></div><table class="grid"><tr><td style="padding-right:8px;"><div class="section-title">Dane klienta</div><div class="box"><strong>{CLIENT_NAME}</strong><br>{CLIENT_ADDRESS}<br>NIP: {CLIENT_NIP}<br>Email: {CLIENT_EMAIL}</div></td><td style="padding-left:8px;"><div class="section-title">Parametry emisji</div><div class="box">Okres emisji: <strong>{VALID_FROM} - {VALID_TO}</strong><br>{ORDER_DETAILS}</div></td></tr></table><div class="section"><div class="section-title">Treść zlecenia</div><table class="kv"><tr><th>Tytuł</th><td>{DOCUMENT_TITLE}</td></tr></table></div><div class="section"><div class="section-title">Wartości</div><table class="values"><tr><th>Netto</th><th>Stawka VAT</th><th>VAT</th><th>Brutto</th></tr><tr><td class="num">{NET_VALUE}</td><td class="num">{VAT_RATE}</td><td class="num">{VAT_VALUE}</td><td class="num"><strong>{GROSS_VALUE}</strong></td></tr></table></div><div class="section"><div class="section-title">Ogólne warunki zamówienia</div><div class="owz">{TERMS_HTML}</div></div><div class="section"><div class="section-title">Warunek zależny od źródła spotu</div><div class="highlight">{DYNAMIC_TERMS_HTML}</div></div>{SIGNATURES_HTML}';
        $annexHtml = '<div class="footer">Aneks {DOCUMENT_NUMBER}</div><div class="header"><table class="header-table"><tr><td style="width:52%;"><div class="owner-name">{COMPANY_NAME}</div><div>{COMPANY_ADDRESS}</div><div>NIP: {COMPANY_NIP}</div><div>Email: {COMPANY_EMAIL}</div><div>Telefon: {COMPANY_PHONE}</div></td><td style="width:48%;"><div class="doc-title">Aneks do zlecenia emisji reklamy</div><div class="doc-meta"><strong>Numer aneksu:</strong> {DOCUMENT_NUMBER}<br><strong>Data wystawienia:</strong> {ISSUE_DATE}</div></td></tr></table></div><table class="grid"><tr><td style="padding-right:8px;"><div class="section-title">Dane klienta</div><div class="box"><strong>{CLIENT_NAME}</strong><br>{CLIENT_ADDRESS}<br>NIP: {CLIENT_NIP}<br>Email: {CLIENT_EMAIL}</div></td><td style="padding-left:8px;"><div class="section-title">Aneks</div><div class="box">Tytuł: <strong>{DOCUMENT_TITLE}</strong><br>Waluta: <strong>{CURRENCY}</strong></div></td></tr></table><div class="section"><div class="section-title">Opis zmiany</div><div class="box">{ANNEX_DETAILS}</div></div><div class="section"><div class="note">Pozostałe warunki zlecenia bazowego pozostają bez zmian. W zakresie sprzeczności pierwszeństwo ma treść niniejszego aneksu.</div></div><div class="section"><div class="section-title">Ogólne warunki zamówienia</div><div class="owz">{TERMS_HTML}</div></div>{SIGNATURES_HTML}';
        $defaults = [
            ['order', 'Zlecenie emisji reklamy', '1.0', $orderHtml, $css],
            ['annex', 'Aneks do zlecenia', '1.0', $annexHtml, $css],
        ];
        $stmt = $pdo->prepare("INSERT INTO document_pdf_templates
            (document_type, name, version, html_template, css_template, is_active, created_at, updated_at)
            SELECT :document_type, :name, :version, :html_template, :css_template, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            WHERE NOT EXISTS (SELECT 1 FROM document_pdf_templates WHERE document_type = :document_type_check)");
        foreach ($defaults as [$type, $name, $version, $html, $defaultCss]) {
            $stmt->execute([
                ':document_type' => $type,
                ':name' => $name,
                ':version' => $version,
                ':html_template' => $html,
                ':css_template' => $defaultCss,
                ':document_type_check' => $type,
            ]);
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot seed document_pdf_templates: ' . $e->getMessage());
    }
}

function defaultOrderTermsHtml(): string {
    return '<ol>'
        . '<li>Zamawiający odpowiada za prawdziwość i zgodność z prawem treści reklamy.</li>'
        . '<li>Zamawiający oświadcza, że posiada prawa do przekazanych materiałów, znaków, muzyki, lektorów i innych elementów.</li>'
        . '<li>W przypadku materiału dostarczonego przez Zamawiającego Wykonawca nie odpowiada za jego jakość techniczną ani treść.</li>'
        . '<li>W przypadku produkcji spotu przez Wykonawcę Zamawiający ma prawo do jednej tury poprawek, o ile strony nie ustaliły inaczej.</li>'
        . '<li>Brak akceptacji lub uwag do materiału w terminie 24 godzin od przesłania oznacza akceptację materiału do emisji.</li>'
        . '<li>Niedostarczenie materiału w terminie nie zwalnia Zamawiającego z obowiązku zapłaty, jeżeli Wykonawca pozostawał gotowy do realizacji emisji.</li>'
        . '<li>Godziny emisji mogą ulec niewielkim przesunięciom wynikającym z układu programu, bloków reklamowych lub przyczyn technicznych.</li>'
        . '<li>W przypadku awarii technicznej Wykonawca może zrealizować emisje zastępcze w innym terminie.</li>'
        . '<li>Reklamacje należy zgłaszać pisemnie lub mailowo w terminie 7 dni od zakończenia kampanii.</li>'
        . '<li>Akceptacja zlecenia może nastąpić podpisem, mailowo albo przez inną udokumentowaną formę potwierdzenia.</li>'
        . '</ol>';
}

function defaultAnnexTermsHtml(): string {
    return '<ol>'
        . '<li>Aneks zmienia wyłącznie zakres wskazany w jego treści.</li>'
        . '<li>Pozostałe postanowienia zlecenia bazowego pozostają bez zmian.</li>'
        . '<li>W przypadku sprzeczności między zleceniem bazowym a aneksem pierwszeństwo ma treść aneksu.</li>'
        . '<li>Akceptacja aneksu może nastąpić podpisem, mailowo lub przez inną udokumentowaną formę potwierdzenia.</li>'
        . '</ol>';
}

function ensureDocumentTermsTables(PDO $pdo): void {
    try {
        if (isSqliteDriver($pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_terms_templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_type TEXT NOT NULL,
                version TEXT NOT NULL,
                title TEXT NOT NULL,
                content_html TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 0,
                valid_from DATE NULL,
                valid_to DATE NULL,
                created_by INTEGER NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_terms_acceptance (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_id INTEGER NOT NULL,
                terms_template_id INTEGER NULL,
                terms_version TEXT NOT NULL,
                terms_content_snapshot TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (document_id)
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_terms_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_type VARCHAR(30) NOT NULL,
                version VARCHAR(30) NOT NULL,
                title VARCHAR(255) NOT NULL,
                content_html MEDIUMTEXT NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 0,
                valid_from DATE NULL,
                valid_to DATE NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_document_terms_type_active (document_type, is_active),
                KEY idx_document_terms_validity (valid_from, valid_to)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_terms_acceptance (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                terms_template_id INT NULL,
                terms_version VARCHAR(30) NOT NULL,
                terms_content_snapshot MEDIUMTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_document_terms_document (document_id),
                KEY idx_document_terms_template (terms_template_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Throwable $e) {
        error_log('db_schema: cannot create document terms tables: ' . $e->getMessage());
    }

    ensureTableColumns($pdo, 'document_terms_templates', [
        'document_type' => "ALTER TABLE document_terms_templates ADD COLUMN document_type VARCHAR(30) NOT NULL",
        'version' => "ALTER TABLE document_terms_templates ADD COLUMN version VARCHAR(30) NOT NULL",
        'title' => "ALTER TABLE document_terms_templates ADD COLUMN title VARCHAR(255) NOT NULL",
        'content_html' => "ALTER TABLE document_terms_templates ADD COLUMN content_html MEDIUMTEXT NOT NULL",
        'is_active' => "ALTER TABLE document_terms_templates ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 0",
        'valid_from' => "ALTER TABLE document_terms_templates ADD COLUMN valid_from DATE NULL",
        'valid_to' => "ALTER TABLE document_terms_templates ADD COLUMN valid_to DATE NULL",
        'created_by' => "ALTER TABLE document_terms_templates ADD COLUMN created_by INT NULL",
        'created_at' => "ALTER TABLE document_terms_templates ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "ALTER TABLE document_terms_templates ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ]);
    ensureTableColumns($pdo, 'document_terms_acceptance', [
        'document_id' => "ALTER TABLE document_terms_acceptance ADD COLUMN document_id INT NOT NULL",
        'terms_template_id' => "ALTER TABLE document_terms_acceptance ADD COLUMN terms_template_id INT NULL",
        'terms_version' => "ALTER TABLE document_terms_acceptance ADD COLUMN terms_version VARCHAR(30) NOT NULL",
        'terms_content_snapshot' => "ALTER TABLE document_terms_acceptance ADD COLUMN terms_content_snapshot MEDIUMTEXT NOT NULL",
        'created_at' => "ALTER TABLE document_terms_acceptance ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ]);
    ensureIndexExists($pdo, 'document_terms_templates', 'idx_document_terms_type_active', 'CREATE INDEX idx_document_terms_type_active ON document_terms_templates(document_type, is_active)');
    ensureIndexExists($pdo, 'document_terms_templates', 'idx_document_terms_validity', 'CREATE INDEX idx_document_terms_validity ON document_terms_templates(valid_from, valid_to)');
    ensureIndexExists($pdo, 'document_terms_acceptance', 'uq_document_terms_document', 'CREATE UNIQUE INDEX uq_document_terms_document ON document_terms_acceptance(document_id)');
    ensureIndexExists($pdo, 'document_terms_acceptance', 'idx_document_terms_template', 'CREATE INDEX idx_document_terms_template ON document_terms_acceptance(terms_template_id)');

    try {
        $stmt = $pdo->prepare("INSERT INTO document_terms_templates
            (document_type, version, title, content_html, is_active, valid_from)
            SELECT 'order', '1.0', 'Ogólne warunki zamówienia - zlecenie', :content_html, 1, CURDATE()
            WHERE NOT EXISTS (
                SELECT 1 FROM document_terms_templates WHERE document_type = 'order'
            )");
        $stmt->execute([':content_html' => defaultOrderTermsHtml()]);

        $stmt = $pdo->prepare("INSERT INTO document_terms_templates
            (document_type, version, title, content_html, is_active, valid_from)
            SELECT 'annex', '1.0', 'Ogólne warunki zamówienia - aneks', :content_html, 1, CURDATE()
            WHERE NOT EXISTS (
                SELECT 1 FROM document_terms_templates WHERE document_type = 'annex'
            )");
        $stmt->execute([':content_html' => defaultAnnexTermsHtml()]);
    } catch (Throwable $e) {
        error_log('db_schema: cannot seed document_terms_templates: ' . $e->getMessage());
    }
}

function ensureNotificationsTable(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS powiadomienia (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            typ VARCHAR(30) NOT NULL,
            tresc TEXT NOT NULL,
            link VARCHAR(255) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_powiadomienia_user (user_id),
            INDEX idx_powiadomienia_type (typ)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create powiadomienia: ' . $e->getMessage());
    }
}

function ensureDocumentsTables(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS dokumenty (
            id INT AUTO_INCREMENT PRIMARY KEY,
            doc_type VARCHAR(30) NOT NULL,
            doc_number VARCHAR(50) NOT NULL UNIQUE,
            client_id INT NOT NULL,
            kampania_id INT NULL,
            created_by_user_id INT NOT NULL,
            stored_filename VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            sha256 CHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_dokumenty_client (client_id),
            INDEX idx_dokumenty_kampania (kampania_id),
            INDEX idx_dokumenty_user (created_by_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create dokumenty: ' . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS numeracja_dokumentow (
            year INT NOT NULL PRIMARY KEY,
            last_number INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('db_schema: cannot create numeracja_dokumentow: ' . $e->getMessage());
    }
}
