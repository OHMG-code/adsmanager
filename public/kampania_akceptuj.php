<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/crm_activity.php';
require_once __DIR__ . '/includes/briefs.php';
require_once __DIR__ . '/includes/documents.php';
require_once __DIR__ . '/includes/transactions.php';
require_once __DIR__ . '/includes/lead_pipeline_helpers.php';
require_once __DIR__ . '/handlers/client_save.php';
require_once __DIR__ . '/../config/config.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

function campaignAcceptReturnPath(?string $raw): string
{
    $default = 'kampanie_lista.php';
    $allowed = [
        'kampania_podglad.php',
        'kampanie_lista.php',
        'lead_szczegoly.php',
        'lead.php',
    ];
    $raw = trim((string)$raw);
    if ($raw === '' || stripos($raw, 'http') === 0) {
        return $default;
    }
    $normalized = ltrim($raw, '/');
    foreach ($allowed as $allowedPath) {
        if (strpos($normalized, $allowedPath) === 0) {
            return $normalized;
        }
    }
    return $default;
}

function campaignIsTransactionalStatus(?string $status, bool $isProposal): bool
{
    if ($isProposal) {
        return false;
    }
    $statusNorm = strtolower(trim((string)$status));
    $statusNorm = str_replace('_', ' ', $statusNorm);
    if ($statusNorm === '') {
        return true;
    }
    return in_array($statusNorm, ['zamowiona', 'zamówiona', 'w realizacji', 'zakonczona', 'zakończona', 'aktywna'], true);
}

$returnPath = campaignAcceptReturnPath($_POST['return_to'] ?? '');
$redirectLocation = BASE_URL . '/' . ltrim($returnPath, '/');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Nieprawidlowa metoda wywolania.'];
    header('Location: ' . $redirectLocation);
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!isCsrfTokenValid($token)) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Niepoprawny token CSRF.'];
    header('Location: ' . $redirectLocation);
    exit;
}

$campaignId = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : 0;
if ($campaignId <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Brak identyfikatora kampanii.'];
    header('Location: ' . $redirectLocation);
    exit;
}

if (!tableExists($pdo, 'kampanie')) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Brak tabeli kampanii.'];
    header('Location: ' . $redirectLocation);
    exit;
}

ensureKampanieOwnershipColumns($pdo);
ensureLeadBriefsTable($pdo);
if (normalizeRole($currentUser) === 'Handlowiec' && !canUserAccessKampania($pdo, $currentUser, $campaignId)) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Brak dostepu do kampanii.'];
    header('Location: ' . $redirectLocation);
    exit;
}

ensureKampanieSalesValueColumn($pdo);
ensureLeadColumns($pdo);
ensureClientLeadColumns($pdo);
$leadColumns = tableExists($pdo, 'leady') ? getTableColumns($pdo, 'leady') : [];

