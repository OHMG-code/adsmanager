<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/order_document_pdf.php';
require_once __DIR__ . '/includes/annex_document_pdf.php';
require_once __DIR__ . '/includes/document_audit.php';
require_once __DIR__ . '/includes/document_locks.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    echo 'Nie znaleziono dokumentu.';
    exit;
}

try {
    ensureSalesDocumentsTable($pdo);
    $stmt = $pdo->prepare('SELECT document_type, status FROM documents WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $stmt->closeCursor();

    if (!$document) {
        throw new RuntimeException('Nie znaleziono dokumentu.');
    }
    $documentType = (string)$document['document_type'];
    assertDocumentNotClosed($pdo, $id, (string)$document['status'], 'generate_pdf');
    if ($documentType === 'order') {
        $pdfResult = orderDocumentPdfGenerateAndSave($pdo, $id);
    } elseif ($documentType === 'annex') {
        $pdfResult = annexDocumentPdfGenerateAndSave($pdo, $id);
    } else {
        throw new RuntimeException('Nieobslugiwany typ dokumentu.');
    }

    logDocumentAudit($pdo, $id, 'document_pdf_generated', 'Wygenerowano PDF dokumentu', [
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
        'new_value' => (string)($pdfResult['path'] ?? ''),
        'metadata' => [
            'path' => $pdfResult['path'] ?? null,
            'filename' => $pdfResult['filename'] ?? null,
            'bytes' => $pdfResult['bytes'] ?? null,
            'terms_version' => $pdfResult['terms_version'] ?? null,
        ],
    ]);

    header('Location: ' . BASE_URL . '/dokument_podglad.php?id=' . $id . '&pdf=generated');
    exit;
} catch (RuntimeException $e) {
    $message = $e->getMessage();
    if ($message === 'Nie znaleziono dokumentu.') {
        http_response_code(404);
        echo $message;
        exit;
    }

    header('Location: ' . BASE_URL . '/dokument_podglad.php?id=' . $id . '&pdf_error=' . urlencode($message));
    exit;
}
