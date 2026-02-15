<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/documents.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    http_response_code(403);
    exit('Brak uprawnień.');
}

$docId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($docId <= 0) {
    http_response_code(400);
    exit('Nieprawidłowy identyfikator dokumentu.');
}

ensureDocumentsTables($pdo);
ensureSystemConfigColumns($pdo);

$stmt = $pdo->prepare('SELECT * FROM dokumenty WHERE id = :id');
$stmt->execute([':id' => $docId]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    http_response_code(404);
    exit('Nie znaleziono dokumentu.');
}

$clientId = (int)($doc['client_id'] ?? 0);
$kampaniaId = !empty($doc['kampania_id']) ? (int)$doc['kampania_id'] : 0;

if (normalizeRole($currentUser) === 'Handlowiec') {
    $allowed = true;
    if ($kampaniaId > 0) {
        $allowed = canUserAccessKampania($pdo, $currentUser, $kampaniaId);
    } elseif ($clientId > 0) {
        $allowed = canUserAccessClient($pdo, $currentUser, $clientId);
    } else {
        $allowed = false;
    }
    if (!$allowed) {
        http_response_code(403);
        exit('Brak dostępu do dokumentu.');
    }
}

$config = fetchSystemConfigSafe($pdo);
$storageDir = resolveDocumentsStoragePath($config);
$filePath = $storageDir . ($doc['stored_filename'] ?? '');
if (!is_file($filePath)) {
    http_response_code(404);
    exit('Plik dokumentu nie istnieje.');
}

$inline = !empty($_GET['inline']);
$filename = $doc['original_filename'] ?? ('dokument_' . $docId . '.pdf');
$filenameSafe = preg_replace('/[\r\n"]+/', '', $filename);

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $filenameSafe . '"');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
exit;
