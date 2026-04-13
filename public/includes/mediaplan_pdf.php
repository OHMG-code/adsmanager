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
    $hasGd = extension_loaded('gd');

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

    return $status = [
        'tcpdf'          => $useTCPDF,
        'dompdf'         => $useDompdf,
        'gd'             => $hasGd,
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

    $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
    if ($ext === 'png' && !extension_loaded('gd')) {
        return convertPngLogoToJpegDataUri($absolutePath);
    }

    $data = @file_get_contents($absolutePath);
    if ($data === false) {
        return null;
    }
    $mime = 'image/png';
    if ($ext === 'jpg' || $ext === 'jpeg') {
        $mime = 'image/jpeg';
    } elseif ($ext === 'svg') {
        $mime = 'image/svg+xml';
    }
    return 'data:' . $mime . ';base64,' . base64_encode($data);
}

function logoToFileUri(?string $absolutePath): ?string {
    if (!$absolutePath || !is_file($absolutePath)) {
        return null;
    }
    return 'file://' . $absolutePath;
}

function convertPngLogoToJpegDataUri(string $absolutePath): ?string {
    $siblingJpg = preg_replace('/\.png$/i', '.jpg', $absolutePath);
    if (is_string($siblingJpg) && $siblingJpg !== '' && is_file($siblingJpg)) {
        $jpegData = @file_get_contents($siblingJpg);
        if ($jpegData !== false && $jpegData !== '') {
            return 'data:image/jpeg;base64,' . base64_encode($jpegData);
        }
    }

    $storageDir = dirname(__DIR__) . '/../storage';
    if (!is_dir($storageDir) && !@mkdir($storageDir, 0775, true)) {
        return null;
    }

    $hash = md5($absolutePath . '|' . (string)@filemtime($absolutePath));
    $targetPath = rtrim($storageDir, '/\\') . '/logo_cache_' . $hash . '.jpg';
    if (!is_file($targetPath) || @filemtime($targetPath) < @filemtime($absolutePath)) {
        $pythonCandidates = ['/usr/bin/python3', 'python3'];
        $converted = false;
        foreach ($pythonCandidates as $python) {
            $cmd = escapeshellcmd($python) . ' -c ' . escapeshellarg(
                'from PIL import Image; import sys; Image.open(sys.argv[1]).convert("RGB").save(sys.argv[2], "JPEG", quality=90)'
            ) . ' ' . escapeshellarg($absolutePath) . ' ' . escapeshellarg($targetPath) . ' 2>/dev/null';
            $output = [];
            $code = 1;
            @exec($cmd, $output, $code);
            if ($code === 0 && is_file($targetPath)) {
                $converted = true;
                break;
            }
        }
        if (!$converted) {
            return null;
        }
    }

    $jpegData = @file_get_contents($targetPath);
    if ($jpegData === false || $jpegData === '') {
        return null;
    }
    return 'data:image/jpeg;base64,' . base64_encode($jpegData);
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

function tableHasColumnMediaplan(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '` LIKE :col');
        $stmt->execute([':col' => $column]);
        $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function normalizeDateOnly(?string $raw): ?DateTimeImmutable {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return null;
    }
    $datePart = substr($raw, 0, 10);
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $datePart);
    if ($dt instanceof DateTimeImmutable) {
        return $dt;
    }
    try {
        return new DateTimeImmutable($raw);
    } catch (Throwable $e) {
        return null;
    }
}

function countDowOccurrences(?DateTimeImmutable $start, ?DateTimeImmutable $end): array {
    $result = ['mon' => 1, 'tue' => 1, 'wed' => 1, 'thu' => 1, 'fri' => 1, 'sat' => 1, 'sun' => 1];
    if (!$start || !$end || $start > $end) {
        return $result;
    }
    $result = ['mon' => 0, 'tue' => 0, 'wed' => 0, 'thu' => 0, 'fri' => 0, 'sat' => 0, 'sun' => 0];
    $map = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
    for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
        $key = $map[(int)$d->format('N')] ?? null;
        if ($key) {
            $result[$key]++;
        }
    }
    return $result;
}

