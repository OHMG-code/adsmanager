<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/LeadFormService.php';
require_once __DIR__ . '/../services/InstallationUrl.php';

$tests = 0;
$failures = 0;

function lfAssert(bool $condition, string $label): void
{
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures++;
        echo "FAIL: {$label}\n";
    }
}

function lfPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE lead_form_sources (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        public_key TEXT NOT NULL,
        allowed_domains TEXT NULL,
        default_source TEXT NOT NULL,
        consent_required INTEGER NOT NULL DEFAULT 0,
        gus_lookup_enabled INTEGER NOT NULL DEFAULT 1,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE lead_form_field_mappings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_id INTEGER NOT NULL,
        external_field TEXT NOT NULL,
        crm_field TEXT NOT NULL,
        is_required INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE lead_form_submissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_id INTEGER NULL,
        raw_payload TEXT NULL,
        normalized_payload TEXT NULL,
        ip_address TEXT NULL,
        user_agent TEXT NULL,
        status TEXT NOT NULL DEFAULT 'received',
        error_message TEXT NULL,
        created_lead_id INTEGER NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE leady (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nazwa_firmy TEXT NOT NULL,
        nip TEXT NULL,
        telefon TEXT NULL,
        email TEXT NULL,
        zrodlo TEXT NOT NULL DEFAULT 'inne',
        status TEXT NOT NULL DEFAULT 'nowy',
        notatki TEXT NULL,
        external_source TEXT NULL,
        kontakt_imie_nazwisko TEXT NULL,
        kontakt_telefon TEXT NULL,
        kontakt_email TEXT NULL,
        kod_pocztowy TEXT NULL,
        miasto TEXT NULL,
        ulica TEXT NULL,
        nr_budynku TEXT NULL,
        nr_lokalu TEXT NULL,
        regon TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE leady_aktywnosci (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        lead_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        typ TEXT NOT NULL,
        opis TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->prepare('INSERT INTO lead_form_sources (name, public_key, allowed_domains, default_source, consent_required, gus_lookup_enabled, is_active) VALUES (?, ?, ?, ?, 0, 1, 1)')
        ->execute(['Form testowy', 'pub_form', json_encode(['example-form.test', 'www.example-form.test']), 'external_form_test']);
    foreach ([['name', 'name', 1], ['email', 'email', 1], ['phone', 'phone', 1], ['nip', 'nip', 0], ['message', 'message', 0]] as $row) {
        $pdo->prepare('INSERT INTO lead_form_field_mappings (source_id, external_field, crm_field, is_required) VALUES (1, ?, ?, ?)')
            ->execute($row);
    }
    return $pdo;
}

function lfServer(string $origin = 'https://www.example-form.test'): array
{
    return [
        'HTTP_ORIGIN' => $origin,
        'HTTP_REFERER' => $origin . '/reklama',
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_USER_AGENT' => 'test',
    ];
}

function lfPayload(array $extra = []): array
{
    return $extra + [
        'public_key' => 'pub_form',
        'name' => 'Jan Kowalski',
        'email' => 'jan@example.com',
        'phone' => '500111222',
        'message' => 'Prosze o kontakt',
    ];
}

$pdo = lfPdo();
$service = new LeadFormService($pdo);
$result = $service->submit(lfPayload(), lfServer());
lfAssert($result['success'] === true && $result['status'] === 'created', 'valid submission without NIP creates lead');
$lead = $pdo->query('SELECT * FROM leady ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
lfAssert($lead['external_source'] === 'external_form_test', 'default source saved on lead');

$newKey = LeadFormService::createUniquePublicKey($pdo);
lfAssert(str_starts_with($newKey, 'lf_') && strlen($newKey) > 20, 'creating form can generate public_key');
$anotherKey = LeadFormService::createUniquePublicKey($pdo);
lfAssert($newKey !== $anotherKey, 'generated public_key is unique');
$embed = LeadFormService::generateEmbedCode(['public_key' => $newKey, 'consent_required' => 1], '/api/public/lead-form-submit.php');
lfAssert(strpos($embed, $newKey) !== false, 'generated code contains public_key');
lfAssert(strpos($embed, '/api/public/lead-form-submit.php') !== false, 'generated code contains CRM endpoint');
lfAssert(strpos($embed, 'source_url') !== false && strpos($embed, 'Wysylanie...') !== false, 'generated code contains source_url and browser messages');
    $legacyHost = implode('.', ['crm', 'radiozulawy', 'pl']);
    lfAssert(strpos($embed, $legacyHost) === false, 'generated code does not contain hardcoded legacy CRM host');

$endpointA = InstallationUrl::endpointUrl(null, ['HTTPS' => 'on', 'HTTP_HOST' => 'crm.example.com']);
lfAssert($endpointA['endpoint'] === 'https://crm.example.com/api/public/lead-form-submit.php', 'autodetect builds endpoint without APP_URL');

putenv('APP_URL=https://crm.app-url.test');
$endpointFromAppUrl = InstallationUrl::endpointUrl(null, ['HTTPS' => 'on', 'HTTP_HOST' => 'ignored.example.com']);
lfAssert($endpointFromAppUrl['endpoint'] === 'https://crm.app-url.test/api/public/lead-form-submit.php' && $endpointFromAppUrl['source'] === 'config', 'generator resolver uses APP_URL');
putenv('APP_URL');

$embedA = LeadFormService::generateEmbedCode(['public_key' => $newKey], $endpointA['endpoint']);
$embedB = LeadFormService::generateEmbedCode(['public_key' => $newKey], 'https://crm.other.test/api/public/lead-form-submit.php');
lfAssert(strpos($embedA, 'https://crm.example.com/api/public/lead-form-submit.php') !== false, 'generator uses resolved APP_URL endpoint');
lfAssert(strpos($embedB, 'https://crm.other.test/api/public/lead-form-submit.php') !== false && $embedA !== $embedB, 'changing APP_URL changes generated endpoint');
lfAssert(InstallationUrl::validate('http://crm.example.com', 'production') !== [], 'production URL without https is rejected');

$pdo = lfPdo();
$gusLookup = static fn(PDO $pdo, string $nip): array => [
    'success' => true,
    'data' => [
        'nazwa' => 'ACME Sp. z o.o.',
        'regon' => '123456785',
        'miejscowosc' => 'Gdansk',
        'kod_pocztowy' => '80-001',
        'ulica' => 'Dluga',
        'nr_nieruchomosci' => '1',
    ],
];
$service = new LeadFormService($pdo, $gusLookup);
$result = $service->submit(lfPayload(['nip' => '521-079-32-16']), lfServer());
lfAssert($result['success'] === true && $result['status'] === 'created', 'valid submission with NIP creates lead');
$lead = $pdo->query('SELECT * FROM leady ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
lfAssert($lead['nazwa_firmy'] === 'ACME Sp. z o.o.' && $lead['regon'] === '123456785', 'GUS data enriches lead');

$pdo = lfPdo();
$service = new LeadFormService($pdo);
$result = $service->submit(lfPayload(), lfServer('https://example.com'));
lfAssert($result['success'] === false && $result['status'] === 'rejected', 'disallowed domain rejected');

$pdo = lfPdo();
$pdo->exec("UPDATE lead_form_sources SET is_active = 0 WHERE public_key = 'pub_form'");
$service = new LeadFormService($pdo);
$result = $service->submit(lfPayload(), lfServer());
lfAssert($result['success'] === false && $result['status'] === 'rejected', 'inactive form rejects submission');

$pdo = lfPdo();
$oldKey = 'pub_form';
$newKey = LeadFormService::createUniquePublicKey($pdo);
$pdo->prepare('UPDATE lead_form_sources SET public_key = ? WHERE public_key = ?')->execute([$newKey, $oldKey]);
$service = new LeadFormService($pdo);
$oldResult = $service->submit(lfPayload(['public_key' => $oldKey]), lfServer());
$newResult = $service->submit(lfPayload(['public_key' => $newKey]), lfServer());
lfAssert($oldResult['success'] === false && $newResult['success'] === true, 'regenerated key invalidates old key');

$pdo = lfPdo();
$service = new LeadFormService($pdo);
$result = $service->submit(lfPayload(['email' => '']), lfServer());
lfAssert($result['success'] === false, 'missing required field rejected');

$pdo = lfPdo();
$service = new LeadFormService($pdo);
$result = $service->submit(lfPayload(['email' => 'not-email']), lfServer());
lfAssert($result['success'] === false, 'invalid email rejected');

$pdo = lfPdo();
$service = new LeadFormService($pdo);
$result = $service->submit(lfPayload(['nip' => '1234567890']), lfServer());
lfAssert($result['success'] === false, 'invalid NIP rejected');

$pdo = lfPdo();
$pdo->prepare("INSERT INTO leady (nazwa_firmy, nip, telefon, email, zrodlo, status) VALUES ('Existing', '5210793216', '600000000', 'other@example.com', 'formularz_www', 'nowy')")->execute();
$service = new LeadFormService($pdo);
$result = $service->submit(lfPayload(['nip' => '5210793216', 'phone' => '501501501']), lfServer());
lfAssert($result['success'] === true && $result['status'] === 'duplicate', 'duplicate by NIP logged without new lead');
lfAssert((int)$pdo->query('SELECT COUNT(*) FROM leady')->fetchColumn() === 1, 'duplicate by NIP does not create second lead');

$pdo = lfPdo();
$pdo->prepare("INSERT INTO leady (nazwa_firmy, nip, telefon, email, zrodlo, status) VALUES ('Existing', NULL, '600000000', 'jan@example.com', 'formularz_www', 'nowy')")->execute();
$service = new LeadFormService($pdo);
$result = $service->submit(lfPayload(['phone' => '501501501']), lfServer());
lfAssert($result['success'] === true && $result['status'] === 'duplicate', 'duplicate by email logged without new lead');

$submission = $pdo->query('SELECT raw_payload, normalized_payload, status FROM lead_form_submissions ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$raw = json_decode((string)$submission['raw_payload'], true);
$normalized = json_decode((string)$submission['normalized_payload'], true);
lfAssert(($raw['email'] ?? '') === 'jan@example.com', 'raw payload saved');
lfAssert(($normalized['email'] ?? '') === 'jan@example.com' && $submission['status'] === 'duplicate', 'normalized payload saved');

if ($failures > 0) {
    echo "Tests: {$tests}, Failures: {$failures}\n";
    exit(1);
}

echo "Tests: {$tests}, Failures: {$failures}\n";
