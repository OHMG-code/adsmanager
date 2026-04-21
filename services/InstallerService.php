<?php

declare(strict_types=1);

require_once __DIR__ . '/InstallBaseline.php';
require_once __DIR__ . '/InstallGuard.php';
require_once __DIR__ . '/MigrationRunner.php';
require_once __DIR__ . '/ReleaseInfo.php';

final class InstallerService
{
    public const DB_STATE_EMPTY = 'empty_database';
    public const DB_STATE_PARTIAL = 'partial_install_detected';
    public const DB_STATE_EXISTING = 'existing_application_detected';
    public const DB_STATE_MISSING = 'database_missing';
    public const DB_STATE_ERROR = 'db_connection_error';

    private string $rootDir;
    private string $configFile;
    private string $lockFile;
    private string $migrationsDir;
    private string $baselinePath;
    private string $migrationLogPath;

    public function __construct(?string $rootDir = null)
    {
        $this->rootDir = $rootDir !== null ? rtrim($rootDir, '/') : dirname(__DIR__);
        $this->configFile = $this->rootDir . '/config/db.local.php';
        $this->lockFile = $this->rootDir . '/storage/installed.lock';
        $this->migrationsDir = $this->rootDir . '/sql/migrations';
        $this->baselinePath = InstallBaseline::schemaPath();
        $this->migrationLogPath = $this->rootDir . '/storage/logs/install-migrations.log';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function requirements(): array
    {
        $checks = [];
        $checks[] = $this->makeCheck(
            'php_version',
            'PHP 8.1+',
            PHP_VERSION_ID >= 80100,
            'Wykryto PHP ' . PHP_VERSION
        );

        foreach (['pdo_mysql', 'mbstring', 'json', 'openssl', 'curl', 'fileinfo'] as $extension) {
            $checks[] = $this->makeCheck(
                'ext_' . $extension,
                'Rozszerzenie ' . $extension,
                extension_loaded($extension),
                extension_loaded($extension) ? 'Załadowane.' : 'Brakuje rozszerzenia.'
            );
        }

        $checks[] = $this->makeCheck(
            'ext_soap',
            'Rozszerzenie soap',
            extension_loaded('soap'),
            extension_loaded('soap') ? 'Załadowane.' : 'Brakuje rozszerzenia SOAP. Integracja GUS pozostanie ograniczona.',
            false
        );

        foreach ([
            $this->rootDir . '/config',
            $this->rootDir . '/storage',
            $this->rootDir . '/storage/logs',
            $this->rootDir . '/storage/docs',
            $this->rootDir . '/public/uploads',
            $this->rootDir . '/public/uploads/settings',
        ] as $path) {
            $checks[] = $this->makeCheck(
                'path_' . md5($path),
                'Zapis do ' . str_replace($this->rootDir . '/', '', $path),
                $this->isPathWritable($path),
                $this->isPathWritable($path) ? 'Ścieżka gotowa do zapisu.' : 'Brak możliwości zapisu lub utworzenia katalogu.'
            );
        }

        $checks[] = $this->makeCheck(
            'baseline_file',
            'Kanoniczny baseline instalacyjny',
            is_file($this->baselinePath),
            is_file($this->baselinePath) ? 'Baseline jest dostępny.' : 'Brakuje pliku baseline instalacyjnego.'
        );

        return $checks;
    }

    /**
     * @param array<string,mixed> $database
     * @return array<string,mixed>
     */
    public function analyzeDatabase(array $database): array
    {
        $db = $this->normalizeDatabaseConfig($database);
        $validationErrors = $this->validateDatabaseConfig($db);
        if ($validationErrors !== []) {
            return [
                'ok' => false,
                'state' => self::DB_STATE_ERROR,
                'message' => $validationErrors[0],
                'details' => $validationErrors,
            ];
        }

        $guardInfo = InstallGuard::inspect($db, [
            'SCRIPT_NAME' => 'install.php',
            'REQUEST_URI' => '/install.php',
        ]);

        if (($guardInfo['status'] ?? null) === InstallGuard::STATUS_DB_ERROR) {
            return [
                'ok' => false,
                'state' => self::DB_STATE_ERROR,
                'message' => (string)($guardInfo['message'] ?? 'Nie udało się połączyć z bazą danych.'),
                'details' => [$guardInfo['reason'] ?? 'db_error'],
            ];
        }

        if (($guardInfo['db_exists'] ?? null) === false || ($guardInfo['reason'] ?? '') === 'database_missing') {
            $message = $db['create_if_missing']
                ? 'Baza jeszcze nie istnieje. Instalator spróbuje utworzyć ją podczas finalnego kroku.'
                : 'Baza jeszcze nie istnieje. Zaznacz opcję utworzenia bazy albo przygotuj ją ręcznie.';

            return [
                'ok' => (bool)$db['create_if_missing'],
                'state' => self::DB_STATE_MISSING,
                'message' => $message,
                'details' => [],
            ];
        }

        $pdo = $this->createPdoConnection($db, true);
        $tables = $this->listTables($pdo);
        if ($tables === []) {
            return [
                'ok' => true,
                'state' => self::DB_STATE_EMPTY,
                'message' => 'Baza jest pusta i gotowa do instalacji.',
                'details' => [],
            ];
        }

        if (($guardInfo['status'] ?? null) === InstallGuard::STATUS_INSTALLED) {
            return [
                'ok' => false,
                'state' => self::DB_STATE_EXISTING,
                'message' => 'W tej bazie wykryto istniejącą instalację CRM. Publiczny instalator nie nadpisuje działających środowisk.',
                'details' => array_slice($tables, 0, 8),
            ];
        }

        return [
            'ok' => false,
            'state' => self::DB_STATE_PARTIAL,
            'message' => 'Baza nie jest pusta, ale nie wygląda na kompletną instalację CRM. To wygląda na środowisko częściowo przygotowane lub uszkodzone.',
            'details' => array_slice($tables, 0, 8),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $existingConfig
     * @return array<string,mixed>
     */
    public function install(array $payload, array $existingConfig = []): array
    {
        $organization = $this->normalizeOrganizationData((array)($payload['organization'] ?? []));
        $database = $this->normalizeDatabaseConfig((array)($payload['database'] ?? []));
        $administrator = $this->normalizeAdministratorData((array)($payload['administrator'] ?? []));

        $errors = [];
        $errors = array_merge($errors, $this->validateOrganizationData($organization));
        $errors = array_merge($errors, $this->validateDatabaseConfig($database));
        $errors = array_merge($errors, $this->validateAdministratorData($administrator));
        $errors = array_merge($errors, $this->validateRuntimeDependencies());
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }

        $analysis = $this->analyzeDatabase($database);
        if (!$analysis['ok']) {
            throw new RuntimeException((string)$analysis['message']);
        }

        $details = [];
        $createdDatabase = false;

        if (($analysis['state'] ?? '') === self::DB_STATE_MISSING && $database['create_if_missing']) {
            $serverPdo = $this->createPdoConnection($database, false);
            $dbState = $this->ensureDatabaseExists($serverPdo, $database['name'], $database['charset']);
            $createdDatabase = $dbState === 'created';
            $details[] = $dbState === 'created'
                ? 'Utworzono bazę danych `' . $database['name'] . '`.'
                : 'Baza danych `' . $database['name'] . '` była już dostępna.';
        }

        $pdo = $this->createPdoConnection($database, true);
        $tablesBefore = $this->listTables($pdo);
        if ($tablesBefore !== []) {
            throw new RuntimeException('Docelowa baza nie jest pusta. Instalator wymaga pustej bazy albo jawnej decyzji o przygotowaniu nowej.');
        }

        $this->importBaseline($pdo);
        $details[] = 'Zaimportowano kanoniczny baseline instalacyjny.';

        $this->seedAbsorbedMigrations($pdo);
        $details[] = 'Oznaczono migracje wchłonięte do baseline’u.';

        $this->runPendingMigrations($pdo, $database);
        $details[] = 'Uruchomiono mechanizm migracji dla zmian nowszych niż baseline.';

        $this->seedReferenceData($pdo);
        $details[] = 'Zasiano dane referencyjne CRM.';

        $this->seedSystemConfiguration($pdo, $organization);
        $details[] = 'Zapisano konfigurację organizacji.';

        $adminId = $this->seedAdministrator($pdo, $administrator);
        $details[] = 'Utworzono pierwsze konto administratora.';

        $secrets = $this->resolveSecrets($existingConfig);
        $this->writeDbLocalConfigAtomically([
            'host' => $database['host'],
            'port' => $database['port'],
            'name' => $database['name'],
            'user' => $database['user'],
            'pass' => $database['pass'],
            'charset' => $database['charset'],
            'table_prefix' => $database['table_prefix'],
            'app_env' => 'production',
            'app_debug' => false,
            'app_secret' => $secrets['app_secret'],
            'mail_secret' => $secrets['mail_secret'],
            'migrator_token' => $secrets['migrator_token'],
        ]);
        $details[] = 'Zapisano konfigurację połączenia do bazy.';

        $this->markAppInstalled($pdo);
        $details[] = 'Zapisano stan instalacji w app_meta.';

        $this->writeInstallerLock($administrator['login'], $database);
        $details[] = 'Utworzono blokadę ponownej instalacji.';

        return [
            'details' => $details,
            'database_created' => $createdDatabase,
            'administrator_id' => $adminId,
            'organization_name' => $organization['company_name'],
            'prog_procentowy_policy' => InstallBaseline::PROG_PROCENTOWY_POLICY,
        ];
    }

    /**
     * @param array<string,mixed> $organization
     * @return array<string,string>
     */
    public function normalizeOrganizationData(array $organization): array
    {
        return [
            'company_name' => trim((string)($organization['company_name'] ?? '')),
            'company_nip' => trim((string)($organization['company_nip'] ?? '')),
            'company_address' => trim((string)($organization['company_address'] ?? '')),
            'company_email' => trim((string)($organization['company_email'] ?? '')),
            'company_phone' => trim((string)($organization['company_phone'] ?? '')),
        ];
    }

    /**
     * @param array<string,mixed> $administrator
     * @return array<string,string>
     */
    public function normalizeAdministratorData(array $administrator): array
    {
        return [
            'first_name' => trim((string)($administrator['first_name'] ?? '')),
            'last_name' => trim((string)($administrator['last_name'] ?? '')),
            'login' => trim((string)($administrator['login'] ?? '')),
            'email' => trim((string)($administrator['email'] ?? '')),
            'password' => (string)($administrator['password'] ?? ''),
            'password_confirm' => (string)($administrator['password_confirm'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $database
     * @return array{host:string,port:int,name:string,user:string,pass:string,charset:string,table_prefix:string,create_if_missing:bool}
     */
    public function normalizeDatabaseConfig(array $database): array
    {
        $port = (int)($database['port'] ?? 3306);
        if ($port <= 0) {
            $port = 3306;
        }

        return [
            'host' => trim((string)($database['host'] ?? '')),
            'port' => $port,
            'name' => trim((string)($database['name'] ?? '')),
            'user' => trim((string)($database['user'] ?? '')),
            'pass' => (string)($database['pass'] ?? ''),
            'charset' => trim((string)($database['charset'] ?? 'utf8mb4')) ?: 'utf8mb4',
            'table_prefix' => trim((string)($database['table_prefix'] ?? '')),
            'create_if_missing' => !empty($database['create_if_missing']),
        ];
    }

    /**
     * @param array<string,string> $organization
     * @return list<string>
     */
    private function validateOrganizationData(array $organization): array
    {
        $errors = [];
        if ($organization['company_name'] === '') {
            $errors[] = 'Nazwa organizacji jest wymagana.';
        }
        if ($organization['company_email'] !== '' && !filter_var($organization['company_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Podaj poprawny adres e-mail organizacji.';
        }
        if ($organization['company_nip'] !== '' && !preg_match('/^[0-9 -]{10,20}$/', $organization['company_nip'])) {
            $errors[] = 'NIP może zawierać cyfry, spacje i myślniki.';
        }
        return $errors;
    }

    /**
     * @param array{host:string,port:int,name:string,user:string,pass:string,charset:string,table_prefix:string,create_if_missing:bool} $database
     * @return list<string>
     */
    private function validateDatabaseConfig(array $database): array
    {
        $errors = [];
        if ($database['host'] === '' || $database['name'] === '' || $database['user'] === '') {
            $errors[] = 'Wszystkie pola bazy danych poza hasłem są wymagane.';
        }
        if ($database['port'] < 1 || $database['port'] > 65535) {
            $errors[] = 'Port bazy danych musi być liczbą z zakresu 1-65535.';
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $database['name'])) {
            $errors[] = 'Nazwa bazy może zawierać tylko litery, cyfry i znak podkreślenia.';
        }
        if ($database['table_prefix'] !== '') {
            if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $database['table_prefix'])) {
                $errors[] = 'Prefiks tabel może zawierać tylko litery, cyfry i znak podkreślenia (1-32 znaki).';
            } else {
                $errors[] = 'Prefiks tabel nie jest jeszcze wspierany przez CRM 1.0. Pozostaw to pole puste.';
            }
        }
        return $errors;
    }

    /**
     * @param array<string,string> $administrator
     * @return list<string>
     */
    private function validateAdministratorData(array $administrator): array
    {
        $errors = [];
        if ($administrator['first_name'] === '' || $administrator['last_name'] === '') {
            $errors[] = 'Imię i nazwisko administratora są wymagane.';
        }
        if ($administrator['login'] === '' || !preg_match('/^[A-Za-z0-9._-]{3,64}$/', $administrator['login'])) {
            $errors[] = 'Login administratora musi mieć 3-64 znaki i może zawierać litery, cyfry, kropkę, podkreślenie oraz myślnik.';
        }
        if (!filter_var($administrator['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Podaj poprawny adres e-mail administratora.';
        }
        if ($administrator['password'] === '' || strlen($administrator['password']) < 8) {
            $errors[] = 'Hasło administratora musi mieć co najmniej 8 znaków.';
        }
        if ($administrator['password'] !== $administrator['password_confirm']) {
            $errors[] = 'Hasło administratora i jego potwierdzenie nie są zgodne.';
        }
        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateRuntimeDependencies(): array
    {
        $errors = [];
        foreach ($this->requirements() as $check) {
            if (!empty($check['required']) && empty($check['ok'])) {
                $errors[] = (string)$check['label'] . ': ' . (string)$check['message'];
            }
        }

        return $errors;
    }

    /**
     * @return array<string,mixed>
     */
    private function makeCheck(string $key, string $label, bool $ok, string $message, bool $required = true): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'ok' => $ok,
            'message' => $message,
            'required' => $required,
        ];
    }

    private function isPathWritable(string $path): bool
    {
        if (!is_dir($path)) {
            if (!@mkdir($path, 0775, true) && !is_dir($path)) {
                return false;
            }
        }

        if (is_writable($path)) {
            return true;
        }

        $separator = DIRECTORY_SEPARATOR;
        $normalizedPath = rtrim($path, "/\\");
        $probeSuffix = function_exists('random_bytes')
            ? bin2hex(random_bytes(6))
            : uniqid('', true);
        $probeFile = $normalizedPath . $separator . '.write-test-' . $probeSuffix . '.tmp';

        $writeResult = @file_put_contents($probeFile, "ok\n");
        if ($writeResult === false) {
            return false;
        }
        @unlink($probeFile);
        return true;
    }

    /**
     * @param array{host:string,port:int,name:string,user:string,pass:string,charset:string,table_prefix:string,create_if_missing:bool} $database
     */
    private function createPdoConnection(array $database, bool $withDatabase): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            $database['host'],
            $database['port'],
            $database['charset']
        );
        if ($withDatabase) {
            $dsn .= ';dbname=' . $database['name'];
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
        }

        return new PDO($dsn, $database['user'], $database['pass'], $options);
    }

    /**
     * @return list<string>
     */
    private function listTables(PDO $pdo): array
    {
        $stmt = $pdo->query('SHOW TABLES');
        if ($stmt === false) {
            return [];
        }

        $tables = [];
        while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            if (isset($row[0]) && $row[0] !== '') {
                $tables[] = (string)$row[0];
            }
        }
        $stmt->closeCursor();
        sort($tables, SORT_STRING);
        return $tables;
    }

    /**
     * @return list<string>
     */
    private function listTableColumns(PDO $pdo, string $table): array
    {
        try {
            $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
            if ($stmt === false) {
                return [];
            }
            $columns = [];
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $name = trim((string)($row['Field'] ?? ''));
                if ($name !== '') {
                    $columns[] = $name;
                }
            }
            $stmt->closeCursor();
            return $columns;
        } catch (Throwable $e) {
            return [];
        }
    }

    private function ensureDatabaseExists(PDO $serverPdo, string $dbName, string $charset): string
    {
        $safeDb = '`' . str_replace('`', '``', $dbName) . '`';
        $sql = sprintf(
            'CREATE DATABASE IF NOT EXISTS %s CHARACTER SET %s COLLATE %s',
            $safeDb,
            $charset,
            $charset . '_unicode_ci'
        );

        try {
            $result = $serverPdo->exec($sql);
            return ((int)$result > 0) ? 'created' : 'exists';
        } catch (Throwable $e) {
            $stmt = $serverPdo->query('SHOW DATABASES LIKE ' . $serverPdo->quote($dbName));
            $exists = (bool)($stmt && $stmt->fetchColumn());
            if ($stmt) {
                $stmt->closeCursor();
            }
            if ($exists) {
                return 'exists';
            }
            throw new RuntimeException('Nie udało się utworzyć bazy danych `' . $dbName . '`: ' . $e->getMessage());
        }
    }

    private function importBaseline(PDO $pdo): void
    {
        if (!is_file($this->baselinePath)) {
            throw new RuntimeException('Brakuje pliku baseline instalacyjnego.');
        }

        $sql = file_get_contents($this->baselinePath);
        if ($sql === false) {
            throw new RuntimeException('Nie udało się odczytać baseline instalacyjnego.');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($this->splitSqlStatements($sql) as $statement) {
                $this->executeSqlStatement($pdo, $statement, basename($this->baselinePath));
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function seedAbsorbedMigrations(PDO $pdo): void
    {
        $columns = $this->listTableColumns($pdo, 'schema_migrations');
        if ($columns === []) {
            throw new RuntimeException('Brakuje tabeli schema_migrations po imporcie baseline.');
        }

        $supports = static function (string $column) use ($columns): bool {
            return in_array($column, $columns, true);
        };

        $fields = ['filename'];
        $placeholders = [':filename'];
        if ($supports('migration_name')) {
            $fields[] = 'migration_name';
            $placeholders[] = ':migration_name';
        }
        if ($supports('applied_at')) {
            $fields[] = 'applied_at';
            $placeholders[] = ':applied_at';
        }
        if ($supports('executed_at')) {
            $fields[] = 'executed_at';
            $placeholders[] = ':executed_at';
        }
        if ($supports('success')) {
            $fields[] = 'success';
            $placeholders[] = ':success';
        }
        if ($supports('checksum')) {
            $fields[] = 'checksum';
            $placeholders[] = ':checksum';
        }
        if ($supports('execution_time_ms')) {
            $fields[] = 'execution_time_ms';
            $placeholders[] = ':execution_time_ms';
        }
        if ($supports('notes')) {
            $fields[] = 'notes';
            $placeholders[] = ':notes';
        }

        $updates = ['filename = VALUES(filename)'];
        if ($supports('migration_name')) {
            $updates[] = 'migration_name = COALESCE(VALUES(migration_name), migration_name)';
        }
        if ($supports('applied_at')) {
            $updates[] = 'applied_at = VALUES(applied_at)';
        }
        if ($supports('executed_at')) {
            $updates[] = 'executed_at = VALUES(executed_at)';
        }
        if ($supports('success')) {
            $updates[] = 'success = VALUES(success)';
        }
        if ($supports('checksum')) {
            $updates[] = 'checksum = VALUES(checksum)';
        }
        if ($supports('execution_time_ms')) {
            $updates[] = 'execution_time_ms = VALUES(execution_time_ms)';
        }
        if ($supports('notes')) {
            $updates[] = 'notes = VALUES(notes)';
        }

        $stmt = $pdo->prepare(
            'INSERT INTO schema_migrations (' . implode(', ', $fields) . ')
             VALUES (' . implode(', ', $placeholders) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
        );

        $appliedAt = date('Y-m-d H:i:s');
        foreach (InstallBaseline::absorbedMigrations() as $filename) {
            $params = [':filename' => $filename];
            if ($supports('migration_name')) {
                $params[':migration_name'] = $filename;
            }
            if ($supports('applied_at')) {
                $params[':applied_at'] = $appliedAt;
            }
            if ($supports('executed_at')) {
                $params[':executed_at'] = $appliedAt;
            }
            if ($supports('success')) {
                $params[':success'] = 1;
            }
            if ($supports('checksum')) {
                $params[':checksum'] = null;
            }
            if ($supports('execution_time_ms')) {
                $params[':execution_time_ms'] = null;
            }
            if ($supports('notes')) {
                $params[':notes'] = 'absorbed_by_install_baseline';
            }
            $stmt->execute($params);
        }
    }

    /**
     * @param array{host:string,port:int,name:string,user:string,pass:string,charset:string,table_prefix:string,create_if_missing:bool} $database
     */
    private function runPendingMigrations(PDO $pdo, array $database): void
    {
        $before = $this->countAppliedMigrations($pdo);
        $runner = new MigrationRunner($pdo, $this->migrationsDir, [
            'host' => $database['host'] . ':' . $database['port'],
            'dbname' => $database['name'],
            'logPaths' => [$this->migrationLogPath],
            'emitOutput' => false,
        ]);
        $exitCode = $runner->run();
        if ($exitCode !== 0) {
            throw new RuntimeException('Mechanizm migracji zakończył się błędem.');
        }

        $after = $this->countAppliedMigrations($pdo);
        if ($after < $before) {
            throw new RuntimeException('Liczba migracji po uruchomieniu spadła, co wskazuje na niespójność instalacji.');
        }
    }

    private function countAppliedMigrations(PDO $pdo): int
    {
        $stmt = $pdo->query('SELECT COUNT(*) FROM schema_migrations');
        $count = (int)($stmt ? $stmt->fetchColumn() : 0);
        if ($stmt) {
            $stmt->closeCursor();
        }
        return $count;
    }

    private function seedReferenceData(PDO $pdo): void
    {
        $existingStatuses = (int)$pdo->query('SELECT COUNT(*) FROM crm_statusy')->fetchColumn();
        if ($existingStatuses === 0) {
            $pdo->exec(
                "INSERT INTO crm_statusy (nazwa, aktywny, dotyczy, sort) VALUES
                ('Nowy', 1, 'oba', 10),
                ('Do kontaktu', 1, 'oba', 20),
                ('Wysłano ofertę', 1, 'oba', 30),
                ('Negocjacje', 1, 'oba', 40),
                ('Wygrany', 1, 'oba', 50),
                ('Przegrany', 1, 'oba', 60)"
            );
        }

        $existingCommissionTypes = (int)$pdo->query('SELECT COUNT(*) FROM commission_types')->fetchColumn();
        if ($existingCommissionTypes === 0) {
            $pdo->exec(
                "INSERT INTO commission_types (code, name, sort_order, is_active) VALUES
                ('NOWA_UMOWA', 'Nowa umowa', 1, 1),
                ('PRZEDLUZENIE', 'Przedluzenie', 2, 1),
                ('KOLEJNA_FAKTURA', 'Kolejna faktura', 3, 1)"
            );
        }
    }

    /**
     * @param array<string,string> $organization
     */
    private function seedSystemConfiguration(PDO $pdo, array $organization): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO konfiguracja_systemu (
                id,
                liczba_blokow,
                godzina_start,
                godzina_koniec,
                prog_procentowy,
                prime_hours,
                standard_hours,
                night_hours,
                pdf_logo_path,
                smtp_host,
                smtp_port,
                smtp_secure,
                smtp_auth,
                smtp_default_from_email,
                smtp_default_from_name,
                smtp_username,
                smtp_password,
                email_signature_template_html,
                limit_prime_seconds_per_day,
                limit_standard_seconds_per_day,
                limit_night_seconds_per_day,
                maintenance_last_run_at,
                maintenance_interval_minutes,
                audio_upload_max_mb,
                audio_allowed_ext,
                gus_enabled,
                gus_api_key,
                gus_environment,
                gus_cache_ttl_days,
                company_name,
                company_address,
                company_nip,
                company_email,
                company_phone,
                documents_storage_path,
                documents_number_prefix,
                gus_auto_refresh_enabled,
                gus_auto_refresh_batch,
                gus_auto_refresh_interval_days,
                gus_auto_refresh_backoff_minutes,
                crm_archive_bcc_email,
                crm_archive_enabled,
                block_duration_seconds
            ) VALUES (
                1, 2, :godzina_start, :godzina_koniec, :prog_procentowy,
                :prime_hours, :standard_hours, :night_hours, NULL,
                NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL,
                3600, 3600, 3600, NULL, 10, 50, :audio_allowed_ext,
                0, NULL, :gus_environment, 30,
                :company_name, :company_address, :company_nip, :company_email, :company_phone,
                :documents_storage_path, :documents_number_prefix,
                0, 20, 30, 60, NULL, 0, 45
            )
            ON DUPLICATE KEY UPDATE
                godzina_start = VALUES(godzina_start),
                godzina_koniec = VALUES(godzina_koniec),
                prog_procentowy = VALUES(prog_procentowy),
                prime_hours = VALUES(prime_hours),
                standard_hours = VALUES(standard_hours),
                night_hours = VALUES(night_hours),
                audio_allowed_ext = VALUES(audio_allowed_ext),
                gus_environment = VALUES(gus_environment),
                company_name = VALUES(company_name),
                company_address = VALUES(company_address),
                company_nip = VALUES(company_nip),
                company_email = VALUES(company_email),
                company_phone = VALUES(company_phone),
                documents_storage_path = VALUES(documents_storage_path),
                documents_number_prefix = VALUES(documents_number_prefix),
                block_duration_seconds = VALUES(block_duration_seconds)'
        );

        $stmt->execute([
            ':godzina_start' => '07:00:00',
            ':godzina_koniec' => '21:00:00',
            ':prog_procentowy' => '2',
            ':prime_hours' => '06:00-09:59,15:00-18:59',
            ':standard_hours' => '10:00-14:59,19:00-22:59',
            ':night_hours' => '00:00-05:59,23:00-23:59',
            ':audio_allowed_ext' => 'wav,mp3',
            ':gus_environment' => 'prod',
            ':company_name' => $organization['company_name'] !== '' ? $organization['company_name'] : null,
            ':company_address' => $organization['company_address'] !== '' ? $organization['company_address'] : null,
            ':company_nip' => $organization['company_nip'] !== '' ? $organization['company_nip'] : null,
            ':company_email' => $organization['company_email'] !== '' ? $organization['company_email'] : null,
            ':company_phone' => $organization['company_phone'] !== '' ? $organization['company_phone'] : null,
            ':documents_storage_path' => 'storage/docs/',
            ':documents_number_prefix' => 'AM/',
        ]);

        $docsDir = $this->rootDir . '/storage/docs';
        if (!is_dir($docsDir)) {
            @mkdir($docsDir, 0775, true);
        }
    }

    /**
     * @param array<string,string> $administrator
     */
    private function seedAdministrator(PDO $pdo, array $administrator): int
    {
        $stmt = $pdo->prepare('SELECT id FROM uzytkownicy WHERE login = :login LIMIT 1');
        $stmt->execute([':login' => $administrator['login']]);
        $existingId = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($existingId !== false) {
            throw new RuntimeException('Login administratora `' . $administrator['login'] . '` jest już zajęty w docelowej bazie.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO uzytkownicy (
                login,
                haslo_hash,
                rola,
                email,
                aktywny,
                imie,
                nazwisko,
                funkcja
            ) VALUES (
                :login,
                :haslo_hash,
                :rola,
                :email,
                :aktywny,
                :imie,
                :nazwisko,
                :funkcja
            )'
        );

        $insert->execute([
            ':login' => $administrator['login'],
            ':haslo_hash' => password_hash($administrator['password'], PASSWORD_DEFAULT),
            ':rola' => 'Administrator',
            ':email' => $administrator['email'],
            ':aktywny' => 1,
            ':imie' => $administrator['first_name'],
            ':nazwisko' => $administrator['last_name'],
            ':funkcja' => 'Administrator systemu',
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $cfg
     */
    private function writeDbLocalConfigAtomically(array $cfg): void
    {
        $dir = dirname($this->configFile);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Nie udało się utworzyć katalogu config.');
        }

        $content = "<?php\nreturn [\n"
            . "    'host' => " . var_export((string)$cfg['host'], true) . ",\n"
            . "    'port' => " . var_export((int)$cfg['port'], true) . ",\n"
            . "    'name' => " . var_export((string)$cfg['name'], true) . ",\n"
            . "    'user' => " . var_export((string)$cfg['user'], true) . ",\n"
            . "    'pass' => " . var_export((string)$cfg['pass'], true) . ",\n"
            . "    'charset' => " . var_export((string)$cfg['charset'], true) . ",\n"
            . "    'table_prefix' => " . var_export((string)($cfg['table_prefix'] ?? ''), true) . ",\n"
            . "    'app_env' => " . var_export((string)($cfg['app_env'] ?? 'production'), true) . ",\n"
            . "    'app_debug' => " . var_export((bool)($cfg['app_debug'] ?? false), true) . ",\n"
            . "    'app_secret' => " . var_export((string)$cfg['app_secret'], true) . ",\n"
            . "    'mail_secret' => " . var_export((string)$cfg['mail_secret'], true) . ",\n"
            . "    'migrator_token' => " . var_export((string)$cfg['migrator_token'], true) . ",\n"
            . "];\n";

        $tmpFile = tempnam($dir, 'db.local.');
        if ($tmpFile === false) {
            throw new RuntimeException('Nie udało się przygotować pliku tymczasowego dla konfiguracji.');
        }

        try {
            if (file_put_contents($tmpFile, $content, LOCK_EX) === false) {
                throw new RuntimeException('Nie udało się zapisać pliku tymczasowego konfiguracji.');
            }
            @chmod($tmpFile, 0640);
            if (!@rename($tmpFile, $this->configFile)) {
                throw new RuntimeException('Nie udało się atomowo podmienić config/db.local.php.');
            }
        } finally {
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }

    /**
     * @param array<string,mixed> $existingConfig
     * @return array<string,string>
     */
    private function resolveSecrets(array $existingConfig): array
    {
        $appSecret = trim((string)($existingConfig['app_secret'] ?? ''));
        $mailSecret = trim((string)($existingConfig['mail_secret'] ?? ''));
        $migratorToken = trim((string)($existingConfig['migrator_token'] ?? ''));

        if ($appSecret === '') {
            $appSecret = bin2hex(random_bytes(32));
        }
        if ($mailSecret === '') {
            $mailSecret = bin2hex(random_bytes(32));
        }
        if ($migratorToken === '') {
            $migratorToken = bin2hex(random_bytes(32));
        }

        return [
            'app_secret' => $appSecret,
            'mail_secret' => $mailSecret,
            'migrator_token' => $migratorToken,
        ];
    }

    private function markAppInstalled(PDO $pdo): void
    {
        $release = (new ReleaseInfo($this->rootDir))->load();
        $installedVersion = defined('APP_VERSION')
            ? trim((string)APP_VERSION)
            : (!empty($release['ok']) ? trim((string)($release['version'] ?? '')) : '');
        $baselineId = trim((string)($release['baseline_id'] ?? '')) ?: InstallBaseline::BASELINE_ID;
        $releaseChannel = !empty($release['ok']) ? trim((string)($release['channel'] ?? '')) : '';
        $appMetaColumns = $this->listTableColumns($pdo, 'app_meta');
        $supportsDbVersion = in_array('db_version', $appMetaColumns, true);

        if ($supportsDbVersion) {
            $stmt = $pdo->prepare(
                "INSERT INTO app_meta (id, install_state, installed_version, db_version, installed_at, baseline_id, release_channel, created_at, updated_at)
                 VALUES (1, 'installed', :installed_version, :db_version, NOW(), :baseline_id, :release_channel, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    install_state = VALUES(install_state),
                    installed_version = COALESCE(VALUES(installed_version), installed_version),
                    db_version = COALESCE(VALUES(db_version), db_version),
                    installed_at = COALESCE(installed_at, VALUES(installed_at)),
                    baseline_id = COALESCE(VALUES(baseline_id), baseline_id),
                    release_channel = COALESCE(VALUES(release_channel), release_channel),
                    updated_at = VALUES(updated_at)"
            );
            $stmt->execute([
                ':installed_version' => $installedVersion !== '' ? $installedVersion : null,
                ':db_version' => $installedVersion !== '' ? $installedVersion : null,
                ':baseline_id' => $baselineId !== '' ? $baselineId : null,
                ':release_channel' => $releaseChannel !== '' ? $releaseChannel : null,
            ]);
            return;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO app_meta (id, install_state, installed_version, installed_at, baseline_id, release_channel, created_at, updated_at)
             VALUES (1, 'installed', :installed_version, NOW(), :baseline_id, :release_channel, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                install_state = VALUES(install_state),
                installed_version = COALESCE(VALUES(installed_version), installed_version),
                installed_at = COALESCE(installed_at, VALUES(installed_at)),
                baseline_id = COALESCE(VALUES(baseline_id), baseline_id),
                release_channel = COALESCE(VALUES(release_channel), release_channel),
                updated_at = VALUES(updated_at)"
        );
        $stmt->execute([
            ':installed_version' => $installedVersion !== '' ? $installedVersion : null,
            ':baseline_id' => $baselineId !== '' ? $baselineId : null,
            ':release_channel' => $releaseChannel !== '' ? $releaseChannel : null,
        ]);
    }

    /**
     * @param array{host:string,port:int,name:string,user:string,pass:string,charset:string,table_prefix:string,create_if_missing:bool} $database
     */
    private function writeInstallerLock(string $adminLogin, array $database): void
    {
        $dir = dirname($this->lockFile);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Nie udało się utworzyć katalogu storage dla blokady instalatora.');
        }

        $payload = [
            'installed_at' => date('c'),
            'db_host' => $database['host'],
            'db_port' => $database['port'],
            'db_name' => $database['name'],
            'admin_login' => $adminLogin,
            'baseline_id' => InstallBaseline::BASELINE_ID,
        ];

        if (file_put_contents($this->lockFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Nie udało się zapisać pliku blokady instalatora.');
        }
    }

    /**
     * @return list<string>
     */
    private function splitSqlStatements(string $sql): array
    {
        $sql = ltrim($sql, "\xEF\xBB\xBF");
        $len = strlen($sql);
        $statements = [];
        $buffer = '';
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($ch === "\n") {
                    $inLineComment = false;
                    $buffer .= "\n";
                }
                continue;
            }

            if ($inBlockComment) {
                if ($ch === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                    $buffer .= ' ';
                }
                continue;
            }

            if (!$inSingle && !$inDouble && !$inBacktick) {
                if ($ch === '-' && $next === '-') {
                    $prev = $i > 0 ? $sql[$i - 1] : '';
                    $after = $i + 2 < $len ? $sql[$i + 2] : '';
                    if (($prev === '' || ctype_space($prev)) && ($after === '' || ctype_space($after))) {
                        $inLineComment = true;
                        $i++;
                        continue;
                    }
                }
                if ($ch === '#') {
                    $inLineComment = true;
                    continue;
                }
                if ($ch === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++;
                    continue;
                }
            }

            if ($inSingle) {
                if ($ch === '\\') {
                    $buffer .= $ch;
                    if ($next !== '') {
                        $buffer .= $next;
                        $i++;
                    }
                    continue;
                }
                if ($ch === "'") {
                    if ($next === "'") {
                        $buffer .= "''";
                        $i++;
                        continue;
                    }
                    $inSingle = false;
                }
                $buffer .= $ch;
                continue;
            }

            if ($inDouble) {
                if ($ch === '\\') {
                    $buffer .= $ch;
                    if ($next !== '') {
                        $buffer .= $next;
                        $i++;
                    }
                    continue;
                }
                if ($ch === '"') {
                    if ($next === '"') {
                        $buffer .= '""';
                        $i++;
                        continue;
                    }
                    $inDouble = false;
                }
                $buffer .= $ch;
                continue;
            }

            if ($inBacktick) {
                if ($ch === '`') {
                    $inBacktick = false;
                }
                $buffer .= $ch;
                continue;
            }

            if ($ch === "'") {
                $inSingle = true;
                $buffer .= $ch;
                continue;
            }
            if ($ch === '"') {
                $inDouble = true;
                $buffer .= $ch;
                continue;
            }
            if ($ch === '`') {
                $inBacktick = true;
                $buffer .= $ch;
                continue;
            }

            if ($ch === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $ch;
        }

        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }

    private function executeSqlStatement(PDO $pdo, string $statement, string $scope): void
    {
        $stmt = null;

        try {
            $stmt = $pdo->prepare($statement);
            if ($stmt === false) {
                throw new RuntimeException('prepare() zwróciło false.');
            }
            $stmt->execute();
            if ($stmt->columnCount() > 0) {
                $stmt->fetchAll();
            }
        } catch (Throwable $e) {
            $snippet = trim((string)(preg_replace('/\s+/', ' ', $statement) ?? ''));
            if (strlen($snippet) > 240) {
                $snippet = substr($snippet, 0, 240) . '...';
            }
            throw new RuntimeException($scope . ': ' . $e->getMessage() . ' [SQL: ' . $snippet . ']');
        } finally {
            if ($stmt instanceof PDOStatement) {
                $stmt->closeCursor();
            }
        }
    }
}
