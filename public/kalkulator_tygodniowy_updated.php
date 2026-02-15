<?php
require_once __DIR__ . '../../config/config.php';
require_once __DIR__ . '/includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $klient_nazwa = $_POST['klient_nazwa'] ?? '';
        $dlugosc_spotu = $_POST['dlugosc_spotu'] ?? null;
        $data_start = $_POST['data_start'] ?? null;
        $data_koniec = $_POST['data_koniec'] ?? null;
        $rabat = $_POST['rabat'] ?? 0;
        $netto_spoty = $_POST['netto_spoty'] ?? 0;
        $netto_dodatki = $_POST['netto_dodatki'] ?? 0;
        $razem_netto = $_POST['razem_netto'] ?? 0;
        $razem_brutto = $_POST['razem_brutto'] ?? 0;
        $propozycja = isset($_POST['propozycja']) ? 1 : 0;

        $stmt = $pdo->prepare("INSERT INTO kampanie 
            (klient_nazwa, dlugosc_spotu, data_start, data_koniec, rabat, netto_spoty, netto_dodatki, razem_netto, razem_brutto, propozycja)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $klient_nazwa, $dlugosc_spotu, $data_start, $data_koniec, $rabat,
            $netto_spoty, $netto_dodatki, $razem_netto, $razem_brutto, $propozycja
        ]);

        echo '<div class="alert alert-success mt-3">✅ Kampania została zapisana pomyślnie.</div>';
    } catch (PDOException $e) {
        echo '<div class="alert alert-danger mt-3">❌ Błąd zapisu kampanii: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}
?>

<?php
require_once '../config/config.php'; // $pdo
$pageTitle = "Kalkulator kampanii – układ tygodniowy";

// Cennik z bazy → struktura dla JS
$mapPasmo = ['Prime Time'=>'prime','Standard Time'=>'standard','Night Time'=>'night'];
$cennikOut = [
  '15'=>['prime'=>0,'standard'=>0,'night'=>0],
  '20'=>['prime'=>0,'standard'=>0,'night'=>0],
  '30'=>['prime'=>0,'standard'=>0,'night'=>0],
];
try {
  $rows = $pdo->query("SELECT dlugosc, pasmo, stawka_netto FROM cennik_spoty")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) {
    $len = (string)$r['dlugosc'];
    $k = $mapPasmo[$r['pasmo']] ?? null;
    if ($k && isset($cennikOut[$len])) $cennikOut[$len][$k] = (float)$r['stawka_netto'];
  }
} catch(Throwable $e){/* pozostaw zera */}

// Godziny i dni
$godziny=[]; for($h=6;$h<=23;$h++) $godziny[]=sprintf('%02d:00',$h); $godziny[]='>23:00';
$dni=['mon'=>'Pon','tue'=>'Wt','wed'=>'Śr','thu'=>'Czw','fri'=>'Pt','sat'=>'Sob','sun'=>'Nd'];

?>
<script>window.cennikSpotow = <?= json_encode($cennikOut, JSON_UNESCAPED_UNICODE) ?>;</script>

<div class="container mt-4">
  <h2>🧮 Kalkulator kampanii – układ tygodniowy</h2>

  <form id="kalkulator-form" class="mt-3">
    <div class="row g-3 align-items-end">
      <div class="col-md-6">
        <label class="form-label">Nazwa klienta</label>
        <input type="text" id="klient_nazwa" name="klient_nazwa" class="form-control" placeholder="np. ELRO" required>
        <!-- opcjonalnie hidden, jeśli zapis ma sam tworzyć rekord klienta gdy id brak -->
        <input type="hidden" id="klient_id" name="klient_id" value="">
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
            <h5 class="card-title mb-3">📊 Kalkulacja</h5>
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
          <button type="button" id="btn-zapisz" class="btn btn-success">💾 Zapisz kampanię</button>
          <button type="button" id="btn-pdf" class="btn btn-outline-secondary">📄 Eksport do PDF</button>
        </div>
      </div>

      <div class="col-md-9">
        <h5 class="mb-2">🗓️ Emisja wg dni tygodnia</h5>
        <div class="table-responsive">
          <table class="table table-bordered table-sm align-middle text-center">
            <thead class="table-light">
              <tr>
                <th style="width:100px">Godzina</th>
                <?php foreach ($dni as $short=>$label): ?>
                  <th><?= $label ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($godziny as $g): ?>
                <tr>
                  <td><strong><?= htmlspecialchars($g) ?></strong></td>
                  <?php foreach ($dni as $short=>$label): ?>
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
    </div>

    <input type="hidden" id="emisja_json" name="emisja_json" value="">
  
