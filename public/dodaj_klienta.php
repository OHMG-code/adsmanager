<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo) ?? [];
$currentRole = normalizeRole($currentUser);
ensureClientLeadColumns($pdo);
$clientColumns = getTableColumns($pdo, 'klienci');
$canAssignOwner = hasColumn($clientColumns, 'assigned_user_id')
    && in_array($currentRole, ['Administrator', 'Manager'], true);
$assignableUsers = [];
if ($canAssignOwner) {
    $userCols = getTableColumns($pdo, 'uzytkownicy');
    $stmt = $pdo->query("SELECT id, login, imie, nazwisko, rola, aktywny FROM uzytkownicy ORDER BY login");
    $users = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($users as $user) {
        if (hasColumn($userCols, 'aktywny') && (int)($user['aktywny'] ?? 1) === 0) {
            continue;
        }
        $assignableUsers[(int)$user['id']] = $user;
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'lookup_nip') {
    header('Content-Type: application/json; charset=utf-8');
    $nip = isset($_GET['nip']) ? preg_replace('/\D/', '', $_GET['nip']) : '';
    if (strlen($nip) !== 10) {
        echo json_encode(['ok' => false, 'error' => 'Podaj poprawny numer NIP (10 cyfr).'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $lookupData = fetchVatLookup($nip);
        echo json_encode(['ok' => true, 'data' => $lookupData], JSON_UNESCAPED_UNICODE);
    } catch (RuntimeException $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function fetchVatLookup(string $nip): array
{
    $url = sprintf('https://wl-api.mf.gov.pl/api/search/nip/%s?date=%s', urlencode($nip), date('Y-m-d'));
    $response = httpGetJson($url);

    $subject = $response['result']['subject'] ?? null;
    if (!$subject) {
        $message = $response['result']['message'] ?? ($response['message'] ?? 'Nie znaleziono w wykazie VAT dla podanego NIP. Możesz dodać klienta ręcznie.');
        throw new RuntimeException($message);
    }

    $addressLine = $subject['workingAddress'] ?? ($subject['residenceAddress'] ?? '');
    $addressParts = parseVatAddress($addressLine);

    return [
        'name'        => $subject['name'] ?? '',
        'nip'         => $subject['nip'] ?? $nip,
        'regon'       => $subject['regon'] ?? '',
        'krs'         => $subject['krs'] ?? '',
        'address'     => $addressParts['street'],
        'city'        => $addressParts['city'],
        'postal'      => $addressParts['postal'],
        'statusVat'   => $subject['statusVat'] ?? '',
        'accounts'    => array_values($subject['accountNumbers'] ?? []),
        'voivodeship' => $subject['voivodeship'] ?? ($subject['province'] ?? ''),
    ];
}

function httpGetJson(string $url): array
{
    $response = null;
    $httpCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if (!$ch) {
            throw new RuntimeException('Nie udało się zainicjować połączenia z API KAS.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'ProjectManager/1.0 (+https://radiozulawy.pl)',
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Błąd połączenia z API KAS: ' . $error);
        }
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'header' => "User-Agent: ProjectManager/1.0\r\n",
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('Nie udało się pobrać danych z API KAS.');
        }
        global $http_response_header;
        if (!empty($http_response_header[0]) && preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0], $matches)) {
            $httpCode = (int)$matches[1];
        }
    }

    if ($httpCode >= 400 || $response === null) {
        throw new RuntimeException('Serwer KAS zwrócił błąd HTTP ' . $httpCode . '.');
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('API KAS zwróciło niepoprawny format danych.');
    }

    return $decoded;
}

function parseVatAddress(string $address): array
{
    $street = trim($address);
    $city = '';
    $postal = '';

    if ($address === '') {
        return ['street' => '', 'city' => '', 'postal' => ''];
    }

    $parts = array_map('trim', explode(',', $address));
    if (count($parts) > 1) {
        $street = array_shift($parts);
        $cityPart = implode(', ', $parts);
    } else {
        $cityPart = $street;
    }

    if (preg_match('/(\d{2}-\d{3})\s*(.+)/u', $cityPart, $matches)) {
        $postal = $matches[1];
        $city = trim($matches[2]);
    } else {
        $city = trim($cityPart);
    }

    return [
        'street' => $street,
        'city'   => $city,
        'postal' => $postal,
    ];
}

function userDisplayName(array $user): string
{
    $full = trim(($user['imie'] ?? '') . ' ' . ($user['nazwisko'] ?? ''));
    if ($full !== '') {
        return $full;
    }
    return $user['login'] ?? ('User #' . ($user['id'] ?? ''));
}

$pageTitle = "Dodaj klienta";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$statusOptions = [
    'Nowy',
    'W trakcie rozmów',
    'Aktywny',
    'Zakończony',
];

$messages = [
    'client_added'   => ['type' => 'success', 'text' => 'Klient został dodany.'],
    'error_missing'  => ['type' => 'danger', 'text' => 'Uzupełnij wymagane pola.'],
    'error_nip'      => ['type' => 'warning', 'text' => 'Podaj poprawny numer NIP (10 cyfr).'],
    'error_exists'   => ['type' => 'warning', 'text' => 'Klient z tym numerem NIP już istnieje.'],
];

$toast = null;
if (isset($_GET['msg'], $messages[$_GET['msg']])) {
    $toast = $messages[$_GET['msg']];
}
if ($toast && !empty($_SESSION['client_save_errors']) && is_array($_SESSION['client_save_errors'])) {
    $extra = implode(', ', array_map('strval', $_SESSION['client_save_errors']));
    if ($extra !== '') {
        $toast['text'] .= ' (' . $extra . ')';
    }
    unset($_SESSION['client_save_errors']);
}

include __DIR__ . '/includes/header.php';
?>

<main class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h4 mb-1">Dodaj nowego klienta</h1>
            <p class="text-muted mb-0">Uzupełnij dane ręcznie lub pobierz je na podstawie numeru NIP.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="klienci.php" class="btn btn-outline-secondary btn-sm">Przegląd</a>
            <a href="lista_klientow.php" class="btn btn-outline-primary btn-sm">Lista klientów</a>
        </div>
    </div>

<?php if ($toast): ?>
    <div class="alert alert-<?= htmlspecialchars($toast['type']) ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($toast['text']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
    </div>
<?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h5 class="card-title mb-3">Wyszukaj po NIP (Biała lista VAT / KAS)</h5>
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label" for="nip_lookup">Numer NIP</label>
                    <input type="text" id="nip_lookup" class="form-control" placeholder="Wpisz NIP (10 cyfr)">
                </div>
                <div class="col-md-3">
                    <button type="button" id="nipLookupTrigger" class="btn btn-outline-primary w-100">
                        Pobierz dane z NIP
                    </button>
                </div>
            </div>
            <div id="nipLookupAlert" class="alert mt-3 d-none" role="alert"></div>
            <textarea id="nipAccountsInfo" class="form-control form-control-sm mt-2 d-none" rows="2" readonly></textarea>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Dane firmy</span>
                    <small class="text-muted">Pola oznaczone * są wymagane</small>
                </div>
                <div class="card-body">
                    <form action="<?= BASE_URL ?>/add_client.php" method="post" id="clientForm" autocomplete="off">
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">NIP *</label>
                                <input type="text" name="nip" id="nip" class="form-control" placeholder="np. 1234567890" minlength="10" maxlength="10" required>
                                <div class="form-text">Uzupełnij ręcznie lub skorzystaj z wyszukiwarki powyżej.</div>
                                <button type="button" id="gusLookupTrigger" class="btn btn-outline-secondary btn-sm w-100 mt-2">
                                    Pobierz z GUS
                                </button>
                                <small id="gusStatus" class="form-text text-muted d-block mt-2"></small>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Nazwa firmy *</label>
                                <input type="text" name="nazwa_firmy" id="nazwa_firmy" class="form-control" required>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">REGON</label>
                                <input type="text" name="regon" id="regon" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Telefon</label>
                                <input type="text" name="telefon" id="telefon" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" id="email" class="form-control">
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Adres (ulica, nr)</label>
                                <input type="text" name="adres" id="adres" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Miasto</label>
                                <input type="text" name="miasto" id="miasto" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Województwo</label>
                                <input type="text" name="wojewodztwo" id="wojewodztwo" class="form-control">
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Branża</label>
                                <input type="text" name="branza" id="branza" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select name="status" id="status" class="form-select">
                                    <?php foreach ($statusOptions as $status): ?>
                                        <option value="<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Potencjał (zł)</label>
                                <input type="number" step="0.01" name="potencjal" id="potencjal" class="form-control">
                            </div>
                        </div>
                        <?php if (hasColumn($clientColumns, 'assigned_user_id')): ?>
                            <?php if ($canAssignOwner): ?>
                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Opiekun</label>
                                        <select name="assigned_user_id" class="form-select">
                                            <?php foreach ($assignableUsers as $userId => $user): ?>
                                                <option value="<?= (int)$userId ?>" <?= (int)$userId === (int)($currentUser['id'] ?? 0) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars(userDisplayName($user)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            <?php else: ?>
                                <input type="hidden" name="assigned_user_id" value="<?= (int)($currentUser['id'] ?? 0) ?>">
                            <?php endif; ?>
                        <?php endif; ?>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Ostatni kontakt</label>
                                <input type="date" name="ostatni_kontakt" id="ostatni_kontakt" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Przypomnienie</label>
                                <input type="date" name="przypomnienie" id="przypomnienie" class="form-control">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Notatka</label>
                            <textarea name="notatka" id="notatka" class="form-control" rows="3" placeholder="Wprowadź dodatkowe informacje..."></textarea>
                        </div>

                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="d-flex justify-content-end gap-2">
                            <a href="klienci.php" class="btn btn-outline-secondary">Anuluj</a>
                            <button type="submit" class="btn btn-success">Zapisz klienta</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header">Wskazówki</div>
                <div class="card-body small text-muted">
                    <ul class="mb-0 ps-3">
                        <li>Pobierz dane firmowe z publicznej bazy po numerze NIP.</li>
                        <li>Potencjał wykorzystaj do prognozowania przychodów.</li>
                        <li>Przypomnienie pomoże zaplanować kolejny kontakt.</li>
                        <li>Statusy służą do prostego raportowania postępu.</li>
                    </ul>
                </div>
            </div>
            <div class="card shadow-sm">
                <div class="card-header">Ostatnio dodani</div>
                <ul class="list-group list-group-flush small">
                    <?php
                    $recent = $pdo->query("SELECT id, nazwa_firmy, nip, data_dodania FROM klienci ORDER BY data_dodania DESC LIMIT 5")->fetchAll();
                    if (empty($recent)): ?>
                        <li class="list-group-item">Brak klientów do wyświetlenia.</li>
                    <?php else: ?>
                        <?php foreach ($recent as $client): ?>
                            <li class="list-group-item">
                                <div class="fw-semibold">
                                    <a href="klient_szczegoly.php?id=<?= (int)$client['id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($client['nazwa_firmy']) ?>
                                    </a>
                                </div>
                                <div class="text-muted">NIP: <?= htmlspecialchars($client['nip']) ?></div>
                                <small><?= htmlspecialchars($client['data_dodania']) ?></small>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</main>

<script>
(function() {
    const lookupInput = document.getElementById('nip_lookup');
    const lookupBtn = document.getElementById('nipLookupTrigger');
    const alertBox = document.getElementById('nipLookupAlert');
    const accountsField = document.getElementById('nipAccountsInfo');
    const formFields = {
        nip: document.getElementById('nip'),
        nazwa: document.getElementById('nazwa_firmy'),
        regon: document.getElementById('regon'),
        adres: document.getElementById('adres'),
        miasto: document.getElementById('miasto'),
        wojewodztwo: document.getElementById('wojewodztwo')
    };

    function showAlert(message, type) {
        alertBox.className = 'alert alert-' + type + ' mt-3';
        alertBox.textContent = message;
        alertBox.classList.remove('d-none');
    }

    function hideAccounts() {
        accountsField.classList.add('d-none');
        accountsField.value = '';
    }

    function fillField(input, value) {
        if (input && value !== undefined && value !== null) {
            input.value = value;
        }
    }

    lookupBtn.addEventListener('click', function() {
        const nip = lookupInput.value.replace(/\D/g, '');
        if (nip.length !== 10) {
            showAlert('Podaj prawidłowy numer NIP (10 cyfr).', 'danger');
            hideAccounts();
            return;
        }

        lookupBtn.disabled = true;
        showAlert('Pobieranie danych z wykazu VAT...', 'info');
        hideAccounts();

        fetch('dodaj_klienta.php?action=lookup_nip&nip=' + nip, {
            headers: { 'Accept': 'application/json' }
        })
            .then(response => response.json())
            .then(payload => {
                if (!payload.ok) {
                    throw new Error(payload.error || 'Nie udało się pobrać danych.');
                }
                const data = payload.data || {};
                fillField(formFields.nip, data.nip || nip);
                fillField(formFields.nazwa, data.name || '');
                fillField(formFields.regon, data.regon || '');
                fillField(formFields.adres, data.address || '');
                const cityWithPostal = ((data.postal ? data.postal + ' ' : '') + (data.city || '')).trim();
                fillField(formFields.miasto, cityWithPostal);
                fillField(formFields.wojewodztwo, data.voivodeship || '');

                let message = 'Dane pobrane z wykazu VAT.';
                if (data.statusVat) {
                    message += ' Status VAT: ' + data.statusVat + '.';
                }
                if (data.krs) {
                    message += ' KRS: ' + data.krs + '.';
                }
                showAlert(message, 'success');

                if (Array.isArray(data.accounts) && data.accounts.length > 0) {
                    accountsField.value = 'Zidentyfikowane rachunki bankowe:\n' + data.accounts.join('\n');
                    accountsField.classList.remove('d-none');
                }
            })
            .catch(err => {
                console.error(err);
                showAlert(err.message || 'Nie udało się pobrać danych.', 'warning');
            })
            .finally(() => {
                lookupBtn.disabled = false;
            });
    });

})();
</script>
<script src="assets/js/gus.js"></script>
<script>
initGusButton({
    buttonId: 'gusLookupTrigger',
    nipInputId: 'nip',
    statusId: 'gusStatus',
    endpoint: 'gus_lookup.php',
    fieldMap: {
        nip: 'nip',
        nazwa_firmy: 'nazwa',
        regon: 'regon',
        adres: 'ulica',
        miasto: 'miejscowosc',
        wojewodztwo: 'wojewodztwo'
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
