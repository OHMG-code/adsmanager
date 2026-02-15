<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
set_error_handler(function ($severity, $message, $file, $line) {
    echo '<pre>PHP ERROR: ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . ' in ' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . ':' . (int)$line . '</pre>';
    return false;
});
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error) {
        echo '<pre>PHP FATAL: ' . htmlspecialchars($error['message'], ENT_QUOTES, 'UTF-8') .
            ' in ' . htmlspecialchars($error['file'], ENT_QUOTES, 'UTF-8') . ':' . (int)$error['line'] . '</pre>';
    }
});
require_once __DIR__ . '/includes/config.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$pageTitle = 'UĹĽytkownicy';
require_once '../config/config.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/crypto.php';
ensureSystemConfigColumns($pdo);
ensureUserColumns($pdo);
requireLogin();

try {
$currentUser = getCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
if (isAdminOverride($currentUser)) {
    ensureCanonicalUserRoleColumn($pdo);
    normalizeUserRoleData($pdo);
    $currentUser = getCurrentUser($pdo);
}
if (!canManageSystem($currentUser)) {
    http_response_code(403);
    include 'includes/header.php';
    echo '<div class="container py-4"><div class="alert alert-danger">Brak uprawnieĹ„ do moduĹ‚u UĹĽytkownicy.</div></div>';
    include 'includes/footer.php';
    exit;
}

function humanizeRoleLabel(string $value): string {
    $prepared = trim(str_replace(['_', '-'], ' ', $value));
    if ($prepared === '') {
        return 'â€”';
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
        $errors[] = $label . ' musi byÄ‡ liczbÄ….';
        return '0.00';
    }
    $value = round((float)$normalized, 2);
    if ($value < 0 || $value > 100) {
        $errors[] = $label . ' musi byÄ‡ w zakresie 0.00â€“100.00.';
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

$userColumns = getTableColumns($pdo, 'uzytkownicy');
$currentRole = normalizeRole($currentUser);
$canManageUsers = canManageSystem($currentUser);
$canManageCommissionRates = $canManageUsers;

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
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'create':
            if (!$canManageUsers) {
                http_response_code(403);
                $alerts[] = ['type' => 'danger', 'msg' => 'Brak uprawnieĹ„ do dodawania uĹĽytkownikĂłw.'];
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
                $errors[] = 'HasĹ‚o jest wymagane.';
            }
            if ($createForm['imie'] === '') {
                $errors[] = 'ImiÄ™ jest wymagane.';
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
                $errors[] = 'Adres e-mail ma nieprawidĹ‚owy format.';
            }
            if ($createForm['rola'] === '' || !array_key_exists($createForm['rola'], $availableRoles)) {
                $errors[] = 'NieprawidĹ‚owa rola uĹĽytkownika.';
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
                $createForm['prov_renewal_pct'] = parseCommissionPercent($createForm['prov_renewal_pct'], 'PrzedĹ‚uĹĽenie (%)', $errors);
            }
            if ($isSalesRole && hasColumn($userColumns, 'prov_next_invoice_pct')) {
                $createForm['prov_next_invoice_pct'] = parseCommissionPercent($createForm['prov_next_invoice_pct'], 'Kolejna faktura (%)', $errors);
            }

            if (!$errors) {
                $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM uzytkownicy WHERE login = :login');
                $existsStmt->execute([':login' => $createForm['login']]);
                if ((int)$existsStmt->fetchColumn() > 0) {
                    $errors[] = 'Podany login jest juĹĽ zajÄ™ty.';
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
                    $alerts[] = ['type' => 'success', 'msg' => 'Dodano nowego uĹĽytkownika.'];
                    $createForm = $createDefaults;
                } else {
                    $alerts[] = ['type' => 'danger', 'msg' => 'Nie udaĹ‚o siÄ™ dodaÄ‡ uĹĽytkownika.'];
                }
            }
            break;

        case 'update':
            if (!$canManageUsers) {
                http_response_code(403);
                $alerts[] = ['type' => 'danger', 'msg' => 'Brak uprawnieĹ„ do edycji uĹĽytkownikĂłw.'];
                break;
            }
            $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
            $editUserId = $userId > 0 ? $userId : $editUserId;
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
            ];
            $newPassword = (string)($_POST['password'] ?? '');
            $smtpPassInput = (string)($_POST['smtp_pass'] ?? '');
            $smtpPassClear = !empty($_POST['smtp_pass_clear']);
            $errors = [];
            $editFormOverride['rola'] = canonicalRoleValue($editFormOverride['rola']) ?? '';
            $isSalesRole = ($editFormOverride['rola'] === 'Handlowiec');

            if ($userId <= 0) {
                $errors[] = 'NieprawidĹ‚owy identyfikator uĹĽytkownika.';
            }
            if ($editFormOverride['login'] === '') {
                $errors[] = 'Login jest wymagany.';
            }
            if ($editFormOverride['imie'] === '') {
                $errors[] = 'ImiÄ™ jest wymagane.';
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
                $errors[] = 'Adres e-mail ma nieprawidĹ‚owy format.';
            }
            if ($editFormOverride['smtp_from_email'] !== '' && !filter_var($editFormOverride['smtp_from_email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Adres nadawcy SMTP ma nieprawidlowy format.';
            }
            if ($editFormOverride['rola'] === '' || !array_key_exists($editFormOverride['rola'], $availableRoles)) {
                $errors[] = 'NieprawidĹ‚owa rola uĹĽytkownika.';
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
                $editFormOverride['prov_renewal_pct'] = parseCommissionPercent($editFormOverride['prov_renewal_pct'], 'PrzedĹ‚uĹĽenie (%)', $errors);
            }
            if ($isSalesRole && hasColumn($userColumns, 'prov_next_invoice_pct')) {
                $editFormOverride['prov_next_invoice_pct'] = parseCommissionPercent($editFormOverride['prov_next_invoice_pct'], 'Kolejna faktura (%)', $errors);
            }

            if (!$errors) {
                $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM uzytkownicy WHERE id = :id');
                $existsStmt->execute([':id' => $userId]);
                if ((int)$existsStmt->fetchColumn() === 0) {
                    $errors[] = 'Wybrany uĹĽytkownik nie istnieje.';
                }
            }

            if (!$errors) {
                $loginStmt = $pdo->prepare('SELECT COUNT(*) FROM uzytkownicy WHERE login = :login AND id <> :id');
                $loginStmt->execute([
                    ':login' => $editFormOverride['login'],
                    ':id'    => $userId,
                ]);
                if ((int)$loginStmt->fetchColumn() > 0) {
                    $errors[] = 'Podany login jest juĹĽ uĹĽywany przez innego uĹĽytkownika.';
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
                if (hasColumn($userColumns, 'smtp_enabled')) {
                    $fields[] = 'smtp_enabled = :smtp_enabled';
                    $params[':smtp_enabled'] = (int)$editFormOverride['smtp_enabled'];
                }
                if (hasColumn($userColumns, 'smtp_host')) {
                    $fields[] = 'smtp_host = :smtp_host';
                    $params[':smtp_host'] = $editFormOverride['smtp_host'] !== '' ? $editFormOverride['smtp_host'] : null;
                }
                if (hasColumn($userColumns, 'smtp_port')) {
                    $fields[] = 'smtp_port = :smtp_port';
                    $params[':smtp_port'] = $editFormOverride['smtp_port'] > 0 ? $editFormOverride['smtp_port'] : null;
                }
                if (hasColumn($userColumns, 'smtp_secure')) {
                    $fields[] = 'smtp_secure = :smtp_secure';
                    $params[':smtp_secure'] = $editFormOverride['smtp_secure'] !== '' ? $editFormOverride['smtp_secure'] : null;
                }
                if (hasColumn($userColumns, 'smtp_user')) {
                    $fields[] = 'smtp_user = :smtp_user';
                    $params[':smtp_user'] = $editFormOverride['smtp_user'] !== '' ? $editFormOverride['smtp_user'] : null;
                }
                if (hasColumn($userColumns, 'smtp_from_email')) {
                    $fields[] = 'smtp_from_email = :smtp_from_email';
                    $params[':smtp_from_email'] = $editFormOverride['smtp_from_email'] !== '' ? $editFormOverride['smtp_from_email'] : null;
                }
                if (hasColumn($userColumns, 'smtp_from_name')) {
                    $fields[] = 'smtp_from_name = :smtp_from_name';
                    $params[':smtp_from_name'] = $editFormOverride['smtp_from_name'] !== '' ? $editFormOverride['smtp_from_name'] : null;
                }
                if (hasColumn($userColumns, 'smtp_pass_enc') && ($smtpPassClear || $smtpPassInput !== '')) {
                    $fields[] = 'smtp_pass_enc = :smtp_pass_enc';
                    $params[':smtp_pass_enc'] = $smtpPassClear ? null : encryptSecret($smtpPassInput);
                }
                if ($newPassword !== '') {
                    $fields[] = 'haslo_hash = :haslo';
                    $params[':haslo'] = password_hash($newPassword, PASSWORD_DEFAULT);
                }

                $update = $pdo->prepare('UPDATE uzytkownicy SET ' . implode(', ', $fields) . ' WHERE id = :id');
                if ($update->execute($params)) {
                    $alerts[] = ['type' => 'success', 'msg' => 'Dane uĹĽytkownika zostaĹ‚y zaktualizowane.'];
                    if ((int)$_SESSION['user_id'] === $userId) {
                        $roleStmt = $pdo->prepare('SELECT rola FROM uzytkownicy WHERE id = :id');
                        $roleStmt->execute([':id' => $userId]);
                        $dbRole = trim((string)$roleStmt->fetchColumn());
                        $_SESSION['user_role'] = normalizeRole(['rola' => $dbRole]);
                    }
                    $editFormOverride = null;
                } else {
                    $alerts[] = ['type' => 'danger', 'msg' => 'Nie udaĹ‚o siÄ™ zaktualizowaÄ‡ uĹĽytkownika.'];
                }
            }
            break;

        case 'delete':
            if (!$canManageUsers) {
                http_response_code(403);
                $alerts[] = ['type' => 'danger', 'msg' => 'Brak uprawnieĹ„ do usuwania uĹĽytkownikĂłw.'];
                break;
            }
            $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
            if ($userId <= 0) {
                $alerts[] = ['type' => 'danger', 'msg' => 'NieprawidĹ‚owy identyfikator uĹĽytkownika.'];
                break;
            }
            if ($userId === (int)$currentUser['id']) {
                $alerts[] = ['type' => 'danger', 'msg' => 'Nie moĹĽesz usunÄ…Ä‡ wĹ‚asnego konta.'];
                break;
            }

            $delete = $pdo->prepare('DELETE FROM uzytkownicy WHERE id = :id');
            if ($delete->execute([':id' => $userId])) {
                $alerts[] = ['type' => 'success', 'msg' => 'UĹĽytkownik zostaĹ‚ usuniÄ™ty.'];
                if ($editUserId === $userId) {
                    $editUserId = null;
                }
            } else {
                $alerts[] = ['type' => 'danger', 'msg' => 'Nie udaĹ‚o siÄ™ usunÄ…Ä‡ uĹĽytkownika.'];
            }
            break;

        default:
            $alerts[] = ['type' => 'danger', 'msg' => 'Nieznane dziaĹ‚anie.'];
            break;
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
];
$usersStmt = $pdo->query('SELECT ' . implode(', ', $userListSelect) . ' FROM uzytkownicy AS u ORDER BY u.`id`');
$users = $usersStmt ? $usersStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$editUser = null;
if ($editUserId) {
    $editStmt = $pdo->prepare('SELECT ' . implode(', ', $userListSelect) . ' FROM uzytkownicy AS u WHERE u.`id` = :id');
    $editStmt->execute([':id' => $editUserId]);
    $editUser = $editStmt->fetch(PDO::FETCH_ASSOC);
    if (!$editUser) {
        $alerts[] = ['type' => 'warning', 'msg' => 'Nie znaleziono wybranego uĹĽytkownika do edycji.'];
        $editUserId = null;
    }
}
if ($editFormOverride && $editUserId && $editUser && (int)$editUser['id'] === $editFormOverride['id']) {
    $editUser = array_merge($editUser, $editFormOverride);
}
if ($editUser) {
    $editUser['rola'] = canonicalRoleValue((string)($editUser['rola'] ?? '')) ?? 'Handlowiec';
}

include 'includes/header.php';
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h4 mb-0">UĹĽytkownicy</h2>
  </div>

  <?php foreach ($alerts as $alert): ?>
    <div class="alert alert-<?= htmlspecialchars($alert['type']) ?>"><?= htmlspecialchars($alert['msg']) ?></div>
  <?php endforeach; ?>

  <?php if ($canManageUsers): ?>
    <div class="card shadow-sm mb-4">
      <div class="card-header fw-semibold">Dodaj uĹĽytkownika</div>
      <div class="card-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="create">
          <div class="col-md-4">
            <label class="form-label">Login</label>
            <input type="text" class="form-control" name="login" value="<?= htmlspecialchars($createForm['login']) ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">HasĹ‚o</label>
            <input type="password" class="form-control" name="password" required>
          </div>
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
          <div class="col-md-4">
            <label class="form-label">ImiÄ™</label>
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
          <div class="col-md-6">
            <label class="form-label">E-mail</label>
            <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($createForm['email']) ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Prowizja aktywna</label>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" name="commission_enabled" value="1"
                     <?= !empty($createForm['commission_enabled']) ? 'checked' : '' ?>>
              <label class="form-check-label">Tak</label>
            </div>
          </div>
          <?php if (hasColumn($userColumns, 'prov_new_contract_pct')): ?>
            <div class="col-12" id="createCommissionSection" style="<?= ($createForm['rola'] ?? '') === 'Handlowiec' ? '' : 'display:none;' ?>">
              <div class="card border-0 bg-light">
                <div class="card-body">
                  <div class="fw-semibold mb-2">Prowizje</div>
                  <div class="row g-3">
                    <div class="col-md-4">
                      <label class="form-label">Nowa umowa (%)</label>
                      <input type="number" class="form-control" name="prov_new_contract_pct" step="0.01" min="0" max="100"
                             placeholder="0.00" value="<?= htmlspecialchars((string)($createForm['prov_new_contract_pct'] ?? '0.00')) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">PrzedĹ‚uĹĽenie (%)</label>
                      <input type="number" class="form-control" name="prov_renewal_pct" step="0.01" min="0" max="100"
                             placeholder="0.00" value="<?= htmlspecialchars((string)($createForm['prov_renewal_pct'] ?? '0.00')) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Kolejna faktura (%)</label>
                      <input type="number" class="form-control" name="prov_next_invoice_pct" step="0.01" min="0" max="100"
                             placeholder="0.00" value="<?= htmlspecialchars((string)($createForm['prov_next_invoice_pct'] ?? '0.00')) ?>">
                    </div>
                  </div>
                  <div class="form-text mt-2">WartoĹ›ci w procentach, liczone od netto.</div>
                </div>
              </div>
            </div>
          <?php endif; ?>
          <div class="col-md-6 d-flex align-items-end justify-content-end">
            <button type="submit" class="btn btn-primary">Dodaj uĹĽytkownika</button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($editUser && $canManageUsers): ?>
    <div class="card shadow-sm mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Edytuj uĹĽytkownika: <?= htmlspecialchars(trim(($editUser['imie'] ?? '') . ' ' . ($editUser['nazwisko'] ?? ''))) ?></span>
        <a href="uzytkownicy.php" class="btn btn-sm btn-outline-secondary">Anuluj edycjÄ™</a>
      </div>
      <div class="card-body">
        <form method="post" class="row g-3" action="<?= BASE_URL ?>/uzytkownicy.php?edit=<?= (int)$editUser['id'] ?>">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">
          <div class="col-md-4">
            <label class="form-label">Login</label>
            <input type="text" class="form-control" name="login" value="<?= htmlspecialchars($editUser['login'] ?? '') ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Nowe hasĹ‚o</label>
            <input type="password" class="form-control" name="password" placeholder="Pozostaw puste bez zmian">
          </div>
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
          <div class="col-md-4">
            <label class="form-label">ImiÄ™</label>
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
          <div class="col-md-6">
            <label class="form-label">E-mail</label>
            <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($editUser['email'] ?? '') ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Prowizja aktywna</label>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" name="commission_enabled" value="1"
                     <?= !empty($editUser['commission_enabled']) ? 'checked' : '' ?>>
              <label class="form-check-label">Tak</label>
            </div>
          </div>
          <?php if (hasColumn($userColumns, 'prov_new_contract_pct')): ?>
            <div class="col-12" id="editCommissionSection" style="<?= ($editUser['rola'] ?? '') === 'Handlowiec' ? '' : 'display:none;' ?>">
              <div class="card border-0 bg-light">
                <div class="card-body">
                  <div class="fw-semibold mb-2">Prowizje</div>
                  <div class="row g-3">
                    <div class="col-md-4">
                      <label class="form-label">Nowa umowa (%)</label>
                      <input type="number" class="form-control" name="prov_new_contract_pct" step="0.01" min="0" max="100"
                             placeholder="0.00" value="<?= htmlspecialchars((string)($editUser['prov_new_contract_pct'] ?? '0.00')) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">PrzedĹ‚uĹĽenie (%)</label>
                      <input type="number" class="form-control" name="prov_renewal_pct" step="0.01" min="0" max="100"
                             placeholder="0.00" value="<?= htmlspecialchars((string)($editUser['prov_renewal_pct'] ?? '0.00')) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Kolejna faktura (%)</label>
                      <input type="number" class="form-control" name="prov_next_invoice_pct" step="0.01" min="0" max="100"
                             placeholder="0.00" value="<?= htmlspecialchars((string)($editUser['prov_next_invoice_pct'] ?? '0.00')) ?>">
                    </div>
                  </div>
                  <div class="form-text mt-2">WartoĹ›ci w procentach, liczone od netto.</div>
                </div>
              </div>
            </div>
          <?php endif; ?>
          <?php if (($editUser['rola'] ?? '') === 'Handlowiec'): ?>
            <div class="col-12">
              <div class="card border-0 bg-light">
                <div class="card-body">
                  <div class="fw-semibold mb-2">Poczta SMTP (handlowiec)</div>
                  <div class="row g-3">
                    <div class="col-md-4">
                      <label class="form-label">Wlaczone</label>
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" name="smtp_enabled" value="1"
                               <?= !empty($editUser['smtp_enabled']) ? 'checked' : '' ?>>
                        <label class="form-check-label">Aktywne</label>
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
                      <label class="form-label">SMTP haslo</label>
                      <input type="password" class="form-control" name="smtp_pass" placeholder="Pozostaw puste bez zmian">
                      <?php if (!empty($editUser['smtp_pass_enc'])): ?>
                        <div class="form-check mt-2">
                          <input class="form-check-input" type="checkbox" id="smtp_pass_clear" name="smtp_pass_clear" value="1">
                          <label class="form-check-label" for="smtp_pass_clear">Usun zapisane haslo</label>
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
                  </div>
                  <div class="form-text mt-2">Dane SMTP ustawia Administrator lub Manager.</div>
                </div>
              </div>
            </div>
          <?php endif; ?>
          <div class="col-md-6 d-flex align-items-end justify-content-end">
            <button type="submit" class="btn btn-primary">Zapisz zmiany</button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-striped table-bordered align-middle">
          <thead class="table-light">
            <tr>
              <th>ID</th>
              <th>Login</th>
              <th>ImiÄ™</th>
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
              <td><?= htmlspecialchars($firstName !== '' ? $firstName : 'â€”') ?></td>
              <td><?= htmlspecialchars($lastName !== '' ? $lastName : 'â€”') ?></td>
              <td><?= htmlspecialchars($phone !== '' ? $phone : 'â€”') ?></td>
              <td><?= htmlspecialchars($userEmail !== '' ? $userEmail : 'â€”') ?></td>
              <td><?= htmlspecialchars($roleLabel) ?></td>
              <?php if ($canManageUsers && hasColumn($userColumns, 'prov_new_contract_pct')): ?>
                <td><?= htmlspecialchars($commissionSummary) ?></td>
              <?php endif; ?>
              <td class="text-end">
                <?php if ($canManageUsers): ?>
                  <div class="d-flex gap-2 justify-content-end">
                    <a class="btn btn-sm btn-outline-primary" href="uzytkownicy.php?edit=<?= (int)$user['id'] ?>">Edytuj</a>
                    <?php if ((int)$user['id'] !== (int)$currentUser['id']): ?>
                      <form method="post" onsubmit="return confirm('UsunÄ…Ä‡ uĹĽytkownika?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">UsuĹ„</button>
                      </form>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  â€”
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script>
(function() {
  const ROLE_SALES = 'Handlowiec';
  function toggleSection(selectId, sectionId) {
    const selectEl = document.getElementById(selectId);
    const sectionEl = document.getElementById(sectionId);
    if (!selectEl || !sectionEl) {
      return;
    }
    const inputs = sectionEl.querySelectorAll('input[type="number"]');
    const update = () => {
      const show = selectEl.value === ROLE_SALES;
      sectionEl.style.display = show ? '' : 'none';
      if (!show) {
        inputs.forEach((input) => {
          input.value = '0.00';
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
