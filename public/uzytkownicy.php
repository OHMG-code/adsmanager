<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
requireCapability('manage_users');

require_once __DIR__ . '/includes/config.php';
$pageTitle = 'Zespół i uprawnienia';
require_once '../config/config.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/crypto.php';
ensureSystemConfigColumns($pdo);
ensureUserColumns($pdo);

try {
$currentUser = getCurrentUser($pdo);
if (!$currentUser) {
    $currentUser = currentUser();
}
if (isAdminOverride($currentUser)) {
    ensureCanonicalUserRoleColumn($pdo);
    normalizeUserRoleData($pdo);
    $currentUser = getCurrentUser($pdo);
}

function humanizeRoleLabel(string $value): string {
    $prepared = trim(str_replace(['_', '-'], ' ', $value));
    if ($prepared === '') {
        return '—';
    }
    if (function_exists('mb_convert_case')) {
        return mb_convert_case($prepared, MB_CASE_TITLE, 'UTF-8');
    }
    return ucwords($prepared);
}

function fetchEnumRoleValues(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM uzytkownicy LIKE 'rola'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($column && isset($column['Type']) && preg_match("/^enum\\((.*)\\)$/i", $column['Type'], $matches)) {
            $raw = $matches[1];
            $values = [];
            if (preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $raw, $valueMatches)) {
                foreach ($valueMatches[1] as $rawValue) {
                    $decoded = stripcslashes($rawValue);
                    if ($decoded !== '') {
                        $values[] = $decoded;
                    }
                }
            }
            return $values;
        }
    } catch (Throwable $e) {
        error_log('uzytkownicy: cannot inspect rola enum values: ' . $e->getMessage());
    }
    return [];
}

function parseCommissionPercent(?string $raw, string $label, array &$errors): string {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '0.00';
    }
    $normalized = str_replace(',', '.', $raw);
    if (!is_numeric($normalized)) {
        $errors[] = $label . ' musi być liczbą.';
        return '0.00';
    }
    $value = round((float)$normalized, 2);
    if ($value < 0 || $value > 100) {
        $errors[] = $label . ' musi być w zakresie 0.00–100.00.';
    }
    return number_format($value, 2, '.', '');
}

function canonicalRoleValue(?string $raw): ?string {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return null;
    }
    $map = [
        'admin' => 'Administrator',
        'administrator' => 'Administrator',
        'uzytkownik' => 'Handlowiec',
        'user' => 'Handlowiec',
        'handlowiec' => 'Handlowiec',
        'manager' => 'Manager',
    ];
    $lower = strtolower($raw);
    if (isset($map[$lower])) {
        return $map[$lower];
    }
    if (in_array($raw, ['Administrator', 'Manager', 'Handlowiec'], true)) {
        return $raw;
    }
    return null;
}

function displayRoleLabel(?string $raw): string {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '(brak)';
    }
    $canonical = canonicalRoleValue($raw);
    return $canonical ?? $raw;
}