function pasmoForHourLabel(string $hour): string {
    $hour = trim($hour);
    if ($hour === '>23:00') {
        return 'night';
    }
    if (!preg_match('/^(\d{2}):(\d{2})$/', $hour, $m)) {
        return 'night';
    }
    $h = (int)$m[1];
    if (($h >= 6 && $h < 10) || ($h >= 15 && $h < 19)) {
        return 'prime';
    }
    if (($h >= 10 && $h < 15) || ($h >= 19 && $h < 23)) {
        return 'standard';
    }
    return 'night';
}

function buildWeeklyHours(array $siatka): array {
    $hours = [];
    for ($h = 6; $h <= 23; $h++) {
        $hours[] = sprintf('%02d:00', $h);
    }
    $hours[] = '>23:00';
    foreach ($siatka as $day => $rows) {
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $hour => $qty) {
            if ((int)$qty <= 0) {
                continue;
            }
            if (!in_array($hour, $hours, true)) {
                $hours[] = $hour;
            }
        }
    }
    usort($hours, static function (string $a, string $b): int {
        if ($a === '>23:00') {
            return 1;
        }
        if ($b === '>23:00') {
            return -1;
        }
        return strcmp($a, $b);
    });
    return $hours;
}

function calculateBandSumsFromSiatka(array $siatka, array $dowOccurrences): array {
    $sumy = ['prime' => 0, 'standard' => 0, 'night' => 0];
    $total = 0;
    foreach ($siatka as $day => $rows) {
        if (!is_array($rows)) {
            continue;
        }
        $multiplier = (int)($dowOccurrences[$day] ?? 1);
        if ($multiplier <= 0) {
            $multiplier = 1;
        }
        foreach ($rows as $hour => $qty) {
            $qtyInt = (int)$qty;
            if ($qtyInt <= 0) {
                continue;
            }
            $sumy[pasmoForHourLabel((string)$hour)] += $qtyInt * $multiplier;
            $total += $qtyInt * $multiplier;
        }
    }
    return ['sumy' => $sumy, 'total' => $total];
}

function resolveCreatorLabel(PDO $pdo, array $kampania): string {
    $creatorId = 0;
    if (isset($kampania['owner_user_id'])) {
        $creatorId = (int)$kampania['owner_user_id'];
    }
    if ($creatorId <= 0 && isset($kampania['created_by_user_id'])) {
        $creatorId = (int)$kampania['created_by_user_id'];
    }
    if ($creatorId <= 0) {
        return 'Nie przypisano opiekuna';
    }

    try {
        $stmt = $pdo->prepare('SELECT login, imie, nazwisko FROM uzytkownicy WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $creatorId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$user) {
            return 'Użytkownik #' . $creatorId;
        }
        $fullName = trim((string)($user['imie'] ?? '') . ' ' . (string)($user['nazwisko'] ?? ''));
        $login = trim((string)($user['login'] ?? ''));
        if ($fullName !== '' && $login !== '') {
            return $fullName . ' (' . $login . ')';
        }
        if ($fullName !== '') {
            return $fullName;
        }
        if ($login !== '') {
            return $login;
        }
        return 'Użytkownik #' . $creatorId;
    } catch (Throwable $e) {
        return 'Użytkownik #' . $creatorId;
    }
}

