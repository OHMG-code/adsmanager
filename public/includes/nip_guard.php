<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/gus_validation.php';

function normalizeNipGuard(?string $value): string
{
    return normalizeNip((string)$value);
}

function isNormalizedNipGuardValid(string $nip): bool
{
    return preg_match('/^[0-9]{10}$/', $nip) === 1;
}

function isRawNipGuardInputProvided($value): bool
{
    return trim((string)$value) !== '';
}

function buildNipGuardNormalizedSql(string $column): string
{
    return "REPLACE(REPLACE(REPLACE(REPLACE(TRIM($column), '-', ''), ' ', ''), '.', ''), '/', '')";
}

function findLeadByNipGuard(PDO $pdo, string $nip, ?int $excludeLeadId = null): ?array
{
    if (!isNormalizedNipGuardValid($nip) || !tableExists($pdo, 'leady')) {
        return null;
    }
    $leadColumns = getTableColumns($pdo, 'leady');
    if (!hasColumn($leadColumns, 'nip')) {
        return null;
    }

    $where = [buildNipGuardNormalizedSql('nip') . ' = :nip'];
    $params = [':nip' => $nip];
    if ($excludeLeadId !== null && $excludeLeadId > 0) {
        $where[] = 'id <> :exclude_id';
        $params[':exclude_id'] = $excludeLeadId;
    }

    $sql = 'SELECT id, nazwa_firmy, status, client_id
            FROM leady
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY id ASC
            LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return $row ?: null;
}

function findClientByNipGuard(PDO $pdo, string $nip, ?int $excludeClientId = null): ?array
{
    if (!isNormalizedNipGuardValid($nip) || !tableExists($pdo, 'klienci')) {
        return null;
    }
    $clientColumns = getTableColumns($pdo, 'klienci');
    if (!hasColumn($clientColumns, 'nip')) {
        return null;
    }

    $where = [buildNipGuardNormalizedSql('nip') . ' = :nip'];
    $params = [':nip' => $nip];
    if ($excludeClientId !== null && $excludeClientId > 0) {
        $where[] = 'id <> :exclude_id';
        $params[':exclude_id'] = $excludeClientId;
    }

    $sql = 'SELECT id, nazwa_firmy, status
            FROM klienci
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY id ASC
            LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return $row ?: null;
}