<hr class="my-4">
<h5>Dodatkowe produkty</h5>
<div class="row">
  <div class="col-md-3 mb-3">
    <label for="display_ad" class="form-label">Reklama Display (zł)</label>
    <input type="number" class="form-control" name="display_ad" id="display_ad" min="0" step="0.01" value="0.00">
  </div>
  <div class="col-md-3 mb-3">
    <label for="sponsor_signal" class="form-label">Sygnał sponsorski (zł)</label>
    <input type="number" class="form-control" name="sponsor_signal" id="sponsor_signal" min="0" step="0.01" value="0.00">
  </div>
  <div class="col-md-3 mb-3">
    <label for="interview" class="form-label">Wywiad (zł)</label>
    <input type="number" class="form-control" name="interview" id="interview" min="0" step="0.01" value="0.00">
  </div>
  <div class="col-md-3 mb-3">
    <label for="social_media" class="form-label">Social Media (zł)</label>
    <input type="number" class="form-control" name="social_media" id="social_media" min="0" step="0.01" value="0.00">
  </div>
</div>

</form>
</div>

<?php include 'includes/footer.php'; ?>
<script>
// Pasmo wg godziny
function okresPasmo(godzina){
  if(godzina === '>23:00') return 'night';
  const h = parseInt(godzina.split(':')[0],10);
  if((h>=6 && h<10) || (h>=15 && h<19)) return 'prime';
  if((h>=10 && h<15) || (h>=19 && h<23)) return 'standard';
  return 'night';
}

// Liczba wystąpień dni tygodnia w zakresie dat
function liczDniTygodnia(startISO,endISO){
  const out={mon:0,tue:0,wed:0,thu:0,fri:0,sat:0,sun:0};
  if(!startISO||!endISO) return out;
  let d=new Date(startISO), e=new Date(endISO);
  if(isNaN(d)||isNaN(e)||d>e) return out;
  while(d<=e){
    const w=d.getDay(); // 0 nd…6 sob
    if(w===1) out.mon++; else if(w===2) out.tue++; else if(w===3) out.wed++;
    else if(w===4) out.thu++; else if(w===5) out.fri++; else if(w===6) out.sat++; else if(w===0) out.sun++;
    d.setDate(d.getDate()+1);
  }
  return out;
}

function przelicz(){
  const dl=document.getElementById('dlugosc').value||'20';
  const ds=document.getElementById('data_start').value;
  const dk=document.getElementById('data_koniec').value;
  const rab=parseFloat(document.getElementById('rabat').value||'0');
  const ceny=(window.cennikSpotow&&window.cennikSpotow[dl])?window.cennikSpotow[dl]:{prime:0,standard:0,night:0};
  const dni=liczDniTygodnia(ds,dk);

  const szt={prime:0,standard:0,night:0};
  const val={prime:0,standard:0,night:0};

  document.querySelectorAll('.emisja').forEach(inp=>{
    const m=inp.name.match(/emisja\[(.+?)\]\[(.+?)\]/);
    const ilosc=parseInt(inp.value||'0',10)||0;
    if(!m) return;
    const dzien=m[1], godz=m[2];
    const n=dni[dzien]||0;
    const total=ilosc*n;
    const pasmo=okresPasmo(godz);
    const cena=ceny[pasmo]||0;
    szt[pasmo]+=total;
    val[pasmo]+=total*cena;
  });

  const netto=val.prime+val.standard+val.night;
  const dodatki=0;
  const poR=(netto+dodatki)*(1-rab/100);
  const brutto=poR*1.23;

  document.querySelector('input[name="sumy[prime]"]').value=szt.prime;
  document.querySelector('input[name="sumy[standard]"]').value=szt.standard;
  document.querySelector('input[name="sumy[night]"]').value=szt.night;
  document.querySelector('input[name="netto_spoty"]').value=netto.toFixed(2)+' zł';
  document.querySelector('input[name="netto_dodatki"]').value=dodatki.toFixed(2)+' zł';
  document.querySelector('input[name="razem_po_rabacie"]').value=poR.toFixed(2)+' zł';
  document.querySelector('input[name="razem_brutto"]').value=brutto.toFixed(2)+' zł';
}

