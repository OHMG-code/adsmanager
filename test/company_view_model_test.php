<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/company_view_model.php';

function assertEqual($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL {$label}: expected=" . var_export($expected, true) . " actual=" . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

// 1) client has company_id and companies has full data
$client = [
    'nazwa_firmy' => 'Legacy Sp. z o.o.',
    'nip' => '1234567890',
    'regon' => '111111111',
    'status' => 'Nowy',
    'adres' => 'Legacy adres',
    'miejscowosc' => 'Legacy City',
    'wojewodztwo' => 'Legacy Woj',
];
$company = [
    'name_full' => 'Canonical Sp. z o.o.',
    'name_short' => 'Canonical',
    'nip' => '9999999999',
    'regon' => '222222222',
    'street' => 'Canonical Street',
    'building_no' => '10',
    'city' => 'Canon City',
    'wojewodztwo' => 'Canon Woj',
    'status' => 'AKTYWNY',
    'is_active' => 1,
];
$vm = buildCompanyViewModel($client, $company);
assertEqual('Canonical Sp. z o.o.', $vm['company_name_display'], 'case1 name');
assertEqual('9999999999', $vm['nip_display'], 'case1 nip');
assertEqual('222222222', $vm['regon_display'], 'case1 regon');
assertEqual('Canonical Street 10, Canon City, Canon Woj', $vm['address_display'], 'case1 address');
assertEqual('AKTYWNY', $vm['status_subject_display'], 'case1 subject status');

// 2) client has company_id but companies missing some data (fallback)
$companyPartial = [
    'name_full' => '',
    'name_short' => '',
    'nip' => '',
    'regon' => '333333333',
    'street' => '',
    'city' => '',
    'wojewodztwo' => '',
];
$vm = buildCompanyViewModel($client, $companyPartial);
assertEqual('Legacy Sp. z o.o.', $vm['company_name_display'], 'case2 name fallback');
assertEqual('1234567890', $vm['nip_display'], 'case2 nip fallback');
assertEqual('333333333', $vm['regon_display'], 'case2 regon from company');
assertEqual('Legacy adres, Legacy City, Legacy Woj', $vm['address_display'], 'case2 address fallback');

// 3) client without company_id (no company row)
$vm = buildCompanyViewModel($client, null);
assertEqual('Legacy Sp. z o.o.', $vm['company_name_display'], 'case3 name legacy');
assertEqual('1234567890', $vm['nip_display'], 'case3 nip legacy');
assertEqual('Legacy adres, Legacy City, Legacy Woj', $vm['address_display'], 'case3 address legacy');

echo "OK\n";
