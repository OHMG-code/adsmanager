<?php
declare(strict_types=1);

/**
 * Map incoming legacy data to CompanyWriter input.
 * Pure mapping only: no DB calls, no side effects.
 */

function fromClientPost(array $post): array
{
    $output = [];

    $name = pickFirstTrimmed($post, ['nazwa_firmy', 'nazwa']);
    addIfNotNull($output, 'name_full', $name);
    addIfNotNull($output, 'nip', pickTrimmed($post, 'nip'));
    addIfNotNull($output, 'regon', pickTrimmed($post, 'regon'));
    addIfNotNull($output, 'address_text', pickTrimmed($post, 'adres'));
    addIfNotNull($output, 'city', pickFirstTrimmed($post, ['miasto', 'miejscowosc']));
    addIfNotNull($output, 'wojewodztwo', pickTrimmed($post, 'wojewodztwo'));

    return $output;
}

function fromLeadConversion(array $leadRow, array $post): array
{
    $output = [];

    $name = pickFirstTrimmed($post, ['nazwa_firmy', 'nazwa']);
    if ($name === null) {
        $name = pickFirstTrimmed($leadRow, ['nazwa_firmy', 'nazwa']);
    }
    addIfNotNull($output, 'name_full', $name);

    $nip = pickTrimmed($post, 'nip');
    if ($nip === null) {
        $nip = pickTrimmed($leadRow, 'nip');
    }
    addIfNotNull($output, 'nip', $nip);

    $regon = pickTrimmed($post, 'regon');
    if ($regon === null) {
        $regon = pickTrimmed($leadRow, 'regon');
    }
    addIfNotNull($output, 'regon', $regon);

    $address = pickTrimmed($post, 'adres');
    if ($address === null) {
        $address = pickTrimmed($leadRow, 'adres');
    }
    addIfNotNull($output, 'address_text', $address);

    $city = pickTrimmed($post, 'miejscowosc');
    if ($city === null) {
        $city = pickTrimmed($leadRow, 'miejscowosc');
    }
    addIfNotNull($output, 'city', $city);

    $woj = pickTrimmed($post, 'wojewodztwo');
    if ($woj === null) {
        $woj = pickTrimmed($leadRow, 'wojewodztwo');
    }
    addIfNotNull($output, 'wojewodztwo', $woj);

    return $output;
}

function fromCsvRow(array $row): array
{
    $output = [];

    $name = pickFirstTrimmed($row, ['nazwa_firmy', 'nazwa']);
    addIfNotNull($output, 'name_full', $name);
    addIfNotNull($output, 'nip', pickTrimmed($row, 'nip'));
    addIfNotNull($output, 'regon', pickTrimmed($row, 'regon'));
    addIfNotNull($output, 'address_text', pickTrimmed($row, 'adres'));
    addIfNotNull($output, 'city', pickTrimmed($row, 'miejscowosc'));
    addIfNotNull($output, 'wojewodztwo', pickTrimmed($row, 'wojewodztwo'));

    return $output;
}

function pickTrimmed(array $source, string $key): ?string
{
    if (!array_key_exists($key, $source)) {
        return null;
    }
    $value = $source[$key];
    if ($value === null) {
        return null;
    }
    $text = trim((string)$value);
    return $text === '' ? null : $text;
}

function pickFirstTrimmed(array $source, array $keys): ?string
{
    foreach ($keys as $key) {
        $value = pickTrimmed($source, $key);
        if ($value !== null) {
            return $value;
        }
    }
    return null;
}

function addIfNotNull(array &$output, string $key, ?string $value): void
{
    if ($value === null) {
        return;
    }
    $output[$key] = $value;
}
