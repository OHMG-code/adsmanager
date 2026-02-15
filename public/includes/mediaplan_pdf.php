<?php
declare(strict_types=1);

/**
 * Wspólny generator PDF mediaplanu dla TCPDF/Dompdf.
 * Dostarcza funkcję generateMediaplanPdfBinary(PDO $pdo, int $kampaniaId).
 */

const DEFAULT_PRIME_HOURS    = '06:00-09:59,15:00-18:59';
const DEFAULT_STANDARD_HOURS = '10:00-14:59,19:00-22:59';
const DEFAULT_NIGHT_HOURS    = '00:00-05:59,23:00-23:59';

const PAGE_SIZE               = 'A4';
const PAGE_ORIENTATION_FRONT  = 'L';
const PAGE_ORIENTATION_TABLE  = 'L';
const MARGIN_L                = 12.0;
const MARGIN_R                = 12.0;
const MARGIN_T                = 14.0;
const MARGIN_B                = 14.0;
const SECTION_GAP             = 6.0;
const COLUMN_GAP              = 6.0;
const HEADER_GAP              = 3.0;
const FRONT_LEFT_RATIO        = 0.58;
const FONT_MAIN               = 'dejavusans';
const FONT_SIZE_TITLE         = 14;
const FONT_SIZE_BASE          = 10;
const FONT_SIZE_SECTION_TITLE = 12;
const FONT_SIZE_TABLE         = 8.5;
const ROW_H                   = 7.0;
const COL_HOUR_W              = 24.0;
const LINE_W                  = 0.2;
const COLOR_HEADER_FILL       = [240, 240, 240];
const COLOR_GRID              = [80, 80, 80];
const COLOR_TEXT              = [20, 20, 20];
const FRONT_INFO_VALUE_W      = 32.0;
const FRONT_LOGO_WIDTH        = 17.5;

function mediaplanPdfLibraryStatus(): array {
    static $status = null;
    if ($status !== null) {
        return $status;
    }

    $publicDir = dirname(__DIR__);
    $autoloadCandidates = [
        $publicDir . '/../vendor/autoload.php',
        $publicDir . '/vendor/autoload.php',
    ];
    foreach ($autoloadCandidates as $candidate) {
        if (file_exists($candidate)) {
            require_once $candidate;
            break;
        }
    }

    $useTCPDF = class_exists('TCPDF');
    $useDompdf = false;

    $tcpdfPathCandidates = [
        $publicDir . '/../vendor/tecnickcom/tcpdf/src/tcpdf.php',
        $publicDir . '/../vendor/tecnickcom/tcpdf/tcpdf.php',
        $publicDir . '/../vendor/tecnickcom/tcpdf/src/TCPDF.php',
        $publicDir . '/../vendor/tecnickcom/tcpdf/TCPDF.php',
        $publicDir . '/vendor/tecnickcom/tcpdf/src/tcpdf.php',
        $publicDir . '/vendor/tecnickcom/tcpdf/tcpdf.php',
        $publicDir . '/vendor/tecnickcom/tcpdf/src/TCPDF.php',
        $publicDir . '/vendor/tecnickcom/tcpdf/TCPDF.php',
        $publicDir . '/../tcpdf/tcpdf.php',
        $publicDir . '/tcpdf/tcpdf.php',
    ];

    if (!$useTCPDF) {
        foreach ($tcpdfPathCandidates as $path) {
            if (file_exists($path)) {
                require_once $path;
                if (class_exists('TCPDF')) {
                    $useTCPDF = true;
                    break;
                }
            }
        }
    }

    if (!$useTCPDF) {
        if (class_exists('\\Dompdf\\Dompdf')) {
            $useDompdf = true;
        } else {
            $dompdfCandidates = [
                $publicDir . '/../vendor/autoload.php',
                $publicDir . '/../vendor/dompdf/dompdf/src/Dompdf.php',
                $publicDir . '/vendor/dompdf/dompdf/src/Dompdf.php',
            ];
            foreach ($dompdfCandidates as $path) {
                if (file_exists($path)) {
                    require_once $path;
                    break;
                }
            }
            if (class_exists('\\Dompdf\\Dompdf')) {
                $useDompdf = true;
            }
        }
    }

    return $status = [
        'tcpdf'          => $useTCPDF,
        'dompdf'         => $useDompdf,
        'autoload_paths' => $autoloadCandidates,
        'tcpdf_paths'    => $tcpdfPathCandidates,
    ];
}

