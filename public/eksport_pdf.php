<?php
require_once __DIR__ . '/includes/config.php';
use Dompdf\Dompdf;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PDF export endpoint. Use POST to generate PDF.';
    exit;
}

$dompdfAutoload = __DIR__ . '/../vendor/dompdf/autoload.inc.php';
if (!is_file($dompdfAutoload)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PDF library (dompdf) not available in this environment.';
    exit;
}
require_once $dompdfAutoload;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Dane podstawowe z formularza
    $klient = htmlspecialchars($_POST['klient_nazwa']);
    $dlugosc = $_POST['dlugosc'];
    $dataStart = $_POST['data_start'];
    $dataKoniec = $_POST['data_koniec'];
    $rabat = floatval($_POST['rabat']);
    $spoty = json_decode($_POST['emisja_json'], true);
    $sumy = $_POST['sumy'];
    $netto_spoty = $_POST['netto_spoty'];
    $netto_dodatki = $_POST['netto_dodatki'];
    $razem_po_rabacie = $_POST['razem_po_rabacie'];
    $razem_brutto = $_POST['razem_brutto'];

    // Ustawienie HTML
    $dni = ['Pon'=>'mon','Wt'=>'tue','Śr'=>'wed','Czw'=>'thu','Pt'=>'fri','Sob'=>'sat','Nd'=>'sun'];
    $godziny = [];
    for ($h = 6; $h <= 23; $h++) $godziny[] = sprintf('%02d:00', $h);
    $godziny[] = '>23:00';

    ob_start();
?>
<style>
body { font-family: DejaVu Sans, sans-serif; font-size: 10px; }
table { border-collapse: collapse; width: 100%; margin-top: 10px; }
th, td { border: 1px solid #aaa; padding: 4px; text-align: center; }
th { background-color: #f2f2f2; }
h2, h3 { margin: 0; padding: 0; }
ul { list-style: none; padding-left: 0; }
</style>

<h2>PLAN EMISJI REKLAM</h2>
<p><strong>Klient:</strong> <?= $klient ?> | <strong>Okres:</strong> <?= $dataStart ?> – <?= $dataKoniec ?> | <strong>Spot:</strong> <?= $dlugosc ?> sek</p>

<table>
<thead>
<tr>
    <th>Godzina</th>
    <?php foreach ($dni as $nazwa => $kod): ?>
        <th><?= $nazwa ?></th>
    <?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php foreach ($godziny as $godzina): ?>
<tr>
    <td><strong><?= $godzina ?></strong></td>
    <?php foreach ($dni as $kod): ?>
        <td><?= isset($spoty[$kod][$godzina]) && $spoty[$kod][$godzina] > 0 ? $spoty[$kod][$godzina] : '' ?></td>
    <?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<h3>Podsumowanie</h3>
<table>
    <tr>
        <th>Typ</th>
        <th>Ilość</th>
    </tr>
    <tr><td>Spoty Prime</td><td><?= $sumy['prime'] ?></td></tr>
    <tr><td>Spoty Standard</td><td><?= $sumy['standard'] ?></td></tr>
    <tr><td>Spoty Night</td><td><?= $sumy['night'] ?></td></tr>
</table>

<h3>Koszty</h3>
<table>
    <tr><td>Netto spotów:</td><td><?= $netto_spoty ?></td></tr>
    <tr><td>Netto dodatków:</td><td><?= $netto_dodatki ?></td></tr>
    <tr><td>Rabat:</td><td><?= $rabat ?>%</td></tr>
    <tr><td>Po rabacie (netto):</td><td><?= $razem_po_rabacie ?></td></tr>
    <tr><td><strong>Brutto:</strong></td><td><strong><?= $razem_brutto ?></strong></td></tr>
</table>
<?php
    $html = ob_get_clean();
    $dompdf = new Dompdf();
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape'); // Układ poziomy
    $dompdf->render();
    $dompdf->stream("kampania_" . date('Ymd_His') . ".pdf", ["Attachment" => true]);
    exit;
}
?>
