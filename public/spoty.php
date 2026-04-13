<?php
require_once __DIR__ . '/includes/config.php';
require_once '../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/emisje_helpers.php';
require_once __DIR__ . '/../services/SpotStatusService.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo) ?? [];
$csrfToken = getCsrfToken();

try {
    SpotStatusService::autoDeactivateExpired($pdo);
} catch (Throwable $e) {
    error_log('spoty.php: auto-deactivate failed: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['spot_toggle'])) {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        header('Location: ' . BASE_URL . '/spoty.php?msg=error_csrf');
        exit;
    }

    $spotId = (int)($_POST['spot_id'] ?? 0);
    $setActive = (int)($_POST['set_active'] ?? 0) === 1;

    if ($spotId > 0) {
        try {
            if (!canAccessSpot($pdo, $spotId, $currentUser)) {
                header('Location: ' . BASE_URL . '/spoty.php?msg=error_permission');
                exit;
            }
            SpotStatusService::setSpotActive($pdo, $spotId, $setActive);
            header('Location: ' . BASE_URL . '/spoty.php?msg=spot_toggled');
            exit;
        } catch (Throwable $e) {
            error_log('spoty.php: toggle status failed: ' . $e->getMessage());
            header('Location: ' . BASE_URL . '/spoty.php?msg=error_db');
            exit;
        }
    }
}

$pageTitle = "Spoty reklamowe";
$pageStyles = ['spoty'];
include 'includes/header.php';

function occupancyLimitSecondsPerHourFromLegacy(string $progProcentowy): int {
    $map = [
        2 => 90,
        7 => 252,
        12 => 432,
        20 => 720,
    ];
    $percent = (int)trim($progProcentowy);
    if ($percent <= 0) {
        $percent = 2;
    }
    if (isset($map[$percent])) {
        return $map[$percent];
    }
    return (int)round(($percent / 100) * 3600);
}

function resolveBlockDurationSeconds(array $settings): int {
    $configured = (int)($settings['block_duration_seconds'] ?? 0);
    if ($configured > 0) {
        return $configured;
    }
    $blocks = max(1, (int)($settings['liczba_blokow'] ?? 2));
    $legacyPerBlock = (int)floor(
        occupancyLimitSecondsPerHourFromLegacy((string)($settings['prog_procentowy'] ?? '0')) / $blocks
    );
    return $legacyPerBlock > 0 ? $legacyPerBlock : 45;
}

function spotyRenderPeriodLabel(array $row, string $mode = 'range'): string
{
    $start = trim((string)($row['data_start'] ?? ''));
    $end = trim((string)($row['data_koniec'] ?? ''));

    if ($mode === 'start_only') {
        return $start !== '' ? ('Start: ' . $start) : 'Start: -';
    }
    if ($start !== '' && $end !== '') {
        return $start . ' - ' . $end;
    }
    if ($start !== '') {
        return 'Start: ' . $start;
    }
    if ($end !== '') {
        return 'Koniec: ' . $end;
    }
    return '-';
}