function fetchSystemConfig(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT * FROM konfiguracja_systemu WHERE id = 1 LIMIT 1");
        return $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        error_log('mediaplan_pdf: konfiguracja_systemu fetch failed: ' . $e->getMessage());
        return [];
    }
}

function normalizePasmoString(?string $value, string $fallback): string {
    $value = trim((string)$value);
    return $value !== '' ? $value : $fallback;
}

function timeStringToMinutes(string $time): int {
    if ($time === '>23:00') {
        return 23 * 60 + 59;
    }
    $parts = explode(':', $time);
    $h = (int)($parts[0] ?? 0);
    $m = (int)($parts[1] ?? 0);
    return max(0, min(23, $h)) * 60 + max(0, min(59, $m));
}

function parseRangeString(string $value): array {
    $value = str_replace([';', '|'], ',', $value);
    $parts = array_filter(array_map('trim', explode(',', $value)));
    $ranges = [];
    foreach ($parts as $part) {
        if (!preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $part, $m)) {
            continue;
        }
        $start = timeStringToMinutes($m[1] . ':' . $m[2]);
        $end = timeStringToMinutes($m[3] . ':' . $m[4]);
        if ($start <= $end) {
            $ranges[] = [$start, $end];
        } else {
            $ranges[] = [$start, 23 * 60 + 59];
            $ranges[] = [0, $end];
        }
    }
    return $ranges;
}

function buildPasmoRanges(array $cfg, string $defaultPrime, string $defaultStandard, string $defaultNight): array {
    $prime    = parseRangeString(normalizePasmoString($cfg['prime_hours'] ?? '', $defaultPrime));
    $standard = parseRangeString(normalizePasmoString($cfg['standard_hours'] ?? '', $defaultStandard));
    $night    = parseRangeString(normalizePasmoString($cfg['night_hours'] ?? '', $defaultNight));
    return [
        'prime'    => $prime,
        'standard' => $standard,
        'night'    => $night,
    ];
}

function resolveLogoPath(?string $webPath): ?string {
    if (!$webPath) {
        return null;
    }
    $publicDir = dirname(__DIR__);
    $full = $publicDir . '/' . ltrim($webPath, '/');
    return is_file($full) ? $full : null;
}

function logoToDataUri(?string $absolutePath): ?string {
    if (!$absolutePath || !is_file($absolutePath)) {
        return null;
    }
    $data = @file_get_contents($absolutePath);
    if ($data === false) {
        return null;
    }
    $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
    $mime = 'image/png';
    if ($ext === 'jpg' || $ext === 'jpeg') {
        $mime = 'image/jpeg';
    } elseif ($ext === 'svg') {
        $mime = 'image/svg+xml';
    }
    return 'data:' . $mime . ';base64,' . base64_encode($data);
}

function getContentWidth($pdf): float {
    return $pdf->getPageWidth() - MARGIN_L - MARGIN_R;
}

