<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json; charset=utf-8');

$nip = isset($_GET['nip']) ? preg_replace('/\D/', '', $_GET['nip']) : '';
if (strlen($nip) !== 10) {
    echo json_encode(['success' => false, 'message' => 'Podaj poprawny numer NIP (10 cyfr).'], JSON_UNESCAPED_UNICODE);
    exit;
}

$apiUrl = sprintf(
    'https://wl-api.mf.gov.pl/api/search/nip/%s?date=%s',
    urlencode($nip),
    date('Y-m-d')
);

try {
    $payload = httpGetJson($apiUrl);
} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

$subject = $payload['result']['subject'] ?? null;
if (!$subject) {
    $message = $payload['result']['message'] ?? ($payload['message'] ?? 'Brak danych dla podanego NIP.');
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$addressLine = $subject['workingAddress'] ?? $subject['residenceAddress'] ?? '';
$parsedAddress = parseAddress($addressLine);

echo json_encode([
    'success' => true,
    'message' => 'Dane pobrane z bazy Ministerstwa Finansów.',
    'nazwa_firmy' => $subject['name'] ?? '',
    'regon'        => $subject['regon'] ?? '',
    'adres'        => $parsedAddress['street'],
    'miejscowosc'  => $parsedAddress['city'],
    'kod_pocztowy' => $parsedAddress['postal'],
    'wojewodztwo'  => $subject['voivodeship'] ?? ($subject['province'] ?? ''),
], JSON_UNESCAPED_UNICODE);
exit;

function httpGetJson(string $url): array
{
    $response = null;
    $httpCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if (!$ch) {
            throw new RuntimeException('Nie udało się zainicjować połączenia.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Błąd połączenia z API MF: ' . $error);
        }
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('Nie udało się nawiązać połączenia HTTP.');
        }
        global $http_response_header;
        if (!empty($http_response_header[0]) && preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0], $matches)) {
            $httpCode = (int)$matches[1];
        }
    }

    if ($httpCode >= 400 || $response === null) {
        throw new RuntimeException('Serwer MF zwrócił błąd HTTP ' . $httpCode . '.');
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Niepoprawna odpowiedź JSON.');
    }

    return $decoded;
}

function parseAddress(string $address): array
{
    $street = trim($address);
    $city = '';
    $postal = '';

    if ($address === '') {
        return ['street' => '', 'city' => '', 'postal' => ''];
    }

    $parts = array_map('trim', explode(',', $address));
    if (count($parts) > 1) {
        $street = array_shift($parts);
        $cityPart = implode(', ', $parts);
    } else {
        $cityPart = $street;
    }

    if (preg_match('/(\d{2}-\d{3})\s*(.+)/u', $cityPart, $matches)) {
        $postal = $matches[1];
        $city = trim($matches[2]);
    } else {
        $city = trim($cityPart);
    }

    return [
        'street' => $street,
        'city' => $city,
        'postal' => $postal,
    ];
}
