<?php
require_once '../config/config.php';

header('Content-Type: application/json');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';

if ($q === '') {
    echo json_encode([]);
    exit;
}

// Szukamy po nazwie firmy lub NIP
$stmt = $pdo->prepare("
    SELECT k.id,
           COALESCE(c.name_full, c.name_short, k.nazwa_firmy) AS nazwa_firmy,
           COALESCE(c.nip, k.nip) AS nip
    FROM klienci k
    LEFT JOIN companies c ON c.id = k.company_id
    WHERE COALESCE(c.name_full, c.name_short, k.nazwa_firmy) LIKE :term
       OR COALESCE(c.nip, k.nip) LIKE :term
    ORDER BY nazwa_firmy ASC
    LIMIT 10
");

$stmt->execute([':term' => '%' . $q . '%']);
$results = $stmt->fetchAll();

$suggestions = [];

foreach ($results as $row) {
    $label = $row['nazwa_firmy'] . ' - ' . $row['nip'];
    $suggestions[] = [
        'id' => $row['id'],
        'label' => $label
    ];
}

echo json_encode($suggestions);
