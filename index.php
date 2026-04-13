<?php
declare(strict_types=1);

$basePath = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
if ($basePath === '.' || $basePath === '/') {
    $basePath = '';
}

header('Location: ' . $basePath . '/public/index.php', true, 302);
exit;
