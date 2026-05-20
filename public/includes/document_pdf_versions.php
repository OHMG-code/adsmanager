<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/document_audit.php';

function documentPdfUploadsDir(): ?string
{
    $dir = dirname(__DIR__) . '/uploads/documents';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }
    $real = realpath($dir);
    return $real !== false ? $real : null;
}

function documentPdfResolvePath(?string $relativePath): ?string
{
    $relativePath = trim((string)$relativePath);
    if ($relativePath === '' || str_contains($relativePath, '..')) {
        return null;
    }
    $uploadsDir = documentPdfUploadsDir();
    if (!$uploadsDir) {
        return null;
    }
    $fullPath = realpath(dirname(__DIR__) . '/' . ltrim($relativePath, '/\\'));
    if (!$fullPath || strpos($fullPath, $uploadsDir) !== 0 || !is_file($fullPath)) {
        return null;
    }
    return $fullPath;
}

function createDocumentPdfVersion(PDO $pdo, int $documentId, string $relativePath, string $fileName, string $fullPath, ?int $generatedBy = null): array
{
    if ($documentId <= 0) {
        throw new InvalidArgumentException('Niepoprawny identyfikator dokumentu.');
    }
    $resolved = documentPdfResolvePath($relativePath);
    if (!$resolved || realpath($fullPath) !== $resolved) {
        throw new RuntimeException('Plik PDF jest poza dozwolonym katalogiem.');
    }

    if (!$pdo->inTransaction()) {
        ensureDocumentPdfVersionsTable($pdo);
    }

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(version_number), 0) FROM document_pdf_versions WHERE document_id = :document_id');
    $stmt->execute([':document_id' => $documentId]);
    $versionNumber = (int)$stmt->fetchColumn() + 1;

    $fileSize = filesize($resolved);
    $checksum = hash_file('sha256', $resolved);

    $pdo->prepare('UPDATE document_pdf_versions SET is_current = 0 WHERE document_id = :document_id')
        ->execute([':document_id' => $documentId]);

    $insert = $pdo->prepare("INSERT INTO document_pdf_versions
        (document_id, version_number, pdf_path, file_name, file_size, checksum_sha256, generated_by, generated_at, is_current, created_at)
        VALUES (:document_id, :version_number, :pdf_path, :file_name, :file_size, :checksum_sha256, :generated_by, CURRENT_TIMESTAMP, 1, CURRENT_TIMESTAMP)");
    $insert->execute([
        ':document_id' => $documentId,
        ':version_number' => $versionNumber,
        ':pdf_path' => $relativePath,
        ':file_name' => $fileName,
        ':file_size' => $fileSize !== false ? (int)$fileSize : null,
        ':checksum_sha256' => $checksum !== false ? $checksum : null,
        ':generated_by' => $generatedBy && $generatedBy > 0 ? $generatedBy : null,
    ]);
    $versionId = (int)$pdo->lastInsertId();

    $pdo->prepare('UPDATE documents SET pdf_path = :pdf_path, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
        ->execute([':pdf_path' => $relativePath, ':id' => $documentId]);

    logDocumentAudit($pdo, $documentId, 'document_pdf_version_created', 'Utworzono nowa wersje PDF', [
        'user_id' => $generatedBy,
        'new_value' => 'v' . $versionNumber,
        'metadata' => [
            'version_id' => $versionId,
            'version_number' => $versionNumber,
            'pdf_path' => $relativePath,
            'file_name' => $fileName,
            'file_size' => $fileSize !== false ? (int)$fileSize : null,
            'checksum_sha256' => $checksum !== false ? $checksum : null,
        ],
    ]);

    return [
        'id' => $versionId,
        'document_id' => $documentId,
        'version_number' => $versionNumber,
        'pdf_path' => $relativePath,
        'file_name' => $fileName,
        'file_size' => $fileSize !== false ? (int)$fileSize : null,
        'checksum_sha256' => $checksum !== false ? $checksum : null,
        'is_current' => 1,
    ];
}

function getCurrentDocumentPdfVersion(PDO $pdo, int $documentId): ?array
{
    if ($documentId <= 0) {
        return null;
    }
    ensureDocumentPdfVersionsTable($pdo);
    $stmt = $pdo->prepare('SELECT * FROM document_pdf_versions WHERE document_id = :document_id AND is_current = 1 ORDER BY version_number DESC, id DESC LIMIT 1');
    $stmt->execute([':document_id' => $documentId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function listDocumentPdfVersions(PDO $pdo, int $documentId): array
{
    if ($documentId <= 0) {
        return [];
    }
    ensureDocumentPdfVersionsTable($pdo);
    $stmt = $pdo->prepare("SELECT v.*, u.login, u.imie, u.nazwisko
        FROM document_pdf_versions v
        LEFT JOIN uzytkownicy u ON u.id = v.generated_by
        WHERE v.document_id = :document_id
        ORDER BY v.version_number DESC, v.id DESC");
    $stmt->execute([':document_id' => $documentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
