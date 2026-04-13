<?php
require_once __DIR__ . '/includes/config.php';
require_once '../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/emisje_helpers.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo) ?? [];
$pageTitle = "Edycja spotu";
include 'includes/header.php';

ensureSystemConfigColumns($pdo);
ensureSpotAudioFilesTable($pdo);
ensureSpotAudioDispatchesTable($pdo);
ensureSpotAudioDispatchItemsTable($pdo);

$id = $_GET['id'] ?? null;
if (!$id || !is_numeric($id)) {
    echo "Nieprawidłowy identyfikator spotu";
    exit;
}

// Pobierz dane spotu
$stmt = $pdo->prepare("SELECT s.*, COALESCE(c.name_full, c.name_short, k.nazwa_firmy) AS nazwa_firmy
    FROM spoty s
    LEFT JOIN klienci k ON k.id = s.klient_id
    LEFT JOIN companies c ON c.id = k.company_id
    WHERE s.id = ?");
$stmt->execute([$id]);
$spot = $stmt->fetch();

if (!$spot) {
    echo "Spot nie został znaleziony.";
    exit;
}

if (!canAccessSpot($pdo, (int)$spot['id'], $currentUser)) {
    http_response_code(403);
    echo "Brak uprawnień do tego spotu.";
    exit;
}

// Pobierz ustawienia
$ust = $pdo->query("SELECT * FROM konfiguracja_systemu WHERE id = 1")->fetch();
$liczba_blokow = (int)$ust['liczba_blokow'];
$godzina_start = new DateTime($ust['godzina_start']);
$godzina_koniec = new DateTime($ust['godzina_koniec']);
$dni_tygodnia = ['mon' => 'Poniedziałek', 'tue' => 'Wtorek', 'wed' => 'Środa', 'thu' => 'Czwartek', 'fri' => 'Piątek', 'sat' => 'Sobota', 'sun' => 'Niedziela'];
$audioUploadMaxMb = (int)($ust['audio_upload_max_mb'] ?? 50);
$audioAllowedExt = (string)($ust['audio_allowed_ext'] ?? 'wav,mp3');

// Kampanie do wyboru
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

// Pobierz siatkę emisji (prefer emisje_spotow)
$mapa = [];
$dowMap = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
$stmtWeekly = $pdo->prepare("SELECT dow, godzina, liczba FROM emisje_spotow WHERE spot_id = ?");
$stmtWeekly->execute([$id]);
$weeklyRows = $stmtWeekly->fetchAll(PDO::FETCH_ASSOC);
if ($weeklyRows) {
    foreach ($weeklyRows as $row) {
        $kod = $dowMap[(int)$row['dow']] ?? null;
        if (!$kod) {
            continue;
        }
        $godzina = strlen($row['godzina']) === 5 ? $row['godzina'] . ':00' : $row['godzina'];
        $liczba = min((int)$row['liczba'], $liczba_blokow);
        for ($i = 0; $i < $liczba; $i++) {
            $litera = chr(65 + $i);
            $mapa[$kod][$godzina][$litera] = true;
        }
    }
} else {
    $stmt = $pdo->prepare("SELECT data, godzina, blok FROM spoty_emisje WHERE spot_id = ?");
    $stmt->execute([$id]);
    $emisje = $stmt->fetchAll();

    foreach ($emisje as $e) {
        $dow = strtolower(date('D', strtotime($e['data']))); // pon = mon
        $mapa[$dow][$e['godzina']][$e['blok']] = true;
    }
}

$audioFiles = [];
$stmtFiles = $pdo->prepare("SELECT f.*, u.login AS uploaded_by_login, au.login AS approved_by_login
    FROM spot_audio_files f
    LEFT JOIN uzytkownicy u ON u.id = f.uploaded_by_user_id
    LEFT JOIN uzytkownicy au ON au.id = f.approved_by_user_id
    WHERE f.spot_id = ?
    ORDER BY f.version_no DESC, f.created_at DESC");
$stmtFiles->execute([$id]);
$audioFiles = $stmtFiles->fetchAll(PDO::FETCH_ASSOC);

$audioUploadError = $_SESSION['audio_upload_error'] ?? null;
$audioUploadSuccess = $_SESSION['audio_upload_success'] ?? null;
unset($_SESSION['audio_upload_error'], $_SESSION['audio_upload_success']);

$autoPlanError = $_SESSION['auto_plan_error'] ?? null;
$autoPlanSuccess = $_SESSION['auto_plan_success'] ?? null;
unset($_SESSION['auto_plan_error'], $_SESSION['auto_plan_success']);

$hasApprovedAudio = hasApprovedAudio($pdo, (int)$spot['id']);
?>

<div class="container mt-4">
    <h4>✏️ Edytuj spot: <?= htmlspecialchars($spot['nazwa_spotu']); ?></h4>
    <?php if ($audioUploadError): ?>
        <div class="alert alert-danger mt-3">
            <?= htmlspecialchars($audioUploadError) ?>
        </div>
    <?php endif; ?>
    <?php if ($audioUploadSuccess): ?>
        <div class="alert alert-success mt-3">
            <?= htmlspecialchars($audioUploadSuccess) ?>
        </div>
    <?php endif; ?>
    <?php if ($autoPlanError): ?>
        <div class="alert alert-danger mt-3">
            <?= nl2br(htmlspecialchars($autoPlanError)) ?>
        </div>
    <?php endif; ?>
    <?php if ($autoPlanSuccess): ?>
        <div class="alert alert-success mt-3">
            <?= htmlspecialchars($autoPlanSuccess) ?>
        </div>
    <?php endif; ?>
    <?php if (!$hasApprovedAudio): ?>
        <div class="alert alert-danger mt-3">
            Brak zaakceptowanego audio – emisja zablokowana.
        </div>
    <?php endif; ?>
    <form method="post" action="<?= BASE_URL ?>/update_spot.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
        <input type="hidden" name="id" value="<?= $spot['id']; ?>">

        <div class="mb-3">
            <label class="form-label">Klient:</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($spot['nazwa_firmy'] ?? 'Brak przypisanego klienta'); ?>" disabled>
        </div>

        <div class="mb-3">
            <label class="form-label">Nazwa spotu:</label>
            <input type="text" name="nazwa_spotu" class="form-control" value="<?= htmlspecialchars($spot['nazwa_spotu']); ?>" required>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">Kampania (opcjonalnie):</label>
                <select name="kampania_id" class="form-select">
                    <option value="">— bez kampanii —</option>
                    <?php foreach ($kampanie as $k): ?>
                        <option value="<?= (int)$k['id'] ?>" <?= ((int)($spot['kampania_id'] ?? 0) === (int)$k['id']) ? 'selected' : '' ?>>
                            #<?= (int)$k['id'] ?> | <?= htmlspecialchars($k['klient_nazwa'] ?? '') ?> (<?= htmlspecialchars($k['data_start'] ?? '') ?> – <?= htmlspecialchars($k['data_koniec'] ?? '') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Długość (s):</label>
                <select name="dlugosc_s" class="form-select">
                    <?php foreach ([15, 20, 30] as $dl): ?>
                        <?php $selectedLength = (int)($spot['dlugosc_s'] ?? $spot['dlugosc']); ?>
                        <option value="<?= $dl ?>" <?= ($selectedLength == $dl ? 'selected' : '') ?>><?= $dl ?> sek</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Data rozpoczęcia:</label>
                <input type="date" name="data_start" class="form-control" value="<?= $spot['data_start']; ?>" required>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-3">
                <label class="form-label">Data zakończenia:</label>
                <input type="date" name="data_koniec" class="form-control" value="<?= $spot['data_koniec']; ?>" required>
            </div>
        </div>

        <hr>
        <h5>Siatka emisji tygodniowej</h5>
        <div class="table-responsive">
            <table class="table table-bordered text-center align-middle">
                <thead>
                    <tr>
                        <th>Godzina / Blok</th>
                        <?php foreach ($dni_tygodnia as $kod => $dzien): ?>
                            <th><?= $dzien ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    for ($czas = clone $godzina_start; $czas <= $godzina_koniec; $czas->modify('+1 hour')) {
                        for ($b = 0; $b < $liczba_blokow; $b++) {
                            $litera = chr(65 + $b);
                            echo "<tr>";
                            echo "<th>" . $czas->format('H:i') . " – Blok " . $litera . "</th>";
                            foreach (array_keys($dni_tygodnia) as $kod) {
                                $checked = isset($mapa[$kod][$czas->format('H:i:s')][$litera]) ? 'checked' : '';
                                $name = "emisja[$kod][" . $czas->format('H:i') . "][blok$litera]";
                                echo "<td><input type='checkbox' class='form-check-input' name='$name' value='1' $checked></td>";
                            }
                            echo "</tr>";
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <div class="text-end mt-3">
            <button type="submit" class="btn btn-success">💾 Zapisz zmiany</button>
        </div>
    </form>

    <hr class="my-4">
    <h5>Auto-plan emisji</h5>
    <div class="card mb-4">
        <div class="card-body">
            <form method="post" action="<?= BASE_URL ?>/auto_plan_spot.php" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="spot_id" value="<?= (int)$spot['id'] ?>">
                <div class="col-12">
                    <label class="form-label">Zakres dni tygodnia</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php
                        $days = ['mon' => 'Pon', 'tue' => 'Wt', 'wed' => 'Śr', 'thu' => 'Czw', 'fri' => 'Pt', 'sat' => 'Sob', 'sun' => 'Nd'];
                        foreach ($days as $code => $label):
                        ?>
                            <label class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="days[]" value="<?= $code ?>" checked>
                                <span class="form-check-label"><?= $label ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">Pasma do użycia</label>
                    <div class="d-flex flex-wrap gap-2">
                        <label class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="bands[]" value="Prime" checked>
                            <span class="form-check-label">Prime</span>
                        </label>
                        <label class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="bands[]" value="Standard" checked>
                            <span class="form-check-label">Standard</span>
                        </label>
                        <label class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="bands[]" value="Night">
                            <span class="form-check-label">Night</span>
                        </label>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Tryb planu</label>
                    <select name="mode" class="form-select">
                        <option value="daily">X emisji dziennie</option>
                        <option value="weekly">Y emisji tygodniowo</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Liczba emisji</label>
                    <input type="number" name="count" class="form-control" min="1" value="2" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Min. odstęp (min)</label>
                    <input type="number" name="min_gap" class="form-control" min="15" value="60" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Start od daty</label>
                    <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($spot['data_start'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-md-6 d-flex align-items-center">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="overwrite" value="1" checked>
                        <label class="form-check-label">Nadpisz istniejący plan emisji</label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">Okna godzinowe pasm (MVP)</label>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small">Prime</label>
                            <input type="text" name="windows_prime" class="form-control" value="06:00-09:59,15:00-18:59">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Standard</label>
                            <input type="text" name="windows_standard" class="form-control" value="10:00-14:59,19:00-22:59">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Night</label>
                            <input type="text" name="windows_night" class="form-control" value="23:00-23:59">
                        </div>
                    </div>
                </div>
                <div class="col-12 text-end">
                    <button type="submit" class="btn btn-primary">⚙️ Auto-plan emisji</button>
                    <a href="symulacja_emisji.php?spot_id=<?= (int)$spot['id'] ?>" class="btn btn-outline-secondary ms-2">
                        🧾 Symulacja emisji
                    </a>
                </div>
            </form>
        </div>
    </div>

    <hr class="my-4">
    <h5>Pliki audio</h5>
    <div class="card mb-4">
        <div class="card-body">
            <form method="post" action="<?= BASE_URL ?>/upload_spot_audio.php" enctype="multipart/form-data" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="spot_id" value="<?= (int)$spot['id'] ?>">
                <div class="col-md-6">
                    <label for="audio_file" class="form-label">Wgraj nową wersję</label>
                    <input type="file" name="audio_file" id="audio_file" class="form-control" required>
                    <div class="form-text">
                        Limit: <?= (int)$audioUploadMaxMb ?> MB. Dozwolone: <?= htmlspecialchars($audioAllowedExt) ?>.
                    </div>
                </div>
                <div class="col-md-6">
                    <label for="upload_note" class="form-label">Notatka (opcjonalnie)</label>
                    <input type="text" name="upload_note" id="upload_note" class="form-control" maxlength="255"
                           placeholder="np. podmiana CTA / poprawka numeru">
                </div>
                <div class="col-12 text-end">
                    <button type="submit" class="btn btn-primary">📤 Wgraj nową wersję</button>
                </div>
            </form>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle">
                            <thead>
                                <tr>
                                    <th>Wysylka</th>
                                    <th>Wersja</th>
                                    <th>Status</th>
                                    <th>Plik</th>
                                    <th>Rozmiar</th>
                                    <th>SHA-256</th>
                                    <th>Dodany przez</th>
                                    <th>Data</th>
                                    <th>Notatka</th>
                                    <th>Akcje</th>
                                    <th>Produkcja</th>
                                    <th>Akceptacja</th>
                                </tr>
                            </thead>
            <tbody>
                <?php if (!$audioFiles): ?>
                    <tr>
                        <td colspan="12" class="text-center text-muted">Brak plików audio.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($audioFiles as $file): ?>
                        <?php
                        $sizeLabel = $file['file_size'] ? number_format((int)$file['file_size'] / 1024, 1, '.', ' ') . ' KB' : '—';
                        $statusLabel = audioProductionStatusLabel((string)($file['production_status'] ?? ''));
                        $isActive = (int)($file['is_active'] ?? 0) === 1;
                        $canApproveAudio = in_array(normalizeRole($currentUser), ['Manager', 'Administrator', 'Handlowiec'], true);
                        $canMarkDispatched = $canApproveAudio;
                        ?>
                        <tr>
                            <td class="text-center">
                                <?php if ($canMarkDispatched): ?>
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="audio_ids[]"
                                        value="<?= (int)$file['id'] ?>"
                                        form="dispatchForm"
                                    >
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)$file['version_no'] ?></td>
                            <td>
                                <?php if ($isActive): ?>
                                    <span class="badge bg-success">Aktywna</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Archiwum</span>
                                <?php endif; ?>
                                <?php if ((int)($file['is_final'] ?? 0) === 1): ?>
                                    <span class="badge bg-primary">Finalna</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($file['original_filename']) ?></td>
                            <td><?= $sizeLabel ?></td>
                            <td class="text-muted"><?= htmlspecialchars((string)($file['sha256'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($file['uploaded_by_login'] ?? '—')) ?></td>
                            <td><?= htmlspecialchars((string)$file['created_at']) ?></td>
                            <td><?= htmlspecialchars((string)($file['upload_note'] ?? '')) ?></td>
                            <td>
                                <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>/download_spot_audio.php?id=<?= (int)$file['id'] ?>">
                                    Pobierz
                                </a>
                            </td>
                            <td>
                                <span class="badge bg-info text-dark"><?= htmlspecialchars($statusLabel) ?></span>
                                <?php if (!empty($file['rejection_reason'])): ?>
                                    <div class="small text-muted"><?= htmlspecialchars($file['rejection_reason']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($file['approved_by_login'])): ?>
                                    <div class="small text-muted">Akceptował: <?= htmlspecialchars($file['approved_by_login']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isActive && $canApproveAudio): ?>
                                    <form method="post" action="<?= BASE_URL ?>/api/audio_update_status.php" class="d-inline-block">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                        <input type="hidden" name="audio_id" value="<?= (int)$file['id'] ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="checkbox" name="override" value="1" id="override<?= (int)$file['id'] ?>">
                                            <label class="form-check-label small" for="override<?= (int)$file['id'] ?>">Override</label>
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-success">Zaakceptuj</button>
                                    </form>
                                    <form method="post" action="<?= BASE_URL ?>/api/audio_update_status.php" class="d-inline-block ms-1">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                        <input type="hidden" name="audio_id" value="<?= (int)$file['id'] ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="text" name="reason" class="form-control form-control-sm d-inline-block w-auto" placeholder="Powód" required>
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Odrzuć</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($audioFiles): ?>
        <form id="dispatchForm" method="post" action="<?= BASE_URL ?>/api/audio_mark_dispatched.php" class="mt-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
            <input type="hidden" name="spot_id" value="<?= (int)$spot['id'] ?>">
            <div class="row g-2 align-items-end">
                <div class="col-md-8">
                    <label class="form-label" for="dispatch_note">Notatka do wysylki (opcjonalnie)</label>
                    <input
                        type="text"
                        id="dispatch_note"
                        name="dispatch_note"
                        class="form-control"
                        maxlength="255"
                        placeholder="np. wysylka do klienta po poprawkach lektorskich"
                    >
                </div>
                <div class="col-md-4 text-md-end">
                    <button type="submit" class="btn btn-outline-primary">Oznacz zaznaczone jako wyslane do klienta</button>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