function renderFrontPage($pdf, array $docInfo, array $summaryRows, array $produkty, ?string $logoPath): void {
    $pdf->AddPage(PAGE_ORIENTATION_FRONT, PAGE_SIZE);
    $pdf->SetFont(FONT_MAIN, 'B', FONT_SIZE_TITLE);

    if ($logoPath) {
        try {
            $pdf->Image($logoPath, MARGIN_L, MARGIN_T, FRONT_LOGO_WIDTH);
        } catch (Throwable $e) {
            error_log('mediaplan_pdf: logo render failed: ' . $e->getMessage());
        }
    }

    $pdf->SetY(MARGIN_T);
    $pdf->Cell(0, 10, 'Plan emisyjny', 0, 1, 'L');
    $pdf->Ln(HEADER_GAP);

    $contentW = getContentWidth($pdf);
    $gap = COLUMN_GAP;
    $leftW = round($contentW * FRONT_LEFT_RATIO, 2);
    $rightW = $contentW - $leftW - $gap;
    $xLeft = MARGIN_L;
    $xRight = MARGIN_L + $leftW + $gap;
    $yTop = $pdf->GetY();

    [$tr, $tg, $tb] = COLOR_TEXT;
    [$gr, $gg, $gb] = COLOR_GRID;
    [$hr, $hg, $hb] = COLOR_HEADER_FILL;
    $pdf->SetTextColor($tr, $tg, $tb);
    $pdf->SetDrawColor($gr, $gg, $gb);

    $labelW = $leftW - FRONT_INFO_VALUE_W;
    $pdf->SetXY($xLeft, $yTop);
    $pdf->SetFont(FONT_MAIN, 'B', FONT_SIZE_SECTION_TITLE);
    $pdf->Cell($leftW, 7, 'Informacje o dokumencie', 1, 1, 'C');
    $pdf->SetFont(FONT_MAIN, '', FONT_SIZE_BASE);
    foreach ($docInfo as $row) {
        $pdf->SetX($xLeft);
        $pdf->Cell($labelW, 7, $row['label'], 1, 0, 'L');
        $pdf->Cell(FRONT_INFO_VALUE_W, 7, $row['value'], 1, 1, 'R');
    }
    $yLeftEnd = $pdf->GetY();

    $pdf->SetXY($xRight, $yTop);
    $pdf->SetFillColor($hr, $hg, $hb);
    $pdf->SetFont(FONT_MAIN, 'B', FONT_SIZE_SECTION_TITLE);
    $pdf->Cell($rightW, 7, 'Podsumowanie', 1, 1, 'C', true);
    $pdf->SetFont(FONT_MAIN, '', FONT_SIZE_BASE);
    $sumLabelW = round($rightW * 0.55, 2);
    $sumValueW = $rightW - $sumLabelW;
    foreach ($summaryRows as $row) {
        $pdf->SetX($xRight);
        $pdf->Cell($sumLabelW, 6, $row['label'], 1, 0, 'L');
        $pdf->Cell($sumValueW, 6, $row['value'], 1, 1, 'R');
    }
    $yRightEnd = $pdf->GetY();

    $pdf->SetY(max($yLeftEnd, $yRightEnd) + SECTION_GAP);

    $prodLines = [];
    foreach ($produkty as $p) {
        if (is_array($p)) {
            $prodLines[] = ($p['nazwa'] ?? 'Produkt') .
                (isset($p['kwota']) ? ' - ' . number_format((float)$p['kwota'], 2, ',', ' ') . ' zł' : '');
        } else {
            $prodLines[] = (string)$p;
        }
    }

    $contentW = getContentWidth($pdf);
    $pdf->SetFont(FONT_MAIN, 'B', FONT_SIZE_BASE);
    $pdf->Cell(0, 7, 'Usługi dodatkowe', 0, 1, 'L');
    $pdf->SetFont(FONT_MAIN, '', FONT_SIZE_BASE);
    $pdf->MultiCell($contentW, 6, $prodLines ? '• ' . implode("\n• ", $prodLines) : 'Brak dodatkowych usług', 0, 'L');
    $pdf->Ln(SECTION_GAP);
    $pdf->SetFont(FONT_MAIN, '', 8);
    $pdf->Cell(0, 6, 'Wygenerowano: ' . (new DateTime('now'))->format('Y-m-d H:i'), 0, 1, 'R');
}

function computeMonthColumnWidths($pdf, int $daysCount): array {
    $contentW = getContentWidth($pdf);
    $hourW = COL_HOUR_W;
    $days = [];
    if ($daysCount > 0) {
        $available = $contentW - $hourW;
        $base = $available / $daysCount;
        $baseRounded = floor($base * 100) / 100;
        for ($i = 0; $i < $daysCount - 1; $i++) {
            $days[] = $baseRounded;
        }
        $used = array_sum($days);
        $last = round(max($available - $used, 0), 2);
        $days[] = $last;
        $sumW = $hourW + array_sum($days);
        if (abs($sumW - $contentW) > 0.02) {
            error_log(sprintf('mediaplan_pdf: width mismatch content=%.2f sum=%.2f days=%d', $contentW, $sumW, $daysCount));
        }
    }
    return ['hour' => $hourW, 'days' => $days];
}

