<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/briefs.php';
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/communication_events.php';

function generateBriefPdfDocument(PDO $pdo, array $user, int $campaignId): array
{
    ensureLeadBriefsTable($pdo);
    ensureDocumentsTables($pdo);
    ensureKampanieOwnershipColumns($pdo);

    if ($campaignId <= 0) {
        return ['success' => false, 'error' => 'Brak kampanii briefu.'];
    }
    if (!canUserAccessKampania($pdo, $user, $campaignId)) {
        return ['success' => false, 'error' => 'Brak dostępu do kampanii.'];
    }

    $stmtCampaign = $pdo->prepare('SELECT * FROM kampanie WHERE id = :id LIMIT 1');
    $stmtCampaign->execute([':id' => $campaignId]);
    $campaign = $stmtCampaign->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$campaign) {
        return ['success' => false, 'error' => 'Nie znaleziono kampanii.'];
    }

    $brief = getBriefByCampaignId($pdo, $campaignId);
    if (!$brief) {
        return ['success' => false, 'error' => 'Kampania nie ma jeszcze briefu.'];
    }

    $clientId = resolveClientIdForCampaign($pdo, $campaignId);
    $client = null;
    if ($clientId > 0) {
        $stmtClient = $pdo->prepare('SELECT * FROM klienci WHERE id = :id LIMIT 1');
        $stmtClient->execute([':id' => $clientId]);
        $client = $stmtClient->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $config = fetchSystemConfigSafe($pdo);
    $prefix = trim((string)($config['documents_number_prefix'] ?? DEFAULT_DOC_PREFIX));
    if ($prefix === '') {
        $prefix = DEFAULT_DOC_PREFIX;
    }

    $docNumber = buildDocumentNumber($pdo, $prefix);
    $company = [
        'name' => trim((string)($config['company_name'] ?? '')),
        'address' => trim((string)($config['company_address'] ?? '')),
        'nip' => trim((string)($config['company_nip'] ?? '')),
        'email' => trim((string)($config['company_email'] ?? '')),
        'phone' => trim((string)($config['company_phone'] ?? '')),
    ];

    $data = [
        'company' => $company,
        'campaign' => $campaign,
        'client' => $client,
        'brief' => $brief,
        'docNumber' => $docNumber,
        'docDate' => date('d.m.Y'),
        'user' => $user,
        'userLabel' => getUserLabel($user),
        'briefStatusLabel' => briefStatusLabel((string)($brief['status'] ?? '')),
        'realizationStatusLabel' => campaignRealizationStatusLabel((string)($campaign['realization_status'] ?? '')),
    ];

    $templatePath = dirname(__DIR__, 2) . '/templates/docs/brief_kampanii.php';
    try {
        $pdfBinary = generateDocumentPdfBinary($templatePath, $data, 'Brief kampanii');
        [$storedFilename] = saveDocumentPdf($config, 'brief', $docNumber, $pdfBinary);
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }

    $originalFilename = 'Brief_' . $docNumber . '.pdf';
    $sha = hash('sha256', $pdfBinary);

    try {
        $stmt = $pdo->prepare("INSERT INTO dokumenty
            (doc_type, doc_number, client_id, kampania_id, created_by_user_id, stored_filename, original_filename, sha256)
            VALUES (:type, :num, :client_id, :kampania_id, :user_id, :stored, :original, :sha)");
        $stmt->execute([
            ':type' => 'brief',
            ':num' => $docNumber,
            ':client_id' => $clientId > 0 ? $clientId : null,
            ':kampania_id' => $campaignId,
            ':user_id' => (int)($user['id'] ?? 0),
            ':stored' => $storedFilename,
            ':original' => $originalFilename,
            ':sha' => $sha,
        ]);
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Nie udało się zapisać dokumentu briefu.'];
    }
    $docId = (int)$pdo->lastInsertId();

    $idempotencyKey = communicationBuildIdempotencyKey('brief_pdf_generated', [
        $campaignId,
        $docId > 0 ? $docId : hash('sha256', $docNumber . '|' . $campaignId),
    ]);
    communicationLogEvent($pdo, [
        'event_type' => 'brief_pdf_generated',
        'idempotency_key' => $idempotencyKey,
        'direction' => 'system',
        'status' => 'logged',
        'subject' => 'Wygenerowano PDF briefu kampanii #' . $campaignId,
        'body' => $originalFilename,
        'meta_json' => [
            'doc_id' => $docId,
            'doc_number' => $docNumber,
            'filename' => $originalFilename,
        ],
        'client_id' => $clientId > 0 ? $clientId : null,
        'campaign_id' => $campaignId,
        'brief_id' => (int)($brief['id'] ?? 0) > 0 ? (int)$brief['id'] : null,
        'created_by_user_id' => (int)($user['id'] ?? 0) > 0 ? (int)$user['id'] : null,
    ]);

    return [
        'success' => true,
        'doc_id' => $docId,
        'doc_number' => $docNumber,
        'original_filename' => $originalFilename,
    ];
}
