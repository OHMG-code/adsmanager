<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/crm_activity.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/handlers/client_save.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

$pageTitle = 'Edycja klienta';
ensureClientLeadColumns($pdo);
$clientColumns = getTableColumns($pdo, 'klienci');

$clientId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$client = null;
$clientError = '';
$companyRow = null;

if ($clientId <= 0) {
    $clientError = 'Brak identyfikatora klienta.';
    http_response_code(400);
} else {
    $stmt = $pdo->prepare('SELECT * FROM klienci WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $clientId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$client || !canAccessCrmObject('klient', $clientId, $currentUser)) {
        $clientError = 'Brak dostepu do klienta lub nie istnieje.';
        http_response_code(403);
        $client = null;
    }
    if ($client && !empty($client['company_id'])) {
        try {
            ensureCompaniesTable($pdo);
            $stmtCompany = $pdo->prepare('SELECT * FROM companies WHERE id = :id LIMIT 1');
            $stmtCompany->execute([':id' => (int)$client['company_id']]);
            $companyRow = $stmtCompany->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            $companyRow = null;
        }
    }
}

$statusOptions = [
    'Nowy' => 'Nowy',
    'W trakcie rozmow' => 'W trakcie rozmow',
    'Aktywny' => 'Aktywny',
    'Zakonczony' => 'Zakonczony',
];
if ($client) {
    $currentStatus = (string)($client['status'] ?? '');
    if ($currentStatus !== '' && !array_key_exists($currentStatus, $statusOptions) && !in_array($currentStatus, $statusOptions, true)) {
        $statusOptions[$currentStatus] = $currentStatus;
    }
}

function normalizeContactValue(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    return preg_replace('/\s+/', ' ', $value) ?: '';
}

function buildContactSummary(array $contact, array $prefLabels): string
{
    $parts = [];
    if ($contact['kontakt_imie_nazwisko'] !== '') {
        $parts[] = $contact['kontakt_imie_nazwisko'];
    }
    if ($contact['kontakt_stanowisko'] !== '') {
        $parts[] = 'stanowisko: ' . $contact['kontakt_stanowisko'];
    }
    if ($contact['kontakt_telefon'] !== '') {
        $parts[] = 'tel: ' . $contact['kontakt_telefon'];
    }
    if ($contact['kontakt_email'] !== '') {
        $parts[] = 'email: ' . $contact['kontakt_email'];
    }
    if ($contact['kontakt_preferencja'] !== '') {
        $pref = $prefLabels[$contact['kontakt_preferencja']] ?? $contact['kontakt_preferencja'];
        $parts[] = 'preferencja: ' . $pref;
    }
    if (!$parts) {
        return 'Usunieto dane osoby kontaktowej.';
    }
    return implode(', ', $parts);
}

$flash = $_SESSION['client_edit_flash'] ?? null;
unset($_SESSION['client_edit_flash']);

$contactPrefOptions = [
    '' => 'Wybierz',
    'telefon' => 'Telefon',
    'email' => 'E-mail',
    'sms' => 'SMS',
];
$contactOld = [
    'kontakt_imie_nazwisko' => $client ? normalizeContactValue($client['kontakt_imie_nazwisko'] ?? '') : '',
    'kontakt_stanowisko' => $client ? normalizeContactValue($client['kontakt_stanowisko'] ?? '') : '',
    'kontakt_telefon' => $client ? normalizeContactValue($client['kontakt_telefon'] ?? '') : '',
    'kontakt_email' => $client ? normalizeContactValue($client['kontakt_email'] ?? '') : '',
    'kontakt_preferencja' => $client ? trim((string)($client['kontakt_preferencja'] ?? '')) : '',
];

$pref = static function (string $key, $companyVal, $legacyVal) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && array_key_exists($key, $_POST)) {
        return (string)$_POST[$key];
    }
    $companyVal = trim((string)$companyVal);
    if ($companyVal !== '') {
        return $companyVal;
    }
    $legacyVal = trim((string)$legacyVal);
    return $legacyVal !== '' ? $legacyVal : '';
};

$form = $client ?? [];
$form['nazwa_firmy'] = $pref('nazwa_firmy', $companyRow['name_full'] ?? ($companyRow['name_short'] ?? null), $client['nazwa_firmy'] ?? null);
$form['nip'] = $pref('nip', $companyRow['nip'] ?? null, $client['nip'] ?? null);
$form['regon'] = $pref('regon', $companyRow['regon'] ?? null, $client['regon'] ?? null);
$form['wojewodztwo'] = $pref('wojewodztwo', $companyRow['wojewodztwo'] ?? null, $client['wojewodztwo'] ?? null);
$form['miasto'] = $pref('miasto', $companyRow['city'] ?? null, $client['miejscowosc'] ?? ($client['miasto'] ?? null));