function prepareMediaplanData(PDO $pdo, int $kampaniaId): array {
    $systemConfig = fetchSystemConfig($pdo);
    $pasmoRanges = buildPasmoRanges($systemConfig, DEFAULT_PRIME_HOURS, DEFAULT_STANDARD_HOURS, DEFAULT_NIGHT_HOURS);
    $logoAbsolutePath = resolveLogoPath($systemConfig['pdf_logo_path'] ?? null);
    $logoDataUri = logoToDataUri($logoAbsolutePath);
    $logoFileUri = logoToFileUri($logoAbsolutePath);

    $kampania = null;
    $emisjeRows = [];

    try {
        $select = [
            'id', 'klient_id', 'klient_nazwa', 'dlugosc_spotu',
            'data_start', 'data_koniec', 'netto_spoty', 'netto_dodatki',
            'rabat', 'razem_netto', 'razem_brutto',
        ];
        if (tableHasColumnMediaplan($pdo, 'kampanie', 'owner_user_id')) {
            $select[] = 'owner_user_id';
        }
        if (tableHasColumnMediaplan($pdo, 'kampanie', 'status')) {
            $select[] = 'status';
        }
        if (tableHasColumnMediaplan($pdo, 'kampanie', 'propozycja')) {
            $select[] = 'propozycja';
        }
        if (tableHasColumnMediaplan($pdo, 'kampanie', 'created_by_user_id')) {
            $select[] = 'created_by_user_id';
        }
        $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM kampanie WHERE id = :id');
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

    $normalizedSiatka = [];
    $allowedDays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    foreach ($siatka as $dayRaw => $rows) {
        if (!is_array($rows)) {
            continue;
        }
        $dayKey = normalizeDowKey((string)$dayRaw);
        if (!$dayKey || !in_array($dayKey, $allowedDays, true)) {
            continue;
        }
        if (!isset($normalizedSiatka[$dayKey])) {
            $normalizedSiatka[$dayKey] = [];
        }
        foreach ($rows as $hourRaw => $qtyRaw) {
            $qty = (int)$qtyRaw;
            if ($qty <= 0) {
                continue;
            }
            $hour = mapDbHourToSlot((string)$hourRaw);
            if ($hour === null || $hour === '') {
                continue;
            }
            if ($hour === '23:59') {
                $hour = '>23:00';
            }
            $normalizedSiatka[$dayKey][$hour] = (int)($normalizedSiatka[$dayKey][$hour] ?? 0) + $qty;
        }
    }
    $siatka = $normalizedSiatka;

    $klient = (string)$kampania['klient_nazwa'];
    $dlugoscSek = (int)($kampania['dlugosc'] ?? 0);
    $dataStart = normalizeDateOnly($kampania['data_start'] ?? null);
    $dataKoniec = normalizeDateOnly($kampania['data_koniec'] ?? null);
    if (!$dataStart || !$dataKoniec) {
        throw new RuntimeException('Nieprawidłowe daty kampanii');
    }

    $sloty = godzinySloty('06:00', '23:00');
    $dni = dniOkresu(new DateTime($dataStart->format('Y-m-d')), new DateTime($dataKoniec->format('Y-m-d')));
    $dniByMonth = groupDaysByMonth($dni);
    $dowOccurrences = countDowOccurrences($dataStart, $dataKoniec);
    $bandStats = calculateBandSumsFromSiatka($siatka, $dowOccurrences);
    $sumy = $bandStats['sumy'];
    $liczbaSpotow = (int)$bandStats['total'];
    $weeklyHours = buildWeeklyHours($siatka);
    $weeklyDays = ['mon' => 'Pon', 'tue' => 'Wt', 'wed' => 'Sr', 'thu' => 'Czw', 'fri' => 'Pt', 'sat' => 'Sob', 'sun' => 'Nd'];

    $nettoSpoty = (float)($kampania['netto_spoty'] ?? 0);
    $nettoDod = (float)($kampania['netto_dodatki'] ?? 0);
    $rabatPct = (float)($kampania['rabat'] ?? 0);
    $statusNorm = strtolower(trim((string)($kampania['status'] ?? '')));
    $isProposal = !empty($kampania['propozycja']) || $statusNorm === 'propozycja';
    $campaignVariantLabel = $isProposal ? 'Propozycja' : 'Aktywna';

    $produktyTotal = 0.0;
    foreach ($produkty as $product) {
        if (!is_array($product)) {
            continue;
        }
        $produktyTotal += (float)($product['kwota'] ?? $product['amount'] ?? 0);
    }
    $nettoDodRozliczenie = max($nettoDod, $produktyTotal);
    $nettoPrzedRabatem = $nettoSpoty + $nettoDodRozliczenie;

    $poRabacie = (float)($kampania['razem_po_rabacie'] ?? 0);
    if ($poRabacie <= 0 && $nettoPrzedRabatem > 0) {
        $poRabacie = $nettoPrzedRabatem * (1 - ($rabatPct / 100));
    }
    $brutto = (float)($kampania['razem_brutto'] ?? 0);
    if ($brutto <= 0 && $poRabacie > 0) {
        $brutto = $poRabacie * 1.23;
    }

    $creatorLabel = resolveCreatorLabel($pdo, $kampania);
    $clientRows = [
        ['label' => 'ID kampanii', 'value' => '#' . (int)$kampania['id']],
        ['label' => 'Wariant', 'value' => $campaignVariantLabel],
        ['label' => 'Klient', 'value' => $klient],
        ['label' => 'Długość spotu', 'value' => $dlugoscSek . ' sek'],
        ['label' => 'Okres kampanii', 'value' => $dataStart->format('d.m.Y') . ' - ' . $dataKoniec->format('d.m.Y')],
        ['label' => 'Liczba spotów', 'value' => number_format($liczbaSpotow, 0, ',', ' ')],
    ];
    $financialRows = [
        ['label' => 'Netto spoty', 'value' => number_format($nettoSpoty, 2, ',', ' ') . ' zł'],
        ['label' => 'Dodatki (rozliczenie)', 'value' => number_format($nettoDodRozliczenie, 2, ',', ' ') . ' zł'],
    ];
    if ($produktyTotal > 0) {
        $financialRows[] = ['label' => 'Produkty dodatkowe (z listy)', 'value' => number_format($produktyTotal, 2, ',', ' ') . ' zł'];
    }
    $financialRows[] = ['label' => 'Netto przed rabatem', 'value' => number_format($nettoPrzedRabatem, 2, ',', ' ') . ' zł'];
    $financialRows[] = ['label' => 'Rabat', 'value' => number_format($rabatPct, 2, ',', ' ') . ' %'];
    $financialRows[] = ['label' => 'Razem po rabacie', 'value' => number_format($poRabacie, 2, ',', ' ') . ' zł'];
    $financialRows[] = ['label' => 'Razem brutto', 'value' => number_format($brutto, 2, ',', ' ') . ' zł'];

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
        ['label' => 'Usługi dodatkowe (netto)', 'value' => number_format($nettoDodRozliczenie, 2, ',', ' ') . ' zł'],
        ['label' => 'RABAT', 'value' => number_format($rabatPct, 2, ',', ' ') . ' %'],
        ['label' => 'Wartość po rabacie (netto)', 'value' => number_format($poRabacie, 2, ',', ' ') . ' zł'],
        ['label' => 'Brutto', 'value' => number_format($brutto, 2, ',', ' ') . ' zł'],
    ];

    return [
        'system_config'    => $systemConfig,
        'logo_path'        => $logoAbsolutePath,
        'logo_data_uri'    => $logoDataUri,
        'logo_file_uri'    => $logoFileUri,
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
        'netto_dodatki'    => $nettoDodRozliczenie,
        'rabat_pct'        => $rabatPct,
        'po_rabacie'       => $poRabacie,
        'brutto'           => $brutto,
        'sumy'             => $sumy,
        'weekly_days'      => $weeklyDays,
        'weekly_hours'     => $weeklyHours,
        'client_rows'      => $clientRows,
        'financial_rows'   => $financialRows,
        'creator_label'    => $creatorLabel,
        'generated_at'     => new DateTimeImmutable('now'),
        'campaign_variant' => $campaignVariantLabel,
        'pasmo_ranges'     => $pasmoRanges,
        'emisje_rows'      => $emisjeRows,
    ];
}

