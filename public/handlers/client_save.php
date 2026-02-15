<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db_schema.php';
require_once __DIR__ . '/../../services/company_writer.php';
require_once __DIR__ . '/../../services/company_input_mapper.php';
require_once __DIR__ . '/../../services/legacy_write_guard.php';

function saveClientFromPost(PDO $pdo, array $post, int $actorUserId, array $options = []): array
{
    $result = [
        'ok' => false,
        'client_id' => null,
        'company_result' => null,
        'errors' => [],
        'warnings' => [],
    ];

    $clientColumns = getTableColumns($pdo, 'klienci');
    if (!$clientColumns) {
        $result['errors'][] = 'client_table_missing';
        return $result;
    }

    $legacyWrites = assertNoFirmFieldWrites($post);
    $postForCrm = $post;
    if ($legacyWrites) {
        foreach ($legacyWrites as $legacyKey) {
            unset($postForCrm[$legacyKey]);
        }
        $warningMsg = 'legacy_firm_fields_blocked:' . implode(',', $legacyWrites);
        error_log('client_save guard: ' . $warningMsg . ' client_id=' . ($post['id'] ?? 'new') . ' actor=' . $actorUserId);
        $result['warnings'][] = $warningMsg;
    }

    $mode = $options['mode'] ?? (isset($post['id']) ? 'update' : 'create');
    $crmData = buildClientCrmDataFromPost($postForCrm, $options, $clientColumns, $mode);

    $columnMeta = [];
    $insertResult = ['placeholders_used' => []];
    if ($mode !== 'update') {
        $columnMeta = getClientColumnMeta($pdo);
    }

    if ($mode === 'update') {
        $clientId = isset($post['id']) ? (int)$post['id'] : 0;
        if ($clientId <= 0) {
            $result['errors'][] = 'invalid_client_id';
            return $result;
        }
        if ($crmData) {
            updateClientCrm($pdo, $clientId, $crmData, $clientColumns);
        }
    } else {
        $insertResult = insertClientCrm($pdo, $crmData, $clientColumns, $columnMeta);
        $clientId = (int)($insertResult['id'] ?? 0);
        if ($clientId <= 0) {
            $result['errors'][] = 'client_insert_failed';
            return $result;
        }
    }

    $companyInput = fromClientPost($post);
    $writer = new CompanyWriter($pdo);
    $companyResult = $writer->upsertForClient($clientId, $companyInput, $actorUserId);
    $result['company_result'] = $companyResult;

    if (!$companyResult['ok']) {
        $result['errors'] = array_merge($result['errors'], $companyResult['errors'] ?? ['company_upsert_failed']);
        $result['warnings'] = array_merge($result['warnings'], $companyResult['warnings'] ?? []);
        error_log('client_save: CompanyWriter failed for client_id=' . $clientId . ' errors=' . implode(',', $result['errors']));
        if (!empty($insertResult['placeholders_used'])) {
            deleteClientById($pdo, $clientId);
            $result['warnings'][] = 'client_insert_rolled_back_placeholder';
        }
        $result['client_id'] = $clientId;
        return $result;
    }

    $result['ok'] = true;
    $result['client_id'] = $clientId;
    return $result;
}

function saveClientFromLeadConversion(PDO $pdo, array $leadRow, array $post, int $actorUserId, array $options = []): array
{
    $result = [
        'ok' => false,
        'client_id' => null,
        'company_result' => null,
        'errors' => [],
        'warnings' => [],
    ];

    $clientColumns = getTableColumns($pdo, 'klienci');
    if (!$clientColumns) {
        $result['errors'][] = 'client_table_missing';
        return $result;
    }

    $crmData = buildClientCrmDataFromLead($leadRow, $options, $clientColumns);
    $columnMeta = getClientColumnMeta($pdo);
    $insertResult = insertClientCrm($pdo, $crmData, $clientColumns, $columnMeta);
    $clientId = (int)($insertResult['id'] ?? 0);
    if ($clientId <= 0) {
        $result['errors'][] = 'client_insert_failed';
        return $result;
    }

    $result['client_id'] = $clientId;

    if (!empty($options['skip_company'])) {
        $result['ok'] = true;
        return $result;
    }

    $companyInput = fromLeadConversion($leadRow, $post);
    $writer = new CompanyWriter($pdo);
    $companyResult = $writer->upsertForClient($clientId, $companyInput, $actorUserId);
    $result['company_result'] = $companyResult;

    if (!$companyResult['ok']) {
        $result['errors'] = array_merge($result['errors'], $companyResult['errors'] ?? ['company_upsert_failed']);
        $result['warnings'] = array_merge($result['warnings'], $companyResult['warnings'] ?? []);
        error_log('client_save: CompanyWriter failed for lead conversion client_id=' . $clientId . ' errors=' . implode(',', $result['errors']));
        if (!empty($insertResult['placeholders_used'])) {
            deleteClientById($pdo, $clientId);
            $result['warnings'][] = 'client_insert_rolled_back_placeholder';
        }
        return $result;
    }

    $result['ok'] = true;
    return $result;
}

