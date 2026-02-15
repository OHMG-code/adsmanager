<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/mailbox_service.php';
require_once __DIR__ . '/includes/imap_service.php';
require_once __DIR__ . '/../config/config.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
    $_SESSION['mail_sync_flash'] = ['type' => 'danger', 'msg' => 'Niepoprawny token CSRF.'];
    header('Location: ' . BASE_URL . '/skrzynka.php');
    exit;
}

$accountId = isset($_POST['account_id']) ? (int)$_POST['account_id'] : 0;
if ($accountId <= 0) {
    $_SESSION['mail_sync_flash'] = ['type' => 'warning', 'msg' => 'Brak wskazanego konta pocztowego.'];
    header('Location: ' . BASE_URL . '/skrzynka.php');
    exit;
}

ensureCrmMailTables($pdo);
$account = getMailAccountForUser($pdo, (int)$currentUser['id']);
$accountInactive = mailAccountInactiveFound((int)$currentUser['id']);

if (!$account) {
    $_SESSION['mail_sync_flash'] = ['type' => 'danger', 'msg' => 'Brak skonfigurowanego konta pocztowego dla uzytkownika.'];
    header('Location: ' . BASE_URL . '/skrzynka.php');
    exit;
}

if ((int)$account['id'] !== $accountId) {
    http_response_code(403);
    exit('Brak uprawnien.');
}

$result = imapSyncMailAccount($pdo, $account, ['limit' => 50]);
if ($result['ok']) {
    $_SESSION['mail_sync_flash'] = [
        'type' => 'success',
        'msg' => 'Synchronizacja zakonczona. Nowe wiadomosci: ' . (int)$result['synced'],
    ];
} else {
    $_SESSION['mail_sync_flash'] = [
        'type' => 'danger',
        'msg' => 'Blad IMAP: ' . ($result['error'] ?: 'nieznany'),
    ];
}

$returnTo = trim((string)($_POST['return_to'] ?? ''));
if ($returnTo === '' || strpos($returnTo, BASE_URL) !== 0) {
    $returnTo = BASE_URL . '/skrzynka.php';
}
header('Location: ' . $returnTo);
exit;
