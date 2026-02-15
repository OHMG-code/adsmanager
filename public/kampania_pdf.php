<?php
declare(strict_types=1);

require_once '../config/config.php';
require_once __DIR__ . '/includes/mediaplan_pdf.php';

$status = mediaplanPdfLibraryStatus();
if (!$status['tcpdf'] && !$status['dompdf']) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Brak bibliotek TCPDF ani Dompdf do wygenerowania PDF.\n\n";
    echo "Sprawdzone ścieżki autoload:\n- " . implode("\n- ", array_map(
        fn($p) => $p . (file_exists($p) ? ' [OK]' : ' [brak]'),
        $status['autoload_paths']
    )) . "\n\n";
    echo "Sprawdzone ścieżki TCPDF:\n- " . implode("\n- ", array_map(
        fn($p) => $p . (file_exists($p) ? ' [OK]' : ' [brak]'),
        $status['tcpdf_paths']
    )) . "\n";
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Brak poprawnego parametru id.';
    exit;
}

try {
    $binary = generateMediaplanPdfBinary($pdo, $id);
} catch (RuntimeException $e) {
    $msg = $e->getMessage();
    if (stripos($msg, 'nie znaleziono') !== false) {
        http_response_code(404);
    } else {
        http_response_code(500);
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
} catch (Throwable $e) {
    error_log('kampania_pdf.php: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nie udało się wygenerować mediaplanu.';
    exit;
}

$filename = 'mediaplan_kampania_' . $id . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . strlen($binary));
echo $binary;
