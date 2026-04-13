<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/config.php';

requireLogin();
header('Location: ' . BASE_URL . '/followup.php?tab=tasks');
exit;
