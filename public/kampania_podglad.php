<?php
declare(strict_types=1);
require_once '../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/documents.php';
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Lista kampanii, gdy brak id
if ($id <= 0) {
    $rows = [];
    try {
        $stmt = $pdo->query("SELECT id, klient_nazwa, data_start, data_koniec, razem_brutto FROM kampanie ORDER BY id DESC LIMIT 200");
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('kampania_podglad.php: blad pobierania listy kampanii: ' . $e->getMessage());
    }

    $pageTitle = 'Lista kampanii';
    include 'includes/header.php';
    ?>
    <div class="container py-4">
      <h3>Lista kampanii</h3>
      <?php if (!empty($rows)): ?>
        <div class="table-responsive">
          <table class="table table-sm table-striped">
            <thead class="table-light">
              <tr><th>ID</th><th>Klient</th><th>Okres</th><th>Brutto</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= htmlspecialchars($r['klient_nazwa'] ?? '') ?></td>
                <td><?= htmlspecialchars(($r['data_start'] ?? '') . ' - ' . ($r['data_koniec'] ?? '')) ?></td>
                <td><?= number_format((float)($r['razem_brutto'] ?? 0), 2, ',', ' ') ?> zl</td>
                <td><a class="btn btn-sm btn-primary" href="kampania_podglad.php?id=<?= (int)$r['id'] ?>">Podglad</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="text-muted">Brak zapisanych kampanii.</p>
      <?php endif; ?>
      <div class="mt-3"><a class="btn btn-secondary" href="kampanie_lista.php">Lista kampanii</a></div>
    </div>
    <?php
    include 'includes/footer.php';
    exit;
}

// Pobierz kampanie z nowej tabeli "kampanie"
$source = 'kampanie';
$k = null;
$stmt = $pdo->prepare('SELECT id, klient_id, klient_nazwa, dlugosc_spotu, data_start, data_koniec, netto_spoty, netto_dodatki, rabat, razem_netto, razem_brutto, created_at FROM kampanie WHERE id = :id');
if ($stmt && $stmt->execute([':id' => $id])) {
    $k = $stmt->fetch();
}