function renderMonthHeaderRow($pdf, array $days, float $hourW, array $daysW): void {
    $pdf->SetX(MARGIN_L);
    [$hr, $hg, $hb] = COLOR_HEADER_FILL;
    $pdf->SetFillColor($hr, $hg, $hb);
    [$gr, $gg, $gb] = COLOR_GRID;
    $pdf->SetDrawColor($gr, $gg, $gb);
    $pdf->SetFont(FONT_MAIN, 'B', FONT_SIZE_TABLE);
    $pdf->Cell($hourW, ROW_H, 'Godzina', 1, 0, 'C', true);
    foreach ($days as $index => $d) {
        if (!$d instanceof DateTime) {
            continue;
        }
        $label = plDzienSkrot($d) . ' ' . $d->format('d.m');
        $width = $daysW[$index] ?? 0;
        $pdf->Cell($width, ROW_H, $label, 1, 0, 'C', true);
    }
    $pdf->Ln();
    $pdf->SetFont(FONT_MAIN, '', FONT_SIZE_TABLE);
}

function renderMonthTable($pdf, string $title, array $days, array $sloty, array $siatka): void {
    if (empty($days)) {
        return;
    }

    $widths = computeMonthColumnWidths($pdf, count($days));
    $hourW = $widths['hour'];
    $daysW = $widths['days'];

    $pdf->SetCellPadding(0.8);
    $pdf->setCellHeightRatio(1.1);
    [$gr, $gg, $gb] = COLOR_GRID;
    $pdf->SetDrawColor($gr, $gg, $gb);
    [$tr, $tg, $tb] = COLOR_TEXT;
    $pdf->SetTextColor($tr, $tg, $tb);

    $renderPage = function (bool $isContinuation) use ($pdf, $title, $days, $hourW, $daysW): void {
        $pdf->AddPage(PAGE_ORIENTATION_TABLE, PAGE_SIZE);
        $pageTitle = $isContinuation ? $title . ' (cd.)' : $title;
        $pdf->SetFont(FONT_MAIN, 'B', FONT_SIZE_BASE);
        $pdf->Cell(0, 8, $pageTitle, 0, 1, 'L');
        $pdf->Ln(HEADER_GAP);
        renderMonthHeaderRow($pdf, $days, $hourW, $daysW);
        $pdf->SetFont(FONT_MAIN, '', FONT_SIZE_TABLE);
    };

    $renderPage(false);
    $pageHeight = $pdf->getPageHeight();

    foreach ($sloty as [$hStart, $hEnd]) {
        if ($pdf->GetY() + ROW_H > ($pageHeight - MARGIN_B)) {
            $renderPage(true);
            $pageHeight = $pdf->getPageHeight();
        }
        $pdf->SetX(MARGIN_L);
        $pdf->Cell($hourW, ROW_H, $hStart . '-' . $hEnd, 1, 0, 'C');
        foreach ($days as $index => $d) {
            $width = $daysW[$index] ?? 0;
            $val = $d instanceof DateTime ? spotsAt($siatka, $d, $hStart) : 0;
            $pdf->Cell($width, ROW_H, $val ? (string)$val : '', 1, 0, 'C');
        }
        $pdf->Ln();
    }
}

function dniOkresu(DateTime $start, DateTime $end): array {
    $days = [];
    $d = (clone $start);
    while ($d <= $end) {
        $days[] = clone $d;
        $d->modify('+1 day');
    }
    return $days;
}

function plDzienSkrot(DateTime $d): string {
    $map = ['Mon' => 'pn', 'Tue' => 'wt', 'Wed' => 'śr', 'Thu' => 'czw', 'Fri' => 'pt', 'Sat' => 'sob', 'Sun' => 'nd'];
    return $map[$d->format('D')] ?? $d->format('D');
}

function plMonthLabel(DateTime $d): string {
    $map = [
        1 => 'Styczeń', 2 => 'Luty', 3 => 'Marzec', 4 => 'Kwiecień',
        5 => 'Maj', 6 => 'Czerwiec', 7 => 'Lipiec', 8 => 'Sierpień',
        9 => 'Wrzesień', 10 => 'Październik', 11 => 'Listopad', 12 => 'Grudzień'
    ];
    $month = (int)$d->format('n');
    $label = $map[$month] ?? $d->format('F');
    return $label . ' ' . $d->format('Y');
}