function buildClientCrmDataFromPost(array $post, array $options, array $clientColumns, string $mode): array
{
    $data = [];
    $fields = [
        'telefon',
        'email',
        'branza',
        'status',
        'ostatni_kontakt',
        'notatka',
        'potencjal',
        'przypomnienie',
        'kontakt_imie_nazwisko',
        'kontakt_stanowisko',
        'kontakt_telefon',
        'kontakt_email',
        'kontakt_preferencja',
        'assigned_user_id',
        'owner_user_id',
        'source_lead_id',
    ];

    foreach ($fields as $field) {
        $value = array_key_exists($field, $options) ? $options[$field] : ($post[$field] ?? null);
        $normalized = normalizeCrmValue($value, $field);
        if ($normalized === null) {
            continue;
        }
        if (!hasColumn($clientColumns, $field)) {
            continue;
        }
        $data[$field] = $normalized;
    }

    if ($mode === 'create' && hasColumn($clientColumns, 'data_dodania')) {
        $data['data_dodania'] = date('Y-m-d H:i:s');
    }

    return $data;
}

function buildClientCrmDataFromLead(array $leadRow, array $options, array $clientColumns): array
{
    $data = [];

    $map = [
        'telefon' => $leadRow['telefon'] ?? null,
        'email' => $leadRow['email'] ?? null,
        'notatka' => $leadRow['notatki'] ?? ($leadRow['notatka'] ?? null),
        'status' => $options['status'] ?? 'Nowy',
        'owner_user_id' => $leadRow['owner_user_id'] ?? null,
        'assigned_user_id' => $leadRow['assigned_user_id'] ?? ($leadRow['owner_user_id'] ?? null),
        'source_lead_id' => $options['source_lead_id'] ?? ($leadRow['id'] ?? null),
    ];

    foreach ($map as $field => $value) {
        $normalized = normalizeCrmValue($value, $field);
        if ($normalized === null) {
            continue;
        }
        if (!hasColumn($clientColumns, $field)) {
            continue;
        }
        $data[$field] = $normalized;
    }

    if (hasColumn($clientColumns, 'data_dodania')) {
        $data['data_dodania'] = date('Y-m-d H:i:s');
    }

    return $data;
}

function normalizeCrmValue($value, string $field)
{
    if ($value === null) {
        return null;
    }
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
    }
    if (in_array($field, ['assigned_user_id', 'owner_user_id', 'source_lead_id'], true)) {
        $intValue = (int)$value;
        return $intValue > 0 ? $intValue : null;
    }
    return $value;
}

function insertClientCrm(PDO $pdo, array $data, array $clientColumns, array $columnMeta): array
{
    if (!$data) {
        $data = [];
    }
    $filtered = [];
    foreach ($data as $col => $value) {
        if (!hasColumn($clientColumns, $col)) {
            continue;
        }
        $filtered[$col] = $value;
    }

    $placeholdersUsed = [];
    $filtered = applyLegacyNotNullPlaceholders($filtered, $columnMeta, $placeholdersUsed);

    $columns = array_keys($filtered);
    $placeholders = array_map(static fn(string $col): string => ':' . $col, $columns);
    $sql = 'INSERT INTO klienci';
    if ($columns) {
        $sql .= ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    } else {
        $sql .= ' () VALUES ()';
    }

    $stmt = $pdo->prepare($sql);
    $params = [];
    foreach ($filtered as $col => $value) {
        $params[':' . $col] = $value;
    }
    $stmt->execute($params);

    return [
        'id' => (int)$pdo->lastInsertId(),
        'placeholders_used' => $placeholdersUsed,
    ];
}

function updateClientCrm(PDO $pdo, int $clientId, array $data, array $clientColumns): void
{
    $updates = [];
    $params = [':id' => $clientId];

    foreach ($data as $col => $value) {
        if (!hasColumn($clientColumns, $col)) {
            continue;
        }
        $updates[] = $col . ' = :' . $col;
        $params[':' . $col] = $value;
    }

    if (!$updates) {
        return;
    }

    $sql = 'UPDATE klienci SET ' . implode(', ', $updates) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function getClientColumnMeta(PDO $pdo): array
{
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM klienci');
    } catch (Throwable $e) {
        error_log('client_save: cannot read klienci columns: ' . $e->getMessage());
        return [];
    }
    $meta = [];
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['Field'])) {
                $meta[strtolower($row['Field'])] = $row;
            }
        }
    }
    return $meta;
}

function applyLegacyNotNullPlaceholders(array $data, array $columnMeta, array &$placeholdersUsed): array
{
    $rules = [
        'nip' => '0000000000',
        'nazwa_firmy' => '(legacy)',
        'regon' => '',
        'adres' => '',
    ];

    foreach ($rules as $field => $placeholder) {
        if (array_key_exists($field, $data)) {
            continue;
        }
        $meta = $columnMeta[strtolower($field)] ?? null;
        if (!$meta) {
            continue;
        }
        $isNullable = strtoupper((string)($meta['Null'] ?? '')) === 'YES';
        $hasDefault = array_key_exists('Default', $meta) && $meta['Default'] !== null;
        if ($isNullable || $hasDefault) {
            continue;
        }
        $data[$field] = $placeholder;
        $placeholdersUsed[$field] = $placeholder;
    }

    return $data;
}

function deleteClientById(PDO $pdo, int $clientId): void
{
    if ($clientId <= 0) {
        return;
    }
    try {
        $stmt = $pdo->prepare('DELETE FROM klienci WHERE id = :id');
        $stmt->execute([':id' => $clientId]);
    } catch (Throwable $e) {
        error_log('client_save: failed to rollback client insert id=' . $clientId . ': ' . $e->getMessage());
    }
}