function loadAvailableRoles(PDO $pdo): array {
    $defaults = [
        'Administrator' => 'Administrator',
        'Manager' => 'Manager',
        'Handlowiec' => 'Handlowiec',
    ];
    $roles = [];
    $enumValues = fetchEnumRoleValues($pdo);
    if ($enumValues) {
        foreach ($enumValues as $value) {
            $roles[$value] = $defaults[$value] ?? humanizeRoleLabel($value);
        }
    } else {
        $roles = $defaults;
    }

    try {
        $stmt = $pdo->query("SELECT DISTINCT rola FROM uzytkownicy WHERE rola IS NOT NULL AND rola <> ''");
        if ($stmt) {
            while (($value = $stmt->fetchColumn()) !== false) {
                $value = trim((string)$value);
                if ($value === '') {
                    continue;
                }
                if (!isset($roles[$value])) {
                    $roles[$value] = humanizeRoleLabel($value);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('uzytkownicy: cannot extend role list: ' . $e->getMessage());
    }

    return $roles ?: $defaults;
}

function mailFormDefaults(): array {
    return [
        'smtp_enabled' => 0,
        'smtp_host' => '',
        'smtp_port' => '',
        'smtp_secure' => 'tls',
        'smtp_user' => '',
        'smtp_pass_enc' => '',
        'smtp_from_email' => '',
        'smtp_from_name' => '',
        'imap_enabled' => 0,
        'imap_host' => '',
        'imap_port' => '',
        'imap_secure' => 'tls',
        'imap_user' => '',
        'imap_pass_enc' => '',
        'imap_mailbox' => 'INBOX',
    ];
}

function mapMailEncryptionForForm(?string $value, string $default): string {
    $normalized = strtolower(trim((string)$value));
    if ($normalized === 'none') {
        return '';
    }
    if (in_array($normalized, ['tls', 'ssl'], true)) {
        return $normalized;
    }
    return $default;
}

function mailAccountField(array $row, array $keys, string $default = ''): string {
    foreach ($keys as $key) {
        if (array_key_exists($key, $row)) {
            $value = trim((string)$row[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }
    return $default;
}

function mailAccountFlag(array $row, array $keys): bool {
    foreach ($keys as $key) {
        if (array_key_exists($key, $row)) {
            return !empty($row[$key]);
        }
    }
    return false;
}

function mailAccountHasAnyValue(array $row, array $keys): bool {
    foreach ($keys as $key) {
        if (!empty($row[$key])) {
            return true;
        }
    }
    return false;
}

function fetchMailAccountForEdit(PDO $pdo, int $userId): ?array {
    if ($userId <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT * FROM mail_accounts WHERE user_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('uzytkownicy: cannot load mail account: ' . $e->getMessage());
        return null;
    }
}

function mapMailAccountToForm(?array $mailAccount): array {
    $form = mailFormDefaults();
    if (!$mailAccount) {
        return $form;
    }

    $smtpHost = mailAccountField($mailAccount, ['smtp_host']);
    $imapHost = mailAccountField($mailAccount, ['imap_host']);
    $isActive = mailAccountFlag($mailAccount, ['is_active', 'enabled', 'active', 'smtp_enabled', 'imap_enabled']);
    $passwordMarker = mailAccountHasAnyValue($mailAccount, [
        'password_enc', 'smtp_pass_enc', 'imap_pass_enc', 'smtp_pass', 'imap_pass',
    ]) ? 'set' : '';

    $form['smtp_host'] = $smtpHost;
    $form['smtp_port'] = (int)($mailAccount['smtp_port'] ?? 0) ?: '';
    $form['smtp_secure'] = mapMailEncryptionForForm($mailAccount['smtp_encryption'] ?? ($mailAccount['smtp_secure'] ?? null), $form['smtp_secure']);
    $form['smtp_user'] = mailAccountField($mailAccount, ['smtp_user', 'username', 'imap_user']);
    $form['smtp_from_email'] = mailAccountField($mailAccount, ['email_address', 'smtp_from_email', 'from_email']);
    $form['smtp_from_name'] = mailAccountField($mailAccount, ['smtp_from_name', 'from_name']);
    $form['smtp_pass_enc'] = $passwordMarker;

    $form['imap_host'] = $imapHost;
    $form['imap_port'] = (int)($mailAccount['imap_port'] ?? 0) ?: '';
    $form['imap_secure'] = mapMailEncryptionForForm($mailAccount['imap_encryption'] ?? ($mailAccount['imap_secure'] ?? null), $form['imap_secure']);
    $form['imap_user'] = mailAccountField($mailAccount, ['imap_user', 'username', 'smtp_user']);
    $form['imap_mailbox'] = mailAccountField($mailAccount, ['imap_mailbox', 'mailbox'], $form['imap_mailbox']);
    $form['imap_pass_enc'] = $passwordMarker;

    $form['smtp_enabled'] = $isActive && $smtpHost !== '' ? 1 : 0;
    $form['imap_enabled'] = $isActive && $imapHost !== '' ? 1 : 0;

    return $form;
}

function pickMailAccountColumns(array $columns, array $candidates): array {
    $matches = [];
    foreach ($candidates as $candidate) {
        if (hasColumn($columns, $candidate)) {
            $matches[] = $columns[strtolower($candidate)] ?? $candidate;
        }
    }
    return array_values(array_unique($matches));
}

function resolveMailAccountColumns(PDO $pdo): array {
    $columns = getTableColumns($pdo, 'mail_accounts');
    $map = [
        'user_id' => pickMailAccountColumns($columns, ['user_id']),
        'imap_host' => pickMailAccountColumns($columns, ['imap_host']),
        'imap_port' => pickMailAccountColumns($columns, ['imap_port']),
        'imap_encryption' => pickMailAccountColumns($columns, ['imap_encryption', 'imap_secure']),
        'imap_mailbox' => pickMailAccountColumns($columns, ['imap_mailbox', 'mailbox']),
        'smtp_host' => pickMailAccountColumns($columns, ['smtp_host']),
        'smtp_port' => pickMailAccountColumns($columns, ['smtp_port']),
        'smtp_encryption' => pickMailAccountColumns($columns, ['smtp_encryption', 'smtp_secure']),
        'email_address' => pickMailAccountColumns($columns, ['email_address', 'smtp_from_email', 'from_email']),
        'username' => pickMailAccountColumns($columns, ['username']),
        'smtp_user' => pickMailAccountColumns($columns, ['smtp_user']),
        'imap_user' => pickMailAccountColumns($columns, ['imap_user']),
        'smtp_from_name' => pickMailAccountColumns($columns, ['smtp_from_name', 'from_name']),
        'password_enc' => pickMailAccountColumns($columns, ['password_enc']),
        'smtp_pass_enc' => pickMailAccountColumns($columns, ['smtp_pass_enc']),
        'imap_pass_enc' => pickMailAccountColumns($columns, ['imap_pass_enc']),
        'smtp_pass' => pickMailAccountColumns($columns, ['smtp_pass']),
        'imap_pass' => pickMailAccountColumns($columns, ['imap_pass']),
        'is_active' => pickMailAccountColumns($columns, ['is_active', 'enabled', 'active']),
        'smtp_enabled' => pickMailAccountColumns($columns, ['smtp_enabled']),
        'imap_enabled' => pickMailAccountColumns($columns, ['imap_enabled']),
    ];

    $missing = [];
    if (!$map['user_id']) {
        $missing[] = 'user_id';
    }
    if (!$map['imap_host']) {
        $missing[] = 'imap_host';
    }
    if (!$map['imap_port']) {
        $missing[] = 'imap_port';
    }
    if (!$map['imap_encryption']) {
        $missing[] = 'imap_encryption';
    }
    if (!$map['smtp_host']) {
        $missing[] = 'smtp_host';
    }
    if (!$map['smtp_port']) {
        $missing[] = 'smtp_port';
    }
    if (!$map['smtp_encryption']) {
        $missing[] = 'smtp_encryption';
    }
    if (!$map['email_address']) {
        $missing[] = 'email_address';
    }
    if (!$map['username'] && !$map['smtp_user'] && !$map['imap_user']) {
        $missing[] = 'username';
    }
    if (!$map['is_active'] && !$map['smtp_enabled'] && !$map['imap_enabled']) {
        $missing[] = 'is_active';
    }

    return ['columns' => $columns, 'map' => $map, 'missing' => $missing];
}

function mailAccountColumnAllowsNull(PDO $pdo, string $column): bool {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `mail_accounts` LIKE " . $pdo->quote($column));
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        return $row && isset($row['Null']) && strtoupper((string)$row['Null']) === 'YES';
    } catch (Throwable $e) {
        return false;
    }
}

function mailAccountPasswordNeedsEncryption(string $column): bool {
    return stripos($column, 'enc') !== false;
}

function mailAccountColumnParam(string $column): string {
    return ':c_' . preg_replace('/[^a-z0-9_]/i', '_', $column);
}

function fetchMailDbDebugInfo(PDO $pdo): array {
    $info = [
        'db' => '',
        'db_user' => '',
        'host' => '',
        'error' => '',
    ];
    try {
        $stmt = $pdo->query('SELECT DATABASE() AS db, USER() AS db_user, @@hostname AS host');
        $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        $info['db'] = (string)($row['db'] ?? '');
        $info['db_user'] = (string)($row['db_user'] ?? '');
        $info['host'] = (string)($row['host'] ?? '');
    } catch (Throwable $e) {
        $info['error'] = $e->getMessage();
    }
    return $info;
}

$userColumns = getTableColumns($pdo, 'uzytkownicy');
$currentRole = normalizeRole($currentUser);
$canManageUsers = canManageSystem($currentUser);
$canManageCommissionRates = $canManageUsers;
$csrfToken = getCsrfToken();
$mailDebugEnabled = !empty($_GET['maildebug']) && canManageSystem($currentUser);
$mailDebugPayload = null;

$availableRoles = [
    'Administrator' => 'Administrator',
    'Manager' => 'Manager',
    'Handlowiec' => 'Handlowiec',
];

$alerts = [];
$createDefaults = [
    'login'    => '',
    'imie'     => '',
    'nazwisko' => '',
    'telefon'  => '',
    'email'    => '',
    'rola'     => 'Handlowiec',
    'commission_enabled' => 0,
    'prov_new_contract_pct' => '0.00',
    'prov_renewal_pct' => '0.00',
    'prov_next_invoice_pct' => '0.00',
];
$createForm = $createDefaults;
$editFormOverride = null;
$editUserId = isset($_GET['edit']) ? max(1, (int)$_GET['edit']) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        $alerts[] = ['type' => 'danger', 'msg' => 'Niepoprawny token CSRF.'];
    } else {
        $action = $_POST['action'] ?? '';
        switch ($action) {
        case 'create':
            if (!$canManageUsers) {
                http_response_code(403);
                $alerts[] = ['type' => 'danger', 'msg' => 'Brak uprawnień do dodawania użytkowników.'];
                break;
            }
            $createForm = [
                'login'    => trim((string)($_POST['login'] ?? '')),
                'imie'     => trim((string)($_POST['imie'] ?? '')),
                'nazwisko' => trim((string)($_POST['nazwisko'] ?? '')),
                'telefon'  => trim((string)($_POST['telefon'] ?? '')),
                'email'    => trim((string)($_POST['email'] ?? '')),
                'rola'     => trim((string)($_POST['rola'] ?? 'Handlowiec')),
                'commission_enabled' => isset($_POST['commission_enabled']) ? 1 : 0,
                'prov_new_contract_pct' => trim((string)($_POST['prov_new_contract_pct'] ?? '0.00')),
                'prov_renewal_pct' => trim((string)($_POST['prov_renewal_pct'] ?? '0.00')),
                'prov_next_invoice_pct' => trim((string)($_POST['prov_next_invoice_pct'] ?? '0.00')),
            ];
            $password = (string)($_POST['password'] ?? '');
            $errors = [];
            $createForm['rola'] = canonicalRoleValue($createForm['rola']) ?? '';
            $isSalesRole = ($createForm['rola'] === 'Handlowiec');

            if ($createForm['login'] === '') {
                $errors[] = 'Login jest wymagany.';
            }
            if ($password === '') {
                $errors[] = 'Hasło jest wymagane.';
            }
            if ($createForm['imie'] === '') {
                $errors[] = 'Imię jest wymagane.';
            }
            if ($createForm['nazwisko'] === '') {
                $errors[] = 'Nazwisko jest wymagane.';
            }
            if ($createForm['telefon'] === '') {
                $errors[] = 'Telefon jest wymagany.';
            }
            if ($createForm['email'] === '') {
                $errors[] = 'Adres e-mail jest wymagany.';
            } elseif (!filter_var($createForm['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Adres e-mail ma nieprawidłowy format.';
            }
            if ($createForm['rola'] === '' || !array_key_exists($createForm['rola'], $availableRoles)) {
                $errors[] = 'Nieprawidłowa rola użytkownika.';
            }
            if (!$isSalesRole) {
                $createForm['prov_new_contract_pct'] = '0.00';
                $createForm['prov_renewal_pct'] = '0.00';
                $createForm['prov_next_invoice_pct'] = '0.00';
            }
            if ($isSalesRole && hasColumn($userColumns, 'prov_new_contract_pct')) {
                $createForm['prov_new_contract_pct'] = parseCommissionPercent($createForm['prov_new_contract_pct'], 'Nowa umowa (%)', $errors);
            }
            if ($isSalesRole && hasColumn($userColumns, 'prov_renewal_pct')) {
                $createForm['prov_renewal_pct'] = parseCommissionPercent($createForm['prov_renewal_pct'], 'Przedłużenie (%)', $errors);
            }
            if ($isSalesRole && hasColumn($userColumns, 'prov_next_invoice_pct')) {
                $createForm['prov_next_invoice_pct'] = parseCommissionPercent($createForm['prov_next_invoice_pct'], 'Kolejna faktura (%)', $errors);
            }

            if (!$errors) {
                $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM uzytkownicy WHERE login = :login');
                $existsStmt->execute([':login' => $createForm['login']]);
                if ((int)$existsStmt->fetchColumn() > 0) {
                    $errors[] = 'Podany login jest już zajęty.';
                }
            }

            if ($errors) {
                foreach ($errors as $msg) {
                    $alerts[] = ['type' => 'danger', 'msg' => $msg];
                }
            } else {
                $fields = ['login', 'haslo_hash', 'rola', 'imie', 'nazwisko', 'telefon', 'email'];
                $placeholders = [':login', ':haslo', ':rola', ':imie', ':nazwisko', ':telefon', ':email'];
                $params = [
                    ':login'    => $createForm['login'],
                    ':haslo'    => password_hash($password, PASSWORD_DEFAULT),
                    ':rola'     => $createForm['rola'],
                    ':imie'     => $createForm['imie'],
                    ':nazwisko' => $createForm['nazwisko'],
                    ':telefon'  => $createForm['telefon'],
                    ':email'    => $createForm['email'],
                ];
                if (hasColumn($userColumns, 'commission_enabled')) {
                    $fields[] = 'commission_enabled';
                    $placeholders[] = ':commission_enabled';
                    $params[':commission_enabled'] = (int)$createForm['commission_enabled'];
                }
                if (hasColumn($userColumns, 'prov_new_contract_pct')) {
                    $fields[] = 'prov_new_contract_pct';
                    $placeholders[] = ':prov_new_contract_pct';
                    $params[':prov_new_contract_pct'] = $isSalesRole ? (float)$createForm['prov_new_contract_pct'] : 0.00;
                }
                if (hasColumn($userColumns, 'prov_renewal_pct')) {
                    $fields[] = 'prov_renewal_pct';
                    $placeholders[] = ':prov_renewal_pct';
                    $params[':prov_renewal_pct'] = $isSalesRole ? (float)$createForm['prov_renewal_pct'] : 0.00;
                }
                if (hasColumn($userColumns, 'prov_next_invoice_pct')) {
                    $fields[] = 'prov_next_invoice_pct';
                    $placeholders[] = ':prov_next_invoice_pct';
                    $params[':prov_next_invoice_pct'] = $isSalesRole ? (float)$createForm['prov_next_invoice_pct'] : 0.00;
                }
                $insert = $pdo->prepare('INSERT INTO uzytkownicy (' . implode(', ', $fields) . ')
                    VALUES (' . implode(', ', $placeholders) . ')');
                $result = $insert->execute($params);
                if ($result) {
                    $alerts[] = ['type' => 'success', 'msg' => 'Dodano nowego użytkownika.'];
                    $createForm = $createDefaults;
                } else {
                    $alerts[] = ['type' => 'danger', 'msg' => 'Nie udało się dodać użytkownika.'];
                }
            }
            break;

        case 'update':
            if (!$canManageUsers) {
                http_response_code(403);
                $alerts[] = ['type' => 'danger', 'msg' => 'Brak uprawnień do edycji użytkowników.'];
                break;
            }
            $editUserIdFromGet = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
            $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
            if ($editUserIdFromGet <= 0 || $userId <= 0 || $editUserIdFromGet !== $userId) {
                $alerts[] = ['type' => 'danger', 'msg' => 'Nieprawidłowy identyfikator edytowanego użytkownika.'];
                break;
            }
            $editUserId = $userId;
            $editFormOverride = [
                'id'       => $userId,
                'login'    => trim((string)($_POST['login'] ?? '')),
                'imie'     => trim((string)($_POST['imie'] ?? '')),
                'nazwisko' => trim((string)($_POST['nazwisko'] ?? '')),
                'telefon'  => trim((string)($_POST['telefon'] ?? '')),
                'email'    => trim((string)($_POST['email'] ?? '')),
                'rola'     => trim((string)($_POST['rola'] ?? 'Handlowiec')),
                'commission_enabled' => isset($_POST['commission_enabled']) ? 1 : 0,
                'prov_new_contract_pct' => trim((string)($_POST['prov_new_contract_pct'] ?? '0.00')),
                'prov_renewal_pct' => trim((string)($_POST['prov_renewal_pct'] ?? '0.00')),
                'prov_next_invoice_pct' => trim((string)($_POST['prov_next_invoice_pct'] ?? '0.00')),
                'smtp_enabled' => isset($_POST['smtp_enabled']) ? 1 : 0,
                'smtp_host' => trim((string)($_POST['smtp_host'] ?? '')),
                'smtp_port' => (int)($_POST['smtp_port'] ?? 0),
                'smtp_secure' => in_array($_POST['smtp_secure'] ?? '', ['', 'tls', 'ssl'], true) ? (string)($_POST['smtp_secure'] ?? '') : '',
                'smtp_user' => trim((string)($_POST['smtp_user'] ?? '')),
                'smtp_from_email' => trim((string)($_POST['smtp_from_email'] ?? '')),
                'smtp_from_name' => trim((string)($_POST['smtp_from_name'] ?? '')),
                'imap_enabled' => isset($_POST['imap_enabled']) ? 1 : 0,
                'imap_host' => trim((string)($_POST['imap_host'] ?? '')),
                'imap_port' => (int)($_POST['imap_port'] ?? 0),
                'imap_secure' => in_array($_POST['imap_secure'] ?? '', ['', 'tls', 'ssl'], true) ? (string)($_POST['imap_secure'] ?? '') : '',
                'imap_user' => trim((string)($_POST['imap_user'] ?? '')),
                'imap_mailbox' => trim((string)($_POST['imap_mailbox'] ?? 'INBOX')),
            ];
            $newPassword = (string)($_POST['password'] ?? '');
            $smtpPassInput = (string)($_POST['smtp_pass'] ?? '');
            $smtpPassClear = !empty($_POST['smtp_pass_clear']);
            $imapPassInput = (string)($_POST['imap_pass'] ?? '');
            $imapPassClear = !empty($_POST['imap_pass_clear']);
            $errors = [];
            $editFormOverride['rola'] = canonicalRoleValue($editFormOverride['rola']) ?? '';
            $isSalesRole = ($editFormOverride['rola'] === 'Handlowiec');

            if ($userId <= 0) {
                $errors[] = 'Nieprawidłowy identyfikator użytkownika.';
            }
            if ($editFormOverride['login'] === '') {
                $errors[] = 'Login jest wymagany.';
            }
            if ($editFormOverride['imie'] === '') {
                $errors[] = 'Imię jest wymagane.';
            }
            if ($editFormOverride['nazwisko'] === '') {
                $errors[] = 'Nazwisko jest wymagane.';
            }
            if ($editFormOverride['telefon'] === '') {
                $errors[] = 'Telefon jest wymagany.';
            }
            if ($editFormOverride['email'] === '') {
                $errors[] = 'Adres e-mail jest wymagany.';
            } elseif (!filter_var($editFormOverride['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Adres e-mail ma nieprawidłowy format.';
            }
            if ($editFormOverride['smtp_from_email'] !== '' && !filter_var($editFormOverride['smtp_from_email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Adres nadawcy SMTP ma nieprawidlowy format.';
            }
            if ($editFormOverride['imap_mailbox'] === '') {
                $editFormOverride['imap_mailbox'] = 'INBOX';
            }
            if ($editFormOverride['rola'] === '' || !array_key_exists($editFormOverride['rola'], $availableRoles)) {
                $errors[] = 'Nieprawidłowa rola użytkownika.';
            }
            if (!$isSalesRole) {
                $editFormOverride['prov_new_contract_pct'] = '0.00';
                $editFormOverride['prov_renewal_pct'] = '0.00';
                $editFormOverride['prov_next_invoice_pct'] = '0.00';
            }
            if ($isSalesRole && hasColumn($userColumns, 'prov_new_contract_pct')) {
                $editFormOverride['prov_new_contract_pct'] = parseCommissionPercent($editFormOverride['prov_new_contract_pct'], 'Nowa umowa (%)', $errors);
            }
            if ($isSalesRole && hasColumn($userColumns, 'prov_renewal_pct')) {
                $editFormOverride['prov_renewal_pct'] = parseCommissionPercent($editFormOverride['prov_renewal_pct'], 'Przedłużenie (%)', $errors);
            }
            if ($isSalesRole && hasColumn($userColumns, 'prov_next_invoice_pct')) {
                $editFormOverride['prov_next_invoice_pct'] = parseCommissionPercent($editFormOverride['prov_next_invoice_pct'], 'Kolejna faktura (%)', $errors);
            }

            if (!$errors) {
                $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM uzytkownicy WHERE id = :id');
                $existsStmt->execute([':id' => $userId]);
                if ((int)$existsStmt->fetchColumn() === 0) {
                    $errors[] = 'Wybrany użytkownik nie istnieje.';
                }
            }

            if (!$errors) {
                $loginStmt = $pdo->prepare('SELECT COUNT(*) FROM uzytkownicy WHERE login = :login AND id <> :id');
                $loginStmt->execute([
                    ':login' => $editFormOverride['login'],
                    ':id'    => $userId,
                ]);
                if ((int)$loginStmt->fetchColumn() > 0) {
                    $errors[] = 'Podany login jest już używany przez innego użytkownika.';
                }
            }

            if ($errors) {
                foreach ($errors as $msg) {
                    $alerts[] = ['type' => 'danger', 'msg' => $msg];
                }
            } else {
                $fields = [
                    'login = :login',
                    'rola = :rola',
                    'imie = :imie',
                    'nazwisko = :nazwisko',
                    'telefon = :telefon',
                    'email = :email',
                ];
                $params = [
                    ':login'    => $editFormOverride['login'],
                    ':rola'     => $editFormOverride['rola'],
                    ':imie'     => $editFormOverride['imie'],
                    ':nazwisko' => $editFormOverride['nazwisko'],
                    ':telefon'  => $editFormOverride['telefon'],
                    ':email'    => $editFormOverride['email'],
                    ':id'       => $userId,
                ];
                if (hasColumn($userColumns, 'commission_enabled')) {
                    $fields[] = 'commission_enabled = :commission_enabled';
                    $params[':commission_enabled'] = (int)$editFormOverride['commission_enabled'];
                }
                if (hasColumn($userColumns, 'prov_new_contract_pct')) {
                    $fields[] = 'prov_new_contract_pct = :prov_new_contract_pct';
                    $params[':prov_new_contract_pct'] = $isSalesRole ? (float)$editFormOverride['prov_new_contract_pct'] : 0.00;
                }
                if (hasColumn($userColumns, 'prov_renewal_pct')) {
                    $fields[] = 'prov_renewal_pct = :prov_renewal_pct';
                    $params[':prov_renewal_pct'] = $isSalesRole ? (float)$editFormOverride['prov_renewal_pct'] : 0.00;
                }
                if (hasColumn($userColumns, 'prov_next_invoice_pct')) {
                    $fields[] = 'prov_next_invoice_pct = :prov_next_invoice_pct';
                    $params[':prov_next_invoice_pct'] = $isSalesRole ? (float)$editFormOverride['prov_next_invoice_pct'] : 0.00;
                }
                if ($newPassword !== '') {
                    $fields[] = 'haslo_hash = :haslo';
                    $params[':haslo'] = password_hash($newPassword, PASSWORD_DEFAULT);
                }

                $update = $pdo->prepare('UPDATE uzytkownicy SET ' . implode(', ', $fields) . ' WHERE id = :id');
                if ($update->execute($params)) {
                    $alerts[] = ['type' => 'success', 'msg' => 'Dane użytkownika zostały zaktualizowane.'];
                    if ((int)$_SESSION['user_id'] === $userId) {
                        $roleStmt = $pdo->prepare('SELECT rola FROM uzytkownicy WHERE id = :id');
                        $roleStmt->execute([':id' => $userId]);
                        $dbRole = trim((string)$roleStmt->fetchColumn());
                        $normalized = normalizeRole(['rola' => $dbRole]);
                        $_SESSION['user_role'] = $normalized;
                        $_SESSION['rola'] = $normalized;
                        if (isSuperAdmin()) {
                            $_SESSION['user_role'] = 'Administrator';
                            $_SESSION['rola'] = 'Administrator';
                            $_SESSION['is_superadmin'] = true;
                        }
                    }
                    $mailConfigPosted = array_key_exists('imap_host', $_POST)
                        || array_key_exists('smtp_host', $_POST)
                        || array_key_exists('imap_user', $_POST)
                        || array_key_exists('smtp_user', $_POST)
                        || array_key_exists('smtp_from_email', $_POST)
                        || array_key_exists('imap_enabled', $_POST)
                        || array_key_exists('smtp_enabled', $_POST);
                    if ($mailConfigPosted) {
                        $debugId = uniqid('mailcfg_', true);
                        ensureCrmMailTables($pdo);
                        if ($userId <= 0) {
                            error_log('[MAIL_SAVE][' . $debugId . '] invalid user_id=' . $userId);
                            if ($mailDebugEnabled) {
                                $dbInfo = fetchMailDbDebugInfo($pdo);
                                $mailDebugPayload = [
                                    'id' => $debugId,
                                    'sqlstate' => '',
                                    'driver_code' => '',
                                    'message' => 'Invalid user_id.',
                                    'db' => $dbInfo['db'],
                                    'db_user' => $dbInfo['db_user'],
                                    'host' => $dbInfo['host'],
                                ];
                            }
                            $alerts[] = [
                                'type' => 'danger',
                                'msg' => 'Nie udało się zapisać konfiguracji poczty. (debug: ' . $debugId . ')',
                            ];
                            break;
                        }
                        $mailEmail = $editFormOverride['smtp_from_email'] !== ''
                            ? $editFormOverride['smtp_from_email']
                            : $editFormOverride['email'];
                        $mailUsername = $editFormOverride['imap_user'] !== ''
                            ? $editFormOverride['imap_user']
                            : $editFormOverride['smtp_user'];
                        $imapEncryption = $editFormOverride['imap_secure'] !== '' ? $editFormOverride['imap_secure'] : 'none';
                        $smtpEncryption = $editFormOverride['smtp_secure'] !== '' ? $editFormOverride['smtp_secure'] : 'none';
                        $imapPort = $editFormOverride['imap_port'] > 0 ? $editFormOverride['imap_port'] : 993;
                        $smtpPort = $editFormOverride['smtp_port'] > 0 ? $editFormOverride['smtp_port'] : 587;
                        $imapMailbox = $editFormOverride['imap_mailbox'] !== '' ? $editFormOverride['imap_mailbox'] : 'INBOX';
                        $isActive = (!empty($editFormOverride['imap_enabled']) || !empty($editFormOverride['smtp_enabled'])) ? 1 : 0;
                        $passwordAction = 'keep';
                        $mailPassword = '';
                        if ($imapPassClear || $smtpPassClear) {
                            $passwordAction = 'clear';
                        } elseif ($imapPassInput !== '') {
                            $passwordAction = 'set';
                            $mailPassword = $imapPassInput;
                        } elseif ($smtpPassInput !== '') {
                            $passwordAction = 'set';
                            $mailPassword = $smtpPassInput;
                        }
                        try {
                            $mailSql = '';
                            $mailParams = [];
                            $mailErrorInfo = null;
                            $columnInfo = resolveMailAccountColumns($pdo);
                            if ($columnInfo['missing']) {
                                error_log('[MAIL_SAVE][' . $debugId . '] missing columns: ' . implode(',', $columnInfo['missing']));
                                throw new RuntimeException('Missing mail_accounts columns: ' . implode(',', $columnInfo['missing']));
                            }
                            $map = $columnInfo['map'];
                            $userIdColumn = $map['user_id'][0] ?? 'user_id';
                            $existingStmt = $pdo->prepare(
                                sprintf('SELECT id FROM mail_accounts WHERE `%s` = :user_id LIMIT 1', $userIdColumn)
                            );
                            $existingStmt->execute([':user_id' => $userId]);
                            $existingId = (int)$existingStmt->fetchColumn();
                            $columnValues = [];
                            foreach ($map['user_id'] as $col) {
                                $columnValues[$col] = $userId;
                            }
                            foreach ($map['imap_host'] as $col) {
                                $columnValues[$col] = $editFormOverride['imap_host'];
                            }
                            foreach ($map['imap_port'] as $col) {
                                $columnValues[$col] = $imapPort;
                            }
                            foreach ($map['imap_encryption'] as $col) {
                                $columnValues[$col] = $imapEncryption;
                            }
                            foreach ($map['imap_mailbox'] as $col) {
                                $columnValues[$col] = $imapMailbox;
                            }
                            foreach ($map['smtp_host'] as $col) {
                                $columnValues[$col] = $editFormOverride['smtp_host'];
                            }
                            foreach ($map['smtp_port'] as $col) {
                                $columnValues[$col] = $smtpPort;
                            }
                            foreach ($map['smtp_encryption'] as $col) {
                                $columnValues[$col] = $smtpEncryption;
                            }
                            foreach ($map['email_address'] as $col) {
                                $columnValues[$col] = $mailEmail;
                            }
                            foreach ($map['smtp_from_name'] as $col) {
                                $columnValues[$col] = $editFormOverride['smtp_from_name'];
                            }
                            foreach ($map['username'] as $col) {
                                $columnValues[$col] = $mailUsername;
                            }
                            foreach ($map['smtp_user'] as $col) {
                                $columnValues[$col] = $editFormOverride['smtp_user'] !== ''
                                    ? $editFormOverride['smtp_user']
                                    : $mailUsername;
                            }
                            foreach ($map['imap_user'] as $col) {
                                $columnValues[$col] = $editFormOverride['imap_user'] !== ''
                                    ? $editFormOverride['imap_user']
                                    : $mailUsername;
                            }
                            foreach ($map['is_active'] as $col) {
                                $columnValues[$col] = $isActive;
                            }
                            foreach ($map['smtp_enabled'] as $col) {
                                $columnValues[$col] = (int)$editFormOverride['smtp_enabled'];
                            }
                            foreach ($map['imap_enabled'] as $col) {
                                $columnValues[$col] = (int)$editFormOverride['imap_enabled'];
                            }

                            $passwordColumns = array_values(array_unique(array_merge(
                                $map['password_enc'],
                                $map['smtp_pass_enc'],
                                $map['imap_pass_enc'],
                                $map['smtp_pass'],
                                $map['imap_pass']
                            )));
                            $passwordActionInsert = $passwordAction === 'keep' ? 'clear' : $passwordAction;
                            if ($passwordActionInsert !== 'keep') {
                                foreach ($passwordColumns as $col) {
                                    if ($passwordActionInsert === 'set') {
                                        $columnValues[$col] = mailAccountPasswordNeedsEncryption($col)
                                            ? encryptMailSecret($mailPassword)
                                            : $mailPassword;
                                    } else {
                                        $columnValues[$col] = mailAccountColumnAllowsNull($pdo, $col) ? null : '';
                                    }
                                }
                            }

                            $insertValues = $columnValues;
                            $updateValues = $columnValues;
                            foreach ($map['user_id'] as $col) {
                                unset($updateValues[$col]);
                            }
                            if ($existingId > 0 && $passwordAction === 'keep') {
                                foreach ($passwordColumns as $col) {
                                    unset($updateValues[$col]);
                                }
                            }

                            if ($existingId > 0) {
                                $assignments = [];
                                $params = [];
                                foreach ($updateValues as $col => $value) {
                                    $param = mailAccountColumnParam($col);
                                    $assignments[] = sprintf('`%s` = %s', $col, $param);
                                    $params[$param] = $value;
                                }
                                $params[':where_user_id'] = $userId;
                                $mailSql = sprintf(
                                    'UPDATE mail_accounts SET %s WHERE `%s` = :where_user_id',
                                    implode(', ', $assignments),
                                    $userIdColumn
                                );
                                $mailParams = $params;
                                $mailStmt = $pdo->prepare($mailSql);
                                $ok = $mailStmt->execute($params);
                                if ($ok === false) {
                                    $mailErrorInfo = $mailStmt->errorInfo();
                                    error_log('[MAIL_SAVE][' . $debugId . '] errorInfo=' . print_r($mailErrorInfo, true));
                                    throw new RuntimeException('Mail account update failed.');
                                }
                            } else {
                                $columns = array_keys($insertValues);
                                $placeholders = [];
                                $params = [];
                                foreach ($insertValues as $col => $value) {
                                    $param = mailAccountColumnParam($col);
                                    $placeholders[] = $param;
                                    $params[$param] = $value;
                                }
                                $mailSql = sprintf(
                                    'INSERT INTO mail_accounts (%s) VALUES (%s)',
                                    implode(', ', array_map(fn(string $col): string => sprintf('`%s`', $col), $columns)),
                                    implode(', ', $placeholders)
                                );
                                $mailParams = $params;
                                $mailStmt = $pdo->prepare($mailSql);
                                $ok = $mailStmt->execute($params);
                                if ($ok === false) {
                                    $mailErrorInfo = $mailStmt->errorInfo();
                                    error_log('[MAIL_SAVE][' . $debugId . '] errorInfo=' . print_r($mailErrorInfo, true));
                                    throw new RuntimeException('Mail account insert failed.');
                                }
                            }

                            $verifyStmt = $pdo->prepare(
                                sprintf('SELECT * FROM mail_accounts WHERE `%s` = :user_id LIMIT 1', $userIdColumn)
                            );
                            $verifyStmt->execute([':user_id' => $userId]);
                            $verifyRow = $verifyStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                            if (!$verifyRow) {
                                throw new RuntimeException('Mail account save verification failed.');
                            }

                            $alerts[] = ['type' => 'success', 'msg' => 'Zapisano.'];
                            $dbInfo = ['db' => '-', 'host' => '-'];
                            try {
                                $dbStmt = $pdo->query('SELECT DATABASE() AS db, @@hostname AS host');
                                $dbRow = $dbStmt ? ($dbStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
                                $dbInfo['db'] = (string)($dbRow['db'] ?? '-');
                                $dbInfo['host'] = (string)($dbRow['host'] ?? '-');
                            } catch (Throwable $e) {
                                $dbInfo['db'] = 'ERR';
                                $dbInfo['host'] = 'ERR';
                            }
                            $targetUid = (int)$userId;
                            $email = $mailEmail !== '' ? $mailEmail : '-';
                            $is_active = (int)$isActive;
                            error_log(
                                '[MAIL_SAVE][' . $debugId . '] target_uid=' . $targetUid
                                . ' db=' . $dbInfo['db']
                                . ' host=' . $dbInfo['host']
                                . ' email=' . $email
                                . ' active=' . $is_active
                            );
                        } catch (PDOException $e) {
                            error_log('[MAIL_SAVE][' . $debugId . '] code=' . $e->getCode() . ' msg=' . $e->getMessage());
                            error_log($e->getTraceAsString());
                            if (!empty($mailSql)) {
                                error_log('[MAIL_SAVE][' . $debugId . '] sql=' . $mailSql);
                                if (!empty($mailParams)) {
                                    error_log('[MAIL_SAVE][' . $debugId . '] params=' . implode(',', array_keys($mailParams)));
                                }
                            }
                            if ($mailDebugEnabled) {
                                $dbInfo = fetchMailDbDebugInfo($pdo);
                                $sqlstate = (string)$e->getCode();
                                $driverCode = '';
                                $message = $e->getMessage();
                                if (property_exists($e, 'errorInfo') && is_array($e->errorInfo)) {
                                    $sqlstate = (string)($e->errorInfo[0] ?? $sqlstate);
                                    $driverCode = (string)($e->errorInfo[1] ?? '');
                                    $message = (string)($e->errorInfo[2] ?? $message);
                                }
                                $mailDebugPayload = [
                                    'id' => $debugId,
                                    'sqlstate' => $sqlstate,
                                    'driver_code' => $driverCode,
                                    'message' => $message,
                                    'db' => $dbInfo['db'],
                                    'db_user' => $dbInfo['db_user'],
                                    'host' => $dbInfo['host'],
                                ];
                            }
                            $alerts[] = [
                                'type' => 'danger',
                                'msg' => 'Nie udało się zapisać konfiguracji poczty. (debug: ' . $debugId . ')',
                            ];
                        } catch (Throwable $e) {
                            error_log('[MAIL_SAVE][' . $debugId . '] ' . $e->getMessage());
                            error_log($e->getTraceAsString());
                            if (!empty($mailSql)) {
                                error_log('[MAIL_SAVE][' . $debugId . '] sql=' . $mailSql);
                                if (!empty($mailParams)) {
                                    error_log('[MAIL_SAVE][' . $debugId . '] params=' . implode(',', array_keys($mailParams)));
                                }
                            }
                            if ($mailDebugEnabled) {
                                $dbInfo = fetchMailDbDebugInfo($pdo);
                                $sqlstate = '';
                                $driverCode = '';
                                $message = $e->getMessage();
                                if (is_array($mailErrorInfo)) {
                                    $sqlstate = (string)($mailErrorInfo[0] ?? '');
                                    $driverCode = (string)($mailErrorInfo[1] ?? '');
                                    $message = (string)($mailErrorInfo[2] ?? $message);
                                }
                                $mailDebugPayload = [
                                    'id' => $debugId,
                                    'sqlstate' => $sqlstate,
                                    'driver_code' => $driverCode,
                                    'message' => $message,
                                    'db' => $dbInfo['db'],
                                    'db_user' => $dbInfo['db_user'],
                                    'host' => $dbInfo['host'],
                                ];
                            }
                            $alerts[] = [
                                'type' => 'danger',
                                'msg' => 'Nie udało się zapisać konfiguracji poczty. (debug: ' . $debugId . ')',
                            ];
                        }
                    }
                    $editFormOverride = null;
                } else {
                    $alerts[] = ['type' => 'danger', 'msg' => 'Nie udało się zaktualizować użytkownika.'];
                }
            }
            break;

        case 'delete':
            if (!$canManageUsers) {
                http_response_code(403);
                $alerts[] = ['type' => 'danger', 'msg' => 'Brak uprawnień do usuwania użytkowników.'];
                break;
            }
            $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
            if ($userId <= 0) {
                $alerts[] = ['type' => 'danger', 'msg' => 'Nieprawidłowy identyfikator użytkownika.'];
                break;
            }
            if ($userId === (int)$currentUser['id']) {
                $alerts[] = ['type' => 'danger', 'msg' => 'Nie możesz usunąć własnego konta.'];
                break;
            }

            $delete = $pdo->prepare('DELETE FROM uzytkownicy WHERE id = :id');
            if ($delete->execute([':id' => $userId])) {
                $alerts[] = ['type' => 'success', 'msg' => 'Użytkownik został usunięty.'];
                if ($editUserId === $userId) {
                    $editUserId = null;
                }
            } else {
                $alerts[] = ['type' => 'danger', 'msg' => 'Nie udało się usunąć użytkownika.'];
            }
            break;

        default:
            $alerts[] = ['type' => 'danger', 'msg' => 'Nieznane działanie.'];
            break;
        }
    }
}

$userListSelect = [
    'u.`id`',
    'u.`login`',
    selectOrEmpty('u', 'imie', $userColumns),
    selectOrEmpty('u', 'nazwisko', $userColumns),
    selectOrEmpty('u', 'telefon', $userColumns),
    selectOrEmpty('u', 'email', $userColumns),
    selectOrEmpty('u', 'rola', $userColumns),
    selectOrEmpty('u', 'commission_enabled', $userColumns),
    selectOrEmpty('u', 'prov_new_contract_pct', $userColumns),
    selectOrEmpty('u', 'prov_renewal_pct', $userColumns),
    selectOrEmpty('u', 'prov_next_invoice_pct', $userColumns),
    selectOrEmpty('u', 'smtp_enabled', $userColumns),
    selectOrEmpty('u', 'smtp_host', $userColumns),
    selectOrEmpty('u', 'smtp_port', $userColumns),
    selectOrEmpty('u', 'smtp_secure', $userColumns),
    selectOrEmpty('u', 'smtp_user', $userColumns),
    selectOrEmpty('u', 'smtp_pass_enc', $userColumns),
    selectOrEmpty('u', 'smtp_from_email', $userColumns),
    selectOrEmpty('u', 'smtp_from_name', $userColumns),
    selectOrEmpty('u', 'imap_enabled', $userColumns),
    selectOrEmpty('u', 'imap_host', $userColumns),
    selectOrEmpty('u', 'imap_port', $userColumns),
    selectOrEmpty('u', 'imap_secure', $userColumns),
    selectOrEmpty('u', 'imap_user', $userColumns),
    selectOrEmpty('u', 'imap_pass_enc', $userColumns),
    selectOrEmpty('u', 'imap_mailbox', $userColumns),
    selectOrEmpty('u', 'imap_last_uid', $userColumns),
    selectOrEmpty('u', 'imap_last_sync_at', $userColumns),
];
$usersStmt = $pdo->query('SELECT ' . implode(', ', $userListSelect) . ' FROM uzytkownicy AS u ORDER BY u.`id`');
$users = $usersStmt ? $usersStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$editUser = null;
if ($editUserId) {
    $editStmt = $pdo->prepare('SELECT ' . implode(', ', $userListSelect) . ' FROM uzytkownicy AS u WHERE u.`id` = :id');
    $editStmt->execute([':id' => $editUserId]);
    $editUser = $editStmt->fetch(PDO::FETCH_ASSOC);
    if (!$editUser) {
        $alerts[] = ['type' => 'warning', 'msg' => 'Nie znaleziono wybranego użytkownika do edycji.'];
        $editUserId = null;
    }
}
if ($editUser) {
    $mailAccount = fetchMailAccountForEdit($pdo, (int)$editUser['id']);
    $editUser = array_merge($editUser, mapMailAccountToForm($mailAccount));
}
if ($editFormOverride && $editUserId && $editUser && (int)$editUser['id'] === $editFormOverride['id']) {
    $editUser = array_merge($editUser, $editFormOverride);
}
if ($editUser) {
    $editUser['rola'] = canonicalRoleValue((string)($editUser['rola'] ?? '')) ?? 'Handlowiec';
}

$pageStyles = ['users'];
include 'includes/header.php';
?>
<main class="page-shell page-shell--users users-page">
  <div class="card shadow-sm users-page-intro">
    <div class="card-body">
      <p class="text-uppercase text-muted fw-semibold small mb-1">Zespół i uprawnienia</p>
      <h1 class="app-title">Użytkownicy i role</h1>
      <p class="text-muted mb-3">
        Kanoniczne wejście do zarządzania użytkownikami, rolami, pocztą użytkownika, podpisami i prowizjami.
        Legacy ekrany pozostają aktywne, ale prowadzą z tego obszaru.
      </p>
      <div class="d-flex flex-wrap gap-2 users-chip-list">
        <span class="badge text-bg-light">Użytkownicy</span>
        <span class="badge text-bg-light">Role i uprawnienia</span>
        <span class="badge text-bg-light">Poczta użytkownika</span>
        <span class="badge text-bg-light">Podpisy</span>
        <span class="badge text-bg-light">Prowizje</span>
      </div>
    </div>
  </div>

  <?php foreach ($alerts as $alert): ?>
    <div class="alert alert-<?= htmlspecialchars($alert['type']) ?>"><?= htmlspecialchars($alert['msg']) ?></div>
  <?php endforeach; ?>

  <?php if ($mailDebugEnabled && $mailDebugPayload): ?>
    <div class="alert alert-secondary">
      <div class="fw-semibold mb-1">Mail debug</div>
      <div>debug_id: <?= htmlspecialchars($mailDebugPayload['id'] ?? '-') ?></div>
      <div>SQLSTATE: <?= htmlspecialchars($mailDebugPayload['sqlstate'] !== '' ? $mailDebugPayload['sqlstate'] : '-') ?></div>
      <div>Driver code: <?= htmlspecialchars($mailDebugPayload['driver_code'] !== '' ? $mailDebugPayload['driver_code'] : '-') ?></div>
      <div>Message: <?= htmlspecialchars($mailDebugPayload['message'] ?? '-') ?></div>
      <div>DB: <?= htmlspecialchars($mailDebugPayload['db'] !== '' ? $mailDebugPayload['db'] : '-') ?></div>
      <div>DB user: <?= htmlspecialchars($mailDebugPayload['db_user'] !== '' ? $mailDebugPayload['db_user'] : '-') ?></div>
      <div>Host: <?= htmlspecialchars($mailDebugPayload['host'] !== '' ? $mailDebugPayload['host'] : '-') ?></div>
    </div>
  <?php endif; ?>

  <?php if ($editUser && $canManageUsers): ?>
    <div class="card shadow-sm mb-4" id="section-edit-user">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Edycja użytkownika: <?= htmlspecialchars(trim(($editUser['imie'] ?? '') . ' ' . ($editUser['nazwisko'] ?? ''))) ?></span>
        <a href="uzytkownicy.php" class="btn btn-sm btn-outline-secondary">Zamknij edycję</a>
      </div>
      <div class="card-body">
        <form method="post" class="row g-3" action="<?= BASE_URL ?>/uzytkownicy.php?edit=<?= (int)$editUser['id'] ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">

          <div class="col-12">
            <div class="users-form-block border rounded-3 p-3 h-100">
              <div class="fw-semibold mb-2">Użytkownik</div>
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">Login</label>
                  <input type="text" class="form-control" name="login" value="<?= htmlspecialchars($editUser['login'] ?? '') ?>" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Nowe hasło</label>
                  <input type="password" class="form-control" name="password" placeholder="Pozostaw puste bez zmian">
                </div>
                <div class="col-md-4">
                  <label class="form-label">E-mail</label>
                  <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($editUser['email'] ?? '') ?>" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Imię</label>
                  <input type="text" class="form-control" name="imie" value="<?= htmlspecialchars($editUser['imie'] ?? '') ?>" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Nazwisko</label>
                  <input type="text" class="form-control" name="nazwisko" value="<?= htmlspecialchars($editUser['nazwisko'] ?? '') ?>" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Telefon</label>
                  <input type="text" class="form-control" name="telefon" value="<?= htmlspecialchars($editUser['telefon'] ?? '') ?>" required>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12">
            <div class="users-form-block border rounded-3 p-3 h-100">
              <div class="fw-semibold mb-2">Role i uprawnienia</div>
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">Rola</label>
                  <select name="rola" id="editRoleSelect" class="form-select" required>
                    <?php foreach ($availableRoles as $roleKey => $roleLabel): ?>
                      <option value="<?= htmlspecialchars($roleKey) ?>" <?= ($editUser['rola'] ?? '') === $roleKey ? 'selected' : '' ?>>
                        <?= htmlspecialchars($roleLabel) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-8 d-flex align-items-end">
                  <div class="text-muted small">
                    Role definiują dostęp do obszarów CRM. To ekran kanoniczny dla zarządzania zespołem i uprawnieniami.
                  </div>
                </div>
              </div>
            </div>
          </div>

          <?php if (($editUser['rola'] ?? '') === 'Handlowiec'): ?>
            <div class="col-12">
              <div class="users-form-block border rounded-3 p-3 h-100">
                <div class="fw-semibold mb-2">Poczta użytkownika</div>
                <div class="row g-3">
                  <div class="col-12">
                    <div class="fw-semibold text-muted small text-uppercase">SMTP</div>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Aktywne</label>
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" role="switch" name="smtp_enabled" value="1"
                             <?= !empty($editUser['smtp_enabled']) ? 'checked' : '' ?>>
                      <label class="form-check-label">Włączone</label>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Host SMTP</label>
                    <input type="text" class="form-control" name="smtp_host" value="<?= htmlspecialchars($editUser['smtp_host'] ?? '') ?>">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Port</label>
                    <input type="number" class="form-control" name="smtp_port" value="<?= htmlspecialchars((string)($editUser['smtp_port'] ?? '')) ?>">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Szyfrowanie</label>
                    <?php $smtpSecure = $editUser['smtp_secure'] ?? 'tls'; ?>
                    <select name="smtp_secure" class="form-select">
                      <option value="" <?= $smtpSecure === '' ? 'selected' : '' ?>>Brak</option>
                      <option value="tls" <?= $smtpSecure === 'tls' ? 'selected' : '' ?>>TLS</option>
                      <option value="ssl" <?= $smtpSecure === 'ssl' ? 'selected' : '' ?>>SSL</option>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">SMTP user</label>
                    <input type="text" class="form-control" name="smtp_user" value="<?= htmlspecialchars($editUser['smtp_user'] ?? '') ?>">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">SMTP hasło</label>
                    <input type="password" class="form-control" name="smtp_pass" placeholder="Pozostaw puste bez zmian">
                    <?php if (!empty($editUser['smtp_pass_enc'])): ?>
                      <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="smtp_pass_clear" name="smtp_pass_clear" value="1">
                        <label class="form-check-label" for="smtp_pass_clear">Usuń zapisane hasło</label>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">From email</label>
                    <input type="email" class="form-control" name="smtp_from_email" value="<?= htmlspecialchars($editUser['smtp_from_email'] ?? '') ?>">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">From name</label>
                    <input type="text" class="form-control" name="smtp_from_name" value="<?= htmlspecialchars($editUser['smtp_from_name'] ?? '') ?>">
                  </div>
                  <div class="col-12">
                    <div class="text-muted small">Dane SMTP ustawia Administrator lub Manager.</div>
                  </div>

                  <div class="col-12 pt-2">
                    <div class="fw-semibold text-muted small text-uppercase">IMAP</div>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Aktywne</label>
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" role="switch" name="imap_enabled" value="1"
                             <?= !empty($editUser['imap_enabled']) ? 'checked' : '' ?>>
                      <label class="form-check-label">Włączone</label>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Host IMAP</label>
                    <input type="text" class="form-control" name="imap_host" value="<?= htmlspecialchars($editUser['imap_host'] ?? '') ?>">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Port</label>
                    <input type="number" class="form-control" name="imap_port" value="<?= htmlspecialchars((string)($editUser['imap_port'] ?? '')) ?>">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Szyfrowanie</label>
                    <?php $imapSecure = $editUser['imap_secure'] ?? 'tls'; ?>
                    <select name="imap_secure" class="form-select">
                      <option value="" <?= $imapSecure === '' ? 'selected' : '' ?>>Brak</option>
                      <option value="tls" <?= $imapSecure === 'tls' ? 'selected' : '' ?>>TLS</option>
                      <option value="ssl" <?= $imapSecure === 'ssl' ? 'selected' : '' ?>>SSL</option>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">IMAP user</label>
                    <input type="text" class="form-control" name="imap_user" value="<?= htmlspecialchars($editUser['imap_user'] ?? '') ?>">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">IMAP hasło</label>
                    <input type="password" class="form-control" name="imap_pass" placeholder="Pozostaw puste bez zmian">
                    <?php if (!empty($editUser['imap_pass_enc'])): ?>
                      <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="imap_pass_clear" name="imap_pass_clear" value="1">
                        <label class="form-check-label" for="imap_pass_clear">Usuń zapisane hasło</label>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Folder (mailbox)</label>
                    <input type="text" class="form-control" name="imap_mailbox" value="<?= htmlspecialchars($editUser['imap_mailbox'] ?? 'INBOX') ?>">
                  </div>
                  <div class="col-12">
                    <div class="text-muted small">IMAP służy do pobierania poczty przychodzącej do CRM.</div>
                  </div>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <div class="col-12">
            <div class="users-form-block border rounded-3 p-3 h-100">
              <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                  <div class="fw-semibold mb-2">Podpisy</div>
                  <p class="text-muted small mb-0">
                    Podpis e-mail i zaawansowana konfiguracja poczty pozostają dostępne na dedykowanym ekranie użytkownika.
                  </p>
                </div>
                <a class="btn btn-outline-secondary btn-sm" href="uzytkownik_edytuj.php?id=<?= (int)$editUser['id'] ?>">Otwórz pocztę i podpis</a>
              </div>
            </div>
          </div>

          <?php if (hasColumn($userColumns, 'commission_enabled') || hasColumn($userColumns, 'prov_new_contract_pct')): ?>
            <div class="col-12" id="editCommissionSection" style="<?= ($editUser['rola'] ?? '') === 'Handlowiec' ? '' : 'display:none;' ?>">
              <div class="users-form-block border rounded-3 p-3 h-100">
                <div class="fw-semibold mb-2">Prowizje</div>
                <div class="row g-3">
                  <?php if (hasColumn($userColumns, 'commission_enabled')): ?>
                    <div class="col-md-3">
                      <label class="form-label">Prowizja aktywna</label>
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" name="commission_enabled" value="1"
                               <?= !empty($editUser['commission_enabled']) ? 'checked' : '' ?>>
                        <label class="form-check-label">Tak</label>
                      </div>
                    </div>
                  <?php endif; ?>
                  <?php if (hasColumn($userColumns, 'prov_new_contract_pct')): ?>
                    <div class="col-md-3">
                      <label class="form-label">Nowa umowa (%)</label>
                      <input type="number" class="form-control" name="prov_new_contract_pct" step="0.01" min="0" max="100"
                             placeholder="0.00" value="<?= htmlspecialchars((string)($editUser['prov_new_contract_pct'] ?? '0.00')) ?>">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Przedłużenie (%)</label>
                      <input type="number" class="form-control" name="prov_renewal_pct" step="0.01" min="0" max="100"
                             placeholder="0.00" value="<?= htmlspecialchars((string)($editUser['prov_renewal_pct'] ?? '0.00')) ?>">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Kolejna faktura (%)</label>
                      <input type="number" class="form-control" name="prov_next_invoice_pct" step="0.01" min="0" max="100"
                             placeholder="0.00" value="<?= htmlspecialchars((string)($editUser['prov_next_invoice_pct'] ?? '0.00')) ?>">
                    </div>
                  <?php endif; ?>
                </div>
                <div class="form-text mt-2">Wartości w procentach, liczone od netto.</div>
              </div>
            </div>
          <?php endif; ?>

          <div class="col-12 d-flex justify-content-end">
            <button type="submit" class="btn btn-primary">Zapisz zmiany</button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <div class="card shadow-sm mb-4 users-table-card" id="section-users">
    <div class="card-header fw-semibold">Użytkownicy</div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-striped table-bordered align-middle">
          <thead class="table-light">
            <tr>
              <th>ID</th>
              <th>Login</th>
              <th>Imię</th>
              <th>Nazwisko</th>
              <th>Telefon</th>
              <th>E-mail</th>
              <th>Rola</th>
              <?php if ($canManageUsers && hasColumn($userColumns, 'prov_new_contract_pct')): ?>
                <th>Prowizje</th>
              <?php endif; ?>
              <th class="text-end">Akcje</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($users as $user):
              $firstName = trim((string)($user['imie'] ?? ''));
              $lastName = trim((string)($user['nazwisko'] ?? ''));
              $userEmail = trim((string)($user['email'] ?? ''));
              $roleLabel = displayRoleLabel($user['rola'] ?? '');
              $phone = trim((string)($user['telefon'] ?? ''));
              $commissionSummary = '-';
              $isSalesUser = (canonicalRoleValue((string)($user['rola'] ?? '')) ?? '') === 'Handlowiec';
              if ($canManageUsers && hasColumn($userColumns, 'prov_new_contract_pct') && $isSalesUser) {
                  $commissionSummary = sprintf(
                      'N: %s%% / P: %s%% / K: %s%%',
                      number_format((float)($user['prov_new_contract_pct'] ?? 0), 2, '.', ''),
                      number_format((float)($user['prov_renewal_pct'] ?? 0), 2, '.', ''),
                      number_format((float)($user['prov_next_invoice_pct'] ?? 0), 2, '.', '')
                  );
              }
          ?>
            <tr>
              <td><?= (int)$user['id'] ?></td>
              <td><?= htmlspecialchars($user['login']) ?></td>
              <td><?= htmlspecialchars($firstName !== '' ? $firstName : '—') ?></td>
              <td><?= htmlspecialchars($lastName !== '' ? $lastName : '—') ?></td>
              <td><?= htmlspecialchars($phone !== '' ? $phone : '—') ?></td>
              <td><?= htmlspecialchars($userEmail !== '' ? $userEmail : '—') ?></td>
              <td><?= htmlspecialchars($roleLabel) ?></td>
              <?php if ($canManageUsers && hasColumn($userColumns, 'prov_new_contract_pct')): ?>
                <td><?= htmlspecialchars($commissionSummary) ?></td>
              <?php endif; ?>
              <td class="text-end">
                <?php if ($canManageUsers): ?>
                  <div class="d-flex flex-wrap gap-2 justify-content-end">
                    <a class="btn btn-sm btn-outline-primary" href="uzytkownicy.php?edit=<?= (int)$user['id'] ?>">Edytuj</a>
                    <a class="btn btn-sm btn-outline-secondary" href="uzytkownik_edytuj.php?id=<?= (int)$user['id'] ?>">Poczta i podpis</a>
                    <?php if ((int)$user['id'] !== (int)$currentUser['id']): ?>
                      <form method="post" onsubmit="return confirm('Usunąć użytkownika?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Usuń</button>
                      </form>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <?php if ($canManageUsers): ?>
    <div class="card shadow-sm mb-4" id="section-create-user">
      <div class="card-header fw-semibold">Dodaj użytkownika</div>
      <div class="card-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <input type="hidden" name="action" value="create">
          <div class="col-12">
            <div class="users-form-block border rounded-3 p-3 h-100">
              <div class="fw-semibold mb-2">Użytkownik</div>
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">Login</label>
                  <input type="text" class="form-control" name="login" value="<?= htmlspecialchars($createForm['login']) ?>" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Hasło</label>
                  <input type="password" class="form-control" name="password" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">E-mail</label>
                  <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($createForm['email']) ?>" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Imię</label>
                  <input type="text" class="form-control" name="imie" value="<?= htmlspecialchars($createForm['imie']) ?>" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Nazwisko</label>
                  <input type="text" class="form-control" name="nazwisko" value="<?= htmlspecialchars($createForm['nazwisko']) ?>" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Telefon</label>
                  <input type="text" class="form-control" name="telefon" value="<?= htmlspecialchars($createForm['telefon']) ?>" required>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12">
            <div class="users-form-block border rounded-3 p-3 h-100">
              <div class="fw-semibold mb-2">Role i uprawnienia</div>
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">Rola</label>
                  <select name="rola" id="createRoleSelect" class="form-select" required>
                    <?php foreach ($availableRoles as $roleKey => $roleLabel): ?>
                      <option value="<?= htmlspecialchars($roleKey) ?>" <?= $createForm['rola'] === $roleKey ? 'selected' : '' ?>>
                        <?= htmlspecialchars($roleLabel) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-8 d-flex align-items-end">
                  <div class="text-muted small">Uprawnienia użytkownika wynikają z przypisanej roli.</div>
                </div>
              </div>
            </div>
          </div>

          <?php if (hasColumn($userColumns, 'commission_enabled') || hasColumn($userColumns, 'prov_new_contract_pct')): ?>
            <div class="col-12" id="createCommissionSection" style="<?= ($createForm['rola'] ?? '') === 'Handlowiec' ? '' : 'display:none;' ?>">
              <div class="users-form-block border rounded-3 p-3 h-100">
                  <div class="fw-semibold mb-2">Prowizje</div>
                  <div class="row g-3">
                    <?php if (hasColumn($userColumns, 'commission_enabled')): ?>
                      <div class="col-md-3">
                        <label class="form-label">Prowizja aktywna</label>
                        <div class="form-check form-switch">
                          <input class="form-check-input" type="checkbox" role="switch" name="commission_enabled" value="1"
                                 <?= !empty($createForm['commission_enabled']) ? 'checked' : '' ?>>
                          <label class="form-check-label">Tak</label>
                        </div>
                      </div>
                    <?php endif; ?>
                    <?php if (hasColumn($userColumns, 'prov_new_contract_pct')): ?>
                      <div class="col-md-4">
                        <label class="form-label">Nowa umowa (%)</label>
                        <input type="number" class="form-control" name="prov_new_contract_pct" step="0.01" min="0" max="100"
                               placeholder="0.00" value="<?= htmlspecialchars((string)($createForm['prov_new_contract_pct'] ?? '0.00')) ?>">
                      </div>
                      <div class="col-md-4">
                        <label class="form-label">Przedłużenie (%)</label>
                        <input type="number" class="form-control" name="prov_renewal_pct" step="0.01" min="0" max="100"
                               placeholder="0.00" value="<?= htmlspecialchars((string)($createForm['prov_renewal_pct'] ?? '0.00')) ?>">
                      </div>
                      <div class="col-md-4">
                        <label class="form-label">Kolejna faktura (%)</label>
                        <input type="number" class="form-control" name="prov_next_invoice_pct" step="0.01" min="0" max="100"
                               placeholder="0.00" value="<?= htmlspecialchars((string)($createForm['prov_next_invoice_pct'] ?? '0.00')) ?>">
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="form-text mt-2">Wartości w procentach, liczone od netto.</div>
              </div>
            </div>
          <?php endif; ?>

          <div class="col-12 d-flex justify-content-end">
            <button type="submit" class="btn btn-primary">Dodaj użytkownika</button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>
</main>
<script>
(function() {
  const ROLE_SALES = 'Handlowiec';
  function toggleSection(selectId, sectionId) {
    const selectEl = document.getElementById(selectId);
    const sectionEl = document.getElementById(sectionId);
    if (!selectEl || !sectionEl) {
      return;
    }
    const numberInputs = sectionEl.querySelectorAll('input[type="number"]');
    const checkboxes = sectionEl.querySelectorAll('input[type="checkbox"]');
    const update = () => {
      const show = selectEl.value === ROLE_SALES;
      sectionEl.style.display = show ? '' : 'none';
      if (!show) {
        numberInputs.forEach((input) => {
          input.value = '0.00';
        });
        checkboxes.forEach((input) => {
          input.checked = false;
        });
      }
    };
    selectEl.addEventListener('change', update);
    update();
  }
  toggleSection('createRoleSelect', 'createCommissionSection');
  toggleSection('editRoleSelect', 'editCommissionSection');
})();
</script>
<?php include 'includes/footer.php'; ?>
<?php
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>ERR: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
}
?>
