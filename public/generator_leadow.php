<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$pageTitle = "Generator leadów";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$googleApiKey = getenv('GOOGLE_MAPS_API_KEY') ?: '';
$googleApiConfigPath = dirname(__DIR__) . '/config/google_api.php';
if ($googleApiKey === '' && file_exists($googleApiConfigPath)) {
    $googleConfig = include $googleApiConfigPath;
    if (is_array($googleConfig) && !empty($googleConfig['maps_api_key'])) {
        $googleApiKey = $googleConfig['maps_api_key'];
    }
}

$formValues = [
    'location' => trim($_POST['location'] ?? ''),
    'keyword'  => trim($_POST['keyword'] ?? ''),
    'radius'   => trim($_POST['radius'] ?? '10'),
];

$searchResults   = [];
$errorMessage    = '';
$infoMessage     = '';
$searchPerformed = ($_SERVER['REQUEST_METHOD'] === 'POST');

if ($searchPerformed) {
    if ($googleApiKey === '') {
        $errorMessage = 'Brak klucza Google Maps API. Ustaw zmienną środowiskową GOOGLE_MAPS_API_KEY lub plik config/google_api.php z kluczem w polu maps_api_key.';
    } else {
        $location = $formValues['location'];
        $keyword  = $formValues['keyword'];
        $radiusKm = $formValues['radius'] !== '' ? (float)$formValues['radius'] : 10;
        $radiusKm = max(0.5, min(50, $radiusKm));
        $radiusMeters = (int)round($radiusKm * 1000);

        if ($location === '' || $keyword === '') {
            $errorMessage = 'Uzupełnij lokalizację oraz branżę/frazę do wyszukania.';
        } else {
            try {
                $coords = geocodeLocation($location, $googleApiKey);
                $places = searchNearbyPlaces($coords, $radiusMeters, $keyword, $googleApiKey);

                if (empty($places)) {
                    $infoMessage = 'Brak wyników dla podanych kryteriów.';
                } else {
                    foreach ($places as $place) {
                        $details = fetchPlaceDetails($place['place_id'] ?? '', $googleApiKey);
                        $searchResults[] = formatPlaceResult($place, $details['result'] ?? []);
                    }
                    $infoMessage = 'Wyświetlam ' . count($places) . ' wyników (Google Places dostarcza maks. 60 rekordów na zapytanie).';
                }
            } catch (RuntimeException $e) {
                $errorMessage = $e->getMessage();
            }
        }
    }
}

include 'includes/header.php';

function googleApiHttpGet(string $url): array
{
    $response = null;
    $httpCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Nie udało się nawiązać połączenia z Google API.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Błąd podczas komunikacji z Google API: ' . $error);
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
            throw new RuntimeException('Nie udało się pobrać danych z Google API (file_get_contents).');
        }
        global $http_response_header;
        if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
            $httpCode = (int)$matches[1];
        }
    }

    if ($httpCode >= 400) {
        throw new RuntimeException('Google API zwróciło kod błędu HTTP ' . $httpCode . '.');
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Google API zwróciło niepoprawny JSON.');
    }

    return $decoded;
}

function geocodeLocation(string $location, string $apiKey): array
{
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($location) . '&language=pl&key=' . urlencode($apiKey);
    $data = googleApiHttpGet($url);
    $status = $data['status'] ?? 'UNKNOWN_ERROR';

    if ($status === 'ZERO_RESULTS') {
        throw new RuntimeException('Nie znaleziono lokalizacji: ' . $location);
    }
    if ($status !== 'OK' || empty($data['results'][0]['geometry']['location'])) {
        $message = $data['error_message'] ?? 'Nieznany błąd geokodowania.';
        throw new RuntimeException('Błąd geokodowania: ' . $message);
    }

    return [
        'lat' => $data['results'][0]['geometry']['location']['lat'],
        'lng' => $data['results'][0]['geometry']['location']['lng'],
    ];
}

function searchNearbyPlaces(array $coords, int $radiusMeters, string $keyword, string $apiKey, int $maxResults = 60): array
{
    $base = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json';
    $collected = [];
    $nextPageToken = null;

    do {
        if ($nextPageToken !== null) {
            usleep(2000000); // Google wymaga krótkiego odczekania zanim token stanie się aktywny
        }

        $params = [
            'location' => $coords['lat'] . ',' . $coords['lng'],
            'radius' => $radiusMeters,
            'keyword' => $keyword,
            'language' => 'pl',
            'key' => $apiKey,
        ];
        if ($nextPageToken) {
            $params['pagetoken'] = $nextPageToken;
        }

        $data = googleApiHttpGet($base . '?' . http_build_query($params));
        $status = $data['status'] ?? 'UNKNOWN_ERROR';

        if ($status === 'ZERO_RESULTS' && empty($collected)) {
            return [];
        }
        if ($status !== 'OK') {
            $message = $data['error_message'] ?? 'Nieznany błąd wyszukiwania.';
            throw new RuntimeException('Błąd wyszukiwania miejsc: ' . $message . ' (status: ' . $status . ')');
        }

        $collected = array_merge($collected, $data['results'] ?? []);
        $collected = array_slice($collected, 0, $maxResults);

        $nextPageToken = $data['next_page_token'] ?? null;
    } while ($nextPageToken && count($collected) < $maxResults);

    return $collected;
}

