<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/gus_service.php';

$tests = 0;
$failures = 0;

function assertSameVal($expected, $actual, string $label): void
{
    global $tests, $failures;
    $tests++;
    if ($expected !== $actual) {
        $failures++;
        echo "FAIL: {$label}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n\n";
    }
}

function assertHasKey(string $key, array $data, string $label): void
{
    global $tests, $failures;
    $tests++;
    if (!array_key_exists($key, $data)) {
        $failures++;
        echo "FAIL: {$label}\nMissing key: {$key}\n\n";
    }
}

$raw = [
    'Nazwa' => 'TEST SP Z O O',
    'Nip' => '5260250274',
    'Regon' => '000002217',
    'Krs' => '0000000000',
    'Ulica' => 'Swietokrzyska',
    'NrNieruchomosci' => '12',
    'NrLokalu' => '3',
    'KodPocztowy' => '00-916',
    'Miejscowosc' => 'Warszawa',
    'Wojewodztwo' => 'MAZOWIECKIE',
    'Powiat' => 'Warszawa',
    'Gmina' => 'Srodmiescie',
    'Poczta' => 'Warszawa',
    'PkdGlowny' => '8411Z',
];

$data = gusStandardizeCompany($raw);

// New API shape.
assertSameVal('TEST SP Z O O', $data['name'] ?? null, 'new shape name');
assertSameVal('Swietokrzyska 12 lok. 3', $data['address_street'] ?? null, 'new shape address_street');
assertSameVal('00-916', $data['address_postal'] ?? null, 'new shape address_postal');
assertSameVal('Warszawa', $data['address_city'] ?? null, 'new shape address_city');

// Legacy UI shape required by add lead/client forms.
assertSameVal('TEST SP Z O O', $data['nazwa'] ?? null, 'legacy nazwa');
assertSameVal('Swietokrzyska', $data['ulica'] ?? null, 'legacy ulica');
assertSameVal('12', $data['nr_nieruchomosci'] ?? null, 'legacy nr_nieruchomosci');
assertSameVal('3', $data['nr_lokalu'] ?? null, 'legacy nr_lokalu');
assertSameVal('00-916', $data['kod_pocztowy'] ?? null, 'legacy kod_pocztowy');
assertSameVal('Warszawa', $data['miejscowosc'] ?? null, 'legacy miejscowosc');
assertSameVal('MAZOWIECKIE', $data['wojewodztwo'] ?? null, 'legacy wojewodztwo');
assertSameVal('Warszawa', $data['powiat'] ?? null, 'legacy powiat');
assertSameVal('Srodmiescie', $data['gmina'] ?? null, 'legacy gmina');
assertSameVal('Warszawa', $data['poczta'] ?? null, 'legacy poczta');
assertSameVal('8411Z', $data['pkd_glowne'] ?? null, 'legacy pkd_glowne');

assertHasKey('nip', $data, 'common nip key exists');
assertHasKey('regon', $data, 'common regon key exists');
assertHasKey('krs', $data, 'common krs key exists');

if ($failures > 0) {
    echo "Tests: {$tests}, Failures: {$failures}\n";
    exit(1);
}

echo "Tests: {$tests}, Failures: {$failures}\n";