try {
    $pdo->beginTransaction();

    $campaignColumns = getTableColumns($pdo, 'kampanie');
    $select = [
        'id',
        'klient_id',
        'klient_nazwa',
        'netto_spoty',
        'netto_dodatki',
        'razem_netto',
        'razem_brutto',
    ];
    if (hasColumn($campaignColumns, 'status')) {
        $select[] = 'status';
    }
    if (hasColumn($campaignColumns, 'realization_status')) {
        $select[] = 'realization_status';
    }
    if (hasColumn($campaignColumns, 'propozycja')) {
        $select[] = 'propozycja';
    }
    if (hasColumn($campaignColumns, 'source_lead_id')) {
        $select[] = 'source_lead_id';
    }
    if (hasColumn($campaignColumns, 'wartosc_netto')) {
        $select[] = 'wartosc_netto';
    }

    $stmtCampaign = $pdo->prepare(
        'SELECT ' . implode(', ', $select) . ' FROM kampanie WHERE id = :id FOR UPDATE'
    );
    $stmtCampaign->execute([':id' => $campaignId]);
    $campaignRow = $stmtCampaign->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$campaignRow) {
        throw new RuntimeException('Nie znaleziono kampanii.');
    }

    $targetClientId = (int)($campaignRow['klient_id'] ?? 0);
    if ($targetClientId <= 0) {
        $resolvedClientId = resolveClientIdForCampaign($pdo, $campaignId);
        if ($resolvedClientId > 0) {
            $targetClientId = $resolvedClientId;
        }
    }
    $sourceLeadId = (int)($campaignRow['source_lead_id'] ?? 0);
    $leadRow = null;

    if ($sourceLeadId > 0 && tableExists($pdo, 'leady')) {
        $stmtLead = $pdo->prepare('SELECT * FROM leady WHERE id = :id FOR UPDATE');
        $stmtLead->execute([':id' => $sourceLeadId]);
        $leadRow = $stmtLead->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($leadRow) {
            $leadClientId = (int)($leadRow['client_id'] ?? 0);
            if ($leadClientId > 0) {
                $targetClientId = $leadClientId;
            }
        }
    }

    if ($targetClientId <= 0 && $leadRow) {
        $clientSave = saveClientFromLeadConversion(
            $pdo,
            $leadRow,
            [],
            (int)($currentUser['id'] ?? 0),
            ['source_lead_id' => $sourceLeadId]
        );
        if (!$clientSave['ok']) {
            $msg = $clientSave['errors'] ? implode(', ', $clientSave['errors']) : 'Nie udalo sie utworzyc klienta.';
            throw new RuntimeException($msg);
        }
        $targetClientId = (int)($clientSave['client_id'] ?? 0);
        if ($targetClientId <= 0) {
            throw new RuntimeException('Nie udalo sie ustalic klienta docelowego.');
        }

        $convertSetParts = [
            'client_id = :client_id',
            'converted_at = NOW()',
            'converted_by_user_id = :user_id',
        ];
        if (hasColumn($leadColumns, 'status')) {
            $convertSetParts[] = 'status = :status';
        }
        $stmtConvertLead = $pdo->prepare(
            'UPDATE leady SET ' . implode(', ', $convertSetParts) . ' WHERE id = :id'
        );
        $convertLeadParams = [
            ':client_id' => $targetClientId,
            ':user_id' => (int)($currentUser['id'] ?? 0),
            ':id' => $sourceLeadId,
        ];
        if (hasColumn($leadColumns, 'status')) {
            $convertLeadParams[':status'] = 'skonwertowany';
        }
        $stmtConvertLead->execute($convertLeadParams);
    }

    if ($targetClientId > 0 && $leadRow && (int)($leadRow['client_id'] ?? 0) <= 0) {
        $convertSetParts = [
            'client_id = :client_id',
            'converted_at = NOW()',
            'converted_by_user_id = :user_id',
        ];
        if (hasColumn($leadColumns, 'status')) {
            $convertSetParts[] = 'status = :status';
        }
        $stmtConvertLead = $pdo->prepare(
            'UPDATE leady SET ' . implode(', ', $convertSetParts) . ' WHERE id = :id'
        );
        $convertLeadParams = [
            ':client_id' => $targetClientId,
            ':user_id' => (int)($currentUser['id'] ?? 0),
            ':id' => $sourceLeadId,
        ];
        if (hasColumn($leadColumns, 'status')) {
            $convertLeadParams[':status'] = 'skonwertowany';
        }
        $stmtConvertLead->execute($convertLeadParams);
    }

    if ($sourceLeadId > 0 && hasColumn($leadColumns, 'status')) {
        $stmtLeadStatus = $pdo->prepare('UPDATE leady SET status = :status WHERE id = :id');
        $stmtLeadStatus->execute([
            ':status' => 'skonwertowany',
            ':id' => $sourceLeadId,
        ]);
    }

    if ($targetClientId <= 0) {
        throw new RuntimeException('Kampania nie jest powiązana z klientem ani leadem. Uzupełnij klienta lub source_lead_id i spróbuj ponownie.');
    }

    $clientName = '';
    if (tableExists($pdo, 'klienci')) {
        $stmtClientName = $pdo->prepare('SELECT nazwa_firmy FROM klienci WHERE id = :id LIMIT 1');
        $stmtClientName->execute([':id' => $targetClientId]);
        $clientName = trim((string)($stmtClientName->fetchColumn() ?: ''));
    }

    $campaignIsProposal = !empty($campaignRow['propozycja']) || strtolower(trim((string)($campaignRow['status'] ?? ''))) === 'propozycja';
    $transactionNetto = (float)($campaignRow['wartosc_netto'] ?? 0);
    if ($transactionNetto <= 0) {
        $transactionNetto = (float)($campaignRow['razem_netto'] ?? 0);
    }
    if ($transactionNetto <= 0) {
        $transactionNetto = (float)($campaignRow['netto_spoty'] ?? 0) + (float)($campaignRow['netto_dodatki'] ?? 0);
    }
    if ($transactionNetto < 0) {
        $transactionNetto = 0;
    }

    $setParts = [];
    $updateParams = [':id' => $campaignId];
    if (hasColumn($campaignColumns, 'klient_id')) {
        $setParts[] = 'klient_id = :klient_id';
        $updateParams[':klient_id'] = $targetClientId;
    }
    if (hasColumn($campaignColumns, 'klient_nazwa') && $clientName !== '') {
        $setParts[] = 'klient_nazwa = :klient_nazwa';
        $updateParams[':klient_nazwa'] = $clientName;
    }
    if (hasColumn($campaignColumns, 'status')) {
        $setParts[] = 'status = :status';
        $updateParams[':status'] = 'Zamówiona';
    }
    if (hasColumn($campaignColumns, 'propozycja')) {
        $setParts[] = 'propozycja = :propozycja';
        $updateParams[':propozycja'] = 0;
    }
    if (hasColumn($campaignColumns, 'wartosc_netto')) {
        $setParts[] = 'wartosc_netto = :wartosc_netto';
        $updateParams[':wartosc_netto'] = $transactionNetto;
    }

    if ($setParts) {
        $stmtUpdateCampaign = $pdo->prepare('UPDATE kampanie SET ' . implode(', ', $setParts) . ' WHERE id = :id');
        $stmtUpdateCampaign->execute($updateParams);
    }

    $brief = findOrCreateBriefForCampaign($pdo, $campaignId, $sourceLeadId > 0 ? $sourceLeadId : null);

    $currentRealizationStatus = trim((string)($campaignRow['realization_status'] ?? ''));
    if (hasColumn($campaignColumns, 'realization_status') && $currentRealizationStatus === '') {
        $nextRealizationStatus = briefIsSubmitted($brief) ? 'brief_otrzymany' : 'brief_oczekuje';
        $stmtRealization = $pdo->prepare('UPDATE kampanie SET realization_status = :status WHERE id = :id');
        $stmtRealization->execute([
            ':status' => $nextRealizationStatus,
            ':id' => $campaignId,
        ]);
    }

    if ($sourceLeadId > 0 && hasColumn($campaignColumns, 'source_lead_id') && hasColumn($campaignColumns, 'klient_id')) {
        $bulkSet = ['klient_id = :client_id'];
        $bulkParams = [
            ':client_id' => $targetClientId,
            ':lead_id' => $sourceLeadId,
        ];
        if (hasColumn($campaignColumns, 'klient_nazwa') && $clientName !== '') {
            $bulkSet[] = 'klient_nazwa = :client_name';
            $bulkParams[':client_name'] = $clientName;
        }
        $stmtBindCampaigns = $pdo->prepare(
            'UPDATE kampanie SET ' . implode(', ', $bulkSet)
            . ' WHERE source_lead_id = :lead_id AND (klient_id IS NULL OR klient_id = 0)'
        );
        $stmtBindCampaigns->execute($bulkParams);
    }

    upsertTransactionForCampaign($pdo, $campaignId, [
        'client_id' => $targetClientId,
        'source_lead_id' => $sourceLeadId > 0 ? $sourceLeadId : null,
        'owner_user_id' => (int)($campaignRow['owner_user_id'] ?? 0) > 0 ? (int)$campaignRow['owner_user_id'] : null,
    ]);

    $transactionStatus = campaignIsTransactionalStatus('Zamówiona', false) ? 'Transakcja' : 'Kampania';
    $activityBody = $transactionStatus . ' z kampanii #' . $campaignId . ', wartosc netto '
        . number_format($transactionNetto, 2, ',', ' ') . ' zł.';
    $leadActivityBody = null;
    if ($sourceLeadId > 0) {
        $leadActivityBody = 'Zaakceptowano kampanie #' . $campaignId . ' i przeniesiono podmiot do klientow.';
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    addActivity('klient', $targetClientId, 'system', (int)$currentUser['id'], $activityBody, null, 'Akceptacja kampanii');
    if ($sourceLeadId > 0 && $leadActivityBody !== null) {
        addActivity('lead', $sourceLeadId, 'system', (int)$currentUser['id'], $leadActivityBody, null, 'Konwersja po akceptacji');
    }

    $clientLink = 'klient_szczegoly.php?id=' . $targetClientId;
    $successMessage = 'Plan zaakceptowany.';
    if ($sourceLeadId > 0) {
        $successMessage .= ' Lead przeniesiony do klientów.';
    }
    $_SESSION['flash'] = [
        'type' => 'success',
        'msg' => $successMessage,
    ];

    // Jesli wywolanie bylo z podgladu kampanii - przekieruj do klienta tylko przy wyraznym zazadaniu.
    if (!empty($_POST['redirect_to_client']) && $_POST['redirect_to_client'] === '1') {
        header('Location: ' . BASE_URL . '/' . $clientLink);
        exit;
    }

    header('Location: ' . $redirectLocation);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('kampania_akceptuj.php: ' . $e->getMessage());
    $_SESSION['flash'] = [
        'type' => 'danger',
        'msg' => 'Nie udalo sie zaakceptowac kampanii: ' . $e->getMessage(),
    ];
    header('Location: ' . $redirectLocation);
    exit;
}
