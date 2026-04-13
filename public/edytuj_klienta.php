<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/documents.php';
require_once __DIR__ . '/includes/crm_activity.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/mailbox_service.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: ' . BASE_URL . '/lista_klientow.php?msg=error_invalid_id');
    exit;
}

$klient_id = (int) $_GET['id'];

try {
    $stmt = $pdo->prepare("SELECT * FROM klienci WHERE id = ?");
    $stmt->execute([$klient_id]);
    $klient = $stmt->fetch();

    if (!$klient) {
        header('Location: ' . BASE_URL . '/lista_klientow.php?msg=error_not_found');
        exit;
    }
} catch (PDOException $e) {
    echo "<pre>Błąd bazy danych: " . $e->getMessage() . "</pre>";
    exit;
}

$companyRow = null;
if (!empty($klient['company_id'])) {
    try {
        ensureCompaniesTable($pdo);
        $stmtCompany = $pdo->prepare('SELECT * FROM companies WHERE id = :id LIMIT 1');
        $stmtCompany->execute([':id' => (int)$klient['company_id']]);
        $companyRow = $stmtCompany->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $companyRow = null;
    }
}

$pref = static function ($companyVal, $legacyVal): string {
    $legacyVal = trim((string)$legacyVal);
    if ($legacyVal !== '') {
        return $legacyVal;
    }
    $companyVal = trim((string)$companyVal);
    return $companyVal !== '' ? $companyVal : '';
};

$form = $klient;
$form['nip'] = $pref($companyRow['nip'] ?? null, $klient['nip'] ?? null);
$form['regon'] = $pref($companyRow['regon'] ?? null, $klient['regon'] ?? null);
$form['nazwa_firmy'] = $pref($companyRow['name_full'] ?? ($companyRow['name_short'] ?? null), $klient['nazwa_firmy'] ?? null);
$form['wojewodztwo'] = $pref($companyRow['wojewodztwo'] ?? null, $klient['wojewodztwo'] ?? null);
$form['miasto'] = $pref($companyRow['city'] ?? null, $klient['miejscowosc'] ?? ($klient['miasto'] ?? null));

$street = trim((string)($companyRow['street'] ?? ''));
if ($street !== '') {
    $addrLine = $street;
    $building = trim((string)($companyRow['building_no'] ?? ''));
    $apartment = trim((string)($companyRow['apartment_no'] ?? ''));
    if ($building !== '') {
        $addrLine = trim($addrLine . ' ' . $building);
    }
    if ($apartment !== '') {
        $addrLine .= '/' . $apartment;
    }
    $form['adres'] = $pref($addrLine, $klient['adres'] ?? null);
} else {
    $form['adres'] = $pref(null, $klient['adres'] ?? null);
}

$pageTitle = "Edycja klienta";
$currentUser = fetchCurrentUser($pdo);
$activityFlash = $_SESSION['client_activity_flash'] ?? null;
unset($_SESSION['client_activity_flash']);
$docAlerts = [];
$generatedDoc = null;
$documents = [];
$documentsAllowed = true;
$campaigns = [];
$crmActivityAllowed = true;

if ($currentUser && normalizeRole($currentUser) === 'Handlowiec') {
    $documentsAllowed = canUserAccessClient($pdo, $currentUser, $klient_id);
    if (!$documentsAllowed) {
        $docAlerts[] = ['type' => 'warning', 'msg' => 'Brak dostępu do dokumentów tego klienta.'];
    }
}

