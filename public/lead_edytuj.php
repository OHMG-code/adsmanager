<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/crm_activity.php';
require_once __DIR__ . '/../config/config.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

$pageTitle = 'Edycja leada';
ensureLeadColumns($pdo);
$leadColumns = getTableColumns($pdo, 'leady');

$leadId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$lead = null;
$leadError = '';

if ($leadId <= 0) {
    $leadError = 'Brak identyfikatora leada.';
    http_response_code(400);
} else {
    $stmt = $pdo->prepare('SELECT * FROM leady WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $leadId]);
    $lead = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$lead || !canAccessCrmObject('lead', $leadId, $currentUser)) {
        $leadError = 'Brak dostepu do leada lub nie istnieje.';
        http_response_code(403);
        $lead = null;
    }
}

$leadStatuses = [
    'nowy' => 'Nowy',
    'w_kontakcie' => 'W kontakcie',
    'oferta_wyslana' => 'Oferta wyslana',
    'odrzucony' => 'Odrzucony',
    'zakonczony' => 'Zakonczony',
    'skonwertowany' => 'Skonwertowany',
];
$leadSources = [
    'telefon' => 'Telefon',
    'email' => 'Email',
    'formularz_www' => 'Formularz WWW',
    'maps_api' => 'Google Maps / API',
    'polecenie' => 'Polecenie',
    'inne' => 'Inne',
];
$leadPriorities = [
    'Niski' => 'Niski',
    'Sredni' => 'Sredni',
    'Wysoki' => 'Wysoki',
    'Pilny' => 'Pilny',
];

if ($lead) {
    $currentStatus = (string)($lead['status'] ?? '');
    if ($currentStatus !== '' && !array_key_exists($currentStatus, $leadStatuses) && !in_array($currentStatus, $leadStatuses, true)) {
        $leadStatuses[$currentStatus] = $currentStatus;
    }
    $currentSource = (string)($lead['zrodlo'] ?? '');
    if ($currentSource !== '' && !array_key_exists($currentSource, $leadSources) && !in_array($currentSource, $leadSources, true)) {
        $leadSources[$currentSource] = $currentSource;
    }
    $currentPriority = (string)($lead['priority'] ?? '');
    if ($currentPriority !== '' && !array_key_exists($currentPriority, $leadPriorities) && !in_array($currentPriority, $leadPriorities, true)) {
        $leadPriorities[$currentPriority] = $currentPriority;
    }
}

function normalizeDateTimeInput(?string $input): ?string
{
    $input = trim((string)$input);
    if ($input === '') {
        return null;
    }
    $formats = ['Y-m-d\\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $input);
        if ($dt) {
            return $dt->format('Y-m-d H:i:s');
        }
    }
    return null;
}

