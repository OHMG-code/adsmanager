<?php
// Copy to config/db.local.php (do not commit secrets).
// Priority in runtime: config/db.local.php -> env vars (DB_* / MIGRATOR_TOKEN).
return [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => (int)(getenv('DB_PORT') ?: 3306),
    'name' => getenv('DB_NAME') ?: 'crm_database',
    'user' => getenv('DB_USER') ?: 'crm_user',
    'pass' => getenv('DB_PASS') ?: 'replace-with-db-password',
    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
    'table_prefix' => getenv('DB_TABLE_PREFIX') ?: '',
    'app_env' => getenv('APP_ENV') ?: 'production',
    'app_debug' => filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOLEAN),
    'migrator_token' => getenv('MIGRATOR_TOKEN') ?: 'replace-with-random-token',
    'update_manifest_url' => getenv('UPDATE_MANIFEST_URL') ?: '',
];
