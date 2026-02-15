<?php
declare(strict_types=1);

function preferValue(array $post, string $key, $companyVal, $legacyVal): string
{
    if (array_key_exists($key, $post)) {
        return (string)$post[$key];
    }
    $companyVal = trim((string)$companyVal);
    if ($companyVal !== '') {
        return $companyVal;
    }
    $legacyVal = trim((string)$legacyVal);
    return $legacyVal !== '' ? $legacyVal : '';
}

function buildAddressLine(?string $street, ?string $building, ?string $apartment): string
{
    $street = trim((string)$street);
    $building = trim((string)$building);
    $apartment = trim((string)$apartment);
    if ($street === '' && $building === '' && $apartment === '') {
        return '';
    }
    $line = $street;
    if ($building !== '') {
        $line = trim($line . ' ' . $building);
    }
    if ($apartment !== '') {
        $line .= '/' . $apartment;
    }
    return trim($line);
}

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

$client = [
    'nazwa_firmy' => 'Legacy Sp. z o.o.',
    'nip' => '1111111111',
    'regon' => '123456789',
    'adres' => 'Stara 1',
    'miejscowosc' => 'Stare Miasto',
];

$company = [
    'name_full' => 'Canonical SA',
    'nip' => '2222222222',
    'regon' => '987654321',
    'street' => 'Nowa',
    'building_no' => '5',
    'apartment_no' => '2',
    'city' => 'Nowe Miasto',
];

$post = [];

$name = preferValue($post, 'nazwa_firmy', $company['name_full'], $client['nazwa_firmy']);
$nip = preferValue($post, 'nip', $company['nip'], $client['nip']);
$regon = preferValue($post, 'regon', $company['regon'], $client['regon']);
$address = preferValue($post, 'adres', buildAddressLine($company['street'], $company['building_no'], $company['apartment_no']), $client['adres']);
$city = preferValue($post, 'miasto', $company['city'], $client['miejscowosc']);

assertSameVal('Canonical SA', $name, 'company name wins when linked');
assertSameVal('2222222222', $nip, 'company nip wins when linked');
assertSameVal('987654321', $regon, 'company regon wins when linked');
assertSameVal('Nowa 5/2', $address, 'company address line composed');
assertSameVal('Nowe Miasto', $city, 'company city wins');

$noCompany = [];
$nameLegacy = preferValue($post, 'nazwa_firmy', $noCompany['name_full'] ?? null, $client['nazwa_firmy']);
$nipLegacy = preferValue($post, 'nip', $noCompany['nip'] ?? null, $client['nip']);
$cityLegacy = preferValue($post, 'miasto', $noCompany['city'] ?? null, $client['miejscowosc']);

assertSameVal('Legacy Sp. z o.o.', $nameLegacy, 'falls back to client when no company');
assertSameVal('1111111111', $nipLegacy, 'nip falls back to client when no company');
assertSameVal('Stare Miasto', $cityLegacy, 'city falls back to client when no company');

if ($failures > 0) {
    echo "Tests: {$tests}, Failures: {$failures}\n";
    exit(1);
}

echo "Tests: {$tests}, Failures: {$failures}\n";
