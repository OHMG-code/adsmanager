<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/db_schema.php';
require_once __DIR__ . '/../public/includes/gus_validation.php';

function leadFormIsSqlite(PDO $pdo): bool
{
    try {
        return strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'sqlite';
    } catch (Throwable $e) {
        return false;
    }
}

function ensureLeadFormTables(PDO $pdo): void
{
    if (leadFormIsSqlite($pdo)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS lead_form_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            public_key TEXT NOT NULL UNIQUE,
            allowed_domains TEXT NULL,
            default_lead_source TEXT NOT NULL DEFAULT 'formularz_www',
            marketing_consent_required INTEGER NOT NULL DEFAULT 0,
            gus_lookup_enabled INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS lead_form_submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            lead_form_source_id INTEGER NOT NULL,
            lead_id INTEGER NULL,
            public_key TEXT NOT NULL,
            origin TEXT NULL,
            referer TEXT NULL,
            remote_addr TEXT NULL,
            status TEXT NOT NULL DEFAULT 'received',
            duplicate_reason TEXT NULL,
            raw_payload TEXT NOT NULL,
            normalized_payload TEXT NULL,
            error_message TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS lead_form_field_mappings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            lead_form_source_id INTEGER NOT NULL,
            external_field TEXT NOT NULL,
            crm_field TEXT NOT NULL,
            is_required INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS lead_form_sources (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        public_key VARCHAR(64) NOT NULL UNIQUE,
        allowed_domains TEXT NULL,
        default_lead_source VARCHAR(40) NOT NULL DEFAULT 'formularz_www',
        marketing_consent_required TINYINT(1) NOT NULL DEFAULT 0,
        gus_lookup_enabled TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_lfs_active (is_active),
        INDEX idx_lfs_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS lead_form_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_form_source_id INT NOT NULL,
        lead_id INT NULL,
        public_key VARCHAR(64) NOT NULL,
        origin VARCHAR(255) NULL,
        referer VARCHAR(500) NULL,
        remote_addr VARCHAR(64) NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'received',
        duplicate_reason VARCHAR(255) NULL,
        raw_payload LONGTEXT NOT NULL,
        normalized_payload LONGTEXT NULL,
        error_message TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lfs_source_created (lead_form_source_id, created_at),
        INDEX idx_lfs_public_key (public_key),
        INDEX idx_lfs_lead_id (lead_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS lead_form_field_mappings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_form_source_id INT NOT NULL,
        external_field VARCHAR(120) NOT NULL,
        crm_field VARCHAR(120) NOT NULL,
        is_required TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_lffm_source_external (lead_form_source_id, external_field)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function leadFormEnsureLeadStorage(PDO $pdo): void
{
    if (!leadFormIsSqlite($pdo)) {
        ensureLeadColumns($pdo);
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS leady (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nazwa_firmy TEXT NOT NULL,
        nip TEXT NULL,
        telefon TEXT NULL,
        email TEXT NULL,
        zrodlo TEXT NOT NULL DEFAULT 'inne',
        status TEXT NOT NULL DEFAULT 'nowy',
        notatki TEXT NULL,
        kod_pocztowy TEXT NULL,
        miasto TEXT NULL,
        ulica TEXT NULL,
        nr_budynku TEXT NULL,
        nr_lokalu TEXT NULL,
        kontakt_email TEXT NULL,
        kontakt_telefon TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
}

function leadFormNormalizeDomains(string $domains): string
{
    $items = preg_split('/[,;\r\n]+/', $domains) ?: [];
    $out = [];
    foreach ($items as $item) {
        $item = strtolower(trim((string)$item));
        $item = preg_replace('#^https?://#', '', $item);
        $item = preg_replace('#/.*$#', '', (string)$item);
        $item = preg_replace('/:\d+$/', '', (string)$item);
        if ($item !== '' && preg_match('/^(\*\.)?[a-z0-9.-]+$/', $item)) {
            $out[$item] = $item;
        }
    }
    return implode(', ', array_values($out));
}

function leadFormGeneratePublicKey(PDO $pdo): string
{
    ensureLeadFormTables($pdo);
    for ($i = 0; $i < 10; $i++) {
        $key = 'lf_' . bin2hex(random_bytes(10));
        $stmt = $pdo->prepare('SELECT id FROM lead_form_sources WHERE public_key = :public_key LIMIT 1');
        $stmt->execute([':public_key' => $key]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            return $key;
        }
    }
    throw new RuntimeException('Nie udało się wygenerować unikalnego public_key.');
}

function leadFormSave(PDO $pdo, array $input): int
{
    ensureLeadFormTables($pdo);
    $id = max(0, (int)($input['id'] ?? 0));
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Nazwa formularza jest wymagana.');
    }
    $data = [
        ':name' => $name,
        ':allowed_domains' => leadFormNormalizeDomains((string)($input['allowed_domains'] ?? '')),
        ':default_lead_source' => leadFormNormalizeLeadSource((string)($input['default_lead_source'] ?? 'formularz_www')),
        ':marketing_consent_required' => !empty($input['marketing_consent_required']) ? 1 : 0,
        ':gus_lookup_enabled' => !empty($input['gus_lookup_enabled']) ? 1 : 0,
        ':is_active' => !empty($input['is_active']) ? 1 : 0,
    ];

    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE lead_form_sources
            SET name = :name, allowed_domains = :allowed_domains, default_lead_source = :default_lead_source,
                marketing_consent_required = :marketing_consent_required, gus_lookup_enabled = :gus_lookup_enabled,
                is_active = :is_active, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id");
        $data[':id'] = $id;
        $stmt->execute($data);
        return $id;
    }

    $data[':public_key'] = leadFormGeneratePublicKey($pdo);
    $stmt = $pdo->prepare("INSERT INTO lead_form_sources
        (name, public_key, allowed_domains, default_lead_source, marketing_consent_required, gus_lookup_enabled, is_active)
        VALUES (:name, :public_key, :allowed_domains, :default_lead_source, :marketing_consent_required, :gus_lookup_enabled, :is_active)");
    $stmt->execute($data);
    return (int)$pdo->lastInsertId();
}

function leadFormNormalizeLeadSource(string $source): string
{
    $source = trim($source);
    $allowed = ['telefon', 'email', 'formularz_www', 'maps_api', 'polecenie', 'inne'];
    return in_array($source, $allowed, true) ? $source : 'formularz_www';
}

function leadFormFetch(PDO $pdo, int $id): ?array
{
    ensureLeadFormTables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM lead_form_sources WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function leadFormFetchByPublicKey(PDO $pdo, string $publicKey): ?array
{
    ensureLeadFormTables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM lead_form_sources WHERE public_key = :public_key LIMIT 1');
    $stmt->execute([':public_key' => trim($publicKey)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function leadFormList(PDO $pdo): array
{
    ensureLeadFormTables($pdo);
    $stmt = $pdo->query('SELECT * FROM lead_form_sources ORDER BY created_at DESC, id DESC');
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function leadFormRecentSubmissions(PDO $pdo, ?int $sourceId = null, int $limit = 10): array
{
    ensureLeadFormTables($pdo);
    $limit = max(1, min(50, $limit));
    if ($sourceId !== null && $sourceId > 0) {
        $stmt = $pdo->prepare("SELECT s.*, f.name AS form_name
            FROM lead_form_submissions s
            LEFT JOIN lead_form_sources f ON f.id = s.lead_form_source_id
            WHERE s.lead_form_source_id = :source_id
            ORDER BY s.created_at DESC, s.id DESC
            LIMIT {$limit}");
        $stmt->execute([':source_id' => $sourceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $stmt = $pdo->query("SELECT s.*, f.name AS form_name
        FROM lead_form_submissions s
        LEFT JOIN lead_form_sources f ON f.id = s.lead_form_source_id
        ORDER BY s.created_at DESC, s.id DESC
        LIMIT {$limit}");
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function leadFormToggle(PDO $pdo, int $id): void
{
    $form = leadFormFetch($pdo, $id);
    if (!$form) {
        throw new RuntimeException('Nie znaleziono formularza.');
    }
    $stmt = $pdo->prepare('UPDATE lead_form_sources SET is_active = :is_active, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    $stmt->execute([':is_active' => empty($form['is_active']) ? 1 : 0, ':id' => $id]);
}

function leadFormRegenerateKey(PDO $pdo, int $id): string
{
    if (!leadFormFetch($pdo, $id)) {
        throw new RuntimeException('Nie znaleziono formularza.');
    }
    $key = leadFormGeneratePublicKey($pdo);
    $stmt = $pdo->prepare('UPDATE lead_form_sources SET public_key = :public_key, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    $stmt->execute([':public_key' => $key, ':id' => $id]);
    return $key;
}

function leadFormResolveAppUrl(array $server = []): array
{
    $configured = trim((string)(defined('APP_URL') ? APP_URL : (getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? $_SERVER['APP_URL'] ?? ''))));
    if ($configured !== '') {
        return ['url' => rtrim($configured, '/'), 'warning' => ''];
    }

    $https = !empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = trim((string)($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? ''));
    if ($host === '') {
        return ['url' => '', 'warning' => 'Brak APP_URL i nie udało się wykonać autodetekcji adresu aplikacji.'];
    }
    $baseUrl = defined('BASE_URL') ? (string)BASE_URL : '';
    return [
        'url' => rtrim($scheme . '://' . $host . '/' . trim($baseUrl, '/'), '/'),
        'warning' => 'APP_URL nie jest ustawiony. Kod został wygenerowany z autodetekcji aktualnego requestu.',
    ];
}

function leadFormBuildEndpointUrl(string $appUrl): string
{
    return rtrim($appUrl, '/') . '/api/public/lead-form-submit.php';
}

function leadFormGenerateEmbedCode(array $form, string $appUrl): string
{
    $endpoint = leadFormBuildEndpointUrl($appUrl);
    $publicKey = (string)($form['public_key'] ?? '');
    return '<form class="crm-lead-form" data-crm-lead-form>' . "\n"
        . '  <input type="hidden" name="public_key" value="' . htmlspecialchars($publicKey, ENT_QUOTES, 'UTF-8') . '">' . "\n"
        . '  <input type="text" name="company_name" placeholder="Nazwa firmy" required>' . "\n"
        . '  <input type="text" name="nip" placeholder="NIP">' . "\n"
        . '  <input type="email" name="email" placeholder="Email">' . "\n"
        . '  <input type="tel" name="phone" placeholder="Telefon">' . "\n"
        . '  <textarea name="message" placeholder="Wiadomość"></textarea>' . "\n"
        . '  <label><input type="checkbox" name="marketing_consent" value="1"> Zgoda marketingowa</label>' . "\n"
        . '  <button type="submit">Wyślij</button>' . "\n"
        . '</form>' . "\n"
        . '<script>' . "\n"
        . '(function(){' . "\n"
        . '  var endpoint = ' . json_encode($endpoint, JSON_UNESCAPED_SLASHES) . ';' . "\n"
        . '  document.querySelectorAll("[data-crm-lead-form]").forEach(function(form){' . "\n"
        . '    form.addEventListener("submit", function(event){' . "\n"
        . '      event.preventDefault();' . "\n"
        . '      fetch(endpoint, {method:"POST", headers:{"Content-Type":"application/json"}, body:JSON.stringify(Object.fromEntries(new FormData(form).entries()))})' . "\n"
        . '        .then(function(response){ return response.json(); })' . "\n"
        . '        .then(function(data){ form.dispatchEvent(new CustomEvent("crmLeadForm:done", {detail:data})); });' . "\n"
        . '    });' . "\n"
        . '  });' . "\n"
        . '})();' . "\n"
        . '</script>';
}

function leadFormExtractHost(?string $url): string
{
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    return strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
}

function leadFormIsDomainAllowed(array $form, ?string $origin, ?string $referer): bool
{
    $allowed = array_filter(array_map('trim', explode(',', strtolower((string)($form['allowed_domains'] ?? '')))));
    if (!$allowed) {
        return true;
    }
    $host = leadFormExtractHost($origin) ?: leadFormExtractHost($referer);
    if ($host === '') {
        return false;
    }
    foreach ($allowed as $domain) {
        if ($domain === $host) {
            return true;
        }
        if (strpos($domain, '*.') === 0) {
            $base = substr($domain, 2);
            if ($host === $base || str_ends_with($host, '.' . $base)) {
                return true;
            }
        }
    }
    return false;
}

function leadFormNormalizePayload(array $payload): array
{
    $companyName = trim((string)($payload['company_name'] ?? $payload['nazwa_firmy'] ?? $payload['firma'] ?? ''));
    $email = strtolower(trim((string)($payload['email'] ?? $payload['kontakt_email'] ?? '')));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = '';
    }
    $phone = preg_replace('/[^\d+]+/', '', (string)($payload['phone'] ?? $payload['telefon'] ?? $payload['kontakt_telefon'] ?? '')) ?: '';
    $nip = normalizeNip((string)($payload['nip'] ?? ''));
    return [
        'company_name' => $companyName,
        'nip' => $nip,
        'email' => $email,
        'phone' => $phone,
        'message' => trim((string)($payload['message'] ?? $payload['wiadomosc'] ?? $payload['notatki'] ?? '')),
        'marketing_consent' => !empty($payload['marketing_consent']) || !empty($payload['zgoda_marketingowa']),
        'city' => trim((string)($payload['city'] ?? $payload['miasto'] ?? '')),
        'postal_code' => trim((string)($payload['postal_code'] ?? $payload['kod_pocztowy'] ?? '')),
        'street' => trim((string)($payload['street'] ?? $payload['ulica'] ?? '')),
        'building_no' => trim((string)($payload['building_no'] ?? $payload['nr_budynku'] ?? '')),
        'flat_no' => trim((string)($payload['flat_no'] ?? $payload['nr_lokalu'] ?? '')),
    ];
}

function leadFormFindDuplicate(PDO $pdo, array $normalized): ?array
{
    leadFormEnsureLeadStorage($pdo);
    $cols = getTableColumns($pdo, 'leady');
    $where = [];
    $params = [];
    if (!empty($normalized['nip']) && hasColumn($cols, 'nip')) {
        $where[] = "REPLACE(REPLACE(REPLACE(REPLACE(TRIM(nip), '-', ''), ' ', ''), '.', ''), '/', '') = :nip";
        $params[':nip'] = $normalized['nip'];
    }
    if (!empty($normalized['email']) && hasColumn($cols, 'email')) {
        $where[] = 'LOWER(TRIM(email)) = :email';
        $params[':email'] = $normalized['email'];
    }
    if (!empty($normalized['phone']) && hasColumn($cols, 'telefon')) {
        $where[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(telefon), '+', ''), '-', ''), ' ', ''), '.', ''), '/', '') = :phone";
        $params[':phone'] = ltrim((string)$normalized['phone'], '+');
    }
    if (!$where) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id FROM leady WHERE ' . implode(' OR ', $where) . ' ORDER BY id ASC LIMIT 1');
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? ['type' => 'lead', 'id' => (int)$row['id']] : null;
}

function leadFormCreateLead(PDO $pdo, array $form, array $normalized): int
{
    leadFormEnsureLeadStorage($pdo);
    $cols = getTableColumns($pdo, 'leady');
    $data = [
        'nazwa_firmy' => $normalized['company_name'],
        'nip' => $normalized['nip'] !== '' ? $normalized['nip'] : null,
        'telefon' => $normalized['phone'] !== '' ? $normalized['phone'] : null,
        'email' => $normalized['email'] !== '' ? $normalized['email'] : null,
        'zrodlo' => leadFormNormalizeLeadSource((string)($form['default_lead_source'] ?? 'formularz_www')),
        'status' => 'nowy',
        'notatki' => $normalized['message'] !== '' ? $normalized['message'] : null,
    ];
    $optional = [
        'kod_pocztowy' => $normalized['postal_code'] !== '' ? $normalized['postal_code'] : null,
        'miasto' => $normalized['city'] !== '' ? $normalized['city'] : null,
        'ulica' => $normalized['street'] !== '' ? $normalized['street'] : null,
        'nr_budynku' => $normalized['building_no'] !== '' ? $normalized['building_no'] : null,
        'nr_lokalu' => $normalized['flat_no'] !== '' ? $normalized['flat_no'] : null,
        'kontakt_email' => $normalized['email'] !== '' ? $normalized['email'] : null,
        'kontakt_telefon' => $normalized['phone'] !== '' ? $normalized['phone'] : null,
    ];
    foreach ($optional as $column => $value) {
        if (hasColumn($cols, $column)) {
            $data[$column] = $value;
        }
    }
    $insert = [];
    foreach ($data as $column => $value) {
        if (hasColumn($cols, $column)) {
            $insert[$column] = $value;
        }
    }
    $columns = array_keys($insert);
    $placeholders = array_map(static fn (string $col): string => ':' . $col, $columns);
    $stmt = $pdo->prepare('INSERT INTO leady (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
    $params = [];
    foreach ($insert as $column => $value) {
        $params[':' . $column] = $value;
    }
    $stmt->execute($params);
    return (int)$pdo->lastInsertId();
}

function leadFormRecordSubmission(PDO $pdo, array $data): int
{
    ensureLeadFormTables($pdo);
    $stmt = $pdo->prepare("INSERT INTO lead_form_submissions
        (lead_form_source_id, lead_id, public_key, origin, referer, remote_addr, status, duplicate_reason, raw_payload, normalized_payload, error_message)
        VALUES (:lead_form_source_id, :lead_id, :public_key, :origin, :referer, :remote_addr, :status, :duplicate_reason, :raw_payload, :normalized_payload, :error_message)");
    $stmt->execute([
        ':lead_form_source_id' => (int)$data['lead_form_source_id'],
        ':lead_id' => $data['lead_id'] ?? null,
        ':public_key' => (string)$data['public_key'],
        ':origin' => $data['origin'] ?? null,
        ':referer' => $data['referer'] ?? null,
        ':remote_addr' => $data['remote_addr'] ?? null,
        ':status' => (string)$data['status'],
        ':duplicate_reason' => $data['duplicate_reason'] ?? null,
        ':raw_payload' => (string)$data['raw_payload'],
        ':normalized_payload' => $data['normalized_payload'] ?? null,
        ':error_message' => $data['error_message'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

function leadFormHandleSubmission(PDO $pdo, array $payload, array $server = [], array $options = []): array
{
    ensureLeadFormTables($pdo);
    $publicKey = trim((string)($payload['public_key'] ?? ''));
    $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $raw = $raw !== false ? $raw : '{}';
    $origin = trim((string)($server['HTTP_ORIGIN'] ?? ''));
    $referer = trim((string)($server['HTTP_REFERER'] ?? ''));

    $form = $publicKey !== '' ? leadFormFetchByPublicKey($pdo, $publicKey) : null;
    if (!$form) {
        return ['ok' => false, 'status' => 404, 'code' => 'FORM_NOT_FOUND', 'message' => 'Formularz nie istnieje.'];
    }
    if (empty($form['is_active'])) {
        leadFormRecordSubmission($pdo, [
            'lead_form_source_id' => (int)$form['id'],
            'public_key' => $publicKey,
            'origin' => $origin ?: null,
            'referer' => $referer ?: null,
            'remote_addr' => $server['REMOTE_ADDR'] ?? null,
            'status' => 'rejected',
            'raw_payload' => $raw,
            'error_message' => 'FORM_INACTIVE',
        ]);
        return ['ok' => false, 'status' => 403, 'code' => 'FORM_INACTIVE', 'message' => 'Formularz jest nieaktywny.'];
    }
    if (!leadFormIsDomainAllowed($form, $origin, $referer)) {
        leadFormRecordSubmission($pdo, [
            'lead_form_source_id' => (int)$form['id'],
            'public_key' => $publicKey,
            'origin' => $origin ?: null,
            'referer' => $referer ?: null,
            'remote_addr' => $server['REMOTE_ADDR'] ?? null,
            'status' => 'blocked',
            'raw_payload' => $raw,
            'error_message' => 'DOMAIN_NOT_ALLOWED',
        ]);
        return ['ok' => false, 'status' => 403, 'code' => 'DOMAIN_NOT_ALLOWED', 'message' => 'Domena nie jest dozwolona.'];
    }

    $normalized = leadFormNormalizePayload($payload);
    if ($normalized['company_name'] === '') {
        leadFormRecordSubmission($pdo, [
            'lead_form_source_id' => (int)$form['id'],
            'public_key' => $publicKey,
            'origin' => $origin ?: null,
            'referer' => $referer ?: null,
            'remote_addr' => $server['REMOTE_ADDR'] ?? null,
            'status' => 'rejected',
            'raw_payload' => $raw,
            'normalized_payload' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null,
            'error_message' => 'VALIDATION_COMPANY_NAME',
        ]);
        return ['ok' => false, 'status' => 400, 'code' => 'VALIDATION_COMPANY_NAME', 'message' => 'Nazwa firmy jest wymagana.'];
    }
    if (!empty($form['marketing_consent_required']) && !$normalized['marketing_consent']) {
        leadFormRecordSubmission($pdo, [
            'lead_form_source_id' => (int)$form['id'],
            'public_key' => $publicKey,
            'origin' => $origin ?: null,
            'referer' => $referer ?: null,
            'remote_addr' => $server['REMOTE_ADDR'] ?? null,
            'status' => 'rejected',
            'raw_payload' => $raw,
            'normalized_payload' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null,
            'error_message' => 'VALIDATION_MARKETING_CONSENT',
        ]);
        return ['ok' => false, 'status' => 400, 'code' => 'VALIDATION_MARKETING_CONSENT', 'message' => 'Zgoda marketingowa jest wymagana.'];
    }

    if (!empty($form['gus_lookup_enabled']) && $normalized['nip'] !== '') {
        $gusFetcher = $options['gus_fetcher'] ?? null;
        try {
            $gusResult = is_callable($gusFetcher) ? $gusFetcher($normalized['nip']) : null;
            if (is_array($gusResult) && !empty($gusResult['success']) && is_array($gusResult['data'] ?? null)) {
                $data = $gusResult['data'];
                $normalized['company_name'] = trim((string)($data['nazwa'] ?? $data['name'] ?? $normalized['company_name'])) ?: $normalized['company_name'];
                $normalized['city'] = trim((string)($data['miejscowosc'] ?? $data['city'] ?? $normalized['city']));
                $normalized['postal_code'] = trim((string)($data['kod_pocztowy'] ?? $data['postal_code'] ?? $normalized['postal_code']));
                $normalized['street'] = trim((string)($data['ulica'] ?? $data['street'] ?? $normalized['street']));
                $normalized['building_no'] = trim((string)($data['nr_nieruchomosci'] ?? $data['building_no'] ?? $normalized['building_no']));
                $normalized['flat_no'] = trim((string)($data['nr_lokalu'] ?? $data['flat_no'] ?? $normalized['flat_no']));
            }
        } catch (Throwable $e) {
            error_log('lead_form GUS lookup failed: ' . $e->getMessage());
        }
    }

    $normalizedJson = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $duplicate = leadFormFindDuplicate($pdo, $normalized);
    if ($duplicate) {
        $submissionId = leadFormRecordSubmission($pdo, [
            'lead_form_source_id' => (int)$form['id'],
            'lead_id' => (int)$duplicate['id'],
            'public_key' => $publicKey,
            'origin' => $origin ?: null,
            'referer' => $referer ?: null,
            'remote_addr' => $server['REMOTE_ADDR'] ?? null,
            'status' => 'duplicate',
            'duplicate_reason' => (string)$duplicate['type'] . ':' . (int)$duplicate['id'],
            'raw_payload' => $raw,
            'normalized_payload' => $normalizedJson ?: null,
        ]);
        return ['ok' => true, 'status' => 200, 'code' => 'DUPLICATE', 'message' => 'Zgłoszenie zapisane jako duplikat.', 'submission_id' => $submissionId, 'lead_id' => (int)$duplicate['id']];
    }

    $leadId = leadFormCreateLead($pdo, $form, $normalized);
    $submissionId = leadFormRecordSubmission($pdo, [
        'lead_form_source_id' => (int)$form['id'],
        'lead_id' => $leadId,
        'public_key' => $publicKey,
        'origin' => $origin ?: null,
        'referer' => $referer ?: null,
        'remote_addr' => $server['REMOTE_ADDR'] ?? null,
        'status' => 'accepted',
        'raw_payload' => $raw,
        'normalized_payload' => $normalizedJson ?: null,
    ]);
    return ['ok' => true, 'status' => 200, 'code' => 'ACCEPTED', 'message' => 'Zgłoszenie zostało zapisane.', 'submission_id' => $submissionId, 'lead_id' => $leadId];
}
