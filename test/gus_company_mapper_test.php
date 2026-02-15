<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/gus_company_mapper.php';

$tests = 0;
$failures = 0;

function assertSame($expected, $actual, string $label): void
{
    global $tests, $failures;
    $tests++;
    if ($expected !== $actual) {
        $failures++;
        echo "FAIL: {$label}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n\n";
    }
}

function assertTrue(bool $condition, string $label): void
{
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures++;
        echo "FAIL: {$label}\n";
    }
}

function assertNoEmptyStrings(array $data, string $label): void
{
    foreach ($data as $key => $value) {
        if ($value === '' || $value === null) {
            assertTrue(false, $label . ' (' . $key . ')');
            return;
        }
    }
    assertTrue(true, $label);
}

$fullPayload = [
    'NazwaPelna' => ' ACME Sp. z o.o. ',
    'NazwaSkrocona' => 'ACME',
    'Nip' => '521-079-3216',
    'Regon' => '123456785',
    'Krs' => '0000123456',
    'StatusPodmiotu' => 'Aktywny',
    'Ulica' => 'Kwiatowa',
    'NrNieruchomosci' => '12',
    'NrLokalu' => '3',
    'KodPocztowy' => '00950',
    'Miejscowosc' => 'Warszawa',
    'Gmina' => 'Srodmiescie',
    'Powiat' => 'Warszawa',
    'Wojewodztwo' => 'Mazowieckie',
    'Kraj' => 'PL',
];

$mappedFull = GusCompanyMapper::fromGusData($fullPayload);
assertSame('ACME Sp. z o.o.', $mappedFull['name_full'] ?? null, 'map name_full');
assertSame('ACME', $mappedFull['name_short'] ?? null, 'map name_short');
assertSame('5210793216', $mappedFull['nip'] ?? null, 'map nip digits');
assertSame('123456785', $mappedFull['regon'] ?? null, 'map regon digits');
assertSame('0000123456', $mappedFull['krs'] ?? null, 'map krs digits');
assertSame('Aktywny', $mappedFull['status'] ?? null, 'map status');
assertSame('Kwiatowa', $mappedFull['street'] ?? null, 'map street');
assertSame('12', $mappedFull['building_no'] ?? null, 'map building_no');
assertSame('3', $mappedFull['apartment_no'] ?? null, 'map apartment_no');
assertSame('00-950', $mappedFull['postal_code'] ?? null, 'map postal_code');
assertSame('Warszawa', $mappedFull['city'] ?? null, 'map city');
assertSame('Mazowieckie', $mappedFull['wojewodztwo'] ?? null, 'map wojewodztwo');
assertNoEmptyStrings($mappedFull, 'no empty strings in full payload');

$minimalPayload = [
    'nazwa' => 'Firma XYZ',
    'nip' => ' 856-734-6210 ',
    'status_podmiotu' => 'Wykreslony',
    'AdresSiedziby' => 'ul. Lipowa 5A, 12-345 Krakow',
    'NazwaSkrocona' => '',
];

$mappedMinimal = GusCompanyMapper::fromGusData($minimalPayload);
assertSame('Firma XYZ', $mappedMinimal['name_full'] ?? null, 'minimal name_full');
assertSame('8567346210', $mappedMinimal['nip'] ?? null, 'minimal nip digits');
assertSame('Wykreslony', $mappedMinimal['status'] ?? null, 'minimal status');
assertSame('ul. Lipowa 5A, 12-345 Krakow', $mappedMinimal['street'] ?? null, 'minimal address fallback');
assertTrue(!array_key_exists('name_short', $mappedMinimal), 'minimal missing name_short');
assertNoEmptyStrings($mappedMinimal, 'no empty strings in minimal payload');

if ($failures > 0) {
    echo "Tests: {$tests}, Failures: {$failures}\n";
    exit(1);
}

echo "Tests: {$tests}, Failures: {$failures}\n";
