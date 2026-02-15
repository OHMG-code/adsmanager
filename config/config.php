<?php
// config/config.php
// Kolejnosc: 1) config/db.local.php (poza repo), 2) zmienne srodowiskowe.

// Ustal bazowy URL aplikacji (dla linkow/redirectow).
$baseConfigPath = __DIR__ . '/../public/includes/config.php';
if (file_exists($baseConfigPath)) {
    require_once $baseConfigPath;
} elseif (!defined('BASE_URL')) {
    define('BASE_URL', '/crm/public');
}

$rootDir = dirname(__DIR__);
// Load .env once for the whole app (shared for web + CLI + admin tools).
if (empty($GLOBALS['__dotenv_loaded'])) {
    $GLOBALS['__dotenv_loaded'] = true;
    $envPath = $rootDir . '/.env';
    if (is_file($envPath) && is_readable($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES);
        if ($lines !== false) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $pos = strpos($line, '=');
                if ($pos === false) {
                    continue;
                }
                $key = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));
                if ($key === '') {
                    continue;
                }
                if (
                    getenv($key) === false
                    && !array_key_exists($key, $_ENV)
                    && !array_key_exists($key, $_SERVER)
                ) {
                    putenv($key . '=' . $value);
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }
    }
}

$dbConfig = [];
$localPath = __DIR__ . '/db.local.php';
if (file_exists($localPath)) {
    $loaded = include $localPath;
    if (is_array($loaded)) {
        $dbConfig = $loaded;
    }
}

$host    = $dbConfig['host']    ?? getenv('DB_HOST') ?? '';
$db      = $dbConfig['name']    ?? getenv('DB_NAME') ?? '';
$user    = $dbConfig['user']    ?? getenv('DB_USER') ?? '';
$pass    = $dbConfig['pass']    ?? getenv('DB_PASS') ?? '';
$charset = $dbConfig['charset'] ?? getenv('DB_CHARSET') ?? 'utf8mb4';
if (!defined('MIGRATOR_TOKEN')) {
    $migratorToken = $dbConfig['migrator_token'] ?? getenv('MIGRATOR_TOKEN') ?? '';
    define('MIGRATOR_TOKEN', $migratorToken);
}
if (!defined('APP_SECRET_KEY')) {
    $secretKey = $dbConfig['app_secret'] ?? getenv('APP_SECRET_KEY') ?? '';
    if ($secretKey === '') {
        $secretKey = hash('sha256', $host . '|' . $db . '|' . $user, false);
    }
    define('APP_SECRET_KEY', $secretKey);
}
if (!defined('MAIL_SECRET_KEY')) {
    $mailKey = $dbConfig['mail_secret'] ?? getenv('MAIL_SECRET_KEY') ?? '';
    define('MAIL_SECRET_KEY', $mailKey);
}

if ($host === '' || $db === '' || $user === '' || $pass === '') {
    die('Brak konfiguracji DB. Ustaw DB_HOST/DB_NAME/DB_USER/DB_PASS lub plik config/db.local.php.');
}

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
    $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
}

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }
} catch (PDOException $e) {
    echo "Blad polaczenia z baza: " . $e->getMessage();
    exit;
}
if (!defined('MIGRATOR_TOKEN')) {
    define('MIGRATOR_TOKEN', 'jePFvYnrffw7CgykoTG7AjuqmNMDxAQK');
}
