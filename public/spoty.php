<?php
require_once __DIR__ . '/includes/config.php';
require_once '../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/../services/SpotStatusService.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo) ?? [];

try {
    SpotStatusService::autoDeactivateExpired($pdo);
} catch (Throwable $e) {
    error_log('spoty.php: auto-deactivate failed: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['spot_toggle'])) {
    $spotId = (int)($_POST['spot_id'] ?? 0);
    $setActive = (int)($_POST['set_active'] ?? 0) === 1;

    if ($spotId > 0) {
        try {
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
include 'includes/header.php';

// Ustawienia systemowe
$stmt = $pdo->query("SELECT * FROM konfiguracja_systemu WHERE id = 1");
$ust = $stmt->fetch();
$liczba_blokow = (int)$ust['liczba_blokow'];
$godzina_start = new DateTime($ust['godzina_start']);
$godzina_koniec = new DateTime($ust['godzina_koniec']);
$dni_tygodnia = ['mon' => 'Poniedziałek', 'tue' => 'Wtorek', 'wed' => 'Środa', 'thu' => 'Czwartek', 'fri' => 'Piątek', 'sat' => 'Sobota', 'sun' => 'Niedziela'];

// Dostępne kampanie do podpięcia spotu
$kampanie = [];
$kampaniaCols = getTableColumns($pdo, 'kampanie');
[$kampaniaOwnerWhere, $kampaniaOwnerParams] = ownerConstraint($kampaniaCols, $currentUser, 'k');
$kampaniaSql = 'SELECT k.id, k.klient_nazwa, k.data_start, k.data_koniec FROM kampanie k';
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
        case 'audio_required':
            $alert = ['type' => 'warning', 'text' => 'Spot zapisany bez emisji. Aby zaplanować emisję, wymagany jest zaakceptowany plik audio.'];
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
                        <select name="kampania_id" id="kampania_id" class="form-select">
                            <option value="">— bez kampanii —</option>
                            <?php foreach ($kampanie as $k): ?>
                                <option value="<?= (int)$k['id'] ?>">
                                    #<?= (int)$k['id'] ?> | <?= htmlspecialchars($k['klient_nazwa'] ?? '') ?> (<?= htmlspecialchars($k['data_start'] ?? '') ?> – <?= htmlspecialchars($k['data_koniec'] ?? '') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Handlowiec widzi tylko swoje kampanie.</small>
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
            <!-- Active -->
            <h5>🎯 Spoty aktywne</h5>
            <div class="list-group mb-3" id="lista-aktywnych">
                <?php
                $qryActive = "SELECT s.id, s.nazwa_spotu, COALESCE(c.name_full, c.name_short, k.nazwa_firmy) AS nazwa_firmy, s.data_start, s.data_koniec{$rotationSelect}" .
                             ($hasRezerwacja ? ", s.rezerwacja" : "") .
                             " FROM spoty s LEFT JOIN klienci k ON k.id = s.klient_id LEFT JOIN companies c ON c.id = k.company_id" .
                             " WHERE COALESCE(s.rezerwacja,0)=0 AND COALESCE(s.aktywny,1) = 1 AND COALESCE(s.status,'Aktywny') = 'Aktywny'" .
                             " AND (s.data_start IS NULL OR s.data_start <= CURDATE())" .
                             " AND (s.data_koniec IS NULL OR s.data_koniec >= CURDATE())" .
                             " ORDER BY s.nazwa_spotu";
                $stmt = $pdo->query($qryActive);
                foreach ($stmt as $row): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong><?= htmlspecialchars($row['nazwa_spotu']); ?></strong>
                            <?php if ($hasRotationGroup && !empty($row['rotation_group'])): ?>
                                <span class="badge badge-neutral spoty-rotation-badge"><?= htmlspecialchars($row['rotation_group']) ?></span>
                            <?php endif; ?>
                            <br>
                            <small><?= htmlspecialchars($row['nazwa_firmy']); ?></small><br>
                            <small class="text-muted"><?= htmlspecialchars($row['data_start'] ?? '') ?> – <?= htmlspecialchars($row['data_koniec'] ?? '') ?></small>
                        </div>
                        <div class="btn-group">
                            <form method="post" action="<?= BASE_URL ?>/spoty.php">
                                <input type="hidden" name="spot_toggle" value="1">
                                <input type="hidden" name="spot_id" value="<?= (int)$row['id']; ?>">
                                <input type="hidden" name="set_active" value="0">
                                <button type="submit" class="btn btn-sm btn-outline-warning">⏸️</button>
                            </form>
                            <a href="edytuj_spot.php?id=<?= $row['id']; ?>" class="btn btn-sm btn-outline-primary">✏️</a>
                            <button class="btn btn-sm btn-outline-danger btn-delete" data-id="<?= $row['id']; ?>">🗑️</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Planned -->
            <h5>📅 Zaplanowane (przyszłe)</h5>
            <div class="list-group mb-3" id="lista-zaplanowanych">
                <?php
                $qryPlanned = "SELECT s.id, s.nazwa_spotu, COALESCE(c.name_full, c.name_short, k.nazwa_firmy) AS nazwa_firmy, s.data_start, s.data_koniec{$rotationSelect}" .
                              " FROM spoty s LEFT JOIN klienci k ON k.id = s.klient_id LEFT JOIN companies c ON c.id = k.company_id" .
                              " WHERE COALESCE(s.aktywny,1) = 1 AND COALESCE(s.status,'Aktywny') = 'Aktywny' AND s.data_start IS NOT NULL AND s.data_start > CURDATE() AND COALESCE(s.rezerwacja,0)=0" .
                              " ORDER BY s.data_start ASC";
                $stmt = $pdo->query($qryPlanned);
                foreach ($stmt as $row): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong><?= htmlspecialchars($row['nazwa_spotu']); ?></strong>
                            <?php if ($hasRotationGroup && !empty($row['rotation_group'])): ?>
                                <span class="badge badge-neutral spoty-rotation-badge"><?= htmlspecialchars($row['rotation_group']) ?></span>
                            <?php endif; ?>
                            <br>
                            <small><?= htmlspecialchars($row['nazwa_firmy']); ?></small><br>
                            <small class="text-muted">Start: <?= htmlspecialchars($row['data_start']) ?></small>
                        </div>
                        <div class="btn-group">
                            <form method="post" action="<?= BASE_URL ?>/spoty.php">
                                <input type="hidden" name="spot_toggle" value="1">
                                <input type="hidden" name="spot_id" value="<?= (int)$row['id']; ?>">
                                <input type="hidden" name="set_active" value="0">
                                <button type="submit" class="btn btn-sm btn-outline-warning">⏸️</button>
                            </form>
                            <a href="edytuj_spot.php?id=<?= $row['id']; ?>" class="btn btn-sm btn-outline-primary">✏️</a>
                            <button class="btn btn-sm btn-outline-danger btn-delete" data-id="<?= $row['id']; ?>">🗑️</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Reservations -->
            <h5>🔖 Rezerwacje pasma</h5>
            <div class="list-group mb-3" id="lista-rezerwacji">
                <?php
                if ($hasRezerwacja) {
                    $qryRes = "SELECT s.id, s.nazwa_spotu, COALESCE(c.name_full, c.name_short, k.nazwa_firmy) AS nazwa_firmy, s.data_start, s.data_koniec{$rotationSelect} FROM spoty s LEFT JOIN klienci k ON k.id = s.klient_id LEFT JOIN companies c ON c.id = k.company_id WHERE COALESCE(s.rezerwacja,0)=1 ORDER BY s.data_start DESC";
                    $stmt = $pdo->query($qryRes);
                    foreach ($stmt as $row): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <strong><?= htmlspecialchars($row['nazwa_spotu']); ?></strong>
                                <?php if ($hasRotationGroup && !empty($row['rotation_group'])): ?>
                                    <span class="badge badge-neutral spoty-rotation-badge"><?= htmlspecialchars($row['rotation_group']) ?></span>
                                <?php endif; ?>
                                <br>
                                <small><?= htmlspecialchars($row['nazwa_firmy']); ?></small><br>
                                <small class="text-muted"><?= htmlspecialchars($row['data_start'] ?? '') ?> – <?= htmlspecialchars($row['data_koniec'] ?? '') ?></small>
                            </div>
                            <div class="btn-group">
                                <a href="edytuj_spot.php?id=<?= $row['id']; ?>" class="btn btn-sm btn-outline-primary">✏️</a>
                                <button class="btn btn-sm btn-outline-danger btn-delete" data-id="<?= $row['id']; ?>">🗑️</button>
                            </div>
                        </div>
                    <?php endforeach;
                } else {
                    echo '<div class="list-group-item text-muted">Kolumna rezerwacja nie istnieje w DB. Możesz ją dodać (rezerwacja = 1).</div>';
                }
                ?>
            </div>

            <!-- Expired / Inactive -->
            <h5>📁 Wygasłe / nieaktywne</h5>
            <div class="list-group" id="lista-nieaktywnych">
                <?php
                $qryExpired = "SELECT s.id, s.nazwa_spotu, COALESCE(c.name_full, c.name_short, k.nazwa_firmy) AS nazwa_firmy, s.data_start, s.data_koniec{$rotationSelect}" .
                              " FROM spoty s LEFT JOIN klienci k ON k.id = s.klient_id LEFT JOIN companies c ON c.id = k.company_id" .
                              " WHERE (COALESCE(s.aktywny,0) = 0 OR COALESCE(s.status,'Aktywny') <> 'Aktywny' OR (s.data_koniec IS NOT NULL AND s.data_koniec < CURDATE()))" .
                              " AND COALESCE(s.rezerwacja,0)=0" .
                              " ORDER BY s.nazwa_spotu";
                $stmt = $pdo->query($qryExpired);
                foreach ($stmt as $row): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong><?= htmlspecialchars($row['nazwa_spotu']); ?></strong>
                            <?php if ($hasRotationGroup && !empty($row['rotation_group'])): ?>
                                <span class="badge badge-neutral spoty-rotation-badge"><?= htmlspecialchars($row['rotation_group']) ?></span>
                            <?php endif; ?>
                            <br>
                            <small><?= htmlspecialchars($row['nazwa_firmy']); ?></small><br>
                            <small class="text-muted"><?= htmlspecialchars($row['data_start'] ?? '') ?> – <?= htmlspecialchars($row['data_koniec'] ?? '') ?></small>
                        </div>
                        <div class="btn-group">
                            <form method="post" action="<?= BASE_URL ?>/spoty.php">
                                <input type="hidden" name="spot_toggle" value="1">
                                <input type="hidden" name="spot_id" value="<?= (int)$row['id']; ?>">
                                <input type="hidden" name="set_active" value="1">
                                <button type="submit" class="btn btn-sm btn-outline-success">▶️</button>
                            </form>
                            <a href="edytuj_spot.php?id=<?= $row['id']; ?>" class="btn btn-sm btn-outline-primary">✏️</a>
                            <button class="btn btn-sm btn-outline-danger btn-delete" data-id="<?= $row['id']; ?>">🗑️</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </aside>

        <main class="spoty-right">
            <h5>Siatka tygodniowa emisji</h5>
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
                                    echo '<td><input type="checkbox" class="form-check-input" style="transform: scale(1.3);" name="' . $nazwa . '" value="1" form="spoty-form"></td>';
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
    const rotationGroup = document.getElementById('rotation_group');
    const rotationMode = document.getElementById('rotation_mode');
    if (rotationGroup && rotationMode) {
        const syncRotationMode = () => {
            const hasGroup = rotationGroup.value !== '';
            rotationMode.disabled = !hasGroup;
            if (!hasGroup) {
                rotationMode.value = '';
            }
        };
        rotationGroup.addEventListener('change', syncRotationMode);
        syncRotationMode();
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
                    body: 'id=' + encodeURIComponent(spotId)
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