function possibleSiatkaKeysForDate(DateTime $d): array {
    $keys = [];
    $keys[] = $d->format('j');
    $eng = strtolower($d->format('D'));
    $keys[] = substr($eng, 0, 3);
    $keys[] = $eng;
    $mapPl = ['Mon' => 'pon', 'Tue' => 'wt', 'Wed' => 'sr', 'Thu' => 'czw', 'Fri' => 'pt', 'Sat' => 'sob', 'Sun' => 'nd'];
    $pl = $mapPl[$d->format('D')] ?? null;
    if ($pl) {
        $keys[] = $pl;
    }
    $mapPlAlt = ['pon' => 'pn', 'wt' => 'wt', 'sr' => 'sr', 'czw' => 'czw', 'pt' => 'pt', 'sob' => 'sob', 'nd' => 'nd'];
    if ($pl && isset($mapPlAlt[$pl])) {
        $keys[] = $mapPlAlt[$pl];
    }
    $seen = [];
    $out = [];
    foreach ($keys as $k) {
        if ($k === null) {
            continue;
        }
        if (!isset($seen[$k])) {
            $seen[$k] = 1;
            $out[] = $k;
        }
    }
    return $out;
}

function spotsAt(array $siatka, DateTime $d, string $hhmm): int {
    $possible = possibleSiatkaKeysForDate($d);
    foreach ($possible as $key) {
        if (isset($siatka[$key]) && is_array($siatka[$key]) && array_key_exists($hhmm, $siatka[$key])) {
            return (int)$siatka[$key][$hhmm];
        }
        if (isset($siatka[$key]) && is_array($siatka[$key])) {
            $val = $siatka[$key][$hhmm] ?? null;
            if (is_array($val)) {
                $sum = 0;
                foreach ($val as $sub) {
                    $sum += (int)$sub;
                }
                return $sum;
            }
        }
    }
    return 0;
}

function godzinySloty(string $from = '06:00', string $to = '22:00'): array {
    $out = [];
    [$fh] = array_map('intval', explode(':', $from));
    [$th] = array_map('intval', explode(':', $to));
    for ($h = $fh; $h <= $th; $h++) {
        $start = sprintf('%02d:00', $h);
        $end   = sprintf('%02d:00', $h + 1);
        $out[] = [$start, $end];
    }
    return $out;
}

function normalizeDowKey(?string $value): ?string {
    if ($value === null) {
        return null;
    }
    $value = trim(strtolower($value));
    if ($value === '') {
        return null;
    }
    $map = [
        'monday' => 'mon', 'mon' => 'mon', 'pon' => 'mon', 'pn' => 'mon',
        'tuesday' => 'tue', 'tue' => 'tue', 'wt' => 'tue',
        'wednesday' => 'wed', 'wed' => 'wed', 'sr' => 'wed', 'śr' => 'wed', 'sro' => 'wed',
        'thursday' => 'thu', 'thu' => 'thu', 'czw' => 'thu',
        'friday' => 'fri', 'fri' => 'fri', 'pt' => 'fri',
        'saturday' => 'sat', 'sat' => 'sat', 'sob' => 'sat',
        'sunday' => 'sun', 'sun' => 'sun', 'nd' => 'sun', 'niedziela' => 'sun',
    ];
    if (isset($map[$value])) {
        return $map[$value];
    }
    $short = substr($value, 0, 3);
    return $map[$short] ?? $short;
}

function mapDbHourToSlot(?string $time): ?string {
    if ($time === null) {
        return null;
    }
    $time = trim($time);
    if ($time === '') {
        return null;
    }
    if ($time === '23:59:59') {
        return '>23:00';
    }
    if (preg_match('/^\d{2}:\d{2}/', $time, $m)) {
        return $m[0];
    }
    return $time;
}

function buildSiatkaFromEmisjeRows(array $rows): array {
    $siatka = [];
    foreach ($rows as $row) {
        $day = normalizeDowKey($row['dzien_tygodnia'] ?? null);
        $slot = mapDbHourToSlot($row['godzina'] ?? null);
        if (!$day || !$slot) {
            continue;
        }
        if (!isset($siatka[$day])) {
            $siatka[$day] = [];
        }
        $siatka[$day][$slot] = (int)($row['ilosc'] ?? 0);
    }
    return $siatka;
}

function sumujSpoty(array $siatka, array $dni, array $sloty): int {
    $sum = 0;
    foreach ($dni as $d) {
        foreach ($sloty as [$hh]) {
            $sum += spotsAt($siatka, $d, $hh);
        }
    }
    return $sum;
}

