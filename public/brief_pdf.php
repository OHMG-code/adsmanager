<?php
declare(strict_types=1);

require_once '../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/brief_pdf.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

$campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;
if ($campaignId <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Brak kampanii briefu.'];
    header('Location: ' . BASE_URL . '/kampanie_lista.php');
    exit;
}

$result = generateBriefPdfDocument($pdo, $currentUser, $campaignId);
if (empty($result['success'])) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => $result['error'] ?? 'Nie udało się wygenerować PDF briefu.'];
    header('Location: ' . BASE_URL . '/kampania_podglad.php?id=' . $campaignId);
    exit;
}

header('Location: ' . BASE_URL . '/docs/download.php?id=' . (int)$result['doc_id'] . '&inline=1');
exit;