function renderSpotyWorkItem(array $row, string $csrfToken, bool $hasRotationGroup, array $options = []): string
{
    $spotId = (int)($row['id'] ?? 0);
    $toggleSetActive = array_key_exists('toggle_set_active', $options) ? (int)$options['toggle_set_active'] : null;
    $toggleLabel = trim((string)($options['toggle_label'] ?? ''));
    $toggleClass = trim((string)($options['toggle_class'] ?? 'btn-outline-secondary'));
    $toggleIcon = trim((string)($options['toggle_icon'] ?? ''));
    $periodMode = trim((string)($options['period_mode'] ?? 'range'));

    ob_start();
    ?>
    <div class="list-group-item work-item">
        <div class="work-item-main">
            <div class="work-item-title"><?= htmlspecialchars((string)($row['nazwa_spotu'] ?? '')) ?></div>
            <div class="work-item-badges">
                <?php if ($hasRotationGroup && !empty($row['rotation_group'])): ?>
                    <span class="badge badge-neutral spoty-rotation-badge"><?= htmlspecialchars((string)$row['rotation_group']) ?></span>
                <?php endif; ?>
            </div>
            <div class="work-item-meta">
                <div class="spoty-item-client"><?= htmlspecialchars((string)($row['nazwa_firmy'] ?? '')) ?></div>
                <div class="spoty-item-period"><?= htmlspecialchars(spotyRenderPeriodLabel($row, $periodMode)) ?></div>
            </div>
        </div>

        <div class="work-item-actions spoty-item-actions-compact">
            <div class="action-stack">
                <div class="action-stack-primary">
                    <a href="edytuj_spot.php?id=<?= $spotId ?>" class="btn btn-sm btn-outline-primary">✏️ Edytuj</a>
                </div>
                <div class="action-stack-secondary">
                    <?php if ($toggleSetActive !== null): ?>
                        <form method="post" action="<?= BASE_URL ?>/spoty.php">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="spot_toggle" value="1">
                            <input type="hidden" name="spot_id" value="<?= $spotId ?>">
                            <input type="hidden" name="set_active" value="<?= $toggleSetActive ?>">
                            <button type="submit" class="btn btn-sm <?= htmlspecialchars($toggleClass) ?>">
                                <?= htmlspecialchars(trim(($toggleIcon !== '' ? ($toggleIcon . ' ') : '') . $toggleLabel)) ?>
                            </button>
                        </form>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-outline-danger btn-delete" data-id="<?= $spotId ?>">🗑️ Usuń</button>
                </div>
            </div>
        </div>
    </div>
    <?php
    return (string)ob_get_clean();
}

// Ustawienia systemowe
ensureSystemConfigColumns($pdo);
$stmt = $pdo->query("SELECT * FROM konfiguracja_systemu WHERE id = 1");
$ust = $stmt->fetch() ?: [];
$liczba_blokow = max(1, (int)($ust['liczba_blokow'] ?? 2));
$godzina_start = new DateTime((string)($ust['godzina_start'] ?? '07:00:00'));
$godzina_koniec = new DateTime((string)($ust['godzina_koniec'] ?? '21:00:00'));
$dni_tygodnia = ['mon' => 'Poniedziałek', 'tue' => 'Wtorek', 'wed' => 'Środa', 'thu' => 'Czwartek', 'fri' => 'Piątek', 'sat' => 'Sobota', 'sun' => 'Niedziela'];
$blockLimitSeconds = resolveBlockDurationSeconds($ust);
$hourlyAdLimitSeconds = $blockLimitSeconds * $liczba_blokow;
$blockLimitLabel = sprintf('%d:%02d', (int)floor($blockLimitSeconds / 60), $blockLimitSeconds % 60);

// Dostępne kampanie do podpięcia spotu
$kampanie = [];
$kampaniaCols = getTableColumns($pdo, 'kampanie');
[$kampaniaOwnerWhere, $kampaniaOwnerParams] = ownerConstraint($kampaniaCols, $currentUser, 'k');
$kampaniaSql = 'SELECT k.id, k.klient_id, k.klient_nazwa, k.dlugosc_spotu, k.data_start, k.data_koniec FROM kampanie k';
$kampaniaParams = [];
$whereParts = [];
if ($kampaniaOwnerWhere) {
    $whereParts[] = $kampaniaOwnerWhere;
    $kampaniaParams = array_merge($kampaniaParams, $kampaniaOwnerParams);
}
if (hasColumn($kampaniaCols, 'status')) {
    $whereParts[] = "COALESCE(k.status, 'W realizacji') <> 'Zakończona'";
}
if ($whereParts) {
    $kampaniaSql .= ' WHERE ' . implode(' AND ', $whereParts);
}
$kampaniaSql .= ' ORDER BY k.data_koniec DESC';
$stmtK = $pdo->prepare($kampaniaSql);
$stmtK->execute($kampaniaParams);
$kampanie = $stmtK->fetchAll(PDO::FETCH_ASSOC);

// Sprawdź, czy tabela `spoty` ma kolumnę `rezerwacja`
$hasRezerwacja = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM spoty LIKE 'rezerwacja'")->fetch();
    if ($col) $hasRezerwacja = true;
} catch (Exception $e) {
    $hasRezerwacja = false;
}
$spotCols = getTableColumns($pdo, 'spoty');
$hasRotationGroup = hasColumn($spotCols, 'rotation_group');
$hasRotationMode = hasColumn($spotCols, 'rotation_mode');
$rotationSelect = $hasRotationGroup ? ", s.rotation_group" : "";
?>

