<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/../config/config.php';

$pageTitle = "Dodaj lead";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$leadStatuses = [
    'nowy'            => 'Nowy',
    'w_kontakcie'     => 'W kontakcie',
    'oferta_wyslana'  => 'Oferta wysłana',
    'odrzucony'       => 'Odrzucony',
    'zakonczony'      => 'Zakończony',
    'skonwertowany'   => 'Skonwertowany',
];

$leadPriorities = [
    'Niski' => 'Niski',
    'Średni' => 'Średni',
    'Wysoki' => 'Wysoki',
    'Pilny' => 'Pilny',
];

$leadSources = [
    'telefon'        => 'Telefon',
    'email'          => 'Email',
    'formularz_www'  => 'Formularz WWW',
    'maps_api'       => 'Google Maps / API',
    'polecenie'      => 'Polecenie',
    'inne'           => 'Inne',
];

ensureLeadInfra($pdo);
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}
$currentRole = normalizeRole($currentUser);
$assignableUsers = fetchAssignableUsers($pdo);

$flashMessage = $_SESSION['flash'] ?? null;
if ($flashMessage) {
    unset($_SESSION['flash']);
}

$recentLeads = [];
$recentError = '';
try {
    $recentLeads = $pdo->query("SELECT id, nazwa_firmy, status, created_at FROM leady ORDER BY created_at DESC LIMIT 6")->fetchAll();
} catch (PDOException $e) {
    $recentError = $e->getMessage();
}

include __DIR__ . '/includes/header.php';

function ensureLeadInfra(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    ensureLeadColumns($pdo);
    ensureLeadActivityTable($pdo);
    $ready = true;
}

function fetchAssignableUsers(PDO $pdo): array
{
    ensureUserColumns($pdo);
    $stmt = $pdo->query("SELECT id, login, imie, nazwisko, rola, aktywny FROM uzytkownicy");
    $users = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $result = [];
    foreach ($users as $user) {
        if (isset($user['aktywny']) && (int)$user['aktywny'] === 0) {
            continue;
        }
        $role = normalizeRole($user);
        if (!in_array($role, ['Handlowiec', 'Manager', 'Administrator'], true)) {
            continue;
        }
        $user['rola'] = $role;
        $result[(int)$user['id']] = $user;
    }
    return $result;
}

function userDisplayName(array $user): string
{
    $full = trim(($user['imie'] ?? '') . ' ' . ($user['nazwisko'] ?? ''));
    if ($full !== '') {
        return $full;
    }
    return $user['login'] ?? ('User #' . ($user['id'] ?? ''));
}
?>