function groupDaysByMonth(array $dni): array {
    $groups = [];
    foreach ($dni as $d) {
        if (!$d instanceof DateTime) {
            continue;
        }
        $key = $d->format('Y-m');
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'label' => plMonthLabel($d),
                'days'  => [],
            ];
        }
        $groups[$key]['days'][] = clone $d;
    }
    return $groups;
}

function prepareMediaplanData(PDO $pdo, int $kampaniaId): array {
    $systemConfig = fetchSystemConfig($pdo);
    $pasmoRanges = buildPasmoRanges($systemConfig, DEFAULT_PRIME_HOURS, DEFAULT_STANDARD_HOURS, DEFAULT_NIGHT_HOURS);
    $logoAbsolutePath = resolveLogoPath($systemConfig['pdf_logo_path'] ?? null);
    $logoDataUri = logoToDataUri($logoAbsolutePath);

    $kampania = null;
    $emisjeRows = [];

    try {
        $stmt = $pdo->prepare('SELECT id, klient_id, klient_nazwa, dlugosc_spotu, data_start, data_koniec, netto_spoty, netto_dodatki, rabat, razem_netto, razem_brutto FROM kampanie WHERE id = :id');
        if ($stmt && $stmt->execute([':id' => $kampaniaId])) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $kampania = $row;
                $emisjeStmt = $pdo->prepare('SELECT dzien_tygodnia, godzina, ilosc FROM kampanie_emisje WHERE kampania_id = :id');
                if ($emisjeStmt && $emisjeStmt->execute([':id' => $kampaniaId])) {
                    $emisjeRows = $emisjeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
            }
        }
    } catch (Throwable $e) {
        error_log('mediaplan_pdf: kampanie query failed: ' . $e->getMessage());
    }

    $isLegacy = false;
    if (!$kampania) {
        $isLegacy = true;
        try {
            $stmt = $pdo->prepare('SELECT id, NULL AS klient_id, klient_nazwa, dlugosc, data_start, data_koniec, netto_spoty, netto_dodatki, rabat, razem_po_rabacie, razem_brutto, sumy, produkty, siatka FROM kampanie_tygodniowe WHERE id = :id');
            if ($stmt && $stmt->execute([':id' => $kampaniaId])) {
                $kampania = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        } catch (Throwable $e) {
            error_log('mediaplan_pdf: kampanie_tygodniowe query failed: ' . $e->getMessage());
        }
    }

    if (!$kampania) {
        throw new RuntimeException('Nie znaleziono kampanii');
    }

    if (!$isLegacy) {
        $kampania['dlugosc'] = (int)($kampania['dlugosc_spotu'] ?? 0);
        if (!isset($kampania['razem_po_rabacie'])) {
            $kampania['razem_po_rabacie'] = $kampania['razem_netto'] ?? null;
        }
    }

    $produkty = [];
    $siatka = [];
    if ($isLegacy) {
        $produkty = json_decode($kampania['produkty'] ?? '[]', true) ?: [];
        $siatka   = json_decode($kampania['siatka'] ?? 'null', true) ?: [];
    } else {
        $siatka = buildSiatkaFromEmisjeRows($emisjeRows);
    }

    $klient = (string)$kampania['klient_nazwa'];
    $dlugoscSek = (int)($kampania['dlugosc'] ?? 0);
    try {
        $dataStart = new DateTime($kampania['data_start']);
        $dataKoniec = new DateTime($kampania['data_koniec']);
    } catch (Throwable $e) {
        throw new RuntimeException('Nieprawidłowe daty kampanii');
    }

    $sloty = godzinySloty('06:00', '22:00');
    $dni = dniOkresu($dataStart, $dataKoniec);
    $dniByMonth = groupDaysByMonth($dni);
    $liczbaSpotow = sumujSpoty($siatka, $dni, $sloty);

    $nettoSpoty = (float)($kampania['netto_spoty'] ?? 0);
    $nettoDod = (float)($kampania['netto_dodatki'] ?? 0);
    $rabatPct = (float)($kampania['rabat'] ?? 0);
    $poRabacie = (float)($kampania['razem_po_rabacie'] ?? 0);
    $brutto = (float)($kampania['razem_brutto'] ?? 0);

    $docInfo = [
        ['label' => 'Klient', 'value' => $klient],
        ['label' => 'Okres od', 'value' => $dataStart->format('Y-m-d')],
        ['label' => 'Okres do', 'value' => $dataKoniec->format('Y-m-d')],
        ['label' => 'Długość spotu', 'value' => $dlugoscSek . ' sek'],
        ['label' => 'ID kampanii', 'value' => '#' . (int)$kampania['id']],
    ];

    $summaryRows = [
        ['label' => 'Ilość spotów', 'value' => number_format($liczbaSpotow, 0, ',', ' ')],
        ['label' => 'Wartość spotów (netto)', 'value' => number_format($nettoSpoty, 2, ',', ' ') . ' zł'],
        ['label' => 'Usługi dodatkowe (netto)', 'value' => number_format($nettoDod, 2, ',', ' ') . ' zł'],
        ['label' => 'RABAT', 'value' => number_format($rabatPct, 2, ',', ' ') . ' %'],
        ['label' => 'Wartość po rabacie (netto)', 'value' => number_format($poRabacie, 2, ',', ' ') . ' zł'],
        ['label' => 'Brutto', 'value' => number_format($brutto, 2, ',', ' ') . ' zł'],
    ];

    return [
        'system_config'    => $systemConfig,
        'logo_path'        => $logoAbsolutePath,
        'logo_data_uri'    => $logoDataUri,
        'kampania'         => $kampania,
        'kampania_id'      => (int)$kampania['id'],
        'klient_id'        => isset($kampania['klient_id']) ? (int)$kampania['klient_id'] : null,
        'is_legacy'        => $isLegacy,
        'produkty'         => $produkty,
        'siatka'           => $siatka,
        'sloty'            => $sloty,
        'dni'              => $dni,
        'dni_by_month'     => $dniByMonth,
        'summary_rows'     => $summaryRows,
        'doc_info'         => $docInfo,
        'klient'           => $klient,
        'dlugosc'          => $dlugoscSek,
        'data_start'       => $dataStart,
        'data_koniec'      => $dataKoniec,
        'liczba_spotow'    => $liczbaSpotow,
        'netto_spoty'      => $nettoSpoty,
        'netto_dodatki'    => $nettoDod,
        'rabat_pct'        => $rabatPct,
        'po_rabacie'       => $poRabacie,
        'brutto'           => $brutto,
        'pasmo_ranges'     => $pasmoRanges,
        'emisje_rows'      => $emisjeRows,
    ];
}

