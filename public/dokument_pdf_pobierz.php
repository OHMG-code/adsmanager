<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/document_pdf_versions.php';

ensureDocumentPdfVersionsTable($pdo);

$versionId = (int)($_GET['version_id'] ?? 0);
if ($versionId <= 0) {
    http_response_code(404);
    echo 'Nie znaleziono wersji PDF.';
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM document_pdf_versions WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $versionId]);
$version = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
$stmt->closeCursor();
if (!$version) {
    http_response_code(404);
    echo 'Nie znaleziono wersji PDF.';
    exit;
}

$fullPath = documentPdfResolvePath($version['pdf_path'] ?? '');
if (!$fullPath) {
    http_response_code(404);
    echo 'Plik PDF nie istnieje.';
    exit;
}

logDocumentAudit($pdo, (int)$version['document_id'], 'document_pdf_version_downloaded', 'Pobrano wersje PDF', [
    'user_id' => (int)($_SESSION['user_id'] ?? 0),
    'metadata' => [
        'version_id' => (int)$version['id'],
        'version_number' => (int)$version['version_number'],
        'file_name' => (string)$version['file_name'],
    ],
]);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . basename((string)$version['file_name']) . '"');
header('Content-Length: ' . filesize($fullPath));
readfile($fullPath);
exit;
