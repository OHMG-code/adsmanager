<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/briefs.php';
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/communication_events.php';

function normalizeTimelineDate(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $ts);
}

function timelineEvent(
    string $typ,
    string $tytul,
    ?string $czas,
    string $zrodlo,
    ?string $link = null,
    array $meta = []
): ?array {
    $normalizedTime = normalizeTimelineDate($czas);
    if ($normalizedTime === null) {
        return null;
    }

    return [
        'typ' => $typ,
        'tytul' => $tytul,
        'czas' => $normalizedTime,
        'zrodlo' => $zrodlo,
        'link' => $link,
        'meta' => $meta,
    ];
}

function timelineCommunicationEventTitle(string $eventType, string $direction, string $status, string $subject): string
{
    $subject = trim($subject);
    if ($subject !== '') {
        return $subject;
    }
    $map = [
        'offer_sent' => 'Wysłano ofertę',
        'brief_link_sent' => 'Wysłano link do briefu',
        'brief_received' => 'Otrzymano brief od klienta',
        'brief_pdf_generated' => 'Wygenerowano PDF briefu',
        'production_started' => 'Rozpoczęto produkcję',
        'audio_dispatched' => 'Wysłano wersje audio',
        'audio_final_selected' => 'Wybrano finalną wersję audio',
        'campaign_ready_for_emission' => 'Kampania gotowa do emisji',
    ];
    $base = $map[$eventType] ?? ('Zdarzenie komunikacji: ' . $eventType);
    if ($status === 'error') {
        return $base . ' (błąd)';
    }
    if ($status === 'skipped_duplicate') {
        return $base . ' (duplikat pominięty)';
    }
    if ($direction === 'internal') {
        return $base . ' (wewnętrzne)';
    }
    return $base;
}