function renderMediaplanTcpdf(array $data): string {
    $status = mediaplanPdfLibraryStatus();
    if (!$status['tcpdf']) {
        throw new RuntimeException('TCPDF niedostępny');
    }

    $pdf = new TCPDF(PAGE_ORIENTATION_FRONT, 'mm', PAGE_SIZE, true, 'UTF-8', false);
    $pdf->SetCreator('Radio Żuławy');
    $pdf->SetAuthor('Radio Żuławy');
    $pdf->SetTitle('Plan emisyjny – kampania #' . $data['kampania_id']);
    $pdf->SetSubject('Mediaplan');
    $pdf->SetMargins(MARGIN_L, MARGIN_T, MARGIN_R);
    $pdf->SetAutoPageBreak(true, MARGIN_B);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetLineWidth(LINE_W);

    renderFrontPage($pdf, $data['doc_info'], $data['summary_rows'], $data['produkty'], $data['logo_path']);

    foreach ($data['dni_by_month'] as $group) {
        $chunks = array_chunk($group['days'], 31);
        foreach ($chunks as $chunkIndex => $chunkDays) {
            $title = 'Kampania #' . $data['kampania_id'] . ' – ' . $group['label'] . ($chunkIndex > 0 ? ' (cd.)' : '');
            renderMonthTable($pdf, $title, $chunkDays, $data['sloty'], $data['siatka']);
        }
    }

    return $pdf->Output('mediaplan_kampania_' . $data['kampania_id'] . '.pdf', 'S');
}

