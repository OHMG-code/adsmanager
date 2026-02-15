<?php
require_once __DIR__ . '/includes/config.php';

echo password_hash("admin123", PASSWORD_DEFAULT);
?>
