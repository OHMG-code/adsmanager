<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();
$csrfToken = getCsrfToken();
$pageTitle = "Kalkulator kampanii - układ tygodniowy";

// Fallback zapisu, gdyby formularz wyslal POST bezposrednio na ten plik
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['klient_nazwa'])) {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        echo '<div class="alert alert-danger mt-3">Niepoprawny token formularza.</div>';
    } else {
        try {
            $klient_nazwa   = $_POST['klient_nazwa'] ?? '';
            $dlugosc_spotu  = $_POST['dlugosc'] ?? null;
            $data_start     = $_POST['data_start'] ?? null;
            $data_koniec    = $_POST['data_koniec'] ?? null;
            $rabat          = $_POST['rabat'] ?? 0;
            $netto_spoty    = $_POST['netto_spoty'] ?? 0;
            $netto_dodatki  = $_POST['netto_dodatki'] ?? 0;
            $razem_po_rab   = $_POST['razem_po_rabacie'] ?? 0;
            $razem_brutto   = $_POST['razem_brutto'] ?? 0;
            $kampaniaStatus = strtolower(trim((string)($_POST['kampania_status'] ?? 'aktywna')));
            $isProposal = $kampaniaStatus === 'propozycja';

            $stmt = $pdo->prepare("INSERT INTO kampanie 
                (klient_nazwa, dlugosc_spotu, data_start, data_koniec, rabat, netto_spoty, netto_dodatki, razem_netto, razem_brutto, propozycja)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $stmt->execute([
                $klient_nazwa,
                $dlugosc_spotu,
                $data_start,
                $data_koniec,
                $rabat,
                $netto_spoty,
                $netto_dodatki,
                $razem_po_rab,
                $razem_brutto,
                $isProposal ? 1 : 0
            ]);

            echo '<div class="alert alert-success mt-3">Kampania została zapisana.</div>';
        } catch (Throwable $e) {
            echo '<div class="alert alert-danger mt-3">Błąd zapisu kampanii: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

// Cennik spotow do kalkulacji
$mapPasmo = ['Prime Time' => 'prime', 'Standard Time' => 'standard', 'Night Time' => 'night'];
$cennikOut = [
    '15' => ['prime' => 0, 'standard' => 0, 'night' => 0],
    '20' => ['prime' => 0, 'standard' => 0, 'night' => 0],
    '30' => ['prime' => 0, 'standard' => 0, 'night' => 0],
];
try {
    $rows = $pdo->query("SELECT dlugosc, pasmo, stawka_netto FROM cennik_spoty")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $len = (string)$r['dlugosc'];
        $k   = $mapPasmo[$r['pasmo']] ?? null;
        if ($k && isset($cennikOut[$len])) {
            $cennikOut[$len][$k] = (float)$r['stawka_netto'];
        }
    }
} catch (Throwable $e) { /* zostaw domyslne zera */ }

// Cennik dodatkow (netto za sztuke) - bierzemy pierwsza stawke z kazdej tabeli
$cennikDodatki = [
    'display_ad'     => 0.0,
    'sponsor_signal' => 0.0,
    'interview'      => 0.0,
    'social_media'   => 0.0,
];
try { $v = $pdo->query("SELECT stawka_netto FROM cennik_display ORDER BY id LIMIT 1")->fetchColumn(); if ($v !== false) $cennikDodatki['display_ad'] = (float)$v; } catch (Throwable $e) {}
try { $v = $pdo->query("SELECT stawka_netto FROM cennik_sygnaly ORDER BY id LIMIT 1")->fetchColumn(); if ($v !== false) $cennikDodatki['sponsor_signal'] = (float)$v; } catch (Throwable $e) {}
try { $v = $pdo->query("SELECT stawka_netto FROM cennik_wywiady ORDER BY id LIMIT 1")->fetchColumn(); if ($v !== false) $cennikDodatki['interview'] = (float)$v; } catch (Throwable $e) {}
try { $v = $pdo->query("SELECT stawka_netto FROM cennik_social ORDER BY id LIMIT 1")->fetchColumn(); if ($v !== false) $cennikDodatki['social_media'] = (float)$v; } catch (Throwable $e) {}

$patronatOptions = [];
try {
    $patronatOptions = $pdo->query("SELECT id, pozycja, stawka_netto FROM cennik_patronat ORDER BY stawka_netto ASC, pozycja ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $patronatOptions = [];
}
$prefilledPatronatId = isset($_GET['patronat_id']) ? max(0, (int)$_GET['patronat_id']) : 0;
if ($prefilledPatronatId === 0 && !empty($patronatOptions)) {
    $prefilledPatronatId = (int)($patronatOptions[0]['id'] ?? 0);
}

// Godziny i dni
$godziny = [];
for ($h = 6; $h <= 23; $h++) {
    $godziny[] = sprintf('%02d:00', $h);
}
$godziny[] = '>23:00';
$dni = ['mon' => 'Pon', 'tue' => 'Wt', 'wed' => 'Sr', 'thu' => 'Czw', 'fri' => 'Pt', 'sat' => 'Sob', 'sun' => 'Nd'];
$kampaniaTygodniowaId = isset($_GET['weekly_id']) ? max(0, (int)$_GET['weekly_id']) : 0;
$sourceLeadId = isset($_GET['lead_id']) ? max(0, (int)$_GET['lead_id']) : 0;
$sourceLeadName = trim((string)($_GET['lead_name'] ?? ''));
$sourceLeadNip = trim((string)($_GET['lead_nip'] ?? ''));
$sourceClientId = isset($_GET['client_id']) ? max(0, (int)$_GET['client_id']) : 0;
$sourceClientName = trim((string)($_GET['client_name'] ?? ''));
$sourceClientNip = trim((string)($_GET['client_nip'] ?? ''));

// Dla kampanii tworzonej z klienta zawsze dociagaj dane identyfikacyjne z bazy po client_id.
if ($sourceClientId > 0) {
    try {
        $stmtClientContext = $pdo->prepare("
            SELECT
                k.nazwa_firmy,
                k.nip,
                c.name_full AS company_name_full,
                c.name_short AS company_name_short
            FROM klienci k
            LEFT JOIN companies c ON c.id = k.company_id
            WHERE k.id = :id
            LIMIT 1
        ");
        $stmtClientContext->execute([':id' => $sourceClientId]);
        $clientContextRow = $stmtClientContext->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($clientContextRow) {
            if ($sourceClientName === '') {
                $sourceClientName = trim((string)($clientContextRow['nazwa_firmy'] ?? ''));
                if ($sourceClientName === '') {
                    $sourceClientName = trim((string)($clientContextRow['company_name_full'] ?? ''));
                }
                if ($sourceClientName === '') {
                    $sourceClientName = trim((string)($clientContextRow['company_name_short'] ?? ''));
                }
            }
            if ($sourceClientNip === '') {
                $sourceClientNip = trim((string)($clientContextRow['nip'] ?? ''));
            }
        }
    } catch (Throwable $e) {
        // Bezpieczny fallback: pozostaw wartosci z URL.
    }
}

$leadContextVisible = $sourceLeadId > 0 || $sourceLeadName !== '' || $sourceLeadNip !== '';
$clientContextVisible = $sourceClientId > 0 || $sourceClientName !== '' || $sourceClientNip !== '';
$prefilledClientName = $sourceLeadName !== '' ? $sourceLeadName : $sourceClientName;
$prefilledClientId = $sourceClientId > 0 ? $sourceClientId : 0;
$campaignVariant = strtolower(trim((string)($_GET['kampania_status'] ?? 'aktywna')));
if (!in_array($campaignVariant, ['aktywna', 'propozycja'], true)) {
    $campaignVariant = 'aktywna';
}
require_once __DIR__ . '/includes/header.php';
?>

<script>
  window.cennikSpotow = <?= json_encode($cennikOut, JSON_UNESCAPED_UNICODE) ?>;
  window.cennikDodatki = <?= json_encode($cennikDodatki, JSON_UNESCAPED_UNICODE) ?>;
  window.cennikPatronat = <?= json_encode($patronatOptions, JSON_UNESCAPED_UNICODE) ?>;
</script>

<div class="mt-4">
  <h2>Kalkulator kampanii - układ tygodniowy</h2>

  <?php if ($leadContextVisible): ?>
    <div class="alert alert-info mt-3">
      Kampania dla leada:
      <strong><?= htmlspecialchars($sourceLeadName !== '' ? $sourceLeadName : ('Lead #' . $sourceLeadId)) ?></strong>
      <?php if ($sourceLeadNip !== ''): ?>
        (NIP: <?= htmlspecialchars($sourceLeadNip) ?>)
      <?php endif; ?>
    </div>
  <?php elseif ($clientContextVisible): ?>
    <div class="alert alert-info mt-3">
      Kampania dla klienta:
      <strong><?= htmlspecialchars($sourceClientName !== '' ? $sourceClientName : ('Klient #' . $sourceClientId)) ?></strong>
      <?php if ($sourceClientNip !== ''): ?>
        (NIP: <?= htmlspecialchars($sourceClientNip) ?>)
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($_SESSION['flash'])): ?>
    <?php $f = $_SESSION['flash']; unset($_SESSION['flash']); ?>
    <div class="alert alert-<?= htmlspecialchars($f['type'] ?? 'info') ?> mt-3">
      <?= htmlspecialchars($f['msg'] ?? '') ?>
    </div>
  <?php endif; ?>

  <form id="kalkulator-form" class="mt-3">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <div class="row g-3 align-items-end">
      <div class="col-md-6">
        <label class="form-label">Nazwa klienta</label>
        <input type="text" id="klient_nazwa" name="klient_nazwa" class="form-control" placeholder="np. ELRO" value="<?= htmlspecialchars($prefilledClientName) ?>" required>
        <input type="hidden" id="klient_id" name="klient_id" value="<?= (int)$prefilledClientId ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">Status kampanii</label>
        <select id="kampania_status" name="kampania_status" class="form-select">
          <option value="aktywna" <?= $campaignVariant === 'aktywna' ? 'selected' : '' ?>>Aktywna</option>
          <option value="propozycja" <?= $campaignVariant === 'propozycja' ? 'selected' : '' ?>>Propozycja</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Długość spotu (sek)</label>
        <select id="dlugosc" name="dlugosc" class="form-select">
          <option value="15">15</option>
          <option value="20" selected>20</option>
          <option value="30">30</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Rabat (%)</label>
        <input type="number" id="rabat" name="rabat" class="form-control" value="0" min="0" max="100">
      </div>
    </div>

    <div class="row g-3 align-items-end mt-2">
      <div class="col-md-3">
        <label class="form-label">Data rozpoczęcia</label>
        <input type="date" id="data_start" name="data_start" class="form-control" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Data zakończenia</label>
        <input type="date" id="data_koniec" name="data_koniec" class="form-control" required>
      </div>
    </div>

    <div class="row mt-4">
      <div class="col-md-3">
        <div class="card border-0 shadow-sm">
          <div class="card-body">
            <h5 class="card-title mb-3">Kalkulacja</h5>
            <div class="mb-2"><label class="form-label mb-0">Spoty Prime</label>
              <input type="number" name="sumy[prime]" class="form-control form-control-sm" readonly value="0">
            </div>
            <div class="mb-2"><label class="form-label mb-0">Spoty Standard</label>
              <input type="number" name="sumy[standard]" class="form-control form-control-sm" readonly value="0">
            </div>
            <div class="mb-2"><label class="form-label mb-0">Spoty Night</label>
              <input type="number" name="sumy[night]" class="form-control form-control-sm" readonly value="0">
            </div>
            <hr>
            <div class="mb-2"><label class="form-label mb-0">Netto spotów</label>
              <input type="text" name="netto_spoty" class="form-control form-control-sm" readonly value="0.00 zł">
            </div>
            <div class="mb-2"><label class="form-label mb-0">Netto dodatków</label>
              <input type="text" name="netto_dodatki" class="form-control form-control-sm" readonly value="0.00 zł">
            </div>
            <div class="mb-2"><label class="form-label mb-0">Netto po rabacie</label>
              <input type="text" name="razem_po_rabacie" class="form-control form-control-sm" readonly value="0.00 zł">
            </div>
            <div class="mb-2"><label class="form-label mb-0">Brutto (23% VAT)</label>
              <input type="text" name="razem_brutto" class="form-control form-control-sm" readonly value="0.00 zł">
            </div>
          </div>
        </div>

        <div class="d-grid gap-2 mt-3">
          <button type="button" id="btn-zapisz" class="btn btn-success">Zapisz kampanię</button>
          <button type="button" id="btn-pdf" class="btn btn-outline-secondary">Eksport do PDF</button>
          <button type="button" id="btn-podglad" class="btn btn-outline-primary">Podgląd kampanii</button>
        </div>
      </div>

      <div class="col-md-9">
        <div class="row g-3">
          <div class="col-lg-8">
            <h5 class="mb-2">Emisja wg dni tygodnia</h5>
            <div class="table-responsive">
              <table class="table table-bordered table-sm align-middle text-center">
                <thead class="table-light">
                  <tr>
                    <th style="width:100px">Godzina</th>
                    <?php foreach ($dni as $short => $label): ?>
                      <th><?= $label ?></th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($godziny as $g): ?>
                    <tr>
                      <td><strong><?= htmlspecialchars($g) ?></strong></td>
                      <?php foreach ($dni as $short => $label): ?>
                        <td>
                          <input type="number"
                                 class="form-control form-control-sm text-center emisja"
                                 name="emisja[<?= $short ?>][<?= htmlspecialchars($g) ?>]"
                                 value="0" min="0" step="1">
                        </td>
                      <?php endforeach; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <h6 class="mb-3">Dodatkowe produkty (ilość x stawka z cennika)</h6>

                <div class="mb-3">
                  <label class="form-label mb-1">Reklama Display</label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">ilość</span>
                    <input type="number" class="form-control addon-qty" data-key="display_ad" min="0" step="1" value="0">
                    <span class="input-group-text">x <?= number_format((float)$cennikDodatki['display_ad'], 2, '.', ' ') ?> zł</span>
                  </div>
                  <small class="text-muted">Kwota: <span data-addon-preview="display_ad">0.00 zł</span></small>
                </div>

                <div class="mb-3">
                  <label class="form-label mb-1">Sygnał sponsorski</label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">ilość</span>
                    <input type="number" class="form-control addon-qty" data-key="sponsor_signal" min="0" step="1" value="0">
                    <span class="input-group-text">x <?= number_format((float)$cennikDodatki['sponsor_signal'], 2, '.', ' ') ?> zł</span>
                  </div>
                  <small class="text-muted">Kwota: <span data-addon-preview="sponsor_signal">0.00 zł</span></small>
                </div>

                <div class="mb-3">
                  <label class="form-label mb-1">Wywiad</label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">ilość</span>
                    <input type="number" class="form-control addon-qty" data-key="interview" min="0" step="1" value="0">
                    <span class="input-group-text">x <?= number_format((float)$cennikDodatki['interview'], 2, '.', ' ') ?> zł</span>
                  </div>
                  <small class="text-muted">Kwota: <span data-addon-preview="interview">0.00 zł</span></small>
                </div>

                <div class="mb-1">
                  <label class="form-label mb-1">Social Media</label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">ilość</span>
                    <input type="number" class="form-control addon-qty" data-key="social_media" min="0" step="1" value="0">
                    <span class="input-group-text">x <?= number_format((float)$cennikDodatki['social_media'], 2, '.', ' ') ?> zł</span>
                  </div>
                  <small class="text-muted">Kwota: <span data-addon-preview="social_media">0.00 zł</span></small>
                </div>

                <div class="mb-1 mt-3">
                  <label class="form-label mb-1">Patronat medialny</label>
                  <select id="patronat_medialny_id" name="patronat_medialny_id" class="form-select form-select-sm">
                    <option value="0" data-netto="0">Brak patronatu</option>
                    <?php foreach ($patronatOptions as $patronat): ?>
                      <?php
                      $patronatId = (int)($patronat['id'] ?? 0);
                      $patronatName = trim((string)($patronat['pozycja'] ?? ''));
                      $patronatNetto = (float)($patronat['stawka_netto'] ?? 0);
                      ?>
                      <option value="<?= $patronatId ?>" data-netto="<?= number_format($patronatNetto, 2, '.', '') ?>" <?= $prefilledPatronatId === $patronatId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($patronatName !== '' ? $patronatName : ('Wariant #' . $patronatId)) ?> — <?= number_format($patronatNetto, 2, ',', ' ') ?> zł
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <small class="text-muted">Kwota: <span data-addon-preview="patronat_medialny">0.00 zł</span></small>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <input type="hidden" id="emisja_json" name="emisja_json" value="">
    <input type="hidden" name="nazwa_kampanii" value="">
    <input type="hidden" name="kampania_tygodniowa_id" value="<?= (int)$kampaniaTygodniowaId ?>">
    <input type="hidden" name="source_lead_id" value="<?= (int)$sourceLeadId ?>">
    <input type="hidden" name="source_lead_nip" value="<?= htmlspecialchars($sourceLeadNip) ?>">
    <input type="hidden" name="display_ad" value="0">
    <input type="hidden" name="sponsor_signal" value="0">
    <input type="hidden" name="interview" value="0">
    <input type="hidden" name="social_media" value="0">
    <input type="hidden" name="patronat_medialny" value="0">
    <input type="hidden" name="display_ad_qty" value="0">
    <input type="hidden" name="sponsor_signal_qty" value="0">
    <input type="hidden" name="interview_qty" value="0">
    <input type="hidden" name="social_media_qty" value="0">
    <input type="hidden" name="patronat_medialny_qty" value="0">

  </form>
</div>

<?php include 'includes/footer.php'; ?>

<script>
function okresPasmo(godzina) {
  if (godzina === '>23:00') return 'night';
  const h = parseInt(godzina.split(':')[0], 10);
  if ((h >= 6 && h < 10) || (h >= 15 && h < 19)) return 'prime';
  if ((h >= 10 && h < 15) || (h >= 19 && h < 23)) return 'standard';
  return 'night';
}

function liczDniTygodnia(startISO, endISO) {
  const out = { mon: 0, tue: 0, wed: 0, thu: 0, fri: 0, sat: 0, sun: 0 };
  if (!startISO || !endISO) return out;
  let d = new Date(startISO), e = new Date(endISO);
  if (isNaN(d) || isNaN(e) || d > e) return out;
  while (d <= e) {
    const w = d.getDay();
    if (w === 1) out.mon++; else if (w === 2) out.tue++; else if (w === 3) out.wed++;
    else if (w === 4) out.thu++; else if (w === 5) out.fri++; else if (w === 6) out.sat++; else if (w === 0) out.sun++;
    d.setDate(d.getDate() + 1);
  }
  return out;
}

// Sumuje dodatki na podstawie ilosci x cena z cennika i zapisuje kwoty do ukrytych pol
function sumaDodatkow() {
  const ceny = window.cennikDodatki || {};
  let sum = 0;

  document.querySelectorAll('.addon-qty').forEach(inp => {
    const key = inp.dataset.key;
    const qty = parseFloat(inp.value || '0') || 0;
    const cena = parseFloat(ceny[key] || '0') || 0;
    const kwota = qty * cena;
    sum += kwota;

    const hidden = document.querySelector(`input[name="${CSS.escape(key)}"]`);
    if (hidden) hidden.value = kwota.toFixed(2);
    const qtyHidden = document.querySelector(`input[name="${CSS.escape(key + '_qty')}"]`);
    if (qtyHidden) qtyHidden.value = qty;
    const preview = document.querySelector(`[data-addon-preview="${CSS.escape(key)}"]`);
    if (preview) preview.textContent = kwota.toFixed(2) + ' zł';
  });

  const patronatSelect = document.getElementById('patronat_medialny_id');
  const patronatHidden = document.querySelector('input[name="patronat_medialny"]');
  const patronatIdField = document.querySelector('[name="patronat_medialny_id"]');
  const patronatQtyHidden = document.querySelector('input[name="patronat_medialny_qty"]');
  const patronatPreview = document.querySelector('[data-addon-preview="patronat_medialny"]');
  let patronatKwota = 0;
  if (patronatSelect) {
    const selectedOption = patronatSelect.options[patronatSelect.selectedIndex] || null;
    patronatKwota = parseFloat(selectedOption ? (selectedOption.getAttribute('data-netto') || '0') : '0') || 0;
    const selectedId = parseInt(patronatSelect.value || '0', 10) || 0;
    if (patronatIdField) patronatIdField.value = String(selectedId);
    if (patronatQtyHidden) patronatQtyHidden.value = selectedId > 0 ? '1' : '0';
  } else if (patronatQtyHidden) {
    patronatQtyHidden.value = '0';
  }
  if (patronatHidden) patronatHidden.value = patronatKwota.toFixed(2);
  if (patronatPreview) patronatPreview.textContent = patronatKwota.toFixed(2) + ' zł';
  sum += patronatKwota;

  return sum;
}

function przelicz() {
  const dl = document.getElementById('dlugosc').value || '20';
  const ds = document.getElementById('data_start').value;
  const dk = document.getElementById('data_koniec').value;
  const rab = parseFloat(document.getElementById('rabat').value || '0');
  const ceny = (window.cennikSpotow && window.cennikSpotow[dl]) ? window.cennikSpotow[dl] : { prime: 0, standard: 0, night: 0 };
  const dni = liczDniTygodnia(ds, dk);

  const szt = { prime: 0, standard: 0, night: 0 };
  const val = { prime: 0, standard: 0, night: 0 };

  document.querySelectorAll('.emisja').forEach(inp => {
    const m = inp.name.match(/emisja\[(.+?)\]\[(.+?)\]/);
    const ilosc = parseInt(inp.value || '0', 10) || 0;
    if (!m) return;
    const dzien = m[1], godz = m[2];
    const n = dni[dzien] || 0;
    const total = ilosc * n;
    const pasmo = okresPasmo(godz);
    const cena = ceny[pasmo] || 0;
    szt[pasmo] += total;
    val[pasmo] += total * cena;
  });

  const netto = val.prime + val.standard + val.night;
  const dodatki = sumaDodatkow();
  const poR = (netto + dodatki) * (1 - rab / 100);
  const brutto = poR * 1.23;

  document.querySelector('input[name="sumy[prime]"]').value = szt.prime;
  document.querySelector('input[name="sumy[standard]"]').value = szt.standard;
  document.querySelector('input[name="sumy[night]"]').value = szt.night;
  document.querySelector('input[name="netto_spoty"]').value = netto.toFixed(2) + ' zł';
  document.querySelector('input[name="netto_dodatki"]').value = dodatki.toFixed(2) + ' zł';
  document.querySelector('input[name="razem_po_rabacie"]').value = poR.toFixed(2) + ' zł';
  document.querySelector('input[name="razem_brutto"]').value = brutto.toFixed(2) + ' zł';
}

function siatkaJSON() {
  const o = {};
  document.querySelectorAll('.emisja').forEach(el => {
    const m = el.name.match(/emisja\[(.+?)\]\[(.+?)\]/);
    if (!m) return;
    const d = m[1], g = m[2];
    if (!o[d]) o[d] = {};
    o[d][g] = el.value || '0';
  });
  return JSON.stringify(o);
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('#kalkulator-form input, #kalkulator-form select, #kalkulator-form textarea')
    .forEach(el => { el.addEventListener('input', przelicz); el.addEventListener('change', przelicz); });
  przelicz();

  // Zapis kampanii
  document.getElementById('btn-zapisz').addEventListener('click', () => {
    const f = document.getElementById('kalkulator-form');
    if (!f.klient_nazwa.value.trim()) { alert('Podaj nazwę klienta.'); return; }
    if (!f.data_start.value || !f.data_koniec.value) { alert('Uzupełnij daty.'); return; }

    document.getElementById('emisja_json').value = siatkaJSON();
    const nameField = f.querySelector('input[name="nazwa_kampanii"]');
    if (nameField) {
      nameField.value = `${f.klient_nazwa.value.trim()} ${f.data_start.value} - ${f.data_koniec.value}`;
    }

    const post = document.createElement('form');
    post.method = 'POST';
    post.action = 'zapisz_kampanie.php';

    const fields = [
      'csrf_token',
      'klient_id', 'klient_nazwa', 'kampania_status', 'dlugosc', 'data_start', 'data_koniec', 'rabat',
      'emisja_json', 'netto_spoty', 'netto_dodatki', 'razem_po_rabacie', 'razem_brutto',
      'nazwa_kampanii', 'kampania_tygodniowa_id',
      'source_lead_id', 'source_lead_nip',
      'display_ad', 'sponsor_signal', 'interview', 'social_media', 'patronat_medialny', 'patronat_medialny_id',
      'display_ad_qty', 'sponsor_signal_qty', 'interview_qty', 'social_media_qty', 'patronat_medialny_qty',
      'sumy[prime]', 'sumy[standard]', 'sumy[night]'
    ];
    fields.forEach(n => {
      const src = f.querySelector(`[name="${CSS.escape(n)}"]`);
      if (!src) return;
      const h = document.createElement('input'); h.type = 'hidden'; h.name = n; h.value = src.value; post.appendChild(h);
    });

    document.body.appendChild(post);
    post.submit();
  });

  // Eksport PDF
  document.getElementById('btn-pdf').addEventListener('click', () => {
    const f = document.getElementById('kalkulator-form');
    if (!f.data_start.value || !f.data_koniec.value) { alert('Uzupełnij daty.'); return; }
    przelicz();

    const pdf = document.createElement('form');
    pdf.method = 'POST';
    pdf.action = 'eksport_pdf.php';
    pdf.target = '_blank';

    ['klient_nazwa', 'dlugosc', 'data_start', 'data_koniec', 'rabat',
     'sumy[prime]', 'sumy[standard]', 'sumy[night]',
     'netto_spoty', 'netto_dodatki', 'razem_po_rabacie', 'razem_brutto'
    ].forEach(n => {
      const src = f.querySelector(`[name="${CSS.escape(n)}"]`);
      if (!src) return;
      const h = document.createElement('input'); h.type = 'hidden'; h.name = n; h.value = src.value; pdf.appendChild(h);
    });

    const ej = document.createElement('input');
    ej.type = 'hidden'; ej.name = 'emisja_json'; ej.value = siatkaJSON();
    pdf.appendChild(ej);

    ['display_ad', 'sponsor_signal', 'interview', 'social_media', 'patronat_medialny', 'patronat_medialny_id',
     'display_ad_qty', 'sponsor_signal_qty', 'interview_qty', 'social_media_qty', 'patronat_medialny_qty'
    ].forEach(n => {
      const src = f.querySelector(`[name="${CSS.escape(n)}"]`);
      if (!src) return;
      const h = document.createElement('input'); h.type = 'hidden'; h.name = n; h.value = src.value; pdf.appendChild(h);
    });

    document.body.appendChild(pdf);
    pdf.submit();
  });

  document.getElementById('btn-podglad').addEventListener('click', () => {
    window.location.href = 'kampanie_lista.php';
  });
});
</script>
