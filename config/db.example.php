<?php
// Copy to config/db.local.php (do not commit secrets).
// Priority in runtime: config/db.local.php -> env vars (DB_* / MIGRATOR_TOKEN).
return [
    'host' => getenv('DB_HOST') ?: 'db',
    'name' => getenv('DB_NAME') ?: 'crm_dev',
    'user' => getenv('DB_USER') ?: 'crm',
    'pass' => getenv('DB_PASS') ?: 'crm',
    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
    'migrator_token' => getenv('MIGRATOR_TOKEN') ?: 'replace-with-random-token',
];