$street = trim((string)($companyRow['street'] ?? ''));
if ($street !== '') {
    $addr = $street;
    $building = trim((string)($companyRow['building_no'] ?? ''));
    $apartment = trim((string)($companyRow['apartment_no'] ?? ''));
    if ($building !== '') {
        $addr = trim($addr . ' ' . $building);
    }
    if ($apartment !== '') {
        $addr .= '/' . $apartment;
    }
    $form['adres'] = $pref('adres', $addr, $client['adres'] ?? null);
} else {
    $form['adres'] = $pref('adres', null, $client['adres'] ?? null);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $client) {
    $token = $_POST['csrf_token'] ?? '';
    if (!isCsrfTokenValid($token)) {
        $flash = ['type' => 'danger', 'msg' => 'Niepoprawny token CSRF.'];
    } elseif (!canAccessCrmObject('klient', $clientId, $currentUser)) {
        $flash = ['type' => 'danger', 'msg' => 'Brak dostepu do klienta.'];
    } else {
        $nazwa = trim((string)($_POST['nazwa_firmy'] ?? ''));
        if ($nazwa === '') {
            $flash = ['type' => 'warning', 'msg' => 'Nazwa firmy jest wymagana.'];
        } else {
            $nip = trim((string)($_POST['nip'] ?? ''));
            $regon = trim((string)($_POST['regon'] ?? ''));
            $telefon = trim((string)($_POST['telefon'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $adres = trim((string)($_POST['adres'] ?? ''));
            $miasto = trim((string)($_POST['miasto'] ?? ''));
            $wojewodztwo = trim((string)($_POST['wojewodztwo'] ?? ''));
            $status = trim((string)($_POST['status'] ?? ''));
            $potencjal = trim((string)($_POST['potencjal'] ?? ''));
            $ostatniKontakt = trim((string)($_POST['ostatni_kontakt'] ?? ''));
            $przypomnienie = trim((string)($_POST['przypomnienie'] ?? ''));
            $notatka = trim((string)($_POST['notatka'] ?? ''));
            $branza = trim((string)($_POST['branza'] ?? ''));
            $contactName = normalizeContactValue($_POST['kontakt_imie_nazwisko'] ?? '');
            $contactTitle = normalizeContactValue($_POST['kontakt_stanowisko'] ?? '');
            $contactPhone = normalizeContactValue($_POST['kontakt_telefon'] ?? '');
            $contactEmail = normalizeContactValue($_POST['kontakt_email'] ?? '');
            $contactPref = trim((string)($_POST['kontakt_preferencja'] ?? ''));
            if (!array_key_exists($contactPref, $contactPrefOptions)) {
                $contactPref = '';
            }

            $hasContactInput = ($contactName !== '' || $contactTitle !== '' || $contactPhone !== '' || $contactEmail !== '' || $contactPref !== '');
            if ($hasContactInput && $contactName === '') {
                $flash = ['type' => 'warning', 'msg' => 'Podaj imie i nazwisko osoby kontaktowej.'];
            } elseif ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
                $flash = ['type' => 'warning', 'msg' => 'Podaj poprawny adres e-mail osoby kontaktowej.'];
            } elseif ($contactPhone !== '' && !preg_match('/^[0-9+ ]+$/', $contactPhone)) {
                $flash = ['type' => 'warning', 'msg' => 'Telefon osoby kontaktowej moze zawierac tylko cyfry, plus i spacje.'];
            } else {
                if (!array_key_exists($status, $statusOptions)) {
                    $status = (string)($client['status'] ?? 'Nowy');
                }

                $data = [
                    'nip' => $nip !== '' ? $nip : null,
                    'nazwa_firmy' => $nazwa,
                    'regon' => $regon !== '' ? $regon : null,
                    'telefon' => $telefon !== '' ? $telefon : null,
                    'email' => $email !== '' ? $email : null,
                    'adres' => $adres !== '' ? $adres : null,
                    'miejscowosc' => $miasto !== '' ? $miasto : null,
                    'wojewodztwo' => $wojewodztwo !== '' ? $wojewodztwo : null,
                    'status' => $status,
                    'potencjal' => $potencjal !== '' ? $potencjal : null,
                    'ostatni_kontakt' => $ostatniKontakt !== '' ? $ostatniKontakt : null,
                    'przypomnienie' => $przypomnienie !== '' ? $przypomnienie : null,
                    'notatka' => $notatka !== '' ? $notatka : null,
                    'branza' => $branza !== '' ? $branza : null,
                    'kontakt_imie_nazwisko' => $contactName !== '' ? $contactName : null,
                    'kontakt_stanowisko' => $contactTitle !== '' ? $contactTitle : null,
                    'kontakt_telefon' => $contactPhone !== '' ? $contactPhone : null,
                    'kontakt_email' => $contactEmail !== '' ? $contactEmail : null,
                    'kontakt_preferencja' => $contactPref,
                ];

                $contactNew = [
                    'kontakt_imie_nazwisko' => $contactName,
                    'kontakt_stanowisko' => $contactTitle,
                    'kontakt_telefon' => $contactPhone,
                    'kontakt_email' => $contactEmail,
                    'kontakt_preferencja' => $contactPref,
                ];
                $contactChanged = false;
                foreach ($contactNew as $field => $value) {
                    if (($contactOld[$field] ?? '') !== $value) {
                        $contactChanged = true;
                        break;
                    }
                }

                try {
                    $payload = array_merge($_POST, $data);
                    $payload['id'] = $clientId;
                    $saveResult = saveClientFromPost($pdo, $payload, (int)($currentUser['id'] ?? 0), ['mode' => 'update']);
                    if (!$saveResult['ok']) {
                        $errorText = $saveResult['errors'] ? implode(', ', $saveResult['errors']) : 'Nie udalo sie zapisac zmian.';
                        $flash = ['type' => 'danger', 'msg' => $errorText];
                    } else {
                        if ($contactChanged) {
                            $summary = buildContactSummary($contactNew, $contactPrefOptions);
                            addActivity('klient', $clientId, 'system', (int)$currentUser['id'], $summary, null, 'Zaktualizowano osobe kontaktowa');
                        }
                        $_SESSION['client_detail_flash'] = ['type' => 'success', 'msg' => 'Dane klienta zostaly zaktualizowane.'];
                        header('Location: ' . BASE_URL . '/klient_szczegoly.php?id=' . $clientId);
                        exit;
                    }
                } catch (Throwable $e) {
                    error_log('klient_edytuj.php: save failed: ' . $e->getMessage());
                    $flash = ['type' => 'danger', 'msg' => 'Nie udalo sie zapisac zmian.'];
                }
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<main class="content-wide py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-1">Edycja klienta</h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($client['nazwa_firmy'] ?? '') ?></p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="text-muted small">ID: <?= (int)$clientId ?></span>
            <?php if ($client): ?>
                <a href="klient_szczegoly.php?id=<?= (int)$clientId ?>" class="btn btn-sm btn-outline-secondary">Powrot do szczegolow</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if ($clientError): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($clientError) ?></div>
    <?php elseif ($client): ?>
        <div class="card">
            <div class="card-header">Dane klienta</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">NIP</label>
                            <input type="text" name="nip" class="form-control" value="<?= htmlspecialchars($form['nip'] ?? '') ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Nazwa firmy *</label>
                            <input type="text" name="nazwa_firmy" class="form-control" required value="<?= htmlspecialchars($form['nazwa_firmy'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">REGON</label>
                            <input type="text" name="regon" class="form-control" value="<?= htmlspecialchars($form['regon'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Telefon</label>
                            <input type="text" name="telefon" class="form-control" value="<?= htmlspecialchars($client['telefon'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($client['email'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Adres</label>
                            <input type="text" name="adres" class="form-control" value="<?= htmlspecialchars($form['adres'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Miasto</label>
                            <input type="text" name="miasto" class="form-control" value="<?= htmlspecialchars($form['miasto'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Wojewodztwo</label>
                            <input type="text" name="wojewodztwo" class="form-control" value="<?= htmlspecialchars($form['wojewodztwo'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Branza</label>
                            <input type="text" name="branza" class="form-control" value="<?= htmlspecialchars($client['branza'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <?php foreach ($statusOptions as $key => $label): ?>
                                    <?php
                                    $currentStatus = (string)($client['status'] ?? '');
                                    $selected = ($currentStatus === $key || $currentStatus === $label) ? 'selected' : '';
                                    ?>
                                    <option value="<?= htmlspecialchars($key) ?>" <?= $selected ?>><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Potencjal (zl)</label>
                            <input type="number" step="0.01" name="potencjal" class="form-control" value="<?= htmlspecialchars($client['potencjal'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Ostatni kontakt</label>
                            <input type="date" name="ostatni_kontakt" class="form-control" value="<?= htmlspecialchars($client['ostatni_kontakt'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Przypomnienie</label>
                            <input type="date" name="przypomnienie" class="form-control" value="<?= htmlspecialchars($client['przypomnienie'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notatka</label>
                        <textarea name="notatka" rows="4" class="form-control"><?= htmlspecialchars($client['notatka'] ?? '') ?></textarea>
                    </div>
                    <div class="mt-4">
                        <h6 class="mb-3">Osoba kontaktowa</h6>
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Imie i nazwisko</label>
                                <input type="text" name="kontakt_imie_nazwisko" class="form-control" value="<?= htmlspecialchars($client['kontakt_imie_nazwisko'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Stanowisko</label>
                                <input type="text" name="kontakt_stanowisko" class="form-control" value="<?= htmlspecialchars($client['kontakt_stanowisko'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Telefon</label>
                                <input type="text" name="kontakt_telefon" class="form-control" value="<?= htmlspecialchars($client['kontakt_telefon'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">E-mail</label>
                                <input type="email" name="kontakt_email" class="form-control" value="<?= htmlspecialchars($client['kontakt_email'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Preferowany kanal</label>
                                <select name="kontakt_preferencja" class="form-select">
                                    <?php foreach ($contactPrefOptions as $key => $label): ?>
                                        <option value="<?= htmlspecialchars($key) ?>" <?= ($client['kontakt_preferencja'] ?? '') === $key ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success">Zapisz zmiany</button>
                        <a href="klient_szczegoly.php?id=<?= (int)$clientId ?>" class="btn btn-outline-secondary">Anuluj</a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