<main class="container py-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h4 mb-1">Dodaj nowy lead</h1>
            <p class="text-muted mb-0">Wprowadź dane pozyskane ręcznie lub z generatora.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="leady.php" class="btn btn-outline-secondary btn-sm">Przegląd</a>
            <a href="lead.php" class="btn btn-outline-primary btn-sm">Lista leadów</a>
        </div>
    </div>

    <?php if ($flashMessage): ?>
        <div class="alert alert-<?= htmlspecialchars($flashMessage['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
            <?= !empty($flashMessage['raw']) ? ($flashMessage['message'] ?? '') : htmlspecialchars($flashMessage['message'] ?? '') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
        </div>
    <?php endif; ?>
    <?php if ($recentError): ?>
        <div class="alert alert-danger">Błąd pobierania listy leadów: <?= htmlspecialchars($recentError) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card shadow-sm mb-4">
                <div class="card-header">Szybkie dodanie</div>
                <div class="card-body">
                    <form method="post" action="<?= BASE_URL ?>/lead.php">
                        <input type="hidden" name="lead_action" value="quick_create">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="return_url" value="dodaj_lead.php">
                        <div class="mb-3">
                            <label class="form-label">Nazwa firmy *</label>
                            <input type="text" name="nazwa_firmy" class="form-control" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Telefon</label>
                                <input type="text" name="telefon" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control">
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Źródło</label>
                                <select name="zrodlo" class="form-select">
                                    <?php foreach ($leadSources as $key => $label): ?>
                                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <?php foreach ($leadStatuses as $key => $label): ?>
                                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <?php if ($currentRole === 'Handlowiec'): ?>
                            <input type="hidden" name="owner_user_id" value="<?= (int)$currentUser['id'] ?>">
                        <?php else: ?>
                            <div class="mb-3">
                                <label class="form-label">Opiekun</label>
                                <select name="owner_user_id" class="form-select">
                                    <?php foreach ($assignableUsers as $userId => $user): ?>
                                        <option value="<?= (int)$userId ?>" <?= (int)$userId === (int)($currentUser['id'] ?? 0) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(userDisplayName($user)) ?> (<?= htmlspecialchars($user['rola']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-success">Dodaj lead</button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header">Pełny formularz</div>
                <div class="card-body">
                    <form method="post" action="<?= BASE_URL ?>/lead.php">
                        <input type="hidden" name="lead_action" value="create">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="return_url" value="dodaj_lead.php">
                        <div class="row g-3 mb-3">
                            <div class="col-md-8">
                                <label class="form-label">Nazwa firmy *</label>
                                <input type="text" name="nazwa_firmy" id="lead_nazwa_firmy" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">NIP</label>
                                <input type="text" name="nip" id="lead_nip" class="form-control">
                                <button type="button" id="leadGusTrigger" class="btn btn-outline-secondary btn-sm w-100 mt-2">
                                    Pobierz z GUS
                                </button>
                                <small id="leadGusStatus" class="form-text text-muted d-block mt-2"></small>
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Telefon</label>
                                <input type="text" name="telefon" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Źródło</label>
                                <select name="zrodlo" class="form-select">
                                    <?php foreach ($leadSources as $key => $label): ?>
                                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Przypisany handlowiec</label>
                                <input type="text" name="przypisany_handlowiec" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <?php foreach ($leadStatuses as $key => $label): ?>
                                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Priorytet</label>
                                <select name="priority" class="form-select">
                                    <?php foreach ($leadPriorities as $key => $label): ?>
                                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <?php if ($currentRole === 'Handlowiec'): ?>
                            <input type="hidden" name="owner_user_id" value="<?= (int)$currentUser['id'] ?>">
                        <?php else: ?>
                            <div class="mb-3">
                                <label class="form-label">Opiekun</label>
                                <select name="owner_user_id" class="form-select">
                                    <?php foreach ($assignableUsers as $userId => $user): ?>
                                        <option value="<?= (int)$userId ?>" <?= (int)$userId === (int)($currentUser['id'] ?? 0) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(userDisplayName($user)) ?> (<?= htmlspecialchars($user['rola']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Następny krok</label>
                                <input type="text" name="next_action" class="form-control" placeholder="Opis działania">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Termin następnego kroku</label>
                                <input type="datetime-local" name="next_action_at" class="form-control">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notatki</label>
                            <textarea name="notatki" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="text-end">
                            <button type="submit" class="btn btn-primary">Zapisz pełny lead</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm mb-4">
                <div class="card-header">Generator</div>
                <div class="card-body">
                    <p class="text-muted small">
                        Skorzystaj z automatycznego generatora opartego o Google Maps, aby pozyskać leady według lokalizacji i branży.
                    </p>
                    <a href="generator_leadow.php" class="btn btn-outline-success btn-sm">
                        Otwórz generator
                    </a>
                </div>
            </div>
            <div class="card shadow-sm">
                <div class="card-header">Ostatnio dodane leady</div>
                <ul class="list-group list-group-flush small">
                    <?php if (empty($recentLeads)): ?>
                        <li class="list-group-item">Brak leadów do wyświetlenia.</li>
                    <?php else: ?>
                        <?php foreach ($recentLeads as $lead): ?>
                            <li class="list-group-item d-flex justify-content-between">
                                <div>
                                    <div class="fw-semibold">
                                        <a href="lead_szczegoly.php?id=<?= (int)$lead['id'] ?>" class="text-decoration-none">
                                            <?= htmlspecialchars($lead['nazwa_firmy']) ?>
                                        </a>
                                    </div>
                                    <div class="text-muted"><?= htmlspecialchars($lead['created_at']) ?></div>
                                </div>
                                <span class="badge bg-secondary"><?= htmlspecialchars($leadStatuses[$lead['status']] ?? $lead['status']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
                <div class="card-footer text-end">
                    <a href="lead.php" class="btn btn-outline-secondary btn-sm">Zobacz wszystkie</a>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="assets/js/gus.js"></script>
<script>
initGusButton({
    buttonId: 'leadGusTrigger',
    nipInputId: 'lead_nip',
    statusId: 'leadGusStatus',
    endpoint: 'gus_lookup.php',
    fieldMap: {
        lead_nip: 'nip',
        lead_nazwa_firmy: 'nazwa'
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