<?php
// Wyświetlanie komunikatów z parametrów GET (flash messages)
$alert = null;
$limitErrors = $_SESSION['flash_limit_errors'] ?? [];
unset($_SESSION['flash_limit_errors']);
if (!empty($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'spot_added':
            $alert = ['type' => 'success', 'text' => 'Spot został dodany pomyślnie.'];
            break;
        case 'spot_updated':
            $alert = ['type' => 'success', 'text' => 'Spot został zaktualizowany.'];
            break;
        case 'spot_toggled':
            $alert = ['type' => 'success', 'text' => 'Status spotu został zmieniony.'];
            break;
        case 'error_missing':
            $alert = ['type' => 'danger', 'text' => 'Brak wymaganych danych. Upewnij się, że wszystkie pola formularza są wypełnione.'];
            break;
        case 'error_no_client':
            $alert = ['type' => 'warning', 'text' => 'Nie znaleziono klienta o podanej nazwie/NIP. Dodaj klienta najpierw lub wprowadź poprawny NIP/nazwę.'];
            break;
        case 'error_db':
            $alert = ['type' => 'danger', 'text' => 'Błąd bazy danych podczas zapisu. Sprawdź logi serwera lub skontaktuj się z administratorem.'];
            break;
        case 'error_permission':
            $alert = ['type' => 'danger', 'text' => 'Brak uprawnień do zapisu emisji dla tej kampanii lub spotu.'];
            break;
        case 'error_csrf':
            $alert = ['type' => 'danger', 'text' => 'Niepoprawny token formularza.'];
            break;
        case 'audio_required':
            $alert = ['type' => 'warning', 'text' => 'Spot zapisany bez emisji. Wejdź w edycję spotu, wgraj i zaakceptuj audio, a następnie zapisz emisję ponownie.'];
            break;
        case 'error':
        default:
            $alert = ['type' => 'danger', 'text' => 'Wystąpił błąd podczas zapisu. Sprawdź pola i spróbuj ponownie.'];
            break;
    }
}
?>