// Fallback do starej tabeli "kampanie_tygodniowe" (z polami sumy/produkty/siatka)
if (!$k) {
    $source = 'kampanie_tygodniowe';
    $stmt = $pdo->prepare('SELECT id, klient_nazwa, dlugosc, data_start, data_koniec, netto_spoty, netto_dodatki, rabat, razem_po_rabacie, razem_brutto, sumy, produkty, siatka, created_at
      FROM kampanie_tygodniowe WHERE id = :id');
    if ($stmt && $stmt->execute([':id' => $id])) {
        $k = $stmt->fetch();
    }
}

if (!$k) {
    http_response_code(404);
    exit('Nie znaleziono kampanii.');
}

$klientId = isset($k['klient_id']) ? (int)$k['klient_id'] : 0;
$klientEmail = '';
$docAlerts = [];
$generatedDoc = null;
$currentUser = fetchCurrentUser($pdo);
if ($klientId > 0) {
    try {
        $klientStmt = $pdo->prepare('SELECT email FROM klienci WHERE id = :id');
        if ($klientStmt && $klientStmt->execute([':id' => $klientId])) {
            $klientEmail = trim((string)($klientStmt->fetchColumn() ?: ''));
        }
    } catch (Throwable $e) {
        error_log('kampania_podglad.php: nie udalo sie pobrac emaila klienta: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['doc_action'] ?? '') === 'generate_aneks') {
    if (!$currentUser) {
        $docAlerts[] = ['type' => 'danger', 'msg' => 'Brak aktywnej sesji użytkownika.'];
    } else {
        $startDate = trim((string)($_POST['aneks_start_date'] ?? ''));
        $result = generateDocument($pdo, $currentUser, 'aneks', $klientId, $id, ['start_date' => $startDate]);
        if (!empty($result['success'])) {
            $generatedDoc = $result;
            $docAlerts[] = ['type' => 'success', 'msg' => 'Aneks terminowy został wygenerowany.'];
        } else {
            $docAlerts[] = ['type' => 'danger', 'msg' => $result['error'] ?? 'Nie udało się wygenerować aneksu.'];
        }
    }
}

$missingAudioCount = 0;
try {
    require_once __DIR__ . '/includes/db_schema.php';
    require_once __DIR__ . '/includes/emisje_helpers.php';
    ensureSpotAudioFilesTable($pdo);
    if ($source === 'kampanie' && tableExists($pdo, 'spoty')) {
        $stmtMissing = $pdo->prepare("
            SELECT COUNT(*) FROM spoty s
            LEFT JOIN spot_audio_files saf
                ON saf.spot_id = s.id AND saf.is_active = 1 AND saf.production_status = 'Zaakceptowany'
            WHERE s.kampania_id = :kampania_id
              AND saf.id IS NULL
        ");
        $stmtMissing->execute([':kampania_id' => $id]);
        $missingAudioCount = (int)($stmtMissing->fetchColumn() ?? 0);
    }
} catch (Throwable $e) {
    error_log('kampania_podglad.php: audio check failed: ' . $e->getMessage());
}

// Mapowanie pol z obu tabel
$dlugosc = isset($k['dlugosc_spotu']) ? (int)$k['dlugosc_spotu'] : (int)($k['dlugosc'] ?? 0);
$razemPoRab = $k['razem_po_rabacie'] ?? $k['razem_netto'] ?? null;
$sumy = [];
$produkty = [];
$siatka = null;
if (!empty($k['sumy'])) {
    $tmp = json_decode($k['sumy'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) $sumy = $tmp;
}
if (!empty($k['produkty'])) {
    $tmp = json_decode($k['produkty'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) $produkty = $tmp;
}
if (!empty($k['siatka'])) {
    $tmp = json_decode($k['siatka'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) $siatka = $tmp;
}

// Audio
$uploadMessage = '';
$audioWebPath = $k['audio_file'] ?? null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['audio'])) {
  if ($id > 0 && isset($_FILES['audio']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
    $u = $_FILES['audio'];
    $filename = $id . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($u['name']));
    $uploadDir = __DIR__ . '/uploads/audio/';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
    $dest = $uploadDir . $filename;
    if (move_uploaded_file($u['tmp_name'], $dest)) {
      $webPath = 'uploads/audio/' . $filename;
      try {
        $uStmt = $pdo->prepare("UPDATE {$source} SET audio_file = :f WHERE id = :id");
        $uStmt->execute([':f' => $webPath, ':id' => $id]);
        $uploadMessage = 'Plik zostal zapisany i przypiety do kampanii.';
        $audioWebPath = $webPath;
      } catch (Throwable $e) {
        // Spróbuj dodać kolumnę audio_file i ponowić zapis
        error_log('kampania_podglad.php: blad przy zapisie audio do DB: ' . $e->getMessage());
        $altered = false;
        try {
          $pdo->exec("ALTER TABLE {$source} ADD COLUMN audio_file VARCHAR(255) NULL");
          $altered = true;
        } catch (Throwable $e2) {
          error_log('kampania_podglad.php: nie udalo sie dodac kolumny audio_file: ' . $e2->getMessage());
        }

        if ($altered) {
          try {
            $uStmt = $pdo->prepare("UPDATE {$source} SET audio_file = :f WHERE id = :id");
            $uStmt->execute([':f' => $webPath, ':id' => $id]);
            $uploadMessage = 'Plik zostal zapisany i przypiety do kampanii.';
            $audioWebPath = $webPath;
          } catch (Throwable $e3) {
            error_log('kampania_podglad.php: blad przy zapisie audio po dodaniu kolumny: ' . $e3->getMessage());
            $uploadMessage = 'Plik zostal zapisany na serwerze: ' . $webPath . '. Nie udalo sie zapisac sciezki w DB.';
          }
        } else {
          $uploadMessage = 'Plik zostal zapisany na serwerze: ' . $webPath . '. Nie udalo sie zapisac sciezki w DB.';
        }
      }
    } else {
      $uploadMessage = 'Blad przenoszenia pliku na serwer.';
    }
  } else {
    $uploadMessage = 'Brak pliku lub blad uploadu.';
  }
}

// Usuwanie pliku audio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_audio']) && $id > 0) {
    $currentPath = $audioWebPath;
    if ($currentPath) {
        $full = __DIR__ . '/' . ltrim($currentPath, '/');
        if (is_file($full)) {
            @unlink($full);
        }
    }
    try {
        $pdo->prepare("UPDATE {$source} SET audio_file = NULL WHERE id = :id")->execute([':id' => $id]);
        $audioWebPath = null;
        $uploadMessage = 'Plik audio zostal usuniety.';
    } catch (Throwable $e) {
        error_log('kampania_podglad.php: blad usuwania audio_file: ' . $e->getMessage());
        $uploadMessage = 'Nie udalo sie wyczyscic sciezki w DB, sprawdz logi.';
    }
}

$pageTitle = 'Kampania #' . (int)$k['id'];

// Formatowanie dat
$fmtStartStr = '';
$fmtEndStr = '';
if (!empty($k['data_start'])) {
  $d = DateTime::createFromFormat('Y-m-d', $k['data_start']) ?: DateTime::createFromFormat('Y-m-d H:i:s', $k['data_start']);
  $fmtStartStr = $d instanceof DateTime ? $d->format('d.m.Y') : htmlspecialchars($k['data_start']);
}
if (!empty($k['data_koniec'])) {
  $d = DateTime::createFromFormat('Y-m-d', $k['data_koniec']) ?: DateTime::createFromFormat('Y-m-d H:i:s', $k['data_koniec']);
  $fmtEndStr = $d instanceof DateTime ? $d->format('d.m.Y') : htmlspecialchars($k['data_koniec']);
}

$documents = [];
$documentsAllowed = true;
if ($currentUser && normalizeRole($currentUser) === 'Handlowiec') {
    $documentsAllowed = canUserAccessKampania($pdo, $currentUser, $id);
    if (!$documentsAllowed) {
        $docAlerts[] = ['type' => 'warning', 'msg' => 'Brak dostępu do archiwum dokumentów tej kampanii.'];
    }
}
if ($documentsAllowed) {
    try {
        ensureDocumentsTables($pdo);
        $stmtDocs = $pdo->prepare("SELECT d.*, u.login, u.imie, u.nazwisko
            FROM dokumenty d
            LEFT JOIN uzytkownicy u ON u.id = d.created_by_user_id
            WHERE d.kampania_id = :id
            ORDER BY d.created_at DESC");
        $stmtDocs->execute([':id' => $id]);
        $documents = $stmtDocs->fetchAll();
    } catch (Throwable $e) {
        error_log('kampania_podglad.php: dokumenty fetch failed: ' . $e->getMessage());
    }
}

$defaultAneksDate = '';
if (!empty($k['data_start'])) {
    $defaultAneksDate = substr((string)$k['data_start'], 0, 10);
}
if ($defaultAneksDate === '') {
    $defaultAneksDate = date('Y-m-d');
}

include 'includes/header.php';
?>
<div class="container py-4">
  <h3>Kampania #<?= (int)$k['id'] ?></h3>
  <?php if ($missingAudioCount > 0): ?>
    <div class="alert alert-danger mt-3">
      W kampanii są spoty bez zaakceptowanego audio (<?= (int)$missingAudioCount ?>). Emisja będzie zablokowana.
    </div>
  <?php endif; ?>

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

  <div class="card mt-3">
    <div class="card-body">
      <h5 class="card-title">Generowanie dokumentów</h5>
      <form method="post" class="row g-3 align-items-end">
        <input type="hidden" name="doc_action" value="generate_aneks">
        <div class="col-md-4">
          <label for="aneks_start_date" class="form-label">Data start aneksu (12 mies.)</label>
          <input type="date" name="aneks_start_date" id="aneks_start_date" class="form-control"
                 value="<?= htmlspecialchars($defaultAneksDate) ?>" required>
        </div>
        <div class="col-md-4">
          <button class="btn btn-primary" type="submit">Generuj aneks terminowy</button>
        </div>
      </form>
    </div>
  </div>

  <div class="row g-3 mt-2">
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="card-title">Informacje podstawowe</h5>
          <p class="mb-1"><strong>Klient:</strong> <?= htmlspecialchars($k['klient_nazwa']) ?></p>
          <p class="mb-1"><strong>Dlugosc spotu:</strong> <?= $dlugosc ?> sek</p>
          <p class="mb-0"><strong>Okres:</strong> <?= $fmtStartStr ?: '&ndash;' ?> - <?= $fmtEndStr ?: '&ndash;' ?></p>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="card-title">Kwoty</h5>
          <ul class="mb-0">
            <li>Netto spoty: <?= number_format((float)$k['netto_spoty'], 2, ',', ' ') ?> zl</li>
            <li>Netto dodatki: <?= number_format((float)$k['netto_dodatki'], 2, ',', ' ') ?> zl</li>
            <li>Rabat: <?= number_format((float)$k['rabat'], 2, ',', ' ') ?> %</li>
            <li>Razem po rabacie: <?= number_format((float)$razemPoRab, 2, ',', ' ') ?> zl</li>
            <li>Razem brutto: <?= number_format((float)$k['razem_brutto'], 2, ',', ' ') ?> zl</li>
          </ul>
          <div class="mt-3 text-center">
            <canvas id="kampania-timeline" width="150" height="150" aria-label="Wykres czasu kampanii"></canvas>
          </div>
          <div class="mt-3">
            <?php if (!empty($audioWebPath)): ?>
              <p class="mb-1"><strong>Dolaczony plik audio:</strong></p>
              <audio controls style="width:100%;max-width:300px;" src="<?= htmlspecialchars($audioWebPath) ?>">Twoja przegladarka nie obsluguje audio</audio>
            <?php else: ?>
              <p class="text-muted mb-1">Brak pliku audio</p>
            <?php endif; ?>
            <?php if (!empty($uploadMessage)): ?><div class="mt-2"><small class="text-info"><?= htmlspecialchars($uploadMessage) ?></small></div><?php endif; ?>
            <form class="mt-2" method="post" enctype="multipart/form-data">
              <div class="mb-2">
                <input type="file" name="audio" accept="audio/*" />
              </div>
              <div class="d-flex gap-2">
                <button class="btn btn-sm btn-primary" type="submit">Przypnij plik audio</button>
                <?php if (!empty($audioWebPath)): ?>
                  <button class="btn btn-sm btn-outline-danger" type="submit" name="delete_audio" value="1">Usun audio</button>
                <?php endif; ?>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="card-title">Sumy pasm</h5>
          <ul class="mb-0">
            <li>Prime: <?= (int)($sumy['prime'] ?? 0) ?></li>
            <li>Standard: <?= (int)($sumy['standard'] ?? 0) ?></li>
            <li>Night: <?= (int)($sumy['night'] ?? 0) ?></li>
          </ul>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="card-title">Produkty dodatkowe</h5>
          <?php if (!empty($produkty) && is_array($produkty)): ?>
            <ul class="mb-0">
            <?php foreach ($produkty as $p): ?>
              <?php if (is_string($p)): ?>
                <li><?= htmlspecialchars($p) ?></li>
              <?php elseif (is_array($p)): ?>
                <?php
                  $nazwa = $p['nazwa'] ?? $p['name'] ?? null;
                  $kwota = $p['kwota'] ?? $p['amount'] ?? null;
                ?>
                <li>
                  <?= $nazwa ? htmlspecialchars($nazwa) : '<em>Produkt</em>' ?>
                  <?php if ($kwota !== null): ?> - <?= number_format((float)$kwota, 2, ',', ' ') ?> zl<?php endif; ?>
                </li>
              <?php else: ?>
                <li><?= htmlspecialchars((string)$p) ?></li>
              <?php endif; ?>
            <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="mb-0 text-muted">Brak</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if (is_array($siatka)): ?>
    <div class="card mt-3">
      <div class="card-body">
        <h5 class="card-title">Siatka emisji</h5>
        <?php
          $days = ['mon'=>'Pon','tue'=>'Wt','wed'=>'Sr','thu'=>'Czw','fri'=>'Pt','sat'=>'Sob','sun'=>'Nd'];
          $hours = [];
          foreach ($siatka as $day => $vals) {
            if (!is_array($vals)) continue;
            foreach (array_keys($vals) as $h) $hours[$h] = true;
          }
          $hours = array_keys($hours);
          sort($hours);
        ?>
        <div class="table-responsive">
          <table class="table table-sm table-bordered text-center mb-0">
            <thead class="table-light">
              <tr>
                <th>Godzina</th>
                <?php foreach ($days as $short => $label): ?><th><?= htmlspecialchars($label) ?></th><?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($hours as $h): ?>
                <tr>
                  <td><strong><?= htmlspecialchars($h) ?></strong></td>
                  <?php foreach (array_keys($days) as $d): ?>
                    <td><?= isset($siatka[$d][$h]) ? (int)$siatka[$d][$h] : 0 ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($documentsAllowed): ?>
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

  <div class="mt-3">
    <a class="btn btn-secondary" href="kampanie_lista.php">Lista kampanii</a>
    <a class="btn btn-outline-secondary" href="kampania_pdf.php?id=<?= (int)$k['id'] ?>" target="_blank">PDF: Mediaplan</a>
    <?php if (!empty($klientEmail)): ?>
      <a class="btn btn-primary" href="wyslij_oferte.php?id=<?= (int)$k['id'] ?>">Wyślij ofertę</a>
    <?php else: ?>
      <button type="button" class="btn btn-primary" disabled title="Brak email klienta">Wyślij ofertę</button>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  var start = <?= json_encode($k['data_start'] ?? '') ?>;
  var end = <?= json_encode($k['data_koniec'] ?? '') ?>;
  var canvas = document.getElementById('kampania-timeline');
  if (!canvas) return;
  var ctx = canvas.getContext('2d');
  function parseDate(s){
    if (!s) return null;
    var parts = s.split(' ')[0].split('-');
    if (parts.length<3) return null;
    return new Date(parts[0], parts[1]-1, parts[2]);
  }
  var sDate = parseDate(start);
  var eDate = parseDate(end);
  var now = new Date();
  if (!sDate || !eDate) {
    ctx.font='12px Arial'; ctx.textAlign='center'; ctx.textBaseline='middle'; ctx.fillText('Brak dat', 75,75); return;
  }
  var total = eDate - sDate;
  var elapsed = 0;
  if (total <= 0) elapsed = 1;
  else if (now < sDate) elapsed = 0;
  else if (now > eDate) elapsed = 1;
  else elapsed = (now - sDate) / total;
  var elapsedAngle = Math.max(0, Math.min(1, elapsed)) * Math.PI * 2;
  var cx = 75, cy = 75, r = 65;
  ctx.clearRect(0,0,150,150);
  ctx.beginPath(); ctx.moveTo(cx,cy);
  ctx.fillStyle = '#d9534f';
  ctx.arc(cx,cy,r, -Math.PI/2, -Math.PI/2 + elapsedAngle, false);
  ctx.closePath(); ctx.fill();
  ctx.beginPath(); ctx.moveTo(cx,cy);
  ctx.fillStyle = '#5cb85c';
  ctx.arc(cx,cy,r, -Math.PI/2 + elapsedAngle, -Math.PI/2 + Math.PI*2, false);
  ctx.closePath(); ctx.fill();
  ctx.beginPath(); ctx.fillStyle = '#fff'; ctx.arc(cx,cy,r*0.55,0,Math.PI*2); ctx.fill();
  ctx.fillStyle = '#333'; ctx.font='14px Arial'; ctx.textAlign='center'; ctx.textBaseline='middle';
  var percent = Math.round((1 - elapsed) * 100);
  ctx.fillText(percent + '%', cx, cy);
})();
</script>
<?php include 'includes/footer.php'; ?>


