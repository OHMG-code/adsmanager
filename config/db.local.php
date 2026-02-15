<?php
return [
    'host' => getenv('DB_HOST') ?: 'db',
    'name' => getenv('DB_DATABASE') ?: 'crm_dev',
    'user' => getenv('DB_USERNAME') ?: 'crm',
    'pass' => getenv('DB_PASSWORD') ?: 'crm',
    'charset' => 'utf8mb4',
    'migrator_token' => '40f9f7e41598d44c12f17553d38a022155f0a8b90786ff771c89a54c2b77b609',
];