<div class="container-fluid">
    <div class="spoty-page-grid mt-3">
        <section class="spoty-header">
            <div class="app-header">
                <div>
                    <h1 class="app-title">Spoty reklamowe</h1>
                    <div class="app-subtitle">Planowanie i zarządzanie emisjami spotów.</div>
                </div>
            </div>
        </section>

        <section class="spoty-form">
            <?php if (!empty($limitErrors)) : ?>
                <div class="alert alert-danger" role="alert">
                    <strong>Nie można zapisać:</strong>
                    <ul class="mb-0">
                        <?php foreach ($limitErrors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php if ($alert) : ?>
                <div class="alert alert-<?= htmlspecialchars($alert['type']) ?>" role="alert">
                    <?= htmlspecialchars($alert['text']) ?>
                </div>
            <?php endif; ?>
            <div class="spoty-form-header">
                <h4>➕ Dodaj nowy spot</h4>
            </div>
            <form method="post" action="<?= BASE_URL ?>/add_spot.php" id="spoty-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="klient_nazwa" class="form-label">Klient (nazwa/NIP):</label>
                        <input type="text" name="klient_nazwa" id="klient_nazwa" class="form-control" autocomplete="off" required>
                        <input type="hidden" name="klient_id" id="klient_id">
                    </div>
                    <div class="col-md-6">
                        <label for="nazwa_spotu" class="form-label">Nazwa spotu:</label>
                        <input type="text" name="nazwa_spotu" id="nazwa_spotu" class="form-control" required>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="kampania_id" class="form-label">Kampania (opcjonalnie):</label>
                        <div class="input-group mb-2">
                            <input type="number" min="1" id="kampania_search_id" class="form-control" placeholder="Wyszukaj po ID kampanii">
                            <button type="button" id="kampania_load_btn" class="btn btn-outline-secondary">Pobierz</button>
                        </div>
                        <select name="kampania_id" id="kampania_id" class="form-select">
                            <option value="">— bez kampanii —</option>
                            <?php foreach ($kampanie as $k): ?>
                                <option value="<?= (int)$k['id'] ?>">
                                    #<?= (int)$k['id'] ?> | <?= htmlspecialchars($k['klient_nazwa'] ?? '') ?> (<?= htmlspecialchars($k['data_start'] ?? '') ?> – <?= htmlspecialchars($k['data_koniec'] ?? '') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Wybierz lub wyszukaj ID, aby uzupelnic dane kampanii; emisja jest przypisywana osobnym mechanizmem wg dnia/godziny i preferencji bloku A/B.</small>
                        <div id="kampania_autofill_info" class="small mt-1 text-muted" aria-live="polite"></div>
                    </div>
                    <div class="col-md-3">
                        <label for="dlugosc_s" class="form-label">Długość spotu (s):</label>
                        <select name="dlugosc_s" id="dlugosc_s" class="form-select" required>
                            <option value="15">15 sek</option>
                            <option value="20">20 sek</option>
                            <option value="30" selected>30 sek</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-center">
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="rezerwacja" id="rezerwacja" value="1">
                            <label class="form-check-label" for="rezerwacja">Rezerwacja pasma</label>
                        </div>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-3">
                        <label for="data_start" class="form-label">Start emisji:</label>
                        <input type="date" name="data_start" id="data_start" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label for="data_koniec" class="form-label">Koniec emisji:</label>
                        <input type="date" name="data_koniec" id="data_koniec" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label for="rotation_group" class="form-label">Grupa rotacji (opcjonalnie):</label>
                        <select name="rotation_group" id="rotation_group" class="form-select">
                            <option value="">Brak</option>
                            <option value="A">A</option>
                            <option value="B">B</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="rotation_mode" class="form-label">Rotacja (opcjonalnie):</label>
                        <select name="rotation_mode" id="rotation_mode" class="form-select">
                            <option value="">Brak</option>
                            <option value="ALT_1_1">Naprzemiennie A/B (1:1)</option>
                            <option value="PREFER_A">Preferuj A</option>
                            <option value="PREFER_B">Preferuj B</option>
                        </select>
                    </div>
                </div>
            </form>
        </section>

        <aside class="spoty-left">
            <section class="spoty-list-section">
            <h5>🎯 Spoty aktywne</h5>
            <div class="list-group spoty-list mb-3" id="lista-aktywnych">
                <?php
                $qryActive = "SELECT s.id, s.nazwa_spotu, COALESCE(c.name_full, c.name_short, k.nazwa_firmy) AS nazwa_firmy, s.data_start, s.data_koniec{$rotationSelect}" .
                             ($hasRezerwacja ? ", s.rezerwacja" : "") .
                             " FROM spoty s LEFT JOIN klienci k ON k.id = s.klient_id LEFT JOIN companies c ON c.id = k.company_id" .
                             " WHERE COALESCE(s.rezerwacja,0)=0 AND COALESCE(s.aktywny,1) = 1 AND COALESCE(s.status,'Aktywny') = 'Aktywny'" .
                             " AND (s.data_start IS NULL OR s.data_start <= CURDATE())" .
                             " AND (s.data_koniec IS NULL OR s.data_koniec >= CURDATE())" .
                             " ORDER BY s.nazwa_spotu";
                $stmt = $pdo->query($qryActive);
                foreach ($stmt as $row):
                    echo renderSpotyWorkItem($row, $csrfToken, $hasRotationGroup, [
                        'toggle_set_active' => 0,
                        'toggle_label' => 'Wstrzymaj',
                        'toggle_class' => 'btn-outline-warning',
                        'toggle_icon' => '⏸️',
                        'period_mode' => 'range',
                    ]);
                endforeach; ?>
            </div>
            </section>

            <section class="spoty-list-section">
            <h5>📅 Zaplanowane (przyszłe)</h5>
            <div class="list-group spoty-list mb-3" id="lista-zaplanowanych">
                <?php
                $qryPlanned = "SELECT s.id, s.nazwa_spotu, COALESCE(c.name_full, c.name_short, k.nazwa_firmy) AS nazwa_firmy, s.data_start, s.data_koniec{$rotationSelect}" .
                              " FROM spoty s LEFT JOIN klienci k ON k.id = s.klient_id LEFT JOIN companies c ON c.id = k.company_id" .
                              " WHERE COALESCE(s.aktywny,1) = 1 AND COALESCE(s.status,'Aktywny') = 'Aktywny' AND s.data_start IS NOT NULL AND s.data_start > CURDATE() AND COALESCE(s.rezerwacja,0)=0" .
                              " ORDER BY s.data_start ASC";
                $stmt = $pdo->query($qryPlanned);
                foreach ($stmt as $row):
                    echo renderSpotyWorkItem($row, $csrfToken, $hasRotationGroup, [
                        'toggle_set_active' => 0,
                        'toggle_label' => 'Wstrzymaj',
                        'toggle_class' => 'btn-outline-warning',
                        'toggle_icon' => '⏸️',
                        'period_mode' => 'start_only',
                    ]);
                endforeach; ?>
            </div>
            </section>

            <section class="spoty-list-section">
            <h5>🔖 Rezerwacje pasma</h5>
            <div class="list-group spoty-list mb-3" id="lista-rezerwacji">
                <?php
                if ($hasRezerwacja) {
                    $qryRes = "SELECT s.id, s.nazwa_spotu, COALESCE(c.name_full, c.name_short, k.nazwa_firmy) AS nazwa_firmy, s.data_start, s.data_koniec{$rotationSelect} FROM spoty s LEFT JOIN klienci k ON k.id = s.klient_id LEFT JOIN companies c ON c.id = k.company_id WHERE COALESCE(s.rezerwacja,0)=1 ORDER BY s.data_start DESC";
                    $stmt = $pdo->query($qryRes);
                    foreach ($stmt as $row):
                        echo renderSpotyWorkItem($row, $csrfToken, $hasRotationGroup, [
                            'period_mode' => 'range',
                        ]);
                    endforeach;
                } else {
                    echo '<div class="list-group-item text-muted">Kolumna rezerwacja nie istnieje w DB. Możesz ją dodać (rezerwacja = 1).</div>';
                }
                ?>
            </div>
            </section>

            <section class="spoty-list-section">
            <h5>📁 Wygasłe / nieaktywne</h5>
            <div class="list-group spoty-list" id="lista-nieaktywnych">
                <?php
                $qryExpired = "SELECT s.id, s.nazwa_spotu, COALESCE(c.name_full, c.name_short, k.nazwa_firmy) AS nazwa_firmy, s.data_start, s.data_koniec{$rotationSelect}" .
                              " FROM spoty s LEFT JOIN klienci k ON k.id = s.klient_id LEFT JOIN companies c ON c.id = k.company_id" .
                              " WHERE (COALESCE(s.aktywny,0) = 0 OR COALESCE(s.status,'Aktywny') <> 'Aktywny' OR (s.data_koniec IS NOT NULL AND s.data_koniec < CURDATE()))" .
                              " AND COALESCE(s.rezerwacja,0)=0" .
                              " ORDER BY s.nazwa_spotu";
                $stmt = $pdo->query($qryExpired);
                foreach ($stmt as $row):
                    echo renderSpotyWorkItem($row, $csrfToken, $hasRotationGroup, [
                        'toggle_set_active' => 1,
                        'toggle_label' => 'Aktywuj',
                        'toggle_class' => 'btn-outline-success',
                        'toggle_icon' => '▶️',
                        'period_mode' => 'range',
                    ]);
                endforeach; ?>
            </div>
            </section>
        </aside>

        <main class="spoty-right">
            <h5>Siatka tygodniowa emisji</h5>
            <div class="alert alert-info py-2 small">
                Długość jednego bloku reklamowego: <strong><?= htmlspecialchars($blockLimitLabel) ?> min</strong>
                (liczba bloków/h: <?= (int)$liczba_blokow ?>, łączny limit/h: <?= (int)$hourlyAdLimitSeconds ?> s).
            </div>
            <div class="table-responsive">
                <table class="table table-zebra text-center align-middle">
                    <thead>
                        <tr>
                            <th>Godzina / Blok</th>
                            <?php foreach ($dni_tygodnia as $kod => $dzien): ?>
                                <th><?php echo $dzien; ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        for ($czas = clone $godzina_start; $czas <= $godzina_koniec; $czas->modify('+1 hour')) {
                            for ($b = 0; $b < $liczba_blokow; $b++) {
                                $litera = chr(65 + $b);
                                echo '<tr>';
                                echo '<th>' . $czas->format('H:i') . ' – Blok ' . $litera . '</th>';
                                foreach (array_keys($dni_tygodnia) as $dzienKod) {
                                    $nazwa = "emisja[$dzienKod][" . $czas->format('H:i') . "][blok$litera]";
                                    echo '<td><input type="checkbox" class="form-check-input" style="transform: scale(1.3);"'
                                        . ' data-day="' . htmlspecialchars($dzienKod) . '"'
                                        . ' data-hour="' . htmlspecialchars($czas->format('H:i')) . '"'
                                        . ' data-block="' . htmlspecialchars($litera) . '"'
                                        . ' name="' . $nazwa . '" value="1" form="spoty-form"></td>';
                                }
                                echo '</tr>';
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <div class="text-end mt-3">
                <button type="submit" class="btn btn-success" form="spoty-form">💾 Dodaj spot</button>
            </div>
        </main>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const rotationGroup = document.getElementById('rotation_group');
    const rotationMode = document.getElementById('rotation_mode');
    const kampaniaSelect = document.getElementById('kampania_id');
    const kampaniaSearchId = document.getElementById('kampania_search_id');
    const kampaniaLoadBtn = document.getElementById('kampania_load_btn');
    const autofillInfo = document.getElementById('kampania_autofill_info');
    const klientNazwaInput = document.getElementById('klient_nazwa');
    const klientIdInput = document.getElementById('klient_id');
    const dataStartInput = document.getElementById('data_start');
    const dataKoniecInput = document.getElementById('data_koniec');
    const dlugoscSelect = document.getElementById('dlugosc_s');
    const gridCheckboxes = Array.from(document.querySelectorAll(
        '#spoty-form input[type="checkbox"][data-day][data-hour][data-block], input[type="checkbox"][data-day][data-hour][data-block][form="spoty-form"]'
    ));

    let loadedCampaignSchedule = null;
    let loadedCampaignId = null;

    const gridIndex = {};
    gridCheckboxes.forEach(function (checkbox) {
        const day = String(checkbox.dataset.day || '').toLowerCase();
        const hour = String(checkbox.dataset.hour || '').slice(0, 5);
        const block = String(checkbox.dataset.block || '').toUpperCase();
        if (!day || !hour || !block) {
            return;
        }
        if (!gridIndex[day]) {
            gridIndex[day] = {};
        }
        if (!gridIndex[day][hour]) {
            gridIndex[day][hour] = {};
        }
        gridIndex[day][hour][block] = checkbox;
    });

    function setAutofillMessage(text, level) {
        if (!autofillInfo) {
            return;
        }
        autofillInfo.classList.remove('text-muted', 'text-success', 'text-danger', 'text-warning');
        if (level === 'success') {
            autofillInfo.classList.add('text-success');
        } else if (level === 'error') {
            autofillInfo.classList.add('text-danger');
        } else if (level === 'warning') {
            autofillInfo.classList.add('text-warning');
        } else {
            autofillInfo.classList.add('text-muted');
        }
        autofillInfo.textContent = text || '';
    }

    function clearScheduleGrid() {
        gridCheckboxes.forEach(function (checkbox) {
            checkbox.checked = false;
        });
    }

    function normalizeHourLabel(hourLabel) {
        return String(hourLabel || '').slice(0, 5);
    }

    function uniqueLetters(letters) {
        const seen = new Set();
        const out = [];
        letters.forEach(function (letter) {
            const normalized = String(letter || '').toUpperCase();
            if (!normalized || seen.has(normalized)) {
                return;
            }
            seen.add(normalized);
            out.push(normalized);
        });
        return out;
    }

    function prioritizeBlocks(blockLetters, primary, secondary) {
        const letters = uniqueLetters(blockLetters).sort();
        const ordered = [];
        const addIfExists = function (candidate) {
            const block = String(candidate || '').toUpperCase();
            if (!block || !letters.includes(block) || ordered.includes(block)) {
                return;
            }
            ordered.push(block);
        };
        addIfExists(primary);
        addIfExists(secondary);
        letters.forEach(addIfExists);
        return ordered;
    }

    function getBlockOrderForEmission(blockLetters, emissionIndex) {
        const letters = uniqueLetters(blockLetters).sort();
        if (letters.length === 0) {
            return [];
        }

        const mode = rotationMode ? String(rotationMode.value || '') : '';
        const selectedGroup = rotationGroup ? String(rotationGroup.value || '').toUpperCase() : '';

        if (mode === 'PREFER_A') {
            return prioritizeBlocks(letters, 'A', selectedGroup);
        }
        if (mode === 'PREFER_B') {
            return prioritizeBlocks(letters, 'B', selectedGroup);
        }
        if (mode === 'ALT_1_1') {
            const start = (selectedGroup === 'A' || selectedGroup === 'B')
                ? selectedGroup
                : (letters.includes('A') ? 'A' : letters[0]);
            let alternate = start === 'A' ? 'B' : 'A';
            if (!letters.includes(alternate)) {
                alternate = '';
            }
            const primary = (emissionIndex % 2 === 0) ? start : (alternate || start);
            const secondary = (emissionIndex % 2 === 0) ? alternate : start;
            return prioritizeBlocks(letters, primary, secondary);
        }
        if (selectedGroup) {
            return prioritizeBlocks(letters, selectedGroup);
        }
        if (letters.includes('A')) {
            return prioritizeBlocks(letters, 'A');
        }
        return prioritizeBlocks(letters);
    }

    // Mechanizm emisji: osobno przypisuje dni/godziny kampanii do komorek tabeli.
    function applyCampaignScheduleToGrid(schedule) {
        clearScheduleGrid();
        const warnings = [];
        if (!schedule || typeof schedule !== 'object') {
            return warnings;
        }

        Object.entries(schedule).forEach(function (dayEntry) {
            const day = String(dayEntry[0] || '').toLowerCase();
            const hours = dayEntry[1];
            if (!hours || typeof hours !== 'object') {
                return;
            }
            Object.entries(hours).forEach(function (hourEntry) {
                const hour = normalizeHourLabel(hourEntry[0]);
                const qty = parseInt(hourEntry[1], 10) || 0;
                if (qty <= 0) {
                    return;
                }

                const dayGrid = gridIndex[day] || {};
                const slotsByBlock = dayGrid[hour] || {};
                const blockLetters = Object.keys(slotsByBlock).sort();
                if (blockLetters.length === 0) {
                    warnings.push(day + ' ' + hour + ': brak komorek w siatce dla tej godziny.');
                    return;
                }

                let assigned = 0;
                for (let i = 0; i < qty; i++) {
                    const preferredOrder = getBlockOrderForEmission(blockLetters, i);
                    const targetBlock = preferredOrder.find(function (block) {
                        return slotsByBlock[block] && !slotsByBlock[block].checked;
                    });
                    if (!targetBlock) {
                        break;
                    }
                    slotsByBlock[targetBlock].checked = true;
                    assigned++;
                }

                if (assigned < qty) {
                    warnings.push(day + ' ' + hour + ': kampania ma ' + qty + ' emisji, przypisano ' + assigned + '.');
                }
            });
        });

        return warnings;
    }

    function ensureCampaignOption(data) {
        if (!kampaniaSelect || !data || !data.id) {
            return;
        }
        const targetValue = String(data.id);
        const existing = Array.from(kampaniaSelect.options).find(function (option) {
            return option.value === targetValue;
        });
        if (existing) {
            kampaniaSelect.value = targetValue;
            return;
        }
        const option = document.createElement('option');
        option.value = targetValue;
        option.textContent = '#' + targetValue + ' | ' + (data.klient_nazwa || '') + ' (' + (data.data_start || '') + ' – ' + (data.data_koniec || '') + ')';
        kampaniaSelect.appendChild(option);
        kampaniaSelect.value = targetValue;
    }

    // Mechanizm danych kampanii: osobno uzupelnia tylko pola formularza.
    function applyCampaignInfoToForm(data) {
        ensureCampaignOption(data);

        if (klientNazwaInput) {
            klientNazwaInput.value = data.klient_label || data.klient_nazwa || '';
        }
        if (klientIdInput) {
            klientIdInput.value = data.klient_id ? String(data.klient_id) : '';
        }
        if (dataStartInput && data.data_start) {
            dataStartInput.value = data.data_start;
        }
        if (dataKoniecInput && data.data_koniec) {
            dataKoniecInput.value = data.data_koniec;
        }
        if (dlugoscSelect && data.dlugosc_spotu) {
            const lengthValue = String(data.dlugosc_spotu);
            if (Array.from(dlugoscSelect.options).some(function (option) { return option.value === lengthValue; })) {
                dlugoscSelect.value = lengthValue;
            }
        }
    }

    function applyCampaignData(data) {
        applyCampaignInfoToForm(data);

        loadedCampaignSchedule = (data && data.emisja && typeof data.emisja === 'object') ? data.emisja : {};
        loadedCampaignId = data && data.id ? String(data.id) : null;

        const warnings = applyCampaignScheduleToGrid(loadedCampaignSchedule);
        if (warnings.length > 0) {
            setAutofillMessage('Kampania wczytana z ograniczeniami: ' + warnings.join(' | '), 'warning');
        } else {
            setAutofillMessage('Kampania #' + data.id + ' zostala wczytana i zastosowana do formularza.', 'success');
        }
    }

    function fetchCampaignData(campaignId) {
        return fetch('api/kampania_details.php?id=' + encodeURIComponent(campaignId), {
            headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (payload) {
                return { ok: response.ok, status: response.status, payload: payload };
            });
        });
    }

    function loadCampaign(campaignId) {
        const id = parseInt(campaignId, 10) || 0;
        if (id <= 0) {
            setAutofillMessage('Podaj poprawne ID kampanii.', 'error');
            return;
        }
        setAutofillMessage('Pobieram kampanie #' + id + '...', 'muted');
        fetchCampaignData(id)
            .then(function (result) {
                if (!result.ok || !result.payload || !result.payload.success) {
                    const errorMsg = (result.payload && result.payload.error) ? result.payload.error : ('HTTP ' + result.status);
                    throw new Error(errorMsg);
                }
                applyCampaignData(result.payload.data || {});
            })
            .catch(function (error) {
                setAutofillMessage('Nie udalo sie pobrac kampanii: ' + (error.message || 'blad'), 'error');
            });
    }

    function reapplyLoadedCampaignSchedule() {
        if (!loadedCampaignSchedule || typeof loadedCampaignSchedule !== 'object') {
            return;
        }
        const warnings = applyCampaignScheduleToGrid(loadedCampaignSchedule);
        if (warnings.length > 0) {
            setAutofillMessage('Przeliczono emisje wg preferencji blokow: ' + warnings.join(' | '), 'warning');
        } else if (loadedCampaignId) {
            setAutofillMessage('Przeliczono emisje kampanii #' + loadedCampaignId + ' wg preferencji blokow A/B.', 'success');
        }
    }

    if (rotationGroup && rotationMode) {
        const syncRotationMode = function () {
            const hasGroup = rotationGroup.value !== '';
            rotationMode.disabled = !hasGroup;
            if (!hasGroup) {
                rotationMode.value = '';
            }
        };
        rotationGroup.addEventListener('change', function () {
            syncRotationMode();
            reapplyLoadedCampaignSchedule();
        });
        rotationMode.addEventListener('change', reapplyLoadedCampaignSchedule);
        syncRotationMode();
    }

    if (kampaniaLoadBtn && kampaniaSearchId) {
        kampaniaLoadBtn.addEventListener('click', function () {
            loadCampaign(kampaniaSearchId.value);
        });
        kampaniaSearchId.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                loadCampaign(kampaniaSearchId.value);
            }
        });
    }

    if (kampaniaSelect) {
        kampaniaSelect.addEventListener('change', function () {
            if (!kampaniaSelect.value) {
                setAutofillMessage('', 'muted');
                loadedCampaignSchedule = null;
                loadedCampaignId = null;
                return;
            }
            if (kampaniaSearchId) {
                kampaniaSearchId.value = kampaniaSelect.value;
            }
            loadCampaign(kampaniaSelect.value);
        });
    }

    // Usuwanie spotu — zachowujemy tę funkcjonalność
    document.querySelectorAll('.btn-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const spotId = this.dataset.id;
            if (confirm('Czy na pewno chcesz trwale usunąć ten spot?')) {
                fetch('usun_spot.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'id=' + encodeURIComponent(spotId) + '&csrf_token=' + encodeURIComponent(csrfToken)
                })
                .then(response => response.text())
                .then(data => {
                    console.log('Odpowiedź:', data);
                    location.reload();
                })
                .catch(error => {
                    alert('Błąd przy usuwaniu spotu');
                });
            }
        });
    });
});
</script>
