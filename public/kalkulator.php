<?php
$pageTitle = "Kalkulator kampanii";
include 'includes/header.php';
require_once '../config/config.php';

$nazwy_miesiecy = [
    1 => 'Styczeń', 2 => 'Luty', 3 => 'Marzec', 4 => 'Kwiecień',
    5 => 'Maj', 6 => 'Czerwiec', 7 => 'Lipiec', 8 => 'Sierpień',
    9 => 'Wrzesień', 10 => 'Październik', 11 => 'Listopad', 12 => 'Grudzień'
];

// Pobierz cennik spotów
$stawki = $pdo->query("SELECT dlugosc, pasmo, stawka_netto FROM cennik_spoty")->fetchAll();
$cennikSpotow = [];
foreach ($stawki as $s) {
    $dlugosc = $s['dlugosc'];
    $pasmo = strtolower(str_replace(' Time', '', $s['pasmo']));
    $cennikSpotow[$dlugosc][$pasmo] = floatval($s['stawka_netto']);
}
?>

<div class="container mt-4">
    <h2>🧮 Kalkulator kampanii reklamowej</h2>

    <form id="kalkulator-form">
        <div class="row mb-3">
            <div class="col-md-6">
                <label for="klient" class="form-label">Klient</label>
                <input type="text" id="klient" name="klient_nazwa" class="form-control" autocomplete="off" required>
                <input type="hidden" id="klient_id" name="klient_id">
            </div>
            <div class="col-md-3">
                <label for="rok" class="form-label">Rok</label>
                <select name="rok" id="rok" class="form-select">
                    <?php for ($r = date('Y'); $r <= date('Y') + 1; $r++): ?>
                        <option value="<?= $r ?>"><?= $r ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="miesiac" class="form-label">Miesiąc</label>
                <select name="miesiac" id="miesiac" class="form-select">
                    <?php foreach ($nazwy_miesiecy as $m => $nazwa): ?>
                        <option value="<?= $m ?>" <?= $m == date('n') ? 'selected' : '' ?>><?= $nazwa ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-md-3">
                <label for="dlugosc" class="form-label">Długość spotu (sek)</label>
                <select name="dlugosc" id="dlugosc" class="form-select">
                    <option value="15">15</option>
                    <option value="20">20</option>
                    <option value="30">30</option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="data_start" class="form-label">Data rozpoczęcia</label>
                <input type="date" name="data_start" id="data_start" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label for="data_koniec" class="form-label">Data zakończenia</label>
                <input type="date" name="data_koniec" id="data_koniec" class="form-control" required>
            </div>
        </div>

        <div class="row mt-4">
            <!-- Kalkulacja (lewo) -->
            <div class="col-md-3">
                <h5>📊 Kalkulacja</h5>

                <div class="mb-2"><label class="form-label">Spoty Prime</label>
                    <input type="number" name="sumy[prime]" readonly class="form-control form-control-sm" value="0"></div>

                <div class="mb-2"><label class="form-label">Spoty Standard</label>
                    <input type="number" name="sumy[standard]" readonly class="form-control form-control-sm" value="0"></div>

                <div class="mb-2"><label class="form-label">Spoty Night</label>
                    <input type="number" name="sumy[night]" readonly class="form-control form-control-sm" value="0"></div>

                <hr class="my-2">

                <div class="mb-2"><label class="form-label">Netto spotów</label>
                    <input type="text" name="netto_spoty" readonly class="form-control form-control-sm" value="0.00 zł"></div>

                <div class="mb-2"><label class="form-label">Netto dodatków</label>
                    <input type="text" name="netto_dodatki" readonly class="form-control form-control-sm" value="0.00 zł"></div>

                <div class="mb-2"><label class="form-label">Rabat (%)</label>
                    <input type="number" name="rabat" class="form-control form-control-sm" min="0" max="100" value="0"></div>

                <div class="mb-2"><label class="form-label">Netto po rabacie</label>
                    <input type="text" name="razem_po_rabacie" readonly class="form-control form-control-sm" value="0.00 zł"></div>

                <div class="mb-2"><label class="form-label">Brutto</label>
                    <input type="text" name="razem_brutto" readonly class="form-control form-control-sm" value="0.00 zł"></div>
            </div>

            <!-- Siatka (środek) -->
            <div class="col-md-6">
                <div id="kontener-siatki"><!-- AJAX załaduje siatkę --></div>
            </div>

            <!-- Produkty dodatkowe (prawo) -->
            <div class="col-md-3">
                <h5>➕ Produkty dodatkowe</h5>
                <?php
                $produkty = [];
                $kategorie = [
                    'Wywiady sponsorowane' => 'cennik_wywiady',
                    'Kampanie display'     => 'cennik_display',
                    'Social media'         => 'cennik_social',
                    'Sygnały sponsorskie'  => 'cennik_sygnaly'
                ];
                foreach ($kategorie as $etykieta => $tabela) {
                    $produkty[$etykieta] = $pdo->query("SELECT * FROM $tabela ORDER BY id")->fetchAll();
                }
                foreach ($produkty as $nazwa_kategorii => $lista): ?>
                    <div class="mb-3 border rounded p-2 bg-light">
                        <strong><?= $nazwa_kategorii ?></strong>
                        <?php foreach ($lista as $p):
                            $id = $p['id'];
                            $nazwa = $p['nazwa'] ?? ($p['format'] ?? ($p['platforma'] ?? $p['typ_programu']));
                            $netto = $p['stawka_netto'];
                        ?>
                            <div class="mb-2">
                                <label class="form-label small"><?= htmlspecialchars($nazwa) ?> (<?= number_format($netto, 2, ',', ' ') ?> zł)</label>
                                <input type="number" name="dodatki[<?= $tabela ?>][<?= $id ?>]" min="0" value="0" step="1"
                                       class="form-control form-control-sm input-dodatek"
                                       data-cena="<?= $netto ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div> <!-- /.row -->
        <div class="mt-4 text-end">
            <button type="submit" class="btn btn-success">💾 Zapisz plan kampanii</button>
        </div>
    </form>
