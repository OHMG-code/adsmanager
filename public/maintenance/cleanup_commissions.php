<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/config.php';

$provided = isset($_GET['token']) ? (string)$_GET['token'] : '';
$expected = '08b7ba9a0da64dc9b0afa682d979375d';
if ($provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$lockFile = __DIR__ . '/../../storage/cleanup_commissions.done';
if (file_exists($lockFile)) {
    echo 'already_done';
    exit;
}

$pdo->beginTransaction();
try {
    $pdo->exec("UPDATE uzytkownicy SET rola = 'Administrator' WHERE LOWER(rola) IN ('admin','administrator')");
    $pdo->exec("UPDATE uzytkownicy SET rola = 'Manager' WHERE LOWER(rola) = 'manager'");
    $pdo->exec("UPDATE uzytkownicy SET rola = 'Handlowiec' WHERE LOWER(rola) IN ('handlowiec','user','uzytkownik')");

    $stmt = $pdo->prepare("UPDATE uzytkownicy
        SET prov_new_contract_pct = 0.00,
            prov_renewal_pct = 0.00,
            prov_next_invoice_pct = 0.00
        WHERE rola IN ('Administrator', 'Manager')");
    $stmt->execute();

    file_put_contents($lockFile, date('c') . "\n");
    $pdo->commit();
    echo 'ok';
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo 'error: ' . $e->getMessage();
}
