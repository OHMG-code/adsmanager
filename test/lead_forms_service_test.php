<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/LeadFormService.php';

function lfAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

ensureLeadFormTables($pdo);

$formId = leadFormSave($pdo, [
    'name' => 'WWW kontakt',
    'allowed_domains' => 'https://example.test, *.example.org',
    'default_lead_source' => 'formularz_www',
    'marketing_consent_required' => 1,
    'gus_lookup_enabled' => 1,
    'is_active' => 1,
]);
$form = leadFormFetch($pdo, $formId);
lfAssert((int)$form['id'] === $formId, 'lead form is saved and fetchable');
lfAssert(preg_match('/^lf_[a-f0-9]{20}$/', (string)$form['public_key']) === 1, 'public_key has expected format');
lfAssert((string)$form['allowed_domains'] === 'example.test, *.example.org', 'domains are normalized');

$embed = leadFormGenerateEmbedCode($form, 'https://crm.example.test');
lfAssert(strpos($embed, 'https://crm.example.test/api/public/lead-form-submit.php') !== false, 'embed code uses APP_URL endpoint');
lfAssert(strpos($embed, (string)$form['public_key']) !== false, 'embed code contains public_key');

$blocked = leadFormHandleSubmission($pdo, [
    'public_key' => $form['public_key'],
    'company_name' => 'Blocked',
    'marketing_consent' => '1',
], ['HTTP_ORIGIN' => 'https://blocked.test']);
lfAssert($blocked['status'] === 403 && $blocked['code'] === 'DOMAIN_NOT_ALLOWED', 'disallowed domain is blocked');

leadFormToggle($pdo, $formId);
$inactive = leadFormHandleSubmission($pdo, [
    'public_key' => $form['public_key'],
    'company_name' => 'Inactive',
    'marketing_consent' => '1',
], ['HTTP_ORIGIN' => 'https://example.test']);
lfAssert($inactive['status'] === 403 && $inactive['code'] === 'FORM_INACTIVE', 'inactive form is blocked');
leadFormToggle($pdo, $formId);

$withNip = leadFormHandleSubmission($pdo, [
    'public_key' => $form['public_key'],
    'company_name' => 'Before GUS',
    'nip' => '521-079-32-16',
    'email' => 'kontakt@example.test',
    'marketing_consent' => '1',
], ['HTTP_ORIGIN' => 'https://www.example.org'], [
    'gus_fetcher' => static function (string $nip): array {
        lfAssert($nip === '5210793216', 'GUS fetcher receives normalized NIP');
        return [
            'success' => true,
            'data' => [
                'nazwa' => 'Firma z GUS',
                'miejscowosc' => 'Gdansk',
                'kod_pocztowy' => '80-001',
                'ulica' => 'Dluga',
                'nr_nieruchomosci' => '1',
            ],
        ];
    },
]);
lfAssert($withNip['status'] === 200 && $withNip['code'] === 'ACCEPTED', 'valid submission with NIP is accepted');
$lead = $pdo->query('SELECT * FROM leady WHERE id = ' . (int)$withNip['lead_id'])->fetch();
lfAssert((string)$lead['nazwa_firmy'] === 'Firma z GUS', 'GUS data enriches lead');
lfAssert((string)$lead['nip'] === '5210793216', 'NIP is normalized on lead');

$withoutNip = leadFormHandleSubmission($pdo, [
    'public_key' => $form['public_key'],
    'company_name' => 'Firma bez NIP',
    'phone' => '+48500100200',
    'marketing_consent' => '1',
], ['HTTP_ORIGIN' => 'https://example.test']);
lfAssert($withoutNip['status'] === 200 && $withoutNip['code'] === 'ACCEPTED', 'valid submission without NIP is accepted');

$duplicate = leadFormHandleSubmission($pdo, [
    'public_key' => $form['public_key'],
    'company_name' => 'Duplikat',
    'email' => 'kontakt@example.test',
    'marketing_consent' => '1',
], ['HTTP_ORIGIN' => 'https://example.test']);
lfAssert($duplicate['status'] === 200 && $duplicate['code'] === 'DUPLICATE', 'duplicate by email is detected');

$submissionCount = (int)$pdo->query('SELECT COUNT(*) FROM lead_form_submissions')->fetchColumn();
lfAssert($submissionCount >= 4, 'submissions are recorded');

echo "Lead forms service test OK\n";