function buildCampaignTimelineEvents(PDO $pdo, int $campaignId, array $options = []): array
{
    $events = [];
    if ($campaignId <= 0 || !tableExists($pdo, 'kampanie')) {
        return $events;
    }

    ensureKampanieOwnershipColumns($pdo);
    ensureLeadBriefsTable($pdo);
    ensureDocumentsTables($pdo);
    ensureSpotAudioDispatchesTable($pdo);
    ensureSpotAudioDispatchItemsTable($pdo);
    ensureSpotAudioFilesTable($pdo);
    ensureTransactionsTable($pdo);
    ensureMailHistoryTable($pdo);

    $kampCols = getTableColumns($pdo, 'kampanie');
    $select = ['id'];
    foreach (['created_at', 'status', 'realization_status', 'source_lead_id', 'klient_id'] as $col) {
        if (hasColumn($kampCols, $col)) {
            $select[] = $col;
        }
    }

    $stmtCampaign = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM kampanie WHERE id = :id LIMIT 1');
    $stmtCampaign->execute([':id' => $campaignId]);
    $campaign = $stmtCampaign->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$campaign) {
        return $events;
    }

    $campaignCreatedAt = (string)($campaign['created_at'] ?? '');
    $campaignStatus = trim((string)($campaign['status'] ?? ''));
    $realizationStatus = trim((string)($campaign['realization_status'] ?? ''));
    $baseLink = 'kampania_podglad.php?id=' . $campaignId;

    $createdEvent = timelineEvent(
        'campaign.created',
        'Utworzono kampanię',
        $campaignCreatedAt,
        'kampanie',
        $baseLink,
        ['campaign_id' => $campaignId]
    );
    if ($createdEvent) {
        $events[] = $createdEvent;
    }

    if ($campaignStatus !== '') {
        $statusEvent = timelineEvent(
            'campaign.status',
            'Status kampanii: ' . $campaignStatus,
            $campaignCreatedAt,
            'kampanie',
            $baseLink,
            ['status' => $campaignStatus]
        );
        if ($statusEvent) {
            $events[] = $statusEvent;
        }
    }

    if ($realizationStatus !== '') {
        $statusLabel = campaignRealizationStatusLabel($realizationStatus);
        $realizationEvent = timelineEvent(
            'campaign.realization_status',
            'Etap realizacji: ' . $statusLabel,
            $campaignCreatedAt,
            'kampanie',
            $baseLink,
            ['realization_status' => $realizationStatus]
        );
        if ($realizationEvent) {
            $events[] = $realizationEvent;
        }
    }

    if (tableExists($pdo, 'transactions')) {
        $stmtTx = $pdo->prepare('SELECT id, status, won_at, value_netto, value_brutto, created_at FROM transactions WHERE campaign_id = :campaign_id ORDER BY COALESCE(won_at, created_at) DESC LIMIT 5');
        $stmtTx->execute([':campaign_id' => $campaignId]);
        while ($tx = $stmtTx->fetch(PDO::FETCH_ASSOC)) {
            $txStatus = trim((string)($tx['status'] ?? ''));
            $txTitle = 'Zapisano transakcję sprzedażową';
            if ($txStatus !== '') {
                $txTitle .= ' (' . $txStatus . ')';
            }
            $txEvent = timelineEvent(
                'transaction.won',
                $txTitle,
                (string)($tx['won_at'] ?? $tx['created_at'] ?? ''),
                'transactions',
                $baseLink,
                [
                    'transaction_id' => (int)($tx['id'] ?? 0),
                    'status' => $txStatus,
                    'value_netto' => (float)($tx['value_netto'] ?? 0),
                    'value_brutto' => (float)($tx['value_brutto'] ?? 0),
                ]
            );
            if ($txEvent) {
                $events[] = $txEvent;
            }
        }
    }

    if (tableExists($pdo, 'dokumenty')) {
        $stmtDocs = $pdo->prepare('SELECT id, doc_type, doc_number, original_filename, created_at FROM dokumenty WHERE kampania_id = :campaign_id ORDER BY created_at DESC LIMIT 30');
        $stmtDocs->execute([':campaign_id' => $campaignId]);
        while ($doc = $stmtDocs->fetch(PDO::FETCH_ASSOC)) {
            $docType = trim((string)($doc['doc_type'] ?? ''));
            $docTypeNorm = strtolower($docType);
            if ($docTypeNorm === 'brief') {
                $title = 'Wygenerowano PDF briefu';
                $type = 'document.brief_pdf';
            } elseif ($docTypeNorm === 'umowa' || $docTypeNorm === 'aneks') {
                $title = 'Wygenerowano dokument: ' . strtoupper($docTypeNorm);
                $type = 'document.contract';
            } else {
                $title = 'Wygenerowano dokument: ' . ($docType !== '' ? $docType : 'plik PDF');
                $type = 'document.generated';
            }

            $docEvent = timelineEvent(
                $type,
                $title,
                (string)($doc['created_at'] ?? ''),
                'dokumenty',
                'docs/download.php?id=' . (int)($doc['id'] ?? 0) . '&inline=1',
                [
                    'document_id' => (int)($doc['id'] ?? 0),
                    'doc_type' => $docType,
                    'doc_number' => (string)($doc['doc_number'] ?? ''),
                    'filename' => (string)($doc['original_filename'] ?? ''),
                ]
            );
            if ($docEvent) {
                $events[] = $docEvent;
            }
        }
    }

    if (tableExists($pdo, 'historia_maili_ofert')) {
        $stmtOffers = $pdo->prepare('SELECT id, to_email, subject, status, error_msg, sent_at FROM historia_maili_ofert WHERE kampania_id = :campaign_id ORDER BY sent_at DESC LIMIT 30');
        $stmtOffers->execute([':campaign_id' => $campaignId]);
        while ($offer = $stmtOffers->fetch(PDO::FETCH_ASSOC)) {
            $status = strtolower(trim((string)($offer['status'] ?? '')));
            $sentOk = $status === 'sent';
            $offerEvent = timelineEvent(
                $sentOk ? 'offer.sent' : 'offer.error',
                $sentOk ? 'Wysłano ofertę do klienta' : 'Błąd wysyłki oferty',
                (string)($offer['sent_at'] ?? ''),
                'historia_maili_ofert',
                'wyslij_oferte.php?id=' . $campaignId,
                [
                    'offer_mail_id' => (int)($offer['id'] ?? 0),
                    'to_email' => (string)($offer['to_email'] ?? ''),
                    'subject' => (string)($offer['subject'] ?? ''),
                    'status' => (string)($offer['status'] ?? ''),
                    'error_msg' => (string)($offer['error_msg'] ?? ''),
                ]
            );
            if ($offerEvent) {
                $events[] = $offerEvent;
            }
        }
    }

    if (tableExists($pdo, 'spot_audio_dispatches')) {
        $stmtDispatch = $pdo->prepare("SELECT d.id, d.spot_id, d.dispatched_by_user_id, d.dispatched_at, d.channel, d.note,
                   COUNT(i.id) AS versions_count
            FROM spot_audio_dispatches d
            LEFT JOIN spot_audio_dispatch_items i ON i.dispatch_id = d.id
            WHERE d.campaign_id = :campaign_id
            GROUP BY d.id, d.spot_id, d.dispatched_by_user_id, d.dispatched_at, d.channel, d.note
            ORDER BY d.dispatched_at DESC
            LIMIT 30");
        $stmtDispatch->execute([':campaign_id' => $campaignId]);
        while ($dispatch = $stmtDispatch->fetch(PDO::FETCH_ASSOC)) {
            $dispatchEvent = timelineEvent(
                'audio.dispatched',
                'Wysłano wersje audio do klienta',
                (string)($dispatch['dispatched_at'] ?? ''),
                'spot_audio_dispatches',
                'kampania_audio.php?campaign_id=' . $campaignId,
                [
                    'group' => 'audio',
                    'dispatch_id' => (int)($dispatch['id'] ?? 0),
                    'spot_id' => (int)($dispatch['spot_id'] ?? 0),
                    'versions_count' => (int)($dispatch['versions_count'] ?? 0),
                    'channel' => (string)($dispatch['channel'] ?? ''),
                    'note' => (string)($dispatch['note'] ?? ''),
                ]
            );
            if ($dispatchEvent) {
                $events[] = $dispatchEvent;
            }
        }
    }

    if (tableExists($pdo, 'spot_audio_files') && tableExists($pdo, 'spoty')) {
        $acceptedValues = acceptedAudioStatusSqlValues();
        $in = implode(',', array_fill(0, count($acceptedValues), '?'));
        $sqlFinal = "SELECT saf.id, saf.spot_id, saf.filename, saf.production_status, saf.updated_at, saf.created_at
            FROM spot_audio_files saf
            INNER JOIN spoty s ON s.id = saf.spot_id
            WHERE s.kampania_id = ?
              AND saf.is_final = 1
              AND LOWER(TRIM(COALESCE(saf.production_status, ''))) IN ($in)
            ORDER BY COALESCE(saf.updated_at, saf.created_at) DESC
            LIMIT 10";
        $stmtFinal = $pdo->prepare($sqlFinal);
        $stmtFinal->execute(array_merge([$campaignId], $acceptedValues));
        while ($audio = $stmtFinal->fetch(PDO::FETCH_ASSOC)) {
            $audioEvent = timelineEvent(
                'audio.final_selected',
                'Wybrano finalną wersję audio',
                (string)($audio['updated_at'] ?? $audio['created_at'] ?? ''),
                'spot_audio_files',
                'edytuj_spot.php?id=' . (int)($audio['spot_id'] ?? 0),
                [
                    'group' => 'audio',
                    'audio_file_id' => (int)($audio['id'] ?? 0),
                    'spot_id' => (int)($audio['spot_id'] ?? 0),
                    'filename' => (string)($audio['filename'] ?? ''),
                    'production_status' => normalizeAudioProductionStatus((string)($audio['production_status'] ?? '')),
                ]
            );
            if ($audioEvent) {
                $events[] = $audioEvent;
            }
        }
    }

    if (tableExists($pdo, 'communication_events')) {
        $stmtComm = $pdo->prepare("SELECT
                id, event_type, direction, status, recipient, subject, body, meta_json, created_at
            FROM communication_events
            WHERE campaign_id = :campaign_id
            ORDER BY created_at DESC
            LIMIT 80");
        $stmtComm->execute([':campaign_id' => $campaignId]);
        while ($comm = $stmtComm->fetch(PDO::FETCH_ASSOC)) {
            $meta = [];
            $metaRaw = (string)($comm['meta_json'] ?? '');
            if ($metaRaw !== '') {
                $decoded = json_decode($metaRaw, true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }
            $meta['communication_event_id'] = (int)($comm['id'] ?? 0);
            $meta['direction'] = (string)($comm['direction'] ?? 'system');
            $meta['status'] = (string)($comm['status'] ?? 'logged');
            $meta['recipient'] = (string)($comm['recipient'] ?? '');
            $meta['group'] = 'communication';
            $title = timelineCommunicationEventTitle(
                (string)($comm['event_type'] ?? 'event'),
                (string)($comm['direction'] ?? 'system'),
                (string)($comm['status'] ?? 'logged'),
                (string)($comm['subject'] ?? '')
            );

            $commEvent = timelineEvent(
                'comm.' . (string)($comm['event_type'] ?? 'event'),
                $title,
                (string)($comm['created_at'] ?? ''),
                'communication_events',
                $baseLink,
                $meta
            );
            if ($commEvent) {
                $events[] = $commEvent;
            }
        }
    }

    usort($events, static function (array $a, array $b): int {
        $aTs = strtotime((string)($a['czas'] ?? '')) ?: 0;
        $bTs = strtotime((string)($b['czas'] ?? '')) ?: 0;
        if ($aTs === $bTs) {
            return strcmp((string)($b['typ'] ?? ''), (string)($a['typ'] ?? ''));
        }
        return $bTs <=> $aTs;
    });

    $limit = isset($options['limit']) ? (int)$options['limit'] : 0;
    if ($limit > 0) {
        return array_slice($events, 0, $limit);
    }

    return $events;
}
