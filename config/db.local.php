<?php
return [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => (int)(getenv('DB_PORT') ?: 3306),
    'name' => getenv('DB_NAME') ?: (getenv('DB_DATABASE') ?: 'crm_database'),
    'user' => getenv('DB_USER') ?: (getenv('DB_USERNAME') ?: 'crm_user'),
    'pass' => getenv('DB_PASS') ?: (getenv('DB_PASSWORD') ?: ''),
    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
    'table_prefix' => '',
    'app_env' => getenv('APP_ENV') ?: 'development',
    'app_debug' => filter_var(getenv('APP_DEBUG') ?: '1', FILTER_VALIDATE_BOOLEAN),
    'mail_secret' => getenv('MAIL_SECRET_KEY') ?: '',
    'migrator_token' => getenv('MIGRATOR_TOKEN') ?: '',
];
