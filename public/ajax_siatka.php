<?php
require_once __DIR__ . '/includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    exit("Brak dostępu");
}

$rok = intval($_POST['rok'] ?? date('Y'));
$miesiac = intval($_POST['miesiac'] ?? date('n'));

// Ustal liczbę dni w miesiącu
$dni_w_miesiacu = cal_days_in_month(CAL_GREGORIAN, $miesiac, $rok);

// Lista godzin od 06:00 do 23:00 + pasmo >23:00
$godziny = [];
for ($h = 6; $h < 24; $h++) {
    $godziny[] = sprintf('%02d:00', $h);
}
$godziny[] = '>23:00';

// Polskie skróty dni tygodnia
$polskie_dni = [
    'Mon' => 'Pn',
    'Tue' => 'Wt',
    'Wed' => 'Śr',
    'Thu' => 'Cz',
    'Fri' => 'Pt',
    'Sat' => 'So',
    'Sun' => 'Nd'
];

// Nagłówek tabeli
echo '<h5 class="mb-3">📅 Siatka emisji – ' . $rok . ', miesiąc: ' . $miesiac . '</h5>';
echo '<div class="table-responsive">';
echo '<table class="table table-bordered text-center align-middle small">';
echo '<thead><tr><th>Godzina</th>';

// Generuj kolumny – dni miesiąca
for ($d = 1; $d <= $dni_w_miesiacu; $d++) {
    $data = mktime(0, 0, 0, $miesiac, $d, $rok);
    $skrot_dnia = date('D', $data);
    $dzien_tyg_pl = $polskie_dni[$skrot_dnia] ?? $skrot_dnia;
    $weekend = (date('N', $data) >= 6);
    $bg = $weekend ? '#ffe0e0' : '#f8f9fa';
    echo "<th style='background-color:$bg;'>$d<br><small>$dzien_tyg_pl</small></th>";
}
echo '</tr></thead><tbody>';

// Generuj wiersze – godziny
foreach ($godziny as $godzina) {
    echo "<tr><th>$godzina</th>";
    for ($d = 1; $d <= $dni_w_miesiacu; $d++) {
        echo "<td><input type='number' name='emisja[$d][$godzina]' value='0' min='0' class='form-control form-control-sm text-center' style='width: 60px;'></td>";
    }
    echo "</tr>";
}

echo '</tbody></table></div>';
