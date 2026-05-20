<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/emisje_helpers.php';
require_once __DIR__ . '/document_audit.php';

function documentCampaignSyncResolvePdfPath(?string $pdfPath): ?string
{
    $pdfPath = trim((string)$pdfPath);
    if ($pdfPath === '') {
        return null;
    }

    $publicDir = dirname(__DIR__);
    $candidates = [];
    if ($pdfPath[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $pdfPath)) {
        $candidates[] = $pdfPath;
    } else {
        $candidates[] = $publicDir . '/' . ltrim($pdfPath, '/\\');
        $candidates[] = dirname($publicDir) . '/' . ltrim($pdfPath, '/\\');
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function documentCampaignSyncLog(
    PDO $pdo,
    int $documentId,
    ?int $campaignId,
    string $action,
    ?string $oldCampaignStatus,
    ?string $newCampaignStatus,
    ?string $oldSpotStatus,
    ?string $newSpotStatus,
    string $message
): void {
    if (!$pdo->inTransaction()) {
        ensureDocumentCampaignSyncLogTable($pdo);
    }

    $stmt = $pdo->prepare("INSERT INTO document_campaign_sync_log
        (document_id, campaign_id, action, old_campaign_status, new_campaign_status, old_spot_status, new_spot_status, message, created_at)
        VALUES (:document_id, :campaign_id, :action, :old_campaign_status, :new_campaign_status, :old_spot_status, :new_spot_status, :message, CURRENT_TIMESTAMP)");
    $stmt->execute([
        ':document_id' => $documentId,
        ':campaign_id' => $campaignId,
        ':action' => substr($action, 0, 40),
        ':old_campaign_status' => $oldCampaignStatus !== null ? substr($oldCampaignStatus, 0, 80) : null,
        ':new_campaign_status' => $newCampaignStatus !== null ? substr($newCampaignStatus, 0, 80) : null,
        ':old_spot_status' => $oldSpotStatus !== null ? substr($oldSpotStatus, 0, 255) : null,
        ':new_spot_status' => $newSpotStatus !== null ? substr($newSpotStatus, 0, 255) : null,
        ':message' => $message,
    ]);

    $auditType = null;
    if ($action === 'activate') {
        $auditType = 'campaign_sync_activate';
    } elseif ($action === 'deactivate') {
        $auditType = 'campaign_sync_deactivate';
    } elseif ($action === 'error') {
        $auditType = 'campaign_sync_error';
    } elseif (in_array($action, ['missing_campaign', 'missing_pdf', 'missing_spot', 'missing_audio'], true)) {
        $auditType = 'campaign_sync_warning';
    }

    if ($auditType !== null && $documentId > 0) {
        logDocumentAudit($pdo, $documentId, $auditType, $message, [
            'old_value' => $oldCampaignStatus,
            'new_value' => $newCampaignStatus,
            'metadata' => [
                'campaign_id' => $campaignId,
                'sync_action' => $action,
                'old_spot_status' => $oldSpotStatus,
                'new_spot_status' => $newSpotStatus,
            ],
        ]);
    }
}

function documentCampaignSyncLoadDocument(PDO $pdo, int $documentId): ?array
{
    if (!$pdo->inTransaction()) {
        ensureSalesDocumentsTable($pdo);
    }
    $stmt = $pdo->prepare('SELECT id, document_type, document_number, related_document_id, campaign_id, status, pdf_path FROM documents WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $documentId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function documentCampaignSyncResolveCampaignId(PDO $pdo, array $document): int
{
    $campaignId = (int)($document['campaign_id'] ?? 0);
    if ($campaignId > 0) {
        return $campaignId;
    }

    $relatedId = (int)($document['related_document_id'] ?? 0);
    if ($relatedId <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare('SELECT campaign_id FROM documents WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $relatedId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function documentCampaignSyncSpotStatusSummary(PDO $pdo, int $campaignId): string
{
    if ($campaignId <= 0 || !tableExists($pdo, 'spoty')) {
        return '';
    }

    $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(status, ''), 'brak') AS status_label, COUNT(*) AS count_rows
        FROM spoty
        WHERE kampania_id = :campaign_id
        GROUP BY COALESCE(NULLIF(status, ''), 'brak')
        ORDER BY status_label");
    $stmt->execute([':campaign_id' => $campaignId]);
    $parts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $parts[] = (string)$row['status_label'] . ':' . (int)$row['count_rows'];
    }
    return implode(', ', $parts);
}

function documentCampaignSyncCountActiveEmissions(PDO $pdo, int $campaignId): int
{
    if ($campaignId <= 0 || !tableExists($pdo, 'spoty') || !tableExists($pdo, 'spoty_emisje')) {
        return 0;
    }

    $spotCols = getTableColumns($pdo, 'spoty');
    $activeCondition = hasColumn($spotCols, 'aktywny') ? 'AND COALESCE(s.aktywny, 1) = 1' : '';
    $statusCondition = hasColumn($spotCols, 'status') ? "AND COALESCE(s.status, 'Aktywny') <> 'Nieaktywny'" : '';
    $stmt = $pdo->prepare("SELECT COUNT(*)
        FROM spoty_emisje se
        INNER JOIN spoty s ON s.id = se.spot_id
        WHERE s.kampania_id = :campaign_id
          {$statusCondition}
          {$activeCondition}");
    $stmt->execute([':campaign_id' => $campaignId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function documentCampaignSyncCampaignState(PDO $pdo, int $campaignId): array
{
    if (!$pdo->inTransaction()) {
        ensureKampanieOwnershipColumns($pdo);
        ensureSpotColumns($pdo);
        ensureSpotAudioFilesTable($pdo);
    }

    $stmt = $pdo->prepare('SELECT id, klient_nazwa, status, realization_status, data_start, data_koniec FROM kampanie WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $campaignId]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$campaign) {
        return [
            'campaign' => null,
            'spot_count' => 0,
            'active_spot_count' => 0,
            'audio_ready_count' => 0,
            'active_emission_count' => 0,
            'missing_spot' => true,
            'missing_audio' => true,
            'emission_blocked' => true,
        ];
    }

    $spotCols = getTableColumns($pdo, 'spoty');
    $activeSelect = hasColumn($spotCols, 'aktywny') ? 'COALESCE(aktywny, 1) AS active_flag' : '1 AS active_flag';
    $statusSelect = hasColumn($spotCols, 'status') ? "COALESCE(status, 'Aktywny') AS status" : "'Aktywny' AS status";
    $stmt = $pdo->prepare("SELECT id, {$activeSelect}, {$statusSelect} FROM spoty WHERE kampania_id = :campaign_id");
    $stmt->execute([':campaign_id' => $campaignId]);
    $spots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $spotCount = count($spots);
    $activeSpotCount = 0;
    $audioReadyCount = 0;
    foreach ($spots as $spot) {
        if ((int)$spot['active_flag'] === 1 && (string)$spot['status'] !== 'Nieaktywny') {
            $activeSpotCount++;
        }
        if (hasApprovedAudio($pdo, (int)$spot['id'])) {
            $audioReadyCount++;
        }
    }

    return [
        'campaign' => $campaign,
        'spot_count' => $spotCount,
        'active_spot_count' => $activeSpotCount,
        'audio_ready_count' => $audioReadyCount,
        'active_emission_count' => documentCampaignSyncCountActiveEmissions($pdo, $campaignId),
        'missing_spot' => $spotCount === 0,
        'missing_audio' => $spotCount === 0 || $audioReadyCount < $spotCount,
        'emission_blocked' => (string)($campaign['status'] ?? '') === 'Anulowana' || $activeSpotCount === 0,
    ];
}

function getDocumentCampaignSummary(PDO $pdo, int $documentId): array
{
    $document = documentCampaignSyncLoadDocument($pdo, $documentId);
    if (!$document) {
        return ['document' => null, 'campaign_id' => 0] + documentCampaignSyncCampaignState($pdo, 0);
    }

    $campaignId = documentCampaignSyncResolveCampaignId($pdo, $document);
    if ($campaignId <= 0) {
        return ['document' => $document, 'campaign_id' => 0] + documentCampaignSyncCampaignState($pdo, 0);
    }

    return ['document' => $document, 'campaign_id' => $campaignId] + documentCampaignSyncCampaignState($pdo, $campaignId);
}

function syncCampaignWithDocument(PDO $pdo, int $documentId): array
{
    if ($documentId <= 0) {
        throw new InvalidArgumentException('Niepoprawny identyfikator dokumentu.');
    }

    if (!$pdo->inTransaction()) {
        ensureDocumentCampaignSyncLogTable($pdo);
        ensureDocumentAuditLogTable($pdo);
        ensureKampanieOwnershipColumns($pdo);
        ensureSpotColumns($pdo);
        ensureSpotAudioFilesTable($pdo);
    }

    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $document = documentCampaignSyncLoadDocument($pdo, $documentId);
        if (!$document) {
            documentCampaignSyncLog($pdo, $documentId, null, 'error', null, null, null, null, 'Dokument nie istnieje.');
            if ($startedTransaction) {
                $pdo->commit();
            }
            return ['ok' => false, 'action' => 'error', 'message' => 'Dokument nie istnieje.'];
        }

        if (!in_array((string)$document['document_type'], ['order', 'annex'], true)) {
            if ($startedTransaction) {
                $pdo->commit();
            }
            return ['ok' => true, 'action' => 'noop', 'message' => 'Typ dokumentu nie steruje emisja.'];
        }

        $status = (string)($document['status'] ?? '');
        if (!in_array($status, ['accepted', 'cancelled'], true)) {
            if ($startedTransaction) {
                $pdo->commit();
            }
            return ['ok' => true, 'action' => 'noop', 'message' => 'Status dokumentu nie wymaga synchronizacji.'];
        }

        $campaignId = documentCampaignSyncResolveCampaignId($pdo, $document);
        if ($campaignId <= 0) {
            documentCampaignSyncLog($pdo, $documentId, null, 'missing_campaign', null, null, null, null, 'Dokument nie ma powiazanej kampanii.');
            if ($startedTransaction) {
                $pdo->commit();
            }
            return ['ok' => false, 'action' => 'missing_campaign', 'message' => 'Dokument nie ma powiazanej kampanii.'];
        }

        $lockSql = isSqliteDriver($pdo)
            ? 'SELECT id, status, realization_status FROM kampanie WHERE id = :id LIMIT 1'
            : 'SELECT id, status, realization_status FROM kampanie WHERE id = :id LIMIT 1 FOR UPDATE';
        $stmt = $pdo->prepare($lockSql);
        $stmt->execute([':id' => $campaignId]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$campaign) {
            documentCampaignSyncLog($pdo, $documentId, $campaignId, 'missing_campaign', null, null, null, null, 'Powiazana kampania nie istnieje.');
            if ($startedTransaction) {
                $pdo->commit();
            }
            return ['ok' => false, 'action' => 'missing_campaign', 'message' => 'Powiazana kampania nie istnieje.'];
        }

        $oldCampaignStatus = trim((string)($campaign['status'] ?? ''));
        $oldCampaignCombined = trim($oldCampaignStatus . ' / ' . (string)($campaign['realization_status'] ?? ''), ' /');
        $oldSpotStatus = documentCampaignSyncSpotStatusSummary($pdo, $campaignId);

        if ($status === 'cancelled') {
            $pdo->prepare("UPDATE kampanie SET status = 'Anulowana', realization_status = 'anulowana' WHERE id = :id")
                ->execute([':id' => $campaignId]);
            $spotCols = getTableColumns($pdo, 'spoty');
            $spotUpdates = [];
            if (hasColumn($spotCols, 'aktywny')) {
                $spotUpdates[] = 'aktywny = 0';
            }
            if (hasColumn($spotCols, 'status')) {
                $spotUpdates[] = "status = 'Nieaktywny'";
            }
            if ($spotUpdates) {
                $pdo->prepare('UPDATE spoty SET ' . implode(', ', $spotUpdates) . ' WHERE kampania_id = :campaign_id')
                    ->execute([':campaign_id' => $campaignId]);
            }

            documentCampaignSyncLog($pdo, $documentId, $campaignId, 'deactivate', $oldCampaignCombined, 'Anulowana / anulowana', $oldSpotStatus, 'Nieaktywny', 'Dokument anulowany - emisja zablokowana.');
            if ($startedTransaction) {
                $pdo->commit();
            }
            return ['ok' => true, 'action' => 'deactivate', 'message' => 'Emisja zostala zablokowana.'];
        }

        if (!documentCampaignSyncResolvePdfPath($document['pdf_path'] ?? null)) {
            documentCampaignSyncLog($pdo, $documentId, $campaignId, 'missing_pdf', $oldCampaignCombined, null, $oldSpotStatus, null, 'Brak pliku PDF dokumentu - kampania nie zostala aktywowana.');
            if ($startedTransaction) {
                $pdo->commit();
            }
            return ['ok' => false, 'action' => 'missing_pdf', 'message' => 'Brak pliku PDF dokumentu.'];
        }

        $state = documentCampaignSyncCampaignState($pdo, $campaignId);
        if (!empty($state['missing_spot'])) {
            documentCampaignSyncLog($pdo, $documentId, $campaignId, 'missing_spot', $oldCampaignCombined, null, $oldSpotStatus, null, 'Kampania nie ma przypisanego spotu - emisja nie zostala aktywowana.');
            if ($startedTransaction) {
                $pdo->commit();
            }
            return ['ok' => false, 'action' => 'missing_spot', 'message' => 'Kampania nie ma przypisanego spotu.'];
        }
        if (!empty($state['missing_audio'])) {
            documentCampaignSyncLog($pdo, $documentId, $campaignId, 'missing_audio', $oldCampaignCombined, null, $oldSpotStatus, null, 'Kampania nie ma zaakceptowanego audio dla kazdego spotu - emisja nie zostala aktywowana.');
            if ($startedTransaction) {
                $pdo->commit();
            }
            return ['ok' => false, 'action' => 'missing_audio', 'message' => 'Kampania nie ma zaakceptowanego audio dla kazdego spotu.'];
        }

        $pdo->prepare("UPDATE kampanie SET status = 'W realizacji', realization_status = 'do_emisji' WHERE id = :id")
            ->execute([':id' => $campaignId]);
        $spotCols = getTableColumns($pdo, 'spoty');
        $spotUpdates = [];
        if (hasColumn($spotCols, 'aktywny')) {
            $spotUpdates[] = 'aktywny = 1';
        }
        if (hasColumn($spotCols, 'status')) {
            $spotUpdates[] = "status = 'Aktywny'";
        }
        if ($spotUpdates) {
            $pdo->prepare('UPDATE spoty SET ' . implode(', ', $spotUpdates) . ' WHERE kampania_id = :campaign_id')
                ->execute([':campaign_id' => $campaignId]);
        }

        documentCampaignSyncLog($pdo, $documentId, $campaignId, 'activate', $oldCampaignCombined, 'W realizacji / do_emisji', $oldSpotStatus, 'Aktywny', 'Dokument zaakceptowany - emisja aktywowana.');
        if ($startedTransaction) {
            $pdo->commit();
        }
        return ['ok' => true, 'action' => 'activate', 'message' => 'Emisja zostala aktywowana.'];
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!$pdo->inTransaction()) {
            try {
                documentCampaignSyncLog($pdo, $documentId, null, 'error', null, null, null, null, $e->getMessage());
            } catch (Throwable $logError) {
                error_log('document_campaign_sync: cannot log error: ' . $logError->getMessage());
            }
        }
        throw $e;
    }
}