function renderMediaplanTcpdf(array $data): string {
    $status = mediaplanPdfLibraryStatus();
    if (!$status['tcpdf']) {
        throw new RuntimeException('TCPDF niedostępny');
    }
    if (empty($status['gd'])) {
        throw new RuntimeException('TCPDF wymaga rozszerzenia GD.');
    }

    $pdf = new TCPDF(PAGE_ORIENTATION_FRONT, 'mm', PAGE_SIZE, true, 'UTF-8', false);
    $pdf->SetCreator('CRM');
    $pdf->SetAuthor('CRM');
    $pdf->SetTitle('Mediaplan – kampania #' . $data['kampania_id']);
    $pdf->SetSubject('Mediaplan');
    $pdf->SetMargins(MARGIN_L, MARGIN_T, MARGIN_R);
    $pdf->SetAutoPageBreak(true, MARGIN_B);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetLineWidth(LINE_W);
    [$tr, $tg, $tb] = COLOR_TEXT;
    [$gr, $gg, $gb] = COLOR_GRID;
    [$hr, $hg, $hb] = COLOR_HEADER_FILL;
    $pdf->SetTextColor($tr, $tg, $tb);
    $pdf->SetDrawColor($gr, $gg, $gb);

    $pdf->AddPage(PAGE_ORIENTATION_FRONT, PAGE_SIZE);
    $pageWidth = $pdf->getPageWidth();
    if (!empty($data['logo_path'])) {
        try {
            $logoX = $pageWidth - MARGIN_R - FRONT_LOGO_WIDTH;
            $pdf->Image($data['logo_path'], $logoX, MARGIN_T, FRONT_LOGO_WIDTH);
        } catch (Throwable $e) {
            error_log('mediaplan_pdf: logo render failed: ' . $e->getMessage());
        }
    }

    $pdf->SetXY(MARGIN_L, MARGIN_T);
    $pdf->SetFont(FONT_MAIN, 'B', FONT_SIZE_TITLE);
    $pdf->Cell(0, 8, 'Mediaplan kampanii #' . (int)$data['kampania_id'], 0, 1, 'L');
    $pdf->SetFont(FONT_MAIN, '', FONT_SIZE_BASE);
    $pdf->Cell(0, 6, 'Wygenerowano: ' . $data['generated_at']->format('Y-m-d H:i'), 0, 1, 'L');
    $pdf->Ln(2);

    $contentW = getContentWidth($pdf);
    $gap = COLUMN_GAP;
    $leftW = round($contentW * FRONT_LEFT_RATIO, 2);
    $rightW = $contentW - $leftW - $gap;
    $xLeft = MARGIN_L;
    $xRight = MARGIN_L + $leftW + $gap;
    $yTop = $pdf->GetY();

    $blockRowH = 6.0;
    $labelRatio = 0.55;

    $pdf->SetXY($xLeft, $yTop);
    $pdf->SetFillColor($hr, $hg, $hb);
    $pdf->SetFont(FONT_MAIN, 'B', FONT_SIZE_BASE);
    $pdf->Cell($leftW, 7, 'Dane klienta i spotów', 1, 1, 'C', true);
    $pdf->SetFont(FONT_MAIN, '', FONT_SIZE_BASE);
    $leftLabelW = round($leftW * $labelRatio, 2);
    $leftValueW = $leftW - $leftLabelW;
    foreach (($data['client_rows'] ?? []) as $row) {
        $pdf->SetX($xLeft);
        $pdf->Cell($leftLabelW, $blockRowH, (string)($row['label'] ?? ''), 1, 0, 'L');
        $pdf->Cell($leftValueW, $blockRowH, (string)($row['value'] ?? ''), 1, 1, 'R');
    }
    $yLeftEnd = $pdf->GetY();

    $pdf->SetXY($xRight, $yTop);
    $pdf->SetFillColor($hr, $hg, $hb);
    $pdf->SetFont(FONT_MAIN, 'B', FONT_SIZE_BASE);
    $pdf->Cell($rightW, 7, 'Rozliczenie finansowe', 1, 1, 'C', true);
    $pdf->SetFont(FONT_MAIN, '', FONT_SIZE_BASE);
    $rightLabelW = round($rightW * 0.6, 2);
    $rightValueW = $rightW - $rightLabelW;
    foreach (($data['financial_rows'] ?? []) as $row) {
        $pdf->SetX($xRight);
        $pdf->Cell($rightLabelW, $blockRowH, (string)($row['label'] ?? ''), 1, 0, 'L');
        $pdf->Cell($rightValueW, $blockRowH, (string)($row['value'] ?? ''), 1, 1, 'R');
    }
    $yRightEnd = $pdf->GetY();

    $pdf->SetY(max($yLeftEnd, $yRightEnd) + SECTION_GAP);
    $pdf->SetFont(FONT_MAIN, 'B', FONT_SIZE_BASE);
    $pdf->Cell(0, 7, 'Plan emisyjny', 0, 1, 'L');
    $pdf->SetFont(FONT_MAIN, '', FONT_SIZE_TABLE);

    $weeklyDays = $data['weekly_days'] ?? ['mon' => 'Pon', 'tue' => 'Wt', 'wed' => 'Sr', 'thu' => 'Czw', 'fri' => 'Pt', 'sat' => 'Sob', 'sun' => 'Nd'];
    $weeklyHours = $data['weekly_hours'] ?? [];
    if (!$weeklyHours) {
        $weeklyHours = buildWeeklyHours($data['siatka'] ?? []);
    }

    $dayCount = max(1, count($weeklyDays));
    $hourColW = COL_HOUR_W;
    $dayW = round((getContentWidth($pdf) - $hourColW) / $dayCount, 2);
    $lastDayW = getContentWidth($pdf) - $hourColW - ($dayW * ($dayCount - 1));

    $renderTableHeader = function () use ($pdf, $weeklyDays, $hourColW, $dayW, $lastDayW, $hr, $hg, $hb): void {
        $pdf->SetX(MARGIN_L);
        $pdf->SetFillColor($hr, $hg, $hb);
        $pdf->SetFont(FONT_MAIN, 'B', FONT_SIZE_TABLE);
        $pdf->Cell($hourColW, ROW_H, 'Godzina', 1, 0, 'C', true);
        $idx = 0;
        $totalDays = count($weeklyDays);
        foreach ($weeklyDays as $label) {
            $idx++;
            $width = ($idx === $totalDays) ? $lastDayW : $dayW;
            $pdf->Cell($width, ROW_H, (string)$label, 1, 0, 'C', true);
        }
        $pdf->Ln();
        $pdf->SetFont(FONT_MAIN, '', FONT_SIZE_TABLE);
    };

    $renderTableHeader();
    $pageHeight = $pdf->getPageHeight();
    foreach ($weeklyHours as $hour) {
        if ($pdf->GetY() + ROW_H > ($pageHeight - MARGIN_B - 14)) {
            $pdf->AddPage(PAGE_ORIENTATION_FRONT, PAGE_SIZE);
            $pdf->SetFont(FONT_MAIN, 'B', FONT_SIZE_BASE);
            $pdf->Cell(0, 7, 'Plan emisyjny (cd.)', 0, 1, 'L');
            $pdf->SetFont(FONT_MAIN, '', FONT_SIZE_TABLE);
            $renderTableHeader();
        }
        $pdf->SetX(MARGIN_L);
        $pdf->Cell($hourColW, ROW_H, (string)$hour, 1, 0, 'C');
        $idx = 0;
        $totalDays = count($weeklyDays);
        foreach (array_keys($weeklyDays) as $dayKey) {
            $idx++;
            $width = ($idx === $totalDays) ? $lastDayW : $dayW;
            $value = (int)($data['siatka'][$dayKey][$hour] ?? 0);
            $pdf->Cell($width, ROW_H, $value > 0 ? (string)$value : '', 1, 0, 'C');
        }
        $pdf->Ln();
    }

    $pdf->Ln(4);
    $pdf->SetFont(FONT_MAIN, '', 9);
    $pdf->Cell(0, 6, 'Mediaplan przygotował: ' . (string)($data['creator_label'] ?? 'Nieznany użytkownik'), 0, 1, 'L');

    return $pdf->Output('mediaplan_kampania_' . $data['kampania_id'] . '.pdf', 'S');
}

