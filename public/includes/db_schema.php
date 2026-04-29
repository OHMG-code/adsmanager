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
            sha256 CHAR(64) NULL,
            production_status VARCHAR(30) NOT NULL DEFAULT 'Do akceptacji',
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
