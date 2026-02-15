<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

function pdfEscape(string $value): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

function buildFallbackPdf(array $lines): string
{
    $yStart = 800;
    $lineHeight = 16;
    $commands = ["BT /F1 12 Tf 40 {$yStart} Td"];

    $index = 0;
    foreach ($lines as $line) {
        $safe = pdfEscape($line);
        if ($index === 0) {
            $commands[] = "({$safe}) Tj";
        } else {
            $commands[] = "0 -{$lineHeight} Td ({$safe}) Tj";
        }
        $index++;
    }
    $commands[] = 'ET';
    $stream = implode("\n", $commands) . "\n";

    $objects = [
        "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
        "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
        "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>\nendobj\n",
        "4 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream\nendobj\n",
        "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $obj) {
        $offsets[] = strlen($pdf);
        $pdf .= $obj;
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }

    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n{$xrefOffset}\n%%EOF";

    return $pdf;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PDF export endpoint. Use POST to generate PDF.';
    exit;
}

$klient = trim((string)($_POST['klient_nazwa'] ?? ''));
$dlugosc = trim((string)($_POST['dlugosc'] ?? ''));
$dataStart = trim((string)($_POST['data_start'] ?? ''));
$dataKoniec = trim((string)($_POST['data_koniec'] ?? ''));
$rabat = (float)($_POST['rabat'] ?? 0);
$sumy = $_POST['sumy'] ?? [];
$nettoSpoty = trim((string)($_POST['netto_spoty'] ?? '0'));
$nettoDodatki = trim((string)($_POST['netto_dodatki'] ?? '0'));
$razemPoRabacie = trim((string)($_POST['razem_po_rabacie'] ?? '0'));
$razemBrutto = trim((string)($_POST['razem_brutto'] ?? '0'));
$spoty = json_decode((string)($_POST['emisja_json'] ?? '{}'), true);
if (!is_array($spoty)) {
    $spoty = [];
}

$dompdfAutoload = __DIR__ . '/../vendor/dompdf/autoload.inc.php';
if (is_file($dompdfAutoload)) {
    require_once $dompdfAutoload;
    if (class_exists('Dompdf\\Dompdf')) {
        $dni = ['Pon' => 'mon', 'Wt' => 'tue', 'Śr' => 'wed', 'Czw' => 'thu', 'Pt' => 'fri', 'Sob' => 'sat', 'Nd' => 'sun'];
        $godziny = [];
        for ($h = 6; $h <= 23; $h++) {
            $godziny[] = sprintf('%02d:00', $h);
        }
        $godziny[] = '>23:00';

        ob_start();
        ?>
<style>
body { font-family: DejaVu Sans, sans-serif; font-size: 10px; }
table { border-collapse: collapse; width: 100%; margin-top: 10px; }
th, td { border: 1px solid #aaa; padding: 4px; text-align: center; }
th { background-color: #f2f2f2; }
</style>
<h2>PLAN EMISJI REKLAM</h2>
<p><strong>Klient:</strong> <?= htmlspecialchars($klient) ?> | <strong>Okres:</strong> <?= htmlspecialchars($dataStart) ?> - <?= htmlspecialchars($dataKoniec) ?> | <strong>Spot:</strong> <?= htmlspecialchars($dlugosc) ?> sek</p>
<table>
  <thead>
    <tr>
      <th>Godzina</th>
      <?php foreach ($dni as $nazwa => $kod): ?>
      <th><?= htmlspecialchars($nazwa) ?></th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($godziny as $godzina): ?>
    <tr>
      <td><strong><?= htmlspecialchars($godzina) ?></strong></td>
      <?php foreach ($dni as $kod): ?>
      <td><?= htmlspecialchars((string)($spoty[$kod][$godzina] ?? '')) ?></td>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<h3>Podsumowanie</h3>
<p>Prime: <?= htmlspecialchars((string)($sumy['prime'] ?? 0)) ?> | Standard: <?= htmlspecialchars((string)($sumy['standard'] ?? 0)) ?> | Night: <?= htmlspecialchars((string)($sumy['night'] ?? 0)) ?></p>
<p>Netto spotow: <?= htmlspecialchars($nettoSpoty) ?> | Netto dodatkow: <?= htmlspecialchars($nettoDodatki) ?> | Rabat: <?= htmlspecialchars((string)$rabat) ?>%</p>
<p>Po rabacie (netto): <?= htmlspecialchars($razemPoRabacie) ?> | Brutto: <?= htmlspecialchars($razemBrutto) ?></p>
<?php
        $html = ob_get_clean();
        $dompdf = new Dompdf\Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="kampania_' . date('Ymd_His') . '.pdf"');
        echo $dompdf->output();
        exit;
    }
}

// Fallback: minimalny poprawny PDF bez zewnętrznych bibliotek.
$lines = [
    'PLAN EMISJI REKLAM',
    'Klient: ' . ($klient !== '' ? $klient : '-'),
    'Okres: ' . ($dataStart !== '' ? $dataStart : '-') . ' - ' . ($dataKoniec !== '' ? $dataKoniec : '-'),
    'Spot: ' . ($dlugosc !== '' ? $dlugosc : '-') . ' sek',
    'Prime/Standard/Night: ' . (string)($sumy['prime'] ?? 0) . '/' . (string)($sumy['standard'] ?? 0) . '/' . (string)($sumy['night'] ?? 0),
    'Netto spotow: ' . $nettoSpoty,
    'Netto dodatkow: ' . $nettoDodatki,
    'Rabat: ' . (string)$rabat . '%',
    'Po rabacie (netto): ' . $razemPoRabacie,
    'Brutto: ' . $razemBrutto,
];

$pdfBinary = buildFallbackPdf($lines);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="kampania_' . date('Ymd_His') . '.pdf"');
header('Content-Length: ' . strlen($pdfBinary));
echo $pdfBinary;
exit;
