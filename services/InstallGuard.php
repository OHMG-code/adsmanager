<?php

declare(strict_types=1);

final class InstallGuard
{
    public const STATE_NOT_INSTALLED = 'not_installed';
    public const STATE_INSTALLED = 'installed';

    public const STATUS_NOT_INSTALLED = 'not_installed';
    public const STATUS_INSTALLED = 'installed';
    public const STATUS_DB_ERROR = 'db_error';

    public const REQUEST_CLI = 'cli';
    public const REQUEST_WEB_HTML = 'web_html';
    public const REQUEST_WEB_PROGRAMMATIC = 'web_programmatic';

    /**
     * @param array{host?:mixed,port?:mixed,name?:mixed,user?:mixed,pass?:mixed,charset?:mixed} $config
     * @param array<string,mixed> $server
     * @return array<string,mixed>
     */
    public static function inspect(array $config, array $server = []): array
    {
        $normalized = self::normalizeConfig($config);
        $requestKind = self::detectRequestKind($server);
        $scriptName = self::resolveScriptName($server);
        $requestUri = self::resolveRequestUri($server);

        $result = [
            'status' => self::STATUS_NOT_INSTALLED,
            'install_state' => self::STATE_NOT_INSTALLED,
            'reason' => 'missing_db_config',
            'request_kind' => $requestKind,
            'script_name' => $scriptName,
            'request_uri' => $requestUri,
            'config_complete' => self::hasRequiredConfig($normalized),
            'db_connectable' => false,
            'db_exists' => null,
            'installer_entry' => self::isInstallerEntry($server),
            'message' => 'Aplikacja nie jest jeszcze skonfigurowana.',
        ];

        if (!$result['config_complete']) {
            return $result;
        }

        try {
            $pdo = self::connect($normalized);
            $result['db_connectable'] = true;
            $result['db_exists'] = true;
        } catch (PDOException $e) {
            $serverProbe = self::probeServerForDatabase($normalized);
            if (self::isUnknownDatabaseError($e)) {
                $result['reason'] = 'database_missing';
                $result['db_exists'] = false;
                $result['message'] = 'Docelowa baza danych nie istnieje jeszcze lub nie jest dostępna.';
                return $result;
            }
            if ($serverProbe === false) {
                $result['reason'] = 'database_missing';
                $result['db_exists'] = false;
                $result['message'] = 'Docelowa baza danych nie istnieje jeszcze lub nie jest dostępna.';
                return $result;
            }

            $result['status'] = self::STATUS_DB_ERROR;
            $result['reason'] = 'db_connect_error';
            $result['db_exists'] = $serverProbe;
            $result['message'] = 'Nie udało się połączyć z bazą danych: ' . $e->getMessage();
            return $result;
        }

        $appMetaState = self::inspectAppMeta($pdo);
        if ($appMetaState['status'] === self::STATUS_DB_ERROR) {
            $appMetaState['request_kind'] = $requestKind;
            $appMetaState['script_name'] = $scriptName;
            $appMetaState['request_uri'] = $requestUri;
            $appMetaState['config_complete'] = true;
            $appMetaState['db_connectable'] = true;
            $appMetaState['db_exists'] = true;
            $appMetaState['installer_entry'] = self::isInstallerEntry($server);
            return $appMetaState;
        }

        if ($appMetaState['status'] === self::STATUS_INSTALLED) {
            return array_merge($result, $appMetaState, [
                'request_kind' => $requestKind,
                'script_name' => $scriptName,
                'request_uri' => $requestUri,
                'config_complete' => true,
                'db_connectable' => true,
                'db_exists' => true,
                'installer_entry' => self::isInstallerEntry($server),
            ]);
        }

        $legacyState = self::inspectLegacyInstall($pdo);
        return array_merge($result, $legacyState, [
            'request_kind' => $requestKind,
            'script_name' => $scriptName,
            'request_uri' => $requestUri,
            'config_complete' => true,
            'db_connectable' => true,
            'db_exists' => true,
            'installer_entry' => self::isInstallerEntry($server),
        ]);
    }