function formatDateTimeInput(?string $value): string
{
    if (!$value) {
        return '';
    }
    try {
        $dt = new DateTime($value);
        return $dt->format('Y-m-d\\TH:i');
    } catch (Throwable $e) {
        return '';
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

$flash = $_SESSION['lead_edit_flash'] ?? null;
unset($_SESSION['lead_edit_flash']);

$contactPrefOptions = [
    '' => 'Wybierz',
    'telefon' => 'Telefon',
    'email' => 'E-mail',
    'sms' => 'SMS',
];
$contactOld = [
    'kontakt_imie_nazwisko' => $lead ? normalizeContactValue($lead['kontakt_imie_nazwisko'] ?? '') : '',
    'kontakt_stanowisko' => $lead ? normalizeContactValue($lead['kontakt_stanowisko'] ?? '') : '',
    'kontakt_telefon' => $lead ? normalizeContactValue($lead['kontakt_telefon'] ?? '') : '',
    'kontakt_email' => $lead ? normalizeContactValue($lead['kontakt_email'] ?? '') : '',
    'kontakt_preferencja' => $lead ? trim((string)($lead['kontakt_preferencja'] ?? '')) : '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $lead) {
    $token = $_POST['csrf_token'] ?? '';
    if (!isCsrfTokenValid($token)) {
        $flash = ['type' => 'danger', 'msg' => 'Niepoprawny token CSRF.'];
    } elseif (!canAccessCrmObject('lead', $leadId, $currentUser)) {
        $flash = ['type' => 'danger', 'msg' => 'Brak dostepu do leada.'];
    } else {
        $nazwa = trim((string)($_POST['nazwa_firmy'] ?? ''));
        if ($nazwa === '') {
            $flash = ['type' => 'warning', 'msg' => 'Nazwa firmy jest wymagana.'];
        } else {
            $nip = trim((string)($_POST['nip'] ?? ''));
            $telefon = trim((string)($_POST['telefon'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $handlowiec = trim((string)($_POST['przypisany_handlowiec'] ?? ''));
            $status = trim((string)($_POST['status'] ?? ''));
            $zrodlo = trim((string)($_POST['zrodlo'] ?? ''));
            $priority = trim((string)($_POST['priority'] ?? ''));
            $nextAction = trim((string)($_POST['next_action'] ?? ''));
            $nextActionAt = normalizeDateTimeInput($_POST['next_action_at'] ?? '');
            $notatki = trim((string)($_POST['notatki'] ?? ''));
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
                if (!array_key_exists($status, $leadStatuses)) {
                    $status = (string)($lead['status'] ?? 'nowy');
                }
                if (!array_key_exists($zrodlo, $leadSources)) {
                    $zrodlo = (string)($lead['zrodlo'] ?? 'inne');
                }
                if ($priority === '') {
                    $priority = (string)($lead['priority'] ?? '');
                }

                $data = [
                    'nazwa_firmy' => $nazwa,
                    'nip' => $nip !== '' ? $nip : null,
                    'telefon' => $telefon !== '' ? $telefon : null,
                    'email' => $email !== '' ? $email : null,
                    'przypisany_handlowiec' => $handlowiec !== '' ? $handlowiec : null,
                    'status' => $status,
                    'zrodlo' => $zrodlo,
                    'notatki' => $notatki !== '' ? $notatki : null,
                    'kontakt_imie_nazwisko' => $contactName !== '' ? $contactName : null,
                    'kontakt_stanowisko' => $contactTitle !== '' ? $contactTitle : null,
                    'kontakt_telefon' => $contactPhone !== '' ? $contactPhone : null,
                    'kontakt_email' => $contactEmail !== '' ? $contactEmail : null,
                    'kontakt_preferencja' => $contactPref,
                ];
                if (hasColumn($leadColumns, 'priority')) {
                    $data['priority'] = $priority !== '' ? $priority : null;
                }
                if (hasColumn($leadColumns, 'next_action')) {
                    $data['next_action'] = $nextAction !== '' ? $nextAction : null;
                }
                if (hasColumn($leadColumns, 'next_action_at')) {
                    $data['next_action_at'] = $nextActionAt;
                }
                if (hasColumn($leadColumns, 'next_action_date')) {
                    $data['next_action_date'] = $nextActionAt ? substr($nextActionAt, 0, 10) : null;
                }

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

                $setParts = [];
                $params = [':id' => $leadId];
                foreach ($data as $col => $value) {
                    if (!hasColumn($leadColumns, $col)) {
                        continue;
                    }
                    $setParts[] = $col . ' = :' . $col;
                    $params[':' . $col] = $value;
                }

                if (!$setParts) {
                    $flash = ['type' => 'warning', 'msg' => 'Brak pol do aktualizacji.'];
                } else {
                    try {
                        $stmt = $pdo->prepare('UPDATE leady SET ' . implode(', ', $setParts) . ' WHERE id = :id');
                        $stmt->execute($params);
                        if ($contactChanged) {
                            $summary = buildContactSummary($contactNew, $contactPrefOptions);
                            addActivity('lead', $leadId, 'system', (int)$currentUser['id'], $summary, null, 'Zaktualizowano osobe kontaktowa');
                        }
                        $_SESSION['lead_detail_flash'] = ['type' => 'success', 'msg' => 'Dane leada zostaly zaktualizowane.'];
                        header('Location: ' . BASE_URL . '/lead_szczegoly.php?id=' . $leadId);
                        exit;
                    } catch (Throwable $e) {
                        $flash = ['type' => 'danger', 'msg' => 'Nie udalo sie zapisac zmian.'];
                    }
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
            <h1 class="h4 mb-1">Edycja leada</h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($lead['nazwa_firmy'] ?? '') ?></p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="text-muted small">ID: <?= (int)$leadId ?></span>
            <?php if ($lead): ?>
                <a href="lead_szczegoly.php?id=<?= (int)$leadId ?>" class="btn btn-sm btn-outline-secondary">Powrot do szczegolow</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if ($leadError): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($leadError) ?></div>
    <?php elseif ($lead): ?>
        <div class="card">
            <div class="card-header">Dane leada</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                    <div class="mb-3">
                        <label class="form-label">Nazwa firmy *</label>
                        <input type="text" name="nazwa_firmy" class="form-control" required value="<?= htmlspecialchars($lead['nazwa_firmy'] ?? '') ?>">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">NIP</label>
                            <input type="text" name="nip" class="form-control" value="<?= htmlspecialchars($lead['nip'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Przypisany handlowiec</label>
                            <input type="text" name="przypisany_handlowiec" class="form-control" value="<?= htmlspecialchars($lead['przypisany_handlowiec'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Telefon</label>
                            <input type="text" name="telefon" class="form-control" value="<?= htmlspecialchars($lead['telefon'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($lead['email'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Zrodlo</label>
                            <select name="zrodlo" class="form-select">
                                <?php foreach ($leadSources as $key => $label): ?>
                                    <?php
                                    $currentSource = (string)($lead['zrodlo'] ?? '');
                                    $selected = ($currentSource === $key || $currentSource === $label) ? 'selected' : '';
                                    ?>
                                    <option value="<?= htmlspecialchars($key) ?>" <?= $selected ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <?php foreach ($leadStatuses as $key => $label): ?>
                                    <?php
                                    $currentStatus = (string)($lead['status'] ?? '');
                                    $selected = ($currentStatus === $key || $currentStatus === $label) ? 'selected' : '';
                                    ?>
                                    <option value="<?= htmlspecialchars($key) ?>" <?= $selected ?>><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Priorytet</label>
                            <select name="priority" class="form-select">
                                <?php foreach ($leadPriorities as $key => $label): ?>
                                    <option value="<?= htmlspecialchars($key) ?>" <?= ($lead['priority'] ?? '') === $key ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php if (hasColumn($leadColumns, 'next_action')): ?>
                        <div class="mb-3">
                            <label class="form-label">Nastepny krok</label>
                            <input type="text" name="next_action" class="form-control" value="<?= htmlspecialchars($lead['next_action'] ?? '') ?>" placeholder="Opis dzialania">
                        </div>
                    <?php endif; ?>
                    <?php if (hasColumn($leadColumns, 'next_action_at')): ?>
                        <div class="mb-3">
                            <label class="form-label">Termin nastepnego kroku</label>
                            <input type="datetime-local" name="next_action_at" class="form-control" value="<?= htmlspecialchars(formatDateTimeInput($lead['next_action_at'] ?? ($lead['next_action_date'] ?? ''))) ?>">
                        </div>
                    <?php endif; ?>
                    <?php if (hasColumn($leadColumns, 'notatki')): ?>
                        <div class="mb-3">
                            <label class="form-label">Notatki</label>
                            <textarea name="notatki" rows="4" class="form-control"><?= htmlspecialchars($lead['notatki'] ?? '') ?></textarea>
                        </div>
                    <?php endif; ?>
                    <div class="mt-4">
                        <h6 class="mb-3">Osoba kontaktowa</h6>
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Imie i nazwisko</label>
                                <input type="text" name="kontakt_imie_nazwisko" class="form-control" value="<?= htmlspecialchars($lead['kontakt_imie_nazwisko'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Stanowisko</label>
                                <input type="text" name="kontakt_stanowisko" class="form-control" value="<?= htmlspecialchars($lead['kontakt_stanowisko'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Telefon</label>
                                <input type="text" name="kontakt_telefon" class="form-control" value="<?= htmlspecialchars($lead['kontakt_telefon'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">E-mail</label>
                                <input type="email" name="kontakt_email" class="form-control" value="<?= htmlspecialchars($lead['kontakt_email'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Preferowany kanal</label>
                                <select name="kontakt_preferencja" class="form-select">
                                    <?php foreach ($contactPrefOptions as $key => $label): ?>
                                        <option value="<?= htmlspecialchars($key) ?>" <?= ($lead['kontakt_preferencja'] ?? '') === $key ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success">Zapisz zmiany</button>
                        <a href="lead_szczegoly.php?id=<?= (int)$leadId ?>" class="btn btn-outline-secondary">Anuluj</a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
