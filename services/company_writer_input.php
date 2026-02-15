<?php
declare(strict_types=1);

function companyWriterInputSchema(): array
{
    return [
        'name_full' => 'string|null',
        'name_short' => 'string|null',
        'nip' => 'string|null',
        'regon' => 'string|null',
        'krs' => 'string|null',
        'address_text' => 'string|null',
        'street' => 'string|null',
        'building_no' => 'string|null',
        'apartment_no' => 'string|null',
        'postal_code' => 'string|null',
        'city' => 'string|null',
        'gmina' => 'string|null',
        'powiat' => 'string|null',
        'wojewodztwo' => 'string|null',
        'country' => 'string|null',
    ];
}

function buildCompanyWriterInputFromLegacy(array $clientRow): array
{
    return [
        'name_full' => $clientRow['nazwa_firmy'] ?? null,
        'nip' => $clientRow['nip'] ?? null,
        'regon' => $clientRow['regon'] ?? null,
        'address_text' => $clientRow['adres'] ?? null,
        'city' => $clientRow['miejscowosc'] ?? null,
        'wojewodztwo' => $clientRow['wojewodztwo'] ?? null,
    ];
}
