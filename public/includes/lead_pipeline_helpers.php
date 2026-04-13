<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';

function leadStatusDefinitions(): array
{
    return [
        'nowy' => 'Nowy',
        'w_kontakcie' => 'W kontakcie',
        'analiza_potrzeb' => 'Analiza potrzeb',
        'oferta_przygotowywana' => 'Oferta przygotowywana',
        'oferta_wyslana' => 'Oferta wysłana',
        'oferta_zaakceptowana' => 'Oferta zaakceptowana',
        'odrzucony' => 'Przegrana',
        'zakonczony' => 'Zamrożony',
        'skonwertowany' => 'Klient',
    ];
}

function leadStatusOptions(bool $includeConverted = true): array
{
    $defs = leadStatusDefinitions();
    if ($includeConverted) {
        return $defs;
    }
    unset($defs['skonwertowany']);
    return $defs;
}

function leadPipelineStatuses(): array
{
    return array_keys(leadStatusOptions(false));
}

function leadStandardStatuses(): array
{
    return leadPipelineStatuses();
}

function leadStatusMapping(): array
{
    return [
        'nowy' => 'nowy',
        'nowy lead' => 'nowy',
        'lead pozyskany' => 'nowy',

        'w kontakcie' => 'w_kontakcie',
        'kontakt' => 'w_kontakcie',
        'kontakt podjety' => 'w_kontakcie',
        'kontakt nawiazany' => 'w_kontakcie',
        'potrzeba potwierdzona' => 'w_kontakcie',
        'negocjacje' => 'w_kontakcie',

        'analiza potrzeb' => 'analiza_potrzeb',
        'analiza_potrzeb' => 'analiza_potrzeb',

        'oferta przygotowywana' => 'oferta_przygotowywana',
        'oferta_przygotowywana' => 'oferta_przygotowywana',

        'oferta wyslana' => 'oferta_wyslana',
        'wyslano oferte' => 'oferta_wyslana',

        'oferta zaakceptowana' => 'oferta_zaakceptowana',
        'oferta_zaakceptowana' => 'oferta_zaakceptowana',
        'wygrana' => 'oferta_zaakceptowana',
        'media plan zaakceptowany' => 'oferta_zaakceptowana',

        'odrzucony' => 'odrzucony',
        'przegrana' => 'odrzucony',

        'zakonczony' => 'zakonczony',
        'zamrozony' => 'zakonczony',
        'wstrzymany' => 'zakonczony',
        'closed' => 'zakonczony',
        'frozen' => 'zakonczony',

        'skonwertowany' => 'skonwertowany',
        'skonwertowany klient' => 'skonwertowany',
        'klient' => 'skonwertowany',
    ];
}

function leadStatusKey(?string $status): string
{
    $status = trim((string)$status);
    if ($status === '') {
        return '';
    }
    $status = str_replace(['_', '-'], ' ', $status);
    $status = preg_replace('/\s+/', ' ', $status) ?? $status;
    if (function_exists('mb_strtolower')) {
        $status = mb_strtolower($status, 'UTF-8');
    } else {
        $status = strtolower($status);
    }
    return strtr($status, [
        'ą' => 'a',
        'ć' => 'c',
        'ę' => 'e',
        'ł' => 'l',
        'ń' => 'n',
        'ó' => 'o',
        'ś' => 's',
        'ż' => 'z',
        'ź' => 'z',
    ]);
}

function normalizeLeadStatus(?string $status): string
{
    $raw = trim((string)$status);
    if ($raw === '') {
        return 'nowy';
    }

    $defs = leadStatusDefinitions();
    if (array_key_exists($raw, $defs)) {
        return $raw;
    }

    $mapping = leadStatusMapping();
    $key = leadStatusKey($raw);
    return $mapping[$key] ?? 'nowy';
}

function leadStatusLabel(?string $status): string
{
    $defs = leadStatusDefinitions();
    $normalized = normalizeLeadStatus($status);
    return $defs[$normalized] ?? $defs['nowy'];
}

function isLeadStatusConverted(?string $status): bool
{
    return normalizeLeadStatus($status) === 'skonwertowany';
}

function isLeadStatusWon(?string $status): bool
{
    $normalized = normalizeLeadStatus($status);
    return in_array($normalized, ['oferta_zaakceptowana', 'skonwertowany'], true);
}

function isLeadStatusLost(?string $status): bool
{
    return in_array(normalizeLeadStatus($status), ['odrzucony', 'zakonczony'], true);
}

function leadFollowupExcludedStatuses(): array
{
    return ['oferta_zaakceptowana', 'odrzucony', 'zakonczony', 'skonwertowany'];
}

function leadWonStatuses(): array
{
    return ['oferta_zaakceptowana', 'skonwertowany'];
}

function leadLostStatuses(): array
{
    return ['odrzucony', 'zakonczony'];
}

function leadOfferStatuses(): array
{
    return ['oferta_wyslana'];
}

function leadPriorityOrder(): array
{
    return [
        'Pilny' => 1,
        'Wysoki' => 2,
        'Średni' => 3,
        'Niski' => 4,
    ];
}

function leadPriorityRank(?string $priority): int
{
    $priority = trim((string)$priority);
    $map = leadPriorityOrder();
    return $map[$priority] ?? 99;
}

function formatNextActionRelative(?string $value): string
{
    if (!$value) {
        return 'brak';
    }
    try {
        $dt = new DateTime($value);
    } catch (Throwable $e) {
        return $value;
    }
    $today = new DateTime('today');
    $tomorrow = (clone $today)->modify('+1 day');
    $diffDays = (int)$today->diff($dt)->format('%r%a');

    if ($dt < $today) {
        $abs = abs($diffDays);
        return $abs === 1 ? 'przeterminowane (1 dzien)' : 'przeterminowane (' . $abs . ' dni)';
    }
    if ($dt >= $today && $dt < $tomorrow) {
        return 'dzisiaj';
    }
    if ($diffDays === 1) {
        return 'jutro';
    }
    return '+' . $diffDays . ' dni';
}

function buildActiveLeadConditions(array $columns, string $alias = ''): array
{
    $conditions = [];
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    if (hasColumn($columns, 'client_id')) {
        $conditions[] = $prefix . 'client_id IS NULL';
    }
    if (hasColumn($columns, 'converted_at')) {
        $conditions[] = $prefix . 'converted_at IS NULL';
    }
    if (hasColumn($columns, 'status')) {
        $statusExpr = "LOWER(TRIM(COALESCE({$prefix}status, '')))";
        $conditions[] = $statusExpr . " NOT IN ('skonwertowany', 'skonwertowany klient', 'klient')";
    }
    return $conditions;
}
