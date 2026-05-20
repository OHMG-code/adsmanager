<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/document_numbering.php';
require_once __DIR__ . '/includes/document_audit.php';
require_once __DIR__ . '/includes/document_locks.php';

ensureDocumentOrderDetailsTable($pdo);
ensureDocumentAnnexDetailsTable($pdo);
ensureDocumentAcceptanceTables($pdo);
ensureDocumentAuditLogTable($pdo);

$documentId = (int)($_POST['document_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $documentId <= 0 || !isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo 'Niepoprawne żądanie.';
    exit;
}

try {
    $pdo->beginTransaction();

    $lockSql = isSqliteDriver($pdo)
        ? 'SELECT * FROM documents WHERE id = :id LIMIT 1'
        : 'SELECT * FROM documents WHERE id = :id LIMIT 1 FOR UPDATE';
    $stmt = $pdo->prepare($lockSql);
    $stmt->execute([':id' => $documentId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $stmt->closeCursor();

    if (!$document) {
        throw new RuntimeException('Nie znaleziono dokumentu.');
    }
    assertDocumentNotClosed($pdo, $documentId, (string)$document['status'], 'recreate_after_rejection');
    if ((string)$document['status'] !== 'sent') {
        throw new RuntimeException('Nową wersję po odrzuceniu można utworzyć tylko dla dokumentu wysłanego.');
    }

    $stmt = $pdo->prepare("SELECT action FROM document_acceptance_log WHERE document_id = :document_id ORDER BY created_at DESC, id DESC LIMIT 1");
    $stmt->execute([':document_id' => $documentId]);
    $lastAction = (string)($stmt->fetchColumn() ?: '');
    if ($lastAction !== 'rejected') {
        throw new RuntimeException('Ostatnie zdarzenie klienta nie jest odrzuceniem dokumentu.');
    }

    $newNumber = generateDocumentNumber($pdo, (string)$document['document_type']);
    $insert = $pdo->prepare("INSERT INTO documents
        (document_type, document_number, related_document_id, client_id, campaign_id, company_profile_id, issue_date, valid_from, valid_to,
         status, title, net_value, vat_rate, vat_value, gross_value, currency, pdf_path, created_by, notes)
        VALUES
        (:document_type, :document_number, :related_document_id, :client_id, :campaign_id, :company_profile_id, CURDATE(), :valid_from, :valid_to,
         'draft', :title, :net_value, :vat_rate, :vat_value, :gross_value, :currency, NULL, :created_by, :notes)");
    $insert->execute([
        ':document_type' => (string)$document['document_type'],
        ':document_number' => $newNumber,
        ':related_document_id' => $document['related_document_id'] !== null ? (int)$document['related_document_id'] : null,
        ':client_id' => $document['client_id'] !== null ? (int)$document['client_id'] : null,
        ':campaign_id' => $document['campaign_id'] !== null ? (int)$document['campaign_id'] : null,
        ':company_profile_id' => $document['company_profile_id'] !== null ? (int)$document['company_profile_id'] : null,
        ':valid_from' => $document['valid_from'] ?: null,
        ':valid_to' => $document['valid_to'] ?: null,
        ':title' => (string)($document['title'] ?? ''),
        ':net_value' => (float)$document['net_value'],
        ':vat_rate' => (float)$document['vat_rate'],
        ':vat_value' => (float)$document['vat_value'],
        ':gross_value' => (float)$document['gross_value'],
        ':currency' => (string)$document['currency'],
        ':created_by' => (int)($_SESSION['user_id'] ?? 0) ?: null,
        ':notes' => (string)($document['notes'] ?? ''),
    ]);
    $newId = (int)$pdo->lastInsertId();

    if ((string)$document['document_type'] === 'order') {
        $stmt = $pdo->prepare('SELECT * FROM document_order_details WHERE document_id = :document_id LIMIT 1');
        $stmt->execute([':document_id' => $documentId]);
        $details = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($details) {
            $copy = $pdo->prepare("INSERT INTO document_order_details
                (document_id, spot_source, material_deadline, spot_length_seconds, emission_count, technical_notes)
                VALUES (:document_id, :spot_source, :material_deadline, :spot_length_seconds, :emission_count, :technical_notes)");
            $copy->execute([
                ':document_id' => $newId,
                ':spot_source' => (string)$details['spot_source'],
                ':material_deadline' => $details['material_deadline'] ?: null,
                ':spot_length_seconds' => (int)$details['spot_length_seconds'],
                ':emission_count' => (int)$details['emission_count'],
                ':technical_notes' => (string)($details['technical_notes'] ?? ''),
            ]);
        }
    } elseif ((string)$document['document_type'] === 'annex') {
        $stmt = $pdo->prepare('SELECT * FROM document_annex_details WHERE document_id = :document_id LIMIT 1');
        $stmt->execute([':document_id' => $documentId]);
        $details = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($details) {
            $copy = $pdo->prepare("INSERT INTO document_annex_details
                (document_id, base_document_id, change_description, old_valid_from, old_valid_to, new_valid_from, new_valid_to, old_net_value, old_gross_value, new_net_value, new_gross_value)
                VALUES (:document_id, :base_document_id, :change_description, :old_valid_from, :old_valid_to, :new_valid_from, :new_valid_to, :old_net_value, :old_gross_value, :new_net_value, :new_gross_value)");
            $copy->execute([
                ':document_id' => $newId,
                ':base_document_id' => (int)$details['base_document_id'],
                ':change_description' => (string)$details['change_description'],
                ':old_valid_from' => $details['old_valid_from'] ?: null,
                ':old_valid_to' => $details['old_valid_to'] ?: null,
                ':new_valid_from' => $details['new_valid_from'] ?: null,
                ':new_valid_to' => $details['new_valid_to'] ?: null,
                ':old_net_value' => (float)$details['old_net_value'],
                ':old_gross_value' => (float)$details['old_gross_value'],
                ':new_net_value' => (float)$details['new_net_value'],
                ':new_gross_value' => (float)$details['new_gross_value'],
            ]);
        }
    }

    logDocumentAudit($pdo, $documentId, 'document_recreated_after_rejection', 'Utworzono nową wersję dokumentu po odrzuceniu', [
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
        'new_value' => $newNumber,
        'metadata' => ['new_document_id' => $newId],
    ]);
    logDocumentAudit($pdo, $newId, 'document_recreated_after_rejection', 'Dokument utworzony jako nowa wersja po odrzuceniu', [
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
        'old_value' => (string)$document['document_number'],
        'metadata' => ['source_document_id' => $documentId],
    ]);

    $pdo->commit();
    header('Location: ' . BASE_URL . '/dokument_podglad.php?id=' . $newId . '&created=1');
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Location: ' . BASE_URL . '/dokument_podglad.php?id=' . $documentId . '&status_error=' . urlencode($e->getMessage()));
    exit;
}