function renderMediaplanDompdf(array $data): string {
    if (!class_exists('\\Dompdf\\Dompdf')) {
        throw new RuntimeException('Brak biblioteki Dompdf');
    }

    $html = '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family: DejaVu Sans, Arial, sans-serif;font-size:12px;}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ccc;padding:4px;text-align:center}th{background:#f0f0f0}</style></head><body>';
    if ($data['logo_data_uri']) {
        $html .= '<div style="text-align:right;margin-bottom:10px"><img src="' . htmlspecialchars($data['logo_data_uri']) . '" alt="Logo" style="max-height:60px;"></div>';
    }
    $html .= '<h2>Plan emisyjny</h2>';
    $html .= '<div>Klient: ' . htmlspecialchars($data['klient']) . '</div>';
    $html .= '<div>Okres: ' . $data['data_start']->format('Y-m-d') . ' – ' . $data['data_koniec']->format('Y-m-d') . ' • Długość spotu: ' . $data['dlugosc'] . ' sek</div>';
    $html .= '<div>ID kampanii: #' . $data['kampania_id'] . '</div>';

    $html .= '<h3>Podsumowanie</h3><div style="display:flex;gap:20px">';
    $html .= '<div style="flex:0 0 50%">';
    $html .= '<p>Ilość spotów: ' . number_format($data['liczba_spotow'], 0, ',', ' ') . '</p>';
    $html .= '<p>Wartość spotów (netto): ' . number_format($data['netto_spoty'], 2, ',', ' ') . ' zł</p>';
    $html .= '<p>Usługi dodatkowe (netto): ' . number_format($data['netto_dodatki'], 2, ',', ' ') . ' zł</p>';
    $html .= '<p>RABAT: ' . number_format($data['rabat_pct'], 2, ',', ' ') . ' %</p>';
    $html .= '<p>Wartość po rabacie (netto): ' . number_format($data['po_rabacie'], 2, ',', ' ') . ' zł</p>';
    $html .= '<p>Brutto: ' . number_format($data['brutto'], 2, ',', ' ') . ' zł</p>';
    $html .= '</div>';
    $html .= '<div style="flex:1">';
    $html .= '<p>Usługi dodatkowe:</p><ul>';
    foreach ($data['produkty'] as $p) {
        if (is_array($p)) {
            $html .= '<li>' . htmlspecialchars($p['nazwa'] ?? 'Produkt') . '</li>';
        } else {
            $html .= '<li>' . htmlspecialchars((string)$p) . '</li>';
        }
    }
    $html .= '</ul></div></div>';

    $html .= '<h3>Siatka emisji</h3>';
    $html .= '<style>table.mediaplan{border-collapse:collapse;width:100%;table-layout:fixed;font-size:10px;margin-bottom:14px}table.mediaplan th,table.mediaplan td{border:1px solid #ccc;padding:3px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}table.mediaplan th{background:#f0f0f0;font-size:9px}</style>';
    foreach ($data['dni_by_month'] as $group) {
        if (empty($group['days'])) {
            continue;
        }
        $html .= '<div style="page-break-before: always">';
        $html .= '<h4 style="margin-bottom:4px">Kampania #' . $data['kampania_id'] . ' – ' . htmlspecialchars($group['label']) . '</h4>';
        $html .= '<table class="mediaplan"><thead><tr><th style="width:8%">Godzina</th>';
        $dayCount = count($group['days']) ?: 1;
        $dayWidthPct = max(6, (int)floor(92 / $dayCount));
        foreach ($group['days'] as $d) {
            $html .= '<th style="width:' . $dayWidthPct . '%">' . htmlspecialchars(plDzienSkrot($d)) . '<br/>' . $d->format('d.m') . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($data['sloty'] as [$hStart, $hEnd]) {
            $html .= '<tr><td>' . htmlspecialchars($hStart . '-' . $hEnd) . '</td>';
            foreach ($group['days'] as $d) {
                $val = spotsAt($data['siatka'], $d, $hStart);
                $html .= '<td>' . ($val ? (int)$val : '') . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
    }

    $html .= '<div style="text-align:right;font-size:10px;margin-top:10px">Wygenerowano: ' . (new DateTime('now'))->format('Y-m-d H:i') . '</div>';
    $html .= '</body></html>';

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->loadHtml($html);
    $dompdf->render();
    return $dompdf->output();
}

function generateMediaplanPdfBinary(PDO $pdo, int $kampaniaId): string {
    $status = mediaplanPdfLibraryStatus();
    if (!$status['tcpdf'] && !$status['dompdf']) {
        throw new RuntimeException('Brak bibliotek TCPDF/Dompdf');
    }
    $data = prepareMediaplanData($pdo, $kampaniaId);
    if ($status['tcpdf']) {
        return renderMediaplanTcpdf($data);
    }
    return renderMediaplanDompdf($data);
}
