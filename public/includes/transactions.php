<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';

function transactionValueFromCampaignRow(array $campaign): array
{
    // One consistent priority for sales value:
    // 1) wartosc_netto
    // 2) razem_netto
    // 3) netto_spoty + netto_dodatki
    $valueNetto = (float)($campaign['wartosc_netto'] ?? 0);
    if ($valueNetto <= 0) {
        $valueNetto = (float)($campaign['razem_netto'] ?? 0);
    }
    if ($valueNetto <= 0) {
        $valueNetto = (float)($campaign['netto_spoty'] ?? 0) + (float)($campaign['netto_dodatki'] ?? 0);
    }
    if ($valueNetto < 0) {
        $valueNetto = 0.0;
    }

    $valueBrutto = (float)($campaign['razem_brutto'] ?? 0);
    if ($valueBrutto < 0) {
        $valueBrutto = 0.0;
    }

    return [
        'value_netto' => round($valueNetto, 2),
        'value_brutto' => round($valueBrutto, 2),
    ];
}

function buildTransactionPayloadFromCampaign(PDO $pdo, int $campaignId): array
{
    ensureKampanieOwnershipColumns($pdo);
    ensureKampanieSalesValueColumn($pdo);
    ensureTransactionsTable($pdo);

    if ($campaignId <= 0 || !tableExists($pdo, 'kampanie')) {
        throw new InvalidArgumentException('Nieprawidlowa kampania dla transakcji.');
    }

    $kampCols = getTableColumns($pdo, 'kampanie');
    $select = ['id'];
    $optional = [
        'klient_id',
        'source_lead_id',
        'owner_user_id',
        'wartosc_netto',
        'razem_netto',
        'razem_brutto',
        'netto_spoty',
        'netto_dodatki',
    ];
    foreach ($optional as $col) {
        if (hasColumn($kampCols, $col)) {
            $select[] = $col;
        }
    }

    $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM kampanie WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $campaignId]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$campaign) {
        throw new RuntimeException('Nie znaleziono kampanii dla transakcji.');
    }

    $values = transactionValueFromCampaignRow($campaign);
    return [
        'campaign_id' => $campaignId,
        'client_id' => (int)($campaign['klient_id'] ?? 0) > 0 ? (int)$campaign['klient_id'] : null,
        'source_lead_id' => (int)($campaign['source_lead_id'] ?? 0) > 0 ? (int)$campaign['source_lead_id'] : null,
        'owner_user_id' => (int)($campaign['owner_user_id'] ?? 0) > 0 ? (int)$campaign['owner_user_id'] : null,
        'value_netto' => (float)$values['value_netto'],
        'value_brutto' => (float)$values['value_brutto'],
        'status' => 'won',
        'won_at' => date('Y-m-d H:i:s'),
    ];
}

function upsertTransactionForCampaign(PDO $pdo, int $campaignId, array $context = []): int
{
    ensureTransactionsTable($pdo);
    $payload = buildTransactionPayloadFromCampaign($pdo, $campaignId);

    if (!empty($context['client_id']) && (int)$context['client_id'] > 0) {
        $payload['client_id'] = (int)$context['client_id'];
    }
    if (!empty($context['source_lead_id']) && (int)$context['source_lead_id'] > 0) {
        $payload['source_lead_id'] = (int)$context['source_lead_id'];
    }
    if (!empty($context['owner_user_id']) && (int)$context['owner_user_id'] > 0) {
        $payload['owner_user_id'] = (int)$context['owner_user_id'];
    }
    if (!empty($context['won_at'])) {
        $payload['won_at'] = (string)$context['won_at'];
    }

    $stmt = $pdo->prepare("INSERT INTO transactions
        (campaign_id, client_id, source_lead_id, owner_user_id, value_netto, value_brutto, status, won_at)
        VALUES (:campaign_id, :client_id, :source_lead_id, :owner_user_id, :value_netto, :value_brutto, :status, :won_at)
        ON DUPLICATE KEY UPDATE
            client_id = VALUES(client_id),
            source_lead_id = VALUES(source_lead_id),
            owner_user_id = VALUES(owner_user_id),
            value_netto = VALUES(value_netto),
            value_brutto = VALUES(value_brutto),
            status = VALUES(status),
            won_at = COALESCE(transactions.won_at, VALUES(won_at)),
            updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([
        ':campaign_id' => $payload['campaign_id'],
        ':client_id' => $payload['client_id'],
        ':source_lead_id' => $payload['source_lead_id'],
        ':owner_user_id' => $payload['owner_user_id'],
        ':value_netto' => $payload['value_netto'],
        ':value_brutto' => $payload['value_brutto'],
        ':status' => $payload['status'],
        ':won_at' => $payload['won_at'],
    ]);

    $id = (int)$pdo->lastInsertId();
    if ($id > 0) {
        return $id;
    }
    $current = getTransactionByCampaignId($pdo, $campaignId);
    return (int)($current['id'] ?? 0);
}

function getTransactionByCampaignId(PDO $pdo, int $campaignId): ?array
{
    ensureTransactionsTable($pdo);
    if ($campaignId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM transactions WHERE campaign_id = :campaign_id LIMIT 1');
    $stmt->execute([':campaign_id' => $campaignId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
