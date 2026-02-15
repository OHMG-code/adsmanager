<?php
declare(strict_types=1);

function leadStandardStatuses(): array
{
    return [
        'Nowy',
        'Kontakt podjęty',
        'Potrzeba potwierdzona',
        'Oferta wysłana',
        'Negocjacje',
        'Wygrana',
        'Przegrana',
        'Zamrożony',
    ];
}

function leadStatusMapping(): array
{
    return [
        'nowy' => 'Nowy',
        'w_kontakcie' => 'Kontakt podjęty',
        'oferta_wyslana' => 'Oferta wysłana',
        'odrzucony' => 'Przegrana',
        'zakonczony' => 'Przegrana',
        'skonwertowany' => 'Wygrana',
        'w_realizacji' => 'Negocjacje',
    ];
}

function normalizeLeadStatus(?string $status): string
{
    $status = trim((string)$status);
    if ($status === '') {
        return 'Nowy';
    }
    $standard = leadStandardStatuses();
    if (in_array($status, $standard, true)) {
        return $status;
    }
    $mapping = leadStatusMapping();
    $lower = strtolower($status);
    return $mapping[$lower] ?? 'Nowy';
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
