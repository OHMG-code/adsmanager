<?php
return [
    'host' => getenv('DB_HOST') ?: 'db',
    'name' => getenv('DB_DATABASE') ?: 'crm_validation',
    'user' => getenv('DB_USERNAME') ?: 'crm',
    'pass' => getenv('DB_PASSWORD') ?: 'crm',
    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
    'app_secret' => getenv('APP_SECRET_KEY') ?: 'crm-validation-app-secret-2026',
    'mail_secret' => getenv('MAIL_SECRET_KEY') ?: 'crm-validation-mail-secret-2026',
    'migrator_token' => getenv('MIGRATOR_TOKEN') ?: 'validation-migrator-token',
];