function fetchPlaceDetails(string $placeId, string $apiKey): array
{
    if ($placeId === '') {
        return ['result' => []];
    }

    $fields = 'name,formatted_address,formatted_phone_number,website,rating,place_id,url';
    $params = http_build_query([
        'place_id' => $placeId,
        'fields' => $fields,
        'language' => 'pl',
        'key' => $apiKey,
    ]);

    $data = googleApiHttpGet('https://maps.googleapis.com/maps/api/place/details/json?' . $params);
    $status = $data['status'] ?? 'UNKNOWN_ERROR';

    if ($status === 'OK') {
        return $data;
    }
    if ($status === 'NOT_FOUND') {
        return ['result' => []];
    }

    $message = $data['error_message'] ?? 'Nieznany błąd szczegółów.';
    throw new RuntimeException('Błąd pobierania szczegółów miejsca: ' . $message . ' (status: ' . $status . ')');
}

function formatPlaceResult(array $summary, array $details): array
{
    $mapsUrl = $details['url'] ?? '';
    if ($mapsUrl === '' && !empty($summary['place_id'])) {
        $mapsUrl = 'https://www.google.com/maps/place/?q=place_id:' . rawurlencode($summary['place_id']);
    }

    return [
        'name' => $details['name'] ?? ($summary['name'] ?? 'Brak nazwy'),
        'address' => $details['formatted_address'] ?? ($summary['vicinity'] ?? ''),
        'phone' => $details['formatted_phone_number'] ?? '',
        'website' => $details['website'] ?? '',
        'rating' => $details['rating'] ?? ($summary['rating'] ?? null),
        'maps_url' => $mapsUrl,
        'place_id' => $summary['place_id'] ?? '',
    ];
}
?>

<h2 class="mb-4">🌍 Generator leadów (Google Maps)</h2>

<div class="card mb-4">
    <div class="card-body">
        <form method="post" id="leadGeneratorForm">
            <div class="row g-3">

                <div class="col-md-4">
                    <label class="form-label">Miasto / lokalizacja</label>
                    <input type="text"
                           name="location"
                           class="form-control"
                           placeholder="np. Elbląg"
                           value="<?= htmlspecialchars($formValues['location']) ?>"
                           required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Branża / fraza</label>
                    <input type="text"
                           name="keyword"
                           class="form-control"
                           placeholder="np. restauracja, hotel"
                           value="<?= htmlspecialchars($formValues['keyword']) ?>"
                           required>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Promień (km)</label>
                    <input type="number"
                           name="radius"
                           class="form-control"
                           value="<?= htmlspecialchars($formValues['radius']) ?>">
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-primary w-100">
                        🔍 Szukaj
                    </button>
                </div>

            </div>
        </form>
        <div class="small text-muted mt-2">
            Wymagany klucz Google Maps API (Geocoding + Places). Ogranicz promień do maks. 50 km (limit API 50 000 m).
        </div>
    </div>
</div>

<?php if ($errorMessage): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($errorMessage) ?></div>
<?php elseif ($infoMessage): ?>
    <div class="alert alert-info"><?= htmlspecialchars($infoMessage) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <h5 class="card-title">Wyniki</h5>

        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Nazwa</th>
                        <th>Adres</th>
                        <th>Telefon</th>
                        <th>Akcja</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$searchPerformed): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted">
                            Brak wyników – użyj formularza wyszukiwania
                        </td>
                    </tr>
                <?php elseif (empty($searchResults)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted">
                            Google Maps nie zwróciło miejsc dla tego zapytania.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($searchResults as $result): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($result['name']) ?></div>
                                <?php if (!empty($result['maps_url'])): ?>
                                    <small>
                                        <a href="<?= htmlspecialchars($result['maps_url']) ?>" target="_blank" rel="noopener">
                                            Otwórz w Mapach
                                        </a>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($result['address']) ?></td>
                            <td><?= htmlspecialchars($result['phone'] ?: '—') ?></td>
                            <td>
                                <div class="d-flex gap-2 flex-wrap">
                                    <?php if (!empty($result['maps_url'])): ?>
                                        <a href="<?= htmlspecialchars($result['maps_url']) ?>"
                                           class="btn btn-outline-secondary btn-sm"
                                           target="_blank"
                                           rel="noopener">
                                            Mapa
                                        </a>
                                    <?php endif; ?>
                                    <form method="post" action="<?= BASE_URL ?>/lead.php">
                                        <input type="hidden" name="lead_action" value="quick_create">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="return_url" value="lead.php">
                                        <input type="hidden" name="nazwa_firmy" value="<?= htmlspecialchars($result['name']) ?>">
                                        <input type="hidden" name="telefon" value="<?= htmlspecialchars($result['phone']) ?>">
                                        <input type="hidden" name="email" value="">
                                        <input type="hidden" name="zrodlo" value="maps_api">
                                        <input type="hidden" name="status" value="nowy">
                                        <button type="submit" class="btn btn-success btn-sm">
                                            Dodaj lead
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <small class="text-muted">
            Wyniki z Google Maps – przed dodaniem do leadów sprawdź poprawność danych.
        </small>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