</div>

<script>
window.cennikSpotow = <?= json_encode($cennikSpotow) ?>;

function aktualizujSiatke() {
    const rok = document.getElementById("rok").value;
    const miesiac = document.getElementById("miesiac").value;

    fetch("ajax_siatka.php", {
        method: "POST",
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ rok, miesiac })
    })
    .then(response => response.text())
    .then(html => {
        document.getElementById("kontener-siatki").innerHTML = html;
        podlaczSumowanie();
    });
}

document.getElementById("rok").addEventListener("change", aktualizujSiatke);
document.getElementById("miesiac").addEventListener("change", aktualizujSiatke);
window.addEventListener("DOMContentLoaded", aktualizujSiatke);

function okresPasmo(godzina) {
    const h = parseInt(godzina.split(':')[0]);
    if ((h >= 6 && h < 10) || (h >= 15 && h < 19)) return 'prime';
    if ((h >= 10 && h < 15) || (h >= 19 && h < 23)) return 'standard';
    return 'night';
}

function podlaczSumowanie() {
    document.querySelectorAll('input[name^="emisja"]').forEach(input => {
        input.addEventListener('input', przeliczKampanie);
    });
    document.querySelectorAll('.input-dodatek').forEach(input => {
        input.addEventListener('input', przeliczKampanie);
    });
    document.querySelector('input[name="rabat"]').addEventListener('input', przeliczKampanie);
    przeliczKampanie();
}

function przeliczKampanie() {
    const dlugosc = document.getElementById("dlugosc").value;
    let sumy = { prime: 0, standard: 0, night: 0 };
    let wartosc = { prime: 0, standard: 0, night: 0 };

    document.querySelectorAll('input[name^="emisja"]').forEach(input => {
        const match = input.name.match(/emisja\[(\d+)\]\[(.+?)\]/);
        if (!match) return;
        const godzina = match[2];
        const ilosc = parseInt(input.value) || 0;
        const pasmo = okresPasmo(godzina);
        const cena = window.cennikSpotow?.[dlugosc]?.[pasmo] || 0;
        sumy[pasmo] += ilosc;
        wartosc[pasmo] += ilosc * cena;
    });

    document.querySelector('input[name="sumy[prime]"]').value = sumy.prime;
    document.querySelector('input[name="sumy[standard]"]').value = sumy.standard;
    document.querySelector('input[name="sumy[night]"]').value = sumy.night;

    const sumaSpotowNetto = wartosc.prime + wartosc.standard + wartosc.night;
    document.querySelector('input[name="netto_spoty"]').value = sumaSpotowNetto.toFixed(2) + " zł";

    // Dodatki
    let sumaDodatki = 0;
    document.querySelectorAll('.input-dodatek').forEach(input => {
        const ilosc = parseInt(input.value) || 0;
        const cena = parseFloat(input.dataset.cena);
        sumaDodatki += ilosc * cena;
    });
    document.querySelector('input[name="netto_dodatki"]').value = sumaDodatki.toFixed(2) + " zł";

    const nettoRazem = sumaSpotowNetto + sumaDodatki;
    const rabat = parseFloat(document.querySelector('input[name="rabat"]').value) || 0;
    const poRabacie = nettoRazem * (1 - rabat / 100);
    const brutto = poRabacie * 1.23;

    document.querySelector('input[name="razem_po_rabacie"]').value = poRabacie.toFixed(2) + " zł";
    document.querySelector('input[name="razem_brutto"]').value = brutto.toFixed(2) + " zł";
}
</script>

<?php include 'includes/footer.php'; ?>