$crmActivityAllowed = $documentsAllowed;
if (!$currentUser) {
    $crmActivityAllowed = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crm_action'])) {
    $action = trim((string)($_POST['crm_action'] ?? ''));
    $token = $_POST['csrf_token'] ?? '';

    if (!isCsrfTokenValid($token)) {
        $activityFlash = ['type' => 'danger', 'msg' => 'Niepoprawny token CSRF.'];
    } elseif (!$currentUser) {
        $activityFlash = ['type' => 'danger', 'msg' => 'Brak aktywnej sesji uzytkownika.'];
    } elseif (!$crmActivityAllowed) {
        $activityFlash = ['type' => 'danger', 'msg' => 'Brak dostepu do tego klienta.'];
    } else {
        switch ($action) {
            case 'status':
                $statusId = isset($_POST['crm_status_id']) ? (int)$_POST['crm_status_id'] : 0;
                $comment = trim((string)($_POST['crm_status_comment'] ?? ''));
                if ($statusId <= 0) {
                    $activityFlash = ['type' => 'warning', 'msg' => 'Wybierz status/czynnosc.'];
                    break;
                }
                $availableStatuses = getStatusy('klient');
                $isValidStatus = false;
                foreach ($availableStatuses as $status) {
                    if ((int)($status['id'] ?? 0) === $statusId) {
                        $isValidStatus = true;
                        break;
                    }
                }
                if (!$isValidStatus) {
                    $activityFlash = ['type' => 'danger', 'msg' => 'Wybrany status nie jest dostepny.'];
                    break;
                }
                if (!addActivity('klient', $klient_id, 'status', (int)$currentUser['id'], $comment, $statusId)) {
                    $activityFlash = ['type' => 'danger', 'msg' => 'Nie udalo sie zapisac statusu.'];
                    break;
                }
                $activityFlash = ['type' => 'success', 'msg' => 'Status/czynnosc zostaly zapisane w osi aktywnosci.'];
                break;

            case 'note':
                $note = trim((string)($_POST['crm_note'] ?? ''));
                if ($note === '') {
                    $activityFlash = ['type' => 'warning', 'msg' => 'Wpisz tresc notatki.'];
                    break;
                }
                if (!addActivity('klient', $klient_id, 'notatka', (int)$currentUser['id'], $note)) {
                    $activityFlash = ['type' => 'danger', 'msg' => 'Nie udalo sie dodac notatki.'];
                    break;
                }
                $activityFlash = ['type' => 'success', 'msg' => 'Notatka zostala dodana do osi aktywnosci.'];
                break;

            default:
                $activityFlash = ['type' => 'danger', 'msg' => 'Nieznana akcja CRM.'];
        }
    }

    $_SESSION['client_activity_flash'] = $activityFlash;
    header('Location: ' . BASE_URL . '/edytuj_klienta.php?id=' . $klient_id . '#client-activity');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['doc_action'] ?? '') === 'generate_umowa') {
    if (!$currentUser) {
        $docAlerts[] = ['type' => 'danger', 'msg' => 'Brak aktywnej sesji użytkownika.'];
    } elseif (!$documentsAllowed) {
        $docAlerts[] = ['type' => 'danger', 'msg' => 'Brak dostępu do klienta.'];
    } else {
        $kampaniaId = (int)($_POST['kampania_id'] ?? 0);
        $result = generateDocument($pdo, $currentUser, 'umowa', $klient_id, $kampaniaId);
        if (!empty($result['success'])) {
            $generatedDoc = $result;
            $docAlerts[] = ['type' => 'success', 'msg' => 'Umowa reklamowa została wygenerowana.'];
        } else {
            $docAlerts[] = ['type' => 'danger', 'msg' => $result['error'] ?? 'Nie udało się wygenerować umowy.'];
        }
    }
}

