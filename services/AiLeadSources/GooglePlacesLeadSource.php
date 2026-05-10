<?php
declare(strict_types=1);

final class GooglePlacesLeadSource
{
    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = trim($apiKey);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function search(string $industry, string $location, int $radiusKm, int $limit): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Google Places API key is not configured.');
        }

        $industry = trim($industry);
        $location = trim($location);
        $radiusMeters = max(500, min(50000, $radiusKm * 1000));
        $limit = max(1, min(60, $limit));

        $coords = $this->geocodeLocation($location);
        $places = $this->nearbySearch($coords, $industry, $radiusMeters, $limit);

        $leads = [];
        foreach ($places as $place) {
            $details = $this->placeDetails((string)($place['place_id'] ?? ''));
            $raw = array_merge($place, ['details' => $details]);
            $city = $this->extractCity((string)($details['formatted_address'] ?? ($place['vicinity'] ?? $location)), $location);

            $leads[] = [
                'company_name' => (string)($details['name'] ?? ($place['name'] ?? '')),
                'city' => $city,
                'phone' => (string)($details['formatted_phone_number'] ?? ''),
                'email' => null,
                'website' => (string)($details['website'] ?? ''),
                'industry' => $industry,
                'score' => $this->baseScore($details, $place),
                'source' => 'google_places',
                'external_id' => (string)($place['place_id'] ?? ''),
                'raw_source_data' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        return array_values(array_filter($leads, static fn (array $lead): bool => trim((string)$lead['company_name']) !== ''));
    }

    /**
     * @return array{lat:float,lng:float}
     */
    private function geocodeLocation(string $location): array
    {
        $data = $this->getJson('https://maps.googleapis.com/maps/api/geocode/json', [
            'address' => $location,
            'language' => 'pl',
            'key' => $this->apiKey,
        ]);
        if (($data['status'] ?? '') !== 'OK' || empty($data['results'][0]['geometry']['location'])) {
            throw new RuntimeException('Nie znaleziono lokalizacji dla generatora leadów.');
        }

        return [
            'lat' => (float)$data['results'][0]['geometry']['location']['lat'],
            'lng' => (float)$data['results'][0]['geometry']['location']['lng'],
        ];
    }

    /**
     * @param array{lat:float,lng:float} $coords
     * @return array<int,array<string,mixed>>
     */
    private function nearbySearch(array $coords, string $keyword, int $radiusMeters, int $limit): array
    {
        $results = [];
        $pageToken = null;
        do {
            if ($pageToken !== null) {
                usleep(2000000);
            }
            $params = [
                'location' => $coords['lat'] . ',' . $coords['lng'],
                'radius' => $radiusMeters,
                'keyword' => $keyword,
                'language' => 'pl',
                'key' => $this->apiKey,
            ];
            if ($pageToken) {
                $params['pagetoken'] = $pageToken;
            }
            $data = $this->getJson('https://maps.googleapis.com/maps/api/place/nearbysearch/json', $params);
            $status = (string)($data['status'] ?? 'UNKNOWN_ERROR');
            if ($status === 'ZERO_RESULTS') {
                break;
            }
            if ($status !== 'OK') {
                throw new RuntimeException('Google Places zwrócił błąd wyszukiwania: ' . $status . '.');
            }
            $results = array_slice(array_merge($results, $data['results'] ?? []), 0, $limit);
            $pageToken = count($results) < $limit ? ($data['next_page_token'] ?? null) : null;
        } while ($pageToken && count($results) < $limit);

        return $results;
    }

    /**
     * @return array<string,mixed>
     */
    private function placeDetails(string $placeId): array
    {
        if ($placeId === '') {
            return [];
        }
        $data = $this->getJson('https://maps.googleapis.com/maps/api/place/details/json', [
            'place_id' => $placeId,
            'fields' => 'name,formatted_address,formatted_phone_number,website,rating,place_id,url',
            'language' => 'pl',
            'key' => $this->apiKey,
        ]);
        if (($data['status'] ?? '') !== 'OK') {
            return [];
        }
        return is_array($data['result'] ?? null) ? $data['result'] : [];
    }

    /**
     * @param array<string,string|int|float> $params
     * @return array<string,mixed>
     */
    private function getJson(string $url, array $params): array
    {
        $fullUrl = $url . '?' . http_build_query($params);
        if (function_exists('curl_init')) {
            $ch = curl_init($fullUrl);
            if ($ch === false) {
                throw new RuntimeException('Nie można uruchomić klienta HTTP.');
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $httpCode >= 400) {
                throw new RuntimeException('Nie udało się pobrać danych z Google Places.');
            }
        } else {
            $body = @file_get_contents($fullUrl);
            if ($body === false) {
                throw new RuntimeException('Nie udało się pobrać danych z Google Places.');
            }
        }

        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Google Places zwrócił niepoprawny JSON.');
        }
        return $decoded;
    }

    private function extractCity(string $address, string $fallback): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $address))));
        foreach ($parts as $part) {
            if (preg_match('/\d{2}-\d{3}\s+(.+)/u', $part, $m)) {
                return trim($m[1]);
            }
        }
        return $parts[count($parts) - 2] ?? $fallback;
    }

    private function baseScore(array $details, array $place): int
    {
        $score = 45;
        if (!empty($details['formatted_phone_number'])) {
            $score += 15;
        }
        if (!empty($details['website'])) {
            $score += 15;
        }
        if (isset($details['rating']) || isset($place['rating'])) {
            $score += 10;
        }
        return max(1, min(100, $score));
    }
}
