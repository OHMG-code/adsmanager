<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/handlers/client_save.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo) ?? [];
$currentRole = normalizeRole($currentUser);
ensureClientLeadColumns($pdo);
$clientColumns = getTableColumns($pdo, 'klienci');

if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
    header('Location: ' . BASE_URL . '/dodaj_klienta.php?msg=error_csrf');
    exit;
}

// Walidacja podstawowych pól
if (empty($_POST['nip']) || empty($_POST['nazwa_firmy'])) {
    header('Location: ' . BASE_URL . '/dodaj_klienta.php?msg=error_missing');
    exit;
}

$nip = trim($_POST['nip']);
$nazwa_firmy = trim($_POST['nazwa_firmy']);
$branza = $_POST['branza'] ?? null;

// Nowe pola CRM
$status = $_POST['status'] ?? 'Nowy';
$assignedUserId = null;
if (hasColumn($clientColumns, 'assigned_user_id')) {
    if ($currentRole === 'Handlowiec') {
        $assignedUserId = (int)($currentUser['id'] ?? 0);
    } else {
        $selected = isset($_POST['assigned_user_id']) ? (int)$_POST['assigned_user_id'] : 0;
        $assignedUserId = $selected > 0 ? $selected : (int)($currentUser['id'] ?? 0);
    }
}

// Walidacja NIP
if (!preg_match('/^[0-9]{10}$/', $nip)) {
    header('Location: ' . BASE_URL . '/dodaj_klienta.php?msg=error_nip');
    exit;
}

// Sprawdź, czy firma o takim NIP już istnieje w kanonie
if (tableExists($pdo, 'companies')) {
    $stmt = $pdo->prepare("SELECT id FROM companies WHERE nip = ? LIMIT 1");
    $stmt->execute([$nip]);
    if ($stmt->fetchColumn()) {
        header('Location: ' . BASE_URL . '/dodaj_klienta.php?msg=error_exists');
        exit;
    }
}

// Dodanie klienta (CRM fields) + kanon companies
try {
    $payload = $_POST;
    $payload['status'] = $status;
    if (hasColumn($clientColumns, 'assigned_user_id')) {
        $payload['assigned_user_id'] = $assignedUserId ?: null;
    }
    if (hasColumn($clientColumns, 'owner_user_id')) {
        $payload['owner_user_id'] = $assignedUserId ?: null;
    }
    if ($branza !== null) {
        $payload['branza'] = $branza;
    }

    $result = saveClientFromPost($pdo, $payload, (int)($currentUser['id'] ?? 0), ['mode' => 'create']);
    if (!$result['ok']) {
        $msg = 'error_missing';
        if (in_array('invalid_nip', $result['errors'], true)) {
            $msg = 'error_nip';
        } elseif (in_array('duplicate_client_nip', $result['errors'], true)) {
            $msg = 'error_exists';
        } elseif (in_array('duplicate_lead_nip', $result['errors'], true)) {
            $msg = 'error_exists_lead';
        }
        $_SESSION['client_save_errors'] = $result['errors'];
        header('Location: ' . BASE_URL . '/dodaj_klienta.php?msg=' . $msg);
        exit;
    }

    header('Location: ' . BASE_URL . '/dodaj_klienta.php?msg=client_added');
    exit;

} catch (PDOException $e) {
    error_log('add_client.php: save failed: ' . $e->getMessage());
    header('Location: ' . BASE_URL . '/dodaj_klienta.php?msg=error_missing');
    exit;
}