if ($documentsAllowed) {
    try {
        ensureDocumentsTables($pdo);
        $stmtDocs = $pdo->prepare("SELECT d.*, u.login, u.imie, u.nazwisko
            FROM dokumenty d
            LEFT JOIN uzytkownicy u ON u.id = d.created_by_user_id
            WHERE d.client_id = :id
            ORDER BY d.created_at DESC");
        $stmtDocs->execute([':id' => $klient_id]);
        $documents = $stmtDocs->fetchAll();
    } catch (Throwable $e) {
        error_log('edytuj_klienta.php: dokumenty fetch failed: ' . $e->getMessage());
    }
}

try {
    $stmtCamp = $pdo->prepare("SELECT id, klient_nazwa, data_start, data_koniec FROM kampanie WHERE klient_id = :id ORDER BY id DESC");
    $stmtCamp->execute([':id' => $klient_id]);
    $campaigns = $stmtCamp->fetchAll();
} catch (Throwable $e) {
    $campaigns = [];
}

$crmStatuses = [];
$crmActivities = [];
$clientOwnerLabel = 'Nieprzypisany';
$clientColumns = getTableColumns($pdo, 'klienci');
$ownerId = 0;
if (hasColumn($clientColumns, 'assigned_user_id') && !empty($klient['assigned_user_id'])) {
    $ownerId = (int)$klient['assigned_user_id'];
} elseif (hasColumn($clientColumns, 'owner_user_id') && !empty($klient['owner_user_id'])) {
    $ownerId = (int)$klient['owner_user_id'];
}
if ($ownerId > 0) {
    try {
        $stmtOwner = $pdo->prepare('SELECT login, imie, nazwisko FROM uzytkownicy WHERE id = :id');
        $stmtOwner->execute([':id' => $ownerId]);
        $ownerRow = $stmtOwner->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($ownerRow) {
            $clientOwnerLabel = getUserLabel($ownerRow);
        } else {
            $clientOwnerLabel = 'User #' . $ownerId;
        }
    } catch (Throwable $e) {
        $clientOwnerLabel = 'User #' . $ownerId;
    }
}
if ($crmActivityAllowed) {
    $crmStatuses = getStatusy('klient');
    $crmActivities = getActivities('klient', $klient_id);
}

$mailMessages = [];
$mailAttachments = [];
$mailThreads = [];
$mailThreadFirst = [];
try {
    ensureCrmMailTables($pdo);
    $mailMessageColumns = getTableColumns($pdo, 'mail_messages');
    $hasLeadsTable = tableExists($pdo, 'leady');

    $clientMailEmails = mailboxNormalizeEmailCandidates([
        (string)($klient['email'] ?? ''),
        (string)($klient['kontakt_email'] ?? ''),
    ]);
    [$mailEmailSql, $mailEmailParams] = mailboxBuildMessageEmailMatchSql($mailMessageColumns, $clientMailEmails, 'm', 'client_edit_msg');

    $mailWhere = [
        "(m.entity_type = 'client' AND m.entity_id = :client_id)",
        "(m.entity_type IS NULL AND m.client_id = :client_id)",
    ];
    if ($hasLeadsTable) {
        $mailWhere[] = "(m.entity_type = 'lead' AND EXISTS (SELECT 1 FROM leady l WHERE l.id = m.entity_id AND l.client_id = :client_id))";
        $mailWhere[] = "(m.entity_type IS NULL AND m.lead_id IS NOT NULL AND EXISTS (SELECT 1 FROM leady l WHERE l.id = m.lead_id AND l.client_id = :client_id))";
    }

    $mailParams = [':client_id' => $klient_id];
    if ($mailEmailSql !== '') {
        $mailWhere[] = '((m.entity_type IS NULL OR m.entity_id IS NULL) AND ' . $mailEmailSql . ')';
        $mailParams = array_merge($mailParams, $mailEmailParams);
    }

    $stmtMail = $pdo->prepare(
        "SELECT m.*
         FROM mail_messages m
         WHERE " . implode(' OR ', $mailWhere) . "
         ORDER BY COALESCE(m.received_at, m.sent_at, m.created_at) DESC
         LIMIT 50"
    );
    $stmtMail->execute($mailParams);
    $mailMessages = $stmtMail->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $messageIds = array_column($mailMessages, 'id');
    if ($messageIds) {
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmtAtt = $pdo->prepare("SELECT * FROM mail_attachments WHERE mail_message_id IN ($placeholders)");
        $stmtAtt->execute($messageIds);
        $attachments = $stmtAtt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($attachments as $attachment) {
            $msgId = (int)($attachment['mail_message_id'] ?? 0);
            if ($msgId <= 0) {
                continue;
            }
            if (!isset($mailAttachments[$msgId])) {
                $mailAttachments[$msgId] = [];
            }
            $mailAttachments[$msgId][] = $attachment;
        }
    }

    foreach ($mailMessages as $message) {
        $threadId = (int)($message['thread_id'] ?? 0);
        if ($threadId <= 0) {
            continue;
        }
        if (!isset($mailThreads[$threadId])) {
            $mailThreads[$threadId] = [];
            $mailThreadFirst[$threadId] = (int)$message['id'];
        }
        $mailThreads[$threadId][] = $message;
    }
} catch (Throwable $e) {
    error_log('edytuj_klienta.php: cannot load client mailbox: ' . $e->getMessage());
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container mt-4">
    <h2>Edytuj dane klienta</h2>
    <form action="<?= BASE_URL ?>/zapisz_klienta.php" method="post">
        <input type="hidden" name="id" value="<?= $klient['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <div class="row mb-3">
            <div class="col-md-4">
                <label for="nip" class="form-label">NIP</label>
                <input type="text" name="nip" id="nip" value="<?= htmlspecialchars($form['nip'] ?? '') ?>" class="form-control" required>
                <button type="button" id="gusLookupTrigger" class="btn btn-outline-secondary btn-sm w-100 mt-2">
                    Pobierz z GUS
                </button>
                <div id="gusLookupAlert" class="alert mt-2 d-none" role="alert"></div>
            </div>
            <div class="col-md-8">
                <label for="nazwa_firmy" class="form-label">Nazwa firmy</label>
                <input type="text" name="nazwa_firmy" id="nazwa_firmy" value="<?= htmlspecialchars($form['nazwa_firmy'] ?? '') ?>" class="form-control" required>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-4">
                <label for="regon" class="form-label">REGON</label>
                <input type="text" name="regon" id="regon" value="<?= htmlspecialchars($form['regon'] ?? '') ?>" class="form-control">
            </div>
            <div class="col-md-4">
                <label for="telefon" class="form-label">Telefon</label>
                <input type="text" name="telefon" id="telefon" value="<?= htmlspecialchars((string)($klient['telefon'] ?? '')) ?>" class="form-control">
            </div>
            <div class="col-md-4">
                <label for="email" class="form-label">Email</label>
                <input type="email" name="email" id="email" value="<?= htmlspecialchars((string)($klient['email'] ?? '')) ?>" class="form-control">
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-4">
                <label for="adres" class="form-label">Adres</label>
                <input type="text" name="adres" id="adres" value="<?= htmlspecialchars($form['adres'] ?? '') ?>" class="form-control">
            </div>
            <div class="col-md-4">
                <label for="miasto" class="form-label">Miasto</label>
                <input type="text" name="miasto" id="miasto" value="<?= htmlspecialchars($form['miasto'] ?? '') ?>" class="form-control">
            </div>
            <div class="col-md-4">
                <label for="wojewodztwo" class="form-label">Województwo</label>
                <input type="text" name="wojewodztwo" id="wojewodztwo" value="<?= htmlspecialchars($form['wojewodztwo'] ?? '') ?>" class="form-control">
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-4">
                <label for="status" class="form-label">Status</label>
                <select name="status" id="status" class="form-select">
                    <?php
                    $opcje = ['Nowy', 'W trakcie rozmów', 'Aktywny', 'Zakończony'];
                    foreach ($opcje as $opcja) {
                        $sel = ($klient['status'] === $opcja) ? 'selected' : '';
                        echo "<option value='$opcja' $sel>$opcja</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="potencjal" class="form-label">Potencjał (zł)</label>
                <input type="number" step="0.01" name="potencjal" id="potencjal" value="<?= htmlspecialchars((string)($klient['potencjal'] ?? '')) ?>" class="form-control">
            </div>
            <div class="col-md-4">
                <label for="ostatni_kontakt" class="form-label">Ostatni kontakt</label>
                <input type="date" name="ostatni_kontakt" id="ostatni_kontakt" value="<?= $klient['ostatni_kontakt'] ?>" class="form-control">
            </div>
        </div>

        <div class="mb-3">
            <label for="przypomnienie" class="form-label">Przypomnienie</label>
            <input type="date" name="przypomnienie" id="przypomnienie" value="<?= $klient['przypomnienie'] ?>" class="form-control">
        </div>

        <div class="mb-3">
            <label for="notatka" class="form-label">Notatki</label>
            <textarea name="notatka" id="notatka" rows="4" class="form-control"><?= htmlspecialchars((string)($klient['notatka'] ?? '')) ?></textarea>
        </div>

        <button type="submit" class="btn btn-success">Zapisz zmiany</button>
        <a href="lista_klientow.php" class="btn btn-secondary">Anuluj</a>
    </form>

    <div class="card mt-4" id="client-activity">
        <div class="card-header">Szczegoly klienta</div>
        <div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-md-4">
                    <div class="text-muted small">Nazwa firmy</div>
                    <div class="fw-semibold"><?= htmlspecialchars($klient['nazwa_firmy'] ?? '') ?></div>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small">NIP</div>
                    <div><?= htmlspecialchars($klient['nip'] ?? '-') ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Telefon</div>
                    <div><?= htmlspecialchars($klient['telefon'] ?? '-') ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Email</div>
                    <div><?= htmlspecialchars($klient['email'] ?? '-') ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Status (glowny)</div>
                    <div><?= htmlspecialchars($klient['status'] ?? '-') ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Opiekun</div>
                    <div><?= htmlspecialchars($clientOwnerLabel) ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Utworzono</div>
                    <div><?= htmlspecialchars($klient['data_dodania'] ?? '-') ?></div>
                </div>
            </div>

            <ul class="nav nav-tabs" id="clientActivityTabs" role="tablist">
                <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#client-activity-pane">Aktywnosc</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#client-mail-pane">Poczta</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#client-sms-pane">SMS</a></li>
            </ul>
            <div class="tab-content pt-3">
                <div class="tab-pane fade show active" id="client-activity-pane">
                    <?php if ($activityFlash): ?>
                        <div class="alert alert-<?= htmlspecialchars($activityFlash['type']) ?>">
                            <?= htmlspecialchars($activityFlash['msg']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!$crmActivityAllowed): ?>
                        <div class="alert alert-warning">Brak dostepu do aktywnosci tego klienta.</div>
                    <?php else: ?>
                        <div class="row g-3 mb-3">
                            <div class="col-lg-6">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <h6 class="mb-2">Status/czynnosc</h6>
                                        <?php if (empty($crmStatuses)): ?>
                                            <div class="alert alert-info mb-2">Brak zdefiniowanych statusow CRM. Dodaj je w tabeli crm_statusy.</div>
                                        <?php endif; ?>
                                        <form method="post" class="row g-2 align-items-end">
                                            <input type="hidden" name="crm_action" value="status">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <div class="col-12">
                                                <label class="form-label">Status/czynnosc</label>
                                                <select name="crm_status_id" class="form-select" <?= empty($crmStatuses) ? 'disabled' : 'required' ?>>
                                                    <option value="">Wybierz z listy</option>
                                                    <?php foreach ($crmStatuses as $status): ?>
                                                        <option value="<?= (int)($status['id'] ?? 0) ?>">
                                                            <?= htmlspecialchars($status['nazwa'] ?? '') ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Komentarz (opcjonalnie)</label>
                                                <input type="text" name="crm_status_comment" class="form-control" maxlength="255">
                                            </div>
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-primary" <?= empty($crmStatuses) ? 'disabled' : '' ?>>Zapisz</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <h6 class="mb-2">Notatka</h6>
                                        <form method="post" class="row g-2 align-items-end">
                                            <input type="hidden" name="crm_action" value="note">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <div class="col-12">
                                                <label class="form-label">Tresc</label>
                                                <textarea name="crm_note" rows="3" class="form-control" required></textarea>
                                            </div>
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-outline-primary">Dodaj</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-2 text-muted small">Ostatnie aktywnosci (najnowsze u gory)</div>
                        <?php if (empty($crmActivities)): ?>
                            <div class="text-muted">Brak aktywnosci do wyswietlenia.</div>
                        <?php else: ?>
                            <?php foreach ($crmActivities as $activity): ?>
                                <?php
                                $activityType = $activity['typ'] ?? 'system';
                                $badgeMap = [
                                    'status' => ['label' => 'Status', 'class' => 'bg-primary'],
                                    'notatka' => ['label' => 'Notatka', 'class' => 'bg-info text-dark'],
                                    'mail' => ['label' => 'Mail', 'class' => 'bg-warning text-dark'],
                                    'system' => ['label' => 'System', 'class' => 'bg-secondary'],
                                ];
                                $badge = $badgeMap[$activityType] ?? $badgeMap['system'];
                                $title = $activityType === 'status'
                                    ? (string)($activity['status_nazwa'] ?? 'Status')
                                    : $badge['label'];
                                if (in_array($activityType, ['mail', 'system'], true) && !empty($activity['temat'])) {
                                    $title = (string)$activity['temat'];
                                }
                                $body = '';
                                if ($activityType === 'status' && !empty($activity['tresc'])) {
                                    $body = (string)$activity['tresc'];
                                } elseif (in_array($activityType, ['notatka', 'mail', 'system'], true)) {
                                    $body = (string)($activity['tresc'] ?? '');
                                }
                                $author = trim((string)($activity['imie'] ?? '') . ' ' . (string)($activity['nazwisko'] ?? ''));
                                if ($author === '') {
                                    $author = (string)($activity['login'] ?? 'Uzytkownik');
                                }
                                $createdAt = !empty($activity['created_at']) ? date('d.m.Y H:i', strtotime($activity['created_at'])) : '';
                                ?>
                                <div class="card mb-2">
                                    <div class="card-body py-2">
                                        <div class="d-flex justify-content-between align-items-start gap-3">
                                            <div>
                                                <span class="badge <?= htmlspecialchars($badge['class']) ?>"><?= htmlspecialchars($badge['label']) ?></span>
                                                <div class="fw-semibold mt-1"><?= htmlspecialchars($title) ?></div>
                                                <?php if ($body !== ''): ?>
                                                    <div class="text-muted small"><?= htmlspecialchars($body) ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-end small text-muted">
                                                <div><?= htmlspecialchars($author) ?></div>
                                                <div><?= htmlspecialchars($createdAt) ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="tab-pane fade" id="client-mail-pane">
                    <?php if (empty($mailMessages)): ?>
                        <div class="text-muted">Brak wiadomosci do wyswietlenia.</div>
                    <?php else: ?>
                        <?php foreach ($mailMessages as $message): ?>
                            <?php
                            $direction = strtolower((string)($message['direction'] ?? ''));
                            $isOut = $direction === 'out';
                            $directionLabel = $isOut ? 'OUT' : 'IN';
                            $directionClass = $isOut ? 'bg-warning text-dark' : 'bg-success';
                            $dateVal = $message['received_at'] ?: ($message['sent_at'] ?: $message['created_at']);
                            $subjectVal = trim((string)($message['subject'] ?? ''));
                            $subjectVal = $subjectVal !== '' ? $subjectVal : '(bez tematu)';
                            $bodyText = trim((string)($message['body_text'] ?? ''));
                            $bodyHtml = trim((string)($message['body_html'] ?? ''));
                            $body = $bodyText !== '' ? $bodyText : trim(strip_tags($bodyHtml));
                            $body = $body !== '' ? $body : '(brak tresci)';
                            $preview = substr($body, 0, 160);
                            if (strlen($body) > 160) {
                                $preview .= '...';
                            }
                            $msgId = (int)$message['id'];
                            $threadId = (int)($message['thread_id'] ?? 0);
                            $threadCount = $threadId > 0 && !empty($mailThreads[$threadId]) ? count($mailThreads[$threadId]) : 0;
                            ?>
                            <div class="card mb-2">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <div>
                                            <span class="badge <?= htmlspecialchars($directionClass) ?>"><?= htmlspecialchars($directionLabel) ?></span>
                                            <div class="text-muted small mt-1"><?= htmlspecialchars($dateVal ?? '') ?></div>
                                            <div class="fw-semibold"><?= htmlspecialchars($subjectVal) ?></div>
                                            <div class="text-muted small"><?= htmlspecialchars($preview) ?></div>
                                        </div>
                                        <div class="text-end">
                                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#client-edit-mail-body-<?= $msgId ?>">
                                                Rozwiń
                                            </button>
                                            <?php if ($threadCount > 1 && (($mailThreadFirst[$threadId] ?? 0) === $msgId)): ?>
                                                <button class="btn btn-sm btn-outline-primary ms-1" type="button" data-bs-toggle="collapse" data-bs-target="#client-edit-thread-<?= $threadId ?>">
                                                    Wątek (<?= (int)$threadCount ?>)
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="collapse mt-3" id="client-edit-mail-body-<?= $msgId ?>">
                                        <div class="border rounded p-3 bg-light" style="white-space: pre-wrap;"><?= htmlspecialchars($body) ?></div>
                                        <?php if (!empty($mailAttachments[$msgId])): ?>
                                            <div class="mt-3">
                                                <div class="fw-semibold">Załączniki</div>
                                                <ul class="mb-0">
                                                    <?php foreach ($mailAttachments[$msgId] as $attachment): ?>
                                                        <li>
                                                            <a href="mail_attachment_download.php?id=<?= (int)$attachment['id'] ?>">
                                                                <?= htmlspecialchars($attachment['filename'] ?? 'zalacznik') ?>
                                                            </a>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($threadCount > 1 && (($mailThreadFirst[$threadId] ?? 0) === $msgId)): ?>
                                        <div class="collapse mt-3" id="client-edit-thread-<?= $threadId ?>">
                                            <div class="border rounded p-3">
                                                <div class="fw-semibold mb-2">Wątek</div>
                                                <ul class="mb-0">
                                                    <?php foreach ($mailThreads[$threadId] as $threadMessage): ?>
                                                        <?php
                                                        $threadDirection = strtolower((string)($threadMessage['direction'] ?? ''));
                                                        $threadLabel = $threadDirection === 'out' ? 'OUT' : 'IN';
                                                        $threadDate = $threadMessage['received_at'] ?: ($threadMessage['sent_at'] ?: $threadMessage['created_at']);
                                                        $threadSubject = trim((string)($threadMessage['subject'] ?? ''));
                                                        $threadSubject = $threadSubject !== '' ? $threadSubject : '(bez tematu)';
                                                        ?>
                                                        <li>
                                                            <span class="text-muted small"><?= htmlspecialchars($threadDate ?? '') ?></span>
                                                            <span class="badge bg-light text-dark ms-1"><?= htmlspecialchars($threadLabel) ?></span>
                                                            <span class="ms-1"><?= htmlspecialchars($threadSubject) ?></span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="tab-pane fade" id="client-sms-pane">
                    <div class="alert alert-info mb-0">Modul SMS zostanie dodany w kolejnym etapie.</div>
                </div>
            </div>
        </div>
    </div>

    <?php foreach ($docAlerts as $alert): ?>
        <div class="alert alert-<?= htmlspecialchars($alert['type']) ?> mt-3">
            <?= htmlspecialchars($alert['msg']) ?>
        </div>
    <?php endforeach; ?>

    <?php if (!empty($generatedDoc['doc_id'])): ?>
        <div class="alert alert-success mt-3">
            Dokument <?= htmlspecialchars($generatedDoc['doc_number'] ?? '') ?> został zapisany.
            <a class="ms-2" href="docs/download.php?id=<?= (int)$generatedDoc['doc_id'] ?>">Pobierz</a>
            <a class="ms-2" href="docs/download.php?id=<?= (int)$generatedDoc['doc_id'] ?>&inline=1" target="_blank">Podgląd</a>
        </div>
    <?php endif; ?>

    <?php if ($documentsAllowed): ?>
        <div class="card mt-4">
            <div class="card-body">
                <h5 class="card-title">Generowanie dokumentów</h5>
                <form method="post" class="row g-3 align-items-end">
                    <input type="hidden" name="doc_action" value="generate_umowa">
                    <div class="col-md-6">
                        <label for="kampania_id" class="form-label">Kampania do umowy</label>
                        <select name="kampania_id" id="kampania_id" class="form-select" required>
                            <option value="">Wybierz kampanię</option>
                            <?php foreach ($campaigns as $camp): ?>
                                <option value="<?= (int)$camp['id'] ?>">
                                    #<?= (int)$camp['id'] ?> <?= htmlspecialchars($camp['klient_nazwa'] ?? $klient['nazwa_firmy']) ?>
                                    (<?= htmlspecialchars($camp['data_start'] ?? '') ?> - <?= htmlspecialchars($camp['data_koniec'] ?? '') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($campaigns)): ?>
                            <div class="form-text">Brak kampanii przypisanych do klienta.</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <button class="btn btn-primary" type="submit">Generuj umowę reklamową</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-body">
                <h5 class="card-title">Archiwum dokumentów</h5>
                <?php if (empty($documents)): ?>
                    <p class="text-muted mb-0">Brak wygenerowanych dokumentów.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Numer</th>
                                    <th>Typ</th>
                                    <th>Data</th>
                                    <th>Wygenerował</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($documents as $doc): ?>
                                    <?php
                                    $docTypeLabel = ($doc['doc_type'] ?? '') === 'umowa' ? 'Umowa' : 'Aneks';
                                    $creator = trim((string)($doc['imie'] ?? '') . ' ' . (string)($doc['nazwisko'] ?? ''));
                                    if ($creator === '') {
                                        $creator = $doc['login'] ?? '—';
                                    }
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($doc['doc_number'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($docTypeLabel) ?></td>
                                        <td><?= htmlspecialchars($doc['created_at'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($creator ?: '—') ?></td>
                                        <td class="text-nowrap">
                                            <a class="btn btn-sm btn-outline-secondary" href="docs/download.php?id=<?= (int)$doc['id'] ?>">Pobierz</a>
                                            <a class="btn btn-sm btn-outline-primary" href="docs/download.php?id=<?= (int)$doc['id'] ?>&inline=1" target="_blank">Podgląd</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
(function() {
    const gusBtn = document.getElementById('gusLookupTrigger');
    const gusAlert = document.getElementById('gusLookupAlert');
    const formFields = {
        nip: document.getElementById('nip'),
        nazwa: document.getElementById('nazwa_firmy'),
        regon: document.getElementById('regon'),
        adres: document.getElementById('adres'),
        miasto: document.getElementById('miasto')
    };

    function fillField(input, value) {
        if (input && value !== undefined && value !== null) {
            input.value = value;
        }
    }

    function showGusAlert(message, type) {
        gusAlert.className = 'alert alert-' + type + ' mt-2';
        gusAlert.textContent = message;
        gusAlert.classList.remove('d-none');
    }

    function parseJsonResponse(response) {
        return response.text().then(function(text) {
            const trimmed = String(text || '').replace(/^\uFEFF/, '').trim();
            const first = trimmed.charAt(0);
            if (first !== '{' && first !== '[') {
                throw new Error('Serwer GUS zwrócił nie-JSON.');
            }

            let payload;
            try {
                payload = JSON.parse(trimmed);
            } catch (err) {
                throw new Error('Niepoprawny JSON z backendu GUS.');
            }

            if (!response.ok) {
                const message = payload && payload.error && typeof payload.error === 'object' && payload.error.message
                    ? payload.error.message
                    : (payload && typeof payload.error === 'string'
                        ? payload.error
                        : (payload && payload.message ? payload.message : 'Nie udało się pobrać danych z GUS.'));
                throw new Error(message);
            }

            return payload;
        });
    }

    function gusFill(data, nipValue, regonValue) {
        fillField(formFields.nip, data.nip || nipValue || '');
        fillField(formFields.regon, data.regon || regonValue || '');
        fillField(formFields.nazwa, data.name || '');
        fillField(formFields.adres, data.address_street || '');
        const cityLine = ((data.address_postal ? data.address_postal + ' ' : '') + (data.address_city || '')).trim();
        if (cityLine) {
            fillField(formFields.miasto, cityLine);
        }
        if (data.legal_form) {
            showGusAlert('Uzupełniono dane z GUS. Forma prawna: ' + data.legal_form + '.', 'success');
        } else {
            showGusAlert('Uzupełniono dane z GUS.', 'success');
        }
    }

    gusBtn.addEventListener('click', function() {
        const nipValue = (formFields.nip.value || '').replace(/\D/g, '');
        const regonValue = (formFields.regon.value || '').replace(/\D/g, '');
        if (nipValue.length !== 10 && regonValue.length < 7) {
            showGusAlert('Podaj poprawny NIP lub REGON.', 'warning');
            return;
        }

        gusBtn.disabled = true;
        showGusAlert('Pobieranie danych z GUS...', 'info');

        const query = nipValue.length === 10 ? 'nip=' + nipValue : 'regon=' + regonValue;
        const gusEndpoint = 'api/gus_lookup.php';
        fetch(gusEndpoint + '?' + query, { headers: { 'Accept': 'application/json' } })
            .then(parseJsonResponse)
            .then(payload => {
                if (!payload || payload.ok !== true) {
                    const message = payload && payload.error && typeof payload.error === 'object' && payload.error.message
                        ? payload.error.message
                        : (payload && typeof payload.error === 'string'
                            ? payload.error
                            : (payload && payload.message ? payload.message : 'Nie udało się pobrać danych z GUS.'));
                    throw new Error(message);
                }
                gusFill(payload.data || {}, nipValue, regonValue);
            })
            .catch(err => {
                console.error(err);
                showGusAlert(err.message || 'Nie udało się pobrać danych z GUS.', 'danger');
            })
            .finally(() => {
                gusBtn.disabled = false;
            });
    });
})();
</script>