function siatkaJSON(){
  const o={};
  document.querySelectorAll('.emisja').forEach(el=>{
    const m=el.name.match(/emisja\[(.+?)\]\[(.+?)\]/);
    if(!m) return;
    const d=m[1], g=m[2];
    if(!o[d]) o[d]={};
    o[d][g]=el.value||'0';
  });
  return JSON.stringify(o);
}

document.addEventListener('DOMContentLoaded',()=>{
  // przeliczenia na zmiany
  document.querySelectorAll('.emisja, #dlugosc, #data_start, #data_koniec, #rabat')
    .forEach(el=>{ el.addEventListener('input',przelicz); el.addEventListener('change',przelicz); });
  przelicz();

  // Zapis kampanii
  document.getElementById('btn-zapisz').addEventListener('click',()=>{
    const f=document.getElementById('kalkulator-form');
    if(!f.klient_nazwa.value.trim()){ alert('Podaj nazwę klienta.'); return; }
    if(!f.data_start.value||!f.data_koniec.value){ alert('Uzupełnij daty.'); return; }

    // ukryte JSON siatki
    document.getElementById('emisja_json').value=siatkaJSON();

    // przygotuj POST
    const post=document.createElement('form');
    post.method='POST';
    post.action='zapisz_kampanie.php';

    const fields=[
      'klient_id','klient_nazwa','dlugosc','data_start','data_koniec','rabat',
      'emisja_json','netto_spoty','netto_dodatki','razem_po_rabacie','razem_brutto',
      'sumy[prime]','sumy[standard]','sumy[night]'
    ];
    fields.forEach(n=>{
      const src=f.querySelector(`[name="${CSS.escape(n)}"]`);
      if(!src) return;
      const h=document.createElement('input'); h.type='hidden'; h.name=n; h.value=src.value; post.appendChild(h);
    });

    document.body.appendChild(post);
    post.submit();
  });

  // Eksport PDF
  document.getElementById('btn-pdf').addEventListener('click',()=>{
    const f=document.getElementById('kalkulator-form');
    if(!f.data_start.value||!f.data_koniec.value){ alert('Uzupełnij daty.'); return; }
    przelicz(); // świeże liczby

    const pdf=document.createElement('form');
    pdf.method='POST';
    pdf.action='eksport_pdf.php';
    pdf.target='_blank';

    ['klient_nazwa','dlugosc','data_start','data_koniec','rabat',
     'sumy[prime]','sumy[standard]','sumy[night]',
     'netto_spoty','netto_dodatki','razem_po_rabacie','razem_brutto'
    ].forEach(n=>{
      const src=f.querySelector(`[name="${CSS.escape(n)}"]`);
      if(!src) return;
      const h=document.createElement('input'); h.type='hidden'; h.name=n; h.value=src.value; pdf.appendChild(h);
    });

    const ej=document.createElement('input');
    ej.type='hidden'; ej.name='emisja_json'; ej.value=siatkaJSON();
    pdf.appendChild(ej);

    document.body.appendChild(pdf);
    pdf.submit();
  });
});
</script>