function renderMediaplanDompdf(array $data): string {
    if (!class_exists('\\Dompdf\\Dompdf')) {
        throw new RuntimeException('Brak biblioteki Dompdf');
    }

    $weeklyDays = $data['weekly_days'] ?? ['mon' => 'Pon', 'tue' => 'Wt', 'wed' => 'Sr', 'thu' => 'Czw', 'fri' => 'Pt', 'sat' => 'Sob', 'sun' => 'Nd'];
    $weeklyHours = $data['weekly_hours'] ?? [];
    if (!$weeklyHours) {
        $weeklyHours = buildWeeklyHours($data['siatka'] ?? []);
    }

    $html = '<!doctype html><html><head><meta charset="utf-8"><style>
body{font-family: DejaVu Sans, Arial, sans-serif;font-size:11px;color:#111;}
h1{font-size:18px;margin:0 0 4px 0;}
.topbar{margin-bottom:10px;}
.logo{text-align:right;margin-bottom:6px;}
.meta{font-size:10px;color:#444;margin-bottom:6px;}
.two-col{width:100%;border-collapse:separate;border-spacing:8px 0;margin-bottom:10px;}
.box{border:1px solid #777;vertical-align:top;}
.box-title{background:#f0f0f0;font-weight:bold;text-align:center;padding:6px;border-bottom:1px solid #777;}
.box-table{width:100%;border-collapse:collapse;}
.box-table td{border-bottom:1px solid #ddd;padding:5px 6px;}
.box-table tr:last-child td{border-bottom:none;}
.box-table td:first-child{width:58%;color:#555;}
.box-table td:last-child{text-align:right;font-weight:bold;}
.plan-title{font-size:13px;font-weight:bold;margin:6px 0;}
table.mediaplan{border-collapse:collapse;width:100%;table-layout:fixed;font-size:10px;}
table.mediaplan th,table.mediaplan td{border:1px solid #999;padding:4px;text-align:center;}
table.mediaplan th{background:#f0f0f0;}
.creator{margin-top:10px;font-size:10px;}
</style></head><body>';

    $logoSrc = (string)($data['logo_data_uri'] ?? '');
    if ($logoSrc === '' && !empty($data['logo_file_uri'])) {
        $logoSrc = (string)$data['logo_file_uri'];
    }
    if ($logoSrc !== '') {
        $html .= '<div class="logo"><img src="' . htmlspecialchars($logoSrc) . '" alt="Logo" style="max-height:55px;"></div>';
    }
    $html .= '<div class="topbar">';
    $html .= '<h1>Mediaplan kampanii #' . (int)$data['kampania_id'] . '</h1>';
    $html .= '<div class="meta">Wygenerowano: ' . $data['generated_at']->format('Y-m-d H:i') . '</div>';
    $html .= '</div>';

    $html .= '<table class="two-col"><tr>';
    $html .= '<td class="box" width="58%"><div class="box-title">Dane klienta i spotów</div><table class="box-table">';
    foreach (($data['client_rows'] ?? []) as $row) {
        $html .= '<tr><td>' . htmlspecialchars((string)($row['label'] ?? '')) . '</td><td>' . htmlspecialchars((string)($row['value'] ?? '')) . '</td></tr>';
    }
    $html .= '</table></td>';
    $html .= '<td class="box" width="42%"><div class="box-title">Rozliczenie finansowe</div><table class="box-table">';
    foreach (($data['financial_rows'] ?? []) as $row) {
        $html .= '<tr><td>' . htmlspecialchars((string)($row['label'] ?? '')) . '</td><td>' . htmlspecialchars((string)($row['value'] ?? '')) . '</td></tr>';
    }
    $html .= '</table></td>';
    $html .= '</tr></table>';

    $html .= '<div class="plan-title">Plan emisyjny</div>';
    $html .= '<table class="mediaplan"><thead><tr><th style="width:10%">Godzina</th>';
    $dayCount = count($weeklyDays) ?: 1;
    $dayWidth = floor(90 / $dayCount);
    foreach ($weeklyDays as $label) {
        $html .= '<th style="width:' . max(8, (int)$dayWidth) . '%">' . htmlspecialchars((string)$label) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($weeklyHours as $hour) {
        $html .= '<tr><td>' . htmlspecialchars((string)$hour) . '</td>';
        foreach (array_keys($weeklyDays) as $dayKey) {
            $value = (int)($data['siatka'][$dayKey][$hour] ?? 0);
            $html .= '<td>' . ($value > 0 ? (string)$value : '') . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    $html .= '<div class="creator">Mediaplan przygotował: ' . htmlspecialchars((string)($data['creator_label'] ?? 'Nieznany użytkownik')) . '</div>';
    $html .= '</body></html>';

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();
    return $dompdf->output();
}

function generateMediaplanPdfBinary(PDO $pdo, int $kampaniaId): string {
    $status = mediaplanPdfLibraryStatus();
    if (!$status['tcpdf'] && !$status['dompdf']) {
        throw new RuntimeException('Brak bibliotek TCPDF/Dompdf');
    }

    $data = prepareMediaplanData($pdo, $kampaniaId);
    $errors = [];

    if ($status['tcpdf']) {
        try {
            return renderMediaplanTcpdf($data);
        } catch (Throwable $e) {
            $errors[] = 'TCPDF: ' . $e->getMessage();
            error_log('mediaplan_pdf: TCPDF fallback to Dompdf: ' . $e->getMessage());
        }
    }

    if ($status['dompdf']) {
        try {
            return renderMediaplanDompdf($data);
        } catch (Throwable $e) {
            $errors[] = 'Dompdf: ' . $e->getMessage();
            error_log('mediaplan_pdf: Dompdf failed: ' . $e->getMessage());
        }
    }

    throw new RuntimeException('Nie udało się wygenerować PDF mediaplanu. ' . implode(' | ', $errors));
}