    /**
     * @param array<string,mixed> $server
     */
    public static function detectRequestKind(array $server = []): string
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return self::REQUEST_CLI;
        }

        $scriptName = self::resolveScriptName($server);
        $requestUri = self::resolveRequestUri($server);
        $accept = strtolower(trim((string)($server['HTTP_ACCEPT'] ?? $_SERVER['HTTP_ACCEPT'] ?? '')));

        $programmaticNeedles = ['/api/', '/handlers/', '/cron/'];
        foreach ($programmaticNeedles as $needle) {
            if (($scriptName !== '' && strpos($scriptName, $needle) !== false)
                || ($requestUri !== '' && strpos($requestUri, $needle) !== false)
            ) {
                return self::REQUEST_WEB_PROGRAMMATIC;
            }
        }

        if ($accept !== '' && (strpos($accept, 'application/json') !== false || strpos($accept, 'text/plain') !== false)) {
            return self::REQUEST_WEB_PROGRAMMATIC;
        }

        return self::REQUEST_WEB_HTML;
    }

    /**
     * @param array<string,mixed> $server
     */
    public static function isInstallerEntry(array $server = []): bool
    {
        $scriptName = basename(self::resolveScriptName($server));
        if ($scriptName === 'install.php') {
            return true;
        }

        $requestUri = self::resolveRequestUri($server);
        if ($requestUri === '') {
            return false;
        }

        $path = parse_url($requestUri, PHP_URL_PATH);
        return basename((string)$path) === 'install.php';
    }

    /**
     * @param array<string,mixed> $info
     */
    public static function installerUrl(string $baseUrl, array $info = []): string
    {
        $url = rtrim($baseUrl, '/') . '/install.php';
        $reason = trim((string)($info['reason'] ?? ''));
        if ($reason === '') {
            return $url;
        }
        return $url . '?reason=' . rawurlencode($reason);
    }

    /**
     * @param array<string,mixed> $server
     */
    private static function resolveScriptName(array $server): string
    {
        foreach (['SCRIPT_NAME', 'PHP_SELF', 'SCRIPT_FILENAME'] as $key) {
            $value = trim((string)($server[$key] ?? $_SERVER[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * @param array<string,mixed> $server
     */
    private static function resolveRequestUri(array $server): string
    {
        return trim((string)($server['REQUEST_URI'] ?? $_SERVER['REQUEST_URI'] ?? ''));
    }

    /**
     * @param array{host?:string,port?:mixed,name?:string,user?:string,pass?:string,charset?:string} $config
     * @return array{host:string,port:int,name:string,user:string,pass:string,charset:string}
     */
    private static function normalizeConfig(array $config): array
    {
        $port = (int)($config['port'] ?? 3306);
        if ($port <= 0) {
            $port = 3306;
        }

        return [
            'host' => trim((string)($config['host'] ?? '')),
            'port' => $port,
            'name' => trim((string)($config['name'] ?? '')),
            'user' => trim((string)($config['user'] ?? '')),
            'pass' => (string)($config['pass'] ?? ''),
            'charset' => trim((string)($config['charset'] ?? 'utf8mb4')) ?: 'utf8mb4',
        ];
    }

    /**
     * @param array{host:string,port:int,name:string,user:string,pass:string,charset:string} $config
     */
    private static function hasRequiredConfig(array $config): bool
    {
        return $config['host'] !== '' && $config['name'] !== '' && $config['user'] !== '';
    }

    /**
     * @param array{host:string,port:int,name:string,user:string,pass:string,charset:string} $config
     */
    private static function connect(array $config, bool $withDatabase = true): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $config['host'], $config['port'], $config['charset']);
        if ($withDatabase) {
            $dsn .= ';dbname=' . $config['name'];
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
        }

        $pdo = new PDO($dsn, $config['user'], $config['pass'], $options);
        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }

        return $pdo;
    }

    private static function isUnknownDatabaseError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return strpos($message, 'unknown database') !== false || strpos($message, '[1049]') !== false;
    }

    /**
     * @param array{host:string,port:int,name:string,user:string,pass:string,charset:string} $config
     */
    private static function probeServerForDatabase(array $config): ?bool
    {
        try {
            $pdo = self::connect($config, false);
        } catch (Throwable $e) {
            return null;
        }

        try {
            $stmt = $pdo->query('SHOW DATABASES LIKE ' . $pdo->quote($config['name']));
            $exists = (bool)($stmt && $stmt->fetchColumn());
            if ($stmt) {
                $stmt->closeCursor();
            }
            return $exists;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function inspectAppMeta(PDO $pdo): array
    {
        if (!self::tableExists($pdo, 'app_meta')) {
            return [
                'status' => self::STATUS_NOT_INSTALLED,
                'install_state' => self::STATE_NOT_INSTALLED,
                'reason' => 'app_meta_missing',
                'message' => 'Brakuje technicznej tabeli app_meta.',
            ];
        }

        try {
            $stmt = $pdo->query('SELECT install_state, installed_version, installed_at FROM app_meta WHERE id = 1 LIMIT 1');
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            if ($stmt) {
                $stmt->closeCursor();
            }
        } catch (Throwable $e) {
            return [
                'status' => self::STATUS_DB_ERROR,
                'install_state' => self::STATE_NOT_INSTALLED,
                'reason' => 'app_meta_read_error',
                'message' => 'Nie udało się odczytać app_meta: ' . $e->getMessage(),
            ];
        }

        if (!$row) {
            return [
                'status' => self::STATUS_NOT_INSTALLED,
                'install_state' => self::STATE_NOT_INSTALLED,
                'reason' => 'app_meta_record_missing',
                'message' => 'Brakuje rekordu instalacyjnego w app_meta.',
            ];
        }

        $state = trim((string)($row['install_state'] ?? ''));
        if ($state === self::STATE_INSTALLED) {
            return [
                'status' => self::STATUS_INSTALLED,
                'install_state' => self::STATE_INSTALLED,
                'reason' => 'app_meta_install',
                'installed_version' => trim((string)($row['installed_version'] ?? '')),
                'installed_at' => $row['installed_at'] ?? null,
                'message' => 'Stan instalacji został potwierdzony przez app_meta.',
            ];
        }

        return [
            'status' => self::STATUS_NOT_INSTALLED,
            'install_state' => self::STATE_NOT_INSTALLED,
            'reason' => 'app_meta_not_installed',
            'installed_version' => trim((string)($row['installed_version'] ?? '')),
            'installed_at' => $row['installed_at'] ?? null,
            'message' => 'app_meta nie potwierdza zakończonej instalacji.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function inspectLegacyInstall(PDO $pdo): array
    {
        $checks = [
            ['table' => 'uzytkownicy', 'sql' => 'SELECT `login` FROM `uzytkownicy` LIMIT 1'],
            ['table' => 'konfiguracja_systemu', 'sql' => 'SELECT `id` FROM `konfiguracja_systemu` LIMIT 1'],
        ];

        foreach ($checks as $check) {
            if (!self::tableExists($pdo, $check['table'])) {
                return [
                    'status' => self::STATUS_NOT_INSTALLED,
                    'install_state' => self::STATE_NOT_INSTALLED,
                    'reason' => 'database_uninitialized',
                    'message' => 'Baza jest dostępna, ale nie wygląda na zainstalowane CRM.',
                ];
            }

            try {
                $stmt = $pdo->query($check['sql']);
                if ($stmt) {
                    $stmt->fetch(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();
                }
            } catch (Throwable $e) {
                return [
                    'status' => self::STATUS_DB_ERROR,
                    'install_state' => self::STATE_NOT_INSTALLED,
                    'reason' => 'legacy_schema_read_error',
                    'message' => 'Legacy bootstrap nie przeszedł odczytu kontrolnego: ' . $e->getMessage(),
                ];
            }
        }

        return [
            'status' => self::STATUS_INSTALLED,
            'install_state' => self::STATE_INSTALLED,
            'reason' => 'legacy_install',
            'message' => 'Stan instalacji został potwierdzony przez legacy schema checks.',
        ];
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return false;
        }

        try {
            $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
            $exists = (bool)($stmt && $stmt->fetchColumn());
            if ($stmt) {
                $stmt->closeCursor();
            }
            return $exists;
        } catch (Throwable $e) {
            return false;
        }
    }
}
