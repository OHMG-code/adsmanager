<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/gus_validation.php';

class LeadFormService
{
    private PDO $pdo;
    /** @var callable|null */
    private $gusLookup;

    /** @param callable|null $gusLookup function(PDO $pdo, string $nip): array */
    public function __construct(PDO $pdo, ?callable $gusLookup = null)
    {
        $this->pdo = $pdo;
        $this->gusLookup = $gusLookup;
    }

    public static function generatePublicKey(): string
    {
        return 'lf_' . bin2hex(random_bytes(24));
    }

    public static function createUniquePublicKey(PDO $pdo): string
    {
        do {
            $key = self::generatePublicKey();
            $stmt = $pdo->prepare('SELECT id FROM lead_form_sources WHERE public_key = :public_key LIMIT 1');
            $stmt->execute([':public_key' => $key]);
        } while ($stmt->fetch(PDO::FETCH_ASSOC));

        return $key;
    }

    public static function generateEmbedCode(array $source, string $endpointUrl): string
    {
        $publicKey = (string)($source['public_key'] ?? '');
        $consentRequired = !empty($source['consent_required']);
        $endpointUrl = rtrim($endpointUrl, '/');

        $consentHtml = '';
        if ($consentRequired) {
            $consentHtml = <<<HTML
  <label>
    <input type="checkbox" name="consent" value="1" required>
    Wyrazam zgode na kontakt w sprawie oferty reklamowej
  </label>

HTML;
        }

        return <<<HTML
<form data-crm-lead-form>
  <input type="hidden" name="public_key" value="{$publicKey}">
  <input name="name" required minlength="3" autocomplete="name" placeholder="Imie i nazwisko">
  <input name="email" type="email" required autocomplete="email" placeholder="Adres e-mail">
  <input name="phone" required minlength="6" autocomplete="tel" placeholder="Telefon">
  <input name="nip" inputmode="numeric" placeholder="NIP">
  <textarea name="message" placeholder="Wiadomosc"></textarea>
{$consentHtml}  <button type="submit">Wyslij</button>
  <p data-crm-lead-message aria-live="polite"></p>
</form>
<script>
(function () {
  var form = document.querySelector('[data-crm-lead-form]');
  var message = document.querySelector('[data-crm-lead-message]');
  if (!form || !message) return;
  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }
    message.textContent = 'Wysylanie...';
    var data = Object.fromEntries(new FormData(form).entries());
    data.public_key = '{$publicKey}';
    data.source_url = window.location.href;
    try {
      var response = await fetch('{$endpointUrl}', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
      });
      var json = await response.json();
      message.textContent = json.message || (response.ok ? 'Dziekujemy za zgloszenie.' : 'Nie udalo sie wyslac formularza.');
      if (response.ok && json.success) {
        form.reset();
      }
    } catch (error) {
      message.textContent = 'Nie udalo sie wyslac formularza. Sprobuj ponownie.';
    }
  });
})();
</script>
HTML;
    }

    public static function parseAllowedDomains(?string $value): array
    {
        $value = trim((string)$value);
        if ($value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $parts = $decoded;
        } else {
            $parts = preg_split('/[\r\n,;]+/', $value) ?: [];
        }

        $domains = [];
        foreach ($parts as $part) {
            $domain = self::normalizeDomain((string)$part);
            if ($domain !== '') {
                $domains[] = $domain;
            }
        }
        return array_values(array_unique($domains));
    }

    public static function normalizeDomain(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        if (preg_match('~^https?://~i', $value)) {
            $host = parse_url($value, PHP_URL_HOST);
            $value = is_string($host) ? $host : '';
        }
        $value = preg_replace('/:\d+$/', '', $value) ?? '';
        $value = trim($value, " \t\n\r\0\x0B/");
        return preg_match('/^[a-z0-9.-]+$/', $value) ? $value : '';
    }

    public static function originHost(?string $origin, ?string $referer): string
    {
        foreach ([$origin, $referer] as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '') {
                continue;
            }
            $host = parse_url($candidate, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return self::normalizeDomain($host);
            }
        }
        return '';
    }

    public static function domainAllowed(string $host, array $allowedDomains): bool
    {
        if ($host === '') {
            return false;
        }
        foreach ($allowedDomains as $domain) {
            $domain = self::normalizeDomain((string)$domain);
            if ($domain !== '' && $host === $domain) {
                return true;
            }
        }
        return false;
    }

    public function findCorsOriginForHost(string $host): ?string
    {
        if ($host === '') {
            return null;
        }
        $stmt = $this->pdo->query('SELECT allowed_domains FROM lead_form_sources WHERE is_active = 1');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (self::domainAllowed($host, self::parseAllowedDomains((string)($row['allowed_domains'] ?? '')))) {
                return $host;
            }
        }
        return null;
    }

    public function submit(array $payload, array $server): array
    {
        $source = null;
        $submissionId = null;
        $rawPayload = $payload;
        $normalized = [];

        try {
            $publicKey = trim((string)($payload['public_key'] ?? ''));
            if ($publicKey === '') {
                return $this->reject(null, $rawPayload, [], 'Brak klucza formularza.', 'rejected');
            }

            $source = $this->findSource($publicKey);
            if (!$source) {
                return $this->reject(null, $rawPayload, [], 'Formularz jest nieaktywny albo nie istnieje.', 'rejected');
            }

            $host = self::originHost($server['HTTP_ORIGIN'] ?? null, $server['HTTP_REFERER'] ?? null);
            $allowed = self::parseAllowedDomains((string)($source['allowed_domains'] ?? ''));
            if (!self::domainAllowed($host, $allowed)) {
                return $this->reject($source, $rawPayload, [], 'Niedozwolona domena formularza.', 'rejected');
            }

            $submissionId = $this->insertSubmission($source, $rawPayload, [], $server, 'received', null, null);
            $normalized = $this->normalizePayload($source, $payload);
            $errors = $this->validateNormalized($source, $normalized);
            if ($errors) {
                $message = implode(' ', $errors);
                $this->updateSubmission($submissionId, $normalized, 'rejected', $message, null);
                return ['success' => false, 'message' => $message, 'status' => 'rejected'];
            }

            if (!empty($normalized['nip']) && !empty($source['gus_lookup_enabled'])) {
                $gusData = $this->lookupGus((string)$normalized['nip']);
                if ($gusData) {
                    $normalized = $this->mergeGusData($normalized, $gusData);
                }
            }

            $duplicate = $this->findDuplicate($normalized);
            if ($duplicate) {
                $leadId = (int)$duplicate['id'];
                $this->logDuplicateActivity($leadId, $source, $normalized);
                $this->updateSubmission($submissionId, $normalized, 'duplicate', null, $leadId);
                return [
                    'success' => true,
                    'message' => 'Dziekujemy. Zgloszenie zostalo zapisane.',
                    'status' => 'duplicate',
                    'lead_id' => $leadId,
                ];
            }

            $leadId = $this->createLead($source, $normalized);
            $this->updateSubmission($submissionId, $normalized, 'created', null, $leadId);
            return [
                'success' => true,
                'message' => 'Dziekujemy. Zgloszenie zostalo przyjete.',
                'status' => 'created',
                'lead_id' => $leadId,
            ];
        } catch (Throwable $e) {
            error_log('lead_form_submit: ' . $e->getMessage());
            if ($submissionId !== null) {
                $this->updateSubmission($submissionId, $normalized, 'error', 'Blad techniczny.', null);
            } elseif ($source) {
                $this->insertSubmission($source, $rawPayload, $normalized, $server, 'error', 'Blad techniczny.', null);
            }
            return ['success' => false, 'message' => 'Nie udalo sie zapisac zgloszenia. Sprobuj ponownie.', 'status' => 'error'];
        }
    }

    private function findSource(string $publicKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM lead_form_sources WHERE public_key = :public_key AND is_active = 1 LIMIT 1');
        $stmt->execute([':public_key' => $publicKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function reject(?array $source, array $rawPayload, array $normalized, string $message, string $status): array
    {
        try {
            $this->insertSubmission($source, $rawPayload, $normalized, $_SERVER, $status, $message, null);
        } catch (Throwable $e) {
            error_log('lead_form_reject_log: ' . $e->getMessage());
        }
        return ['success' => false, 'message' => $message, 'status' => $status];
    }

    private function normalizePayload(array $source, array $payload): array
    {
        $normalized = [];
        $aliases = [
            'name' => 'name',
            'full_name' => 'name',
            'contact_name' => 'name',
            'email' => 'email',
            'phone' => 'phone',
            'nip' => 'nip',
            'company_name' => 'company_name',
            'message' => 'message',
            'consent' => 'consent',
            'source_url' => 'source_url',
        ];

        foreach ($aliases as $external => $crm) {
            if (array_key_exists($external, $payload) && !array_key_exists($crm, $normalized)) {
                $normalized[$crm] = $payload[$external];
            }
        }

        $stmt = $this->pdo->prepare('SELECT external_field, crm_field FROM lead_form_field_mappings WHERE source_id = :source_id');
        $stmt->execute([':source_id' => (int)$source['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $mapping) {
            $external = (string)($mapping['external_field'] ?? '');
            $crm = (string)($mapping['crm_field'] ?? '');
            if ($external !== '' && $crm !== '' && array_key_exists($external, $payload)) {
                $normalized[$crm] = $payload[$external];
            }
        }

        foreach ($normalized as $key => $value) {
            if (is_bool($value)) {
                $normalized[$key] = $value;
            } elseif (is_scalar($value) || $value === null) {
                $normalized[$key] = trim((string)$value);
            }
        }

        if (!empty($normalized['nip'])) {
            $normalized['nip'] = normalizeNip((string)$normalized['nip']);
        }
        if (!empty($normalized['phone'])) {
            $normalized['phone_digits'] = preg_replace('/\D+/', '', (string)$normalized['phone']) ?? '';
        }
        if (!empty($normalized['email'])) {
            $normalized['email'] = strtolower((string)$normalized['email']);
        }
        if (array_key_exists('consent', $normalized)) {
            $value = strtolower(trim((string)$normalized['consent']));
            $normalized['consent'] = in_array($value, ['1', 'true', 'yes', 'on', 'tak'], true) ? '1' : '';
        }
        $normalized['source'] = trim((string)($source['default_source'] ?? ''));
        return $normalized;
    }

    private function validateNormalized(array $source, array $normalized): array
    {
        $errors = [];
        $required = [];
        $stmt = $this->pdo->prepare('SELECT crm_field FROM lead_form_field_mappings WHERE source_id = :source_id AND is_required = 1');
        $stmt->execute([':source_id' => (int)$source['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $required[] = (string)$row['crm_field'];
        }
        if (!$required) {
            $required = ['name', 'email', 'phone'];
        }
        if (!empty($source['consent_required'])) {
            $required[] = 'consent';
        }
        foreach (array_unique($required) as $field) {
            $value = $normalized[$field] ?? '';
            if ($value === '' || $value === null) {
                $errors[] = 'Uzupelnij wymagane pole: ' . $field . '.';
            }
        }

        $name = trim((string)($normalized['name'] ?? ''));
        if ($name !== '' && mb_strlen($name) < 3) {
            $errors[] = 'Imie i nazwisko musi miec co najmniej 3 znaki.';
        }
        $email = trim((string)($normalized['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Podaj poprawny adres email.';
        }
        $phoneDigits = (string)($normalized['phone_digits'] ?? preg_replace('/\D+/', '', (string)($normalized['phone'] ?? '')));
        if (trim((string)($normalized['phone'] ?? '')) !== '' && strlen($phoneDigits) < 6) {
            $errors[] = 'Podaj poprawny numer telefonu.';
        }
        $nip = trim((string)($normalized['nip'] ?? ''));
        if ($nip !== '' && !isValidNip($nip)) {
            $errors[] = 'Podaj poprawny NIP.';
        }
        if (!empty($source['consent_required']) && empty($normalized['consent'])) {
            $errors[] = 'Zgoda marketingowa jest wymagana.';
        }
        return $errors;
    }

    private function lookupGus(string $nip): ?array
    {
        $cacheTtlDays = 30;
        try {
            $stmt = $this->pdo->query('SELECT gus_enabled, gus_cache_ttl_days FROM konfiguracja_systemu WHERE id = 1 LIMIT 1');
            $config = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            if (is_array($config)) {
                if (empty($config['gus_enabled'])) {
                    return null;
                }
                $cacheTtlDays = max(1, (int)($config['gus_cache_ttl_days'] ?? 30));
            }
        } catch (Throwable $e) {
            // Test databases and older installs may not have the global config table.
        }

        if ($this->gusLookup) {
            $result = call_user_func($this->gusLookup, $this->pdo, $nip);
        } elseif (function_exists('gusFetchCompanyByNip')) {
            $result = gusFetchCompanyByNip($this->pdo, $nip, ['cache_ttl_days' => $cacheTtlDays], null);
        } else {
            return null;
        }
        if (!is_array($result) || empty($result['success']) || !is_array($result['data'] ?? null)) {
            return null;
        }
        return $result['data'];
    }

    private function mergeGusData(array $normalized, array $gusData): array
    {
        $map = [
            'company_name' => ['nazwa', 'name_full', 'name', 'nazwa_firmy'],
            'regon' => ['regon'],
            'address' => ['adres', 'address'],
            'city' => ['miejscowosc', 'city'],
            'postal_code' => ['kod_pocztowy', 'postal_code'],
            'street' => ['ulica', 'street'],
            'building_no' => ['nr_nieruchomosci', 'building_no'],
            'apartment_no' => ['nr_lokalu', 'apartment_no'],
        ];
        foreach ($map as $target => $keys) {
            foreach ($keys as $key) {
                if (!empty($gusData[$key])) {
                    $normalized[$target] = (string)$gusData[$key];
                    break;
                }
            }
        }
        $normalized['gus'] = $gusData;
        return $normalized;
    }

    private function findDuplicate(array $normalized): ?array
    {
        $parts = [];
        $params = [];
        if (!empty($normalized['nip'])) {
            $parts[] = "REPLACE(REPLACE(REPLACE(REPLACE(TRIM(nip), '-', ''), ' ', ''), '.', ''), '/', '') = :nip";
            $params[':nip'] = $normalized['nip'];
        }
        if (!empty($normalized['email'])) {
            $parts[] = 'LOWER(TRIM(email)) = :email';
            $params[':email'] = $normalized['email'];
        }
        if (!empty($normalized['phone_digits'])) {
            $parts[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(telefon), ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') = :phone";
            $params[':phone'] = $normalized['phone_digits'];
        }
        if (!$parts) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id, nazwa_firmy FROM leady WHERE ' . implode(' OR ', $parts) . ' ORDER BY id ASC LIMIT 1');
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function createLead(array $source, array $normalized): int
    {
        $companyName = trim((string)($normalized['company_name'] ?? ''));
        $name = trim((string)($normalized['name'] ?? ''));
        $leadName = $companyName !== '' ? $companyName : $name;
        $message = trim((string)($normalized['message'] ?? ''));
        $noteLines = [];
        if ($message !== '') {
            $noteLines[] = $message;
        }
        if (!empty($normalized['source_url'])) {
            $noteLines[] = 'URL zrodla: ' . (string)$normalized['source_url'];
        }
        $noteLines[] = 'Formularz zewnetrzny: ' . (string)($source['name'] ?? '');
        $noteLines[] = 'Zrodlo zewnetrzne: ' . (string)($source['default_source'] ?? '');

        $data = [
            'nazwa_firmy' => $leadName,
            'nip' => $normalized['nip'] ?? null,
            'telefon' => $normalized['phone'] ?? null,
            'email' => $normalized['email'] ?? null,
            'zrodlo' => 'formularz_www',
            'status' => 'nowy',
            'notatki' => implode("\n", array_filter($noteLines)),
        ];
        $optionalMap = [
            'external_source' => 'source',
            'kontakt_imie_nazwisko' => 'name',
            'kontakt_telefon' => 'phone',
            'kontakt_email' => 'email',
            'kod_pocztowy' => 'postal_code',
            'miasto' => 'city',
            'ulica' => 'street',
            'nr_budynku' => 'building_no',
            'nr_lokalu' => 'apartment_no',
            'regon' => 'regon',
        ];
        $columns = $this->tableColumns('leady');
        foreach ($optionalMap as $column => $key) {
            if (isset($columns[strtolower($column)]) && array_key_exists($key, $normalized)) {
                $data[$column] = $normalized[$key];
            }
        }
        if (isset($columns['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }
        if (isset($columns['updated_at'])) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        $cols = array_keys($data);
        $params = [];
        foreach ($cols as $col) {
            $params[':' . $col] = $data[$col] !== '' ? $data[$col] : null;
        }
        $sql = 'INSERT INTO leady (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_keys($params)) . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$this->pdo->lastInsertId();
    }

    private function insertSubmission(?array $source, array $raw, array $normalized, array $server, string $status, ?string $error, ?int $leadId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO lead_form_submissions
             (source_id, raw_payload, normalized_payload, ip_address, user_agent, status, error_message, created_lead_id)
             VALUES (:source_id, :raw_payload, :normalized_payload, :ip_address, :user_agent, :status, :error_message, :created_lead_id)'
        );
        $stmt->execute([
            ':source_id' => $source ? (int)$source['id'] : null,
            ':raw_payload' => json_encode($raw, JSON_UNESCAPED_UNICODE),
            ':normalized_payload' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
            ':ip_address' => substr((string)($server['REMOTE_ADDR'] ?? ''), 0, 45),
            ':user_agent' => substr((string)($server['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ':status' => $status,
            ':error_message' => $error,
            ':created_lead_id' => $leadId,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function updateSubmission(int $submissionId, array $normalized, string $status, ?string $error, ?int $leadId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE lead_form_submissions
             SET normalized_payload = :normalized_payload, status = :status, error_message = :error_message, created_lead_id = :created_lead_id
             WHERE id = :id'
        );
        $stmt->execute([
            ':normalized_payload' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
            ':status' => $status,
            ':error_message' => $error,
            ':created_lead_id' => $leadId,
            ':id' => $submissionId,
        ]);
    }

    private function logDuplicateActivity(int $leadId, array $source, array $normalized): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO leady_aktywnosci (lead_id, user_id, typ, opis)
                 VALUES (:lead_id, 0, 'duplicate_submission', :opis)"
            );
            $stmt->execute([
                ':lead_id' => $leadId,
                ':opis' => 'Ponowne zgloszenie z formularza ' . (string)($source['name'] ?? '') . ': ' . json_encode($normalized, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            error_log('lead_form_duplicate_activity: ' . $e->getMessage());
        }
    }

    private function tableColumns(string $table): array
    {
        try {
            $stmt = $this->pdo->query('SELECT * FROM ' . $table . ' WHERE 1 = 0');
            $count = $stmt ? $stmt->columnCount() : 0;
            $columns = [];
            for ($i = 0; $i < $count; $i++) {
                $meta = $stmt->getColumnMeta($i);
                if (!empty($meta['name'])) {
                    $columns[strtolower((string)$meta['name'])] = (string)$meta['name'];
                }
            }
            return $columns;
        } catch (Throwable $e) {
            return [];
        }
    }
}
