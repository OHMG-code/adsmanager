<?php
declare(strict_types=1);

final class ZadarmaApiClient
{
    private string $apiKey;
    private string $apiSecret;
    private string $baseUrl;
    private int $timeoutSeconds;

    public function __construct(?string $apiKey = null, ?string $apiSecret = null, ?string $baseUrl = null, int $timeoutSeconds = 10)
    {
        $this->apiKey = trim((string)($apiKey ?? (defined('ZADARMA_API_KEY') ? ZADARMA_API_KEY : '')));
        $this->apiSecret = trim((string)($apiSecret ?? (defined('ZADARMA_API_SECRET') ? ZADARMA_API_SECRET : '')));
        $this->baseUrl = rtrim(trim((string)($baseUrl ?? (defined('ZADARMA_API_BASE_URL') ? ZADARMA_API_BASE_URL : 'https://api.zadarma.com'))), '/');
        if ($this->baseUrl === '') {
            $this->baseUrl = 'https://api.zadarma.com';
        }
        $this->timeoutSeconds = max(3, $timeoutSeconds);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->apiSecret !== '';
    }

    public function post(string $path, array $params): array
    {
        $path = '/' . ltrim($path, '/');
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'Brak konfiguracji API Zadarma.',
                'http_code' => null,
                'response' => null,
            ];
        }

        ksort($params);
        $paramsStr = http_build_query($params, '', '&');
        $authorization = $this->buildAuthorizationHeader($path, $paramsStr);
        $url = $this->baseUrl . $path;

        try {
            if (function_exists('curl_init')) {
                return $this->postWithCurl($url, $paramsStr, $authorization);
            }
            return $this->postWithStream($url, $paramsStr, $authorization);
        } catch (Throwable $e) {
            error_log('zadarma_api: request failed: ' . $this->sanitizeError($e->getMessage()));
            return [
                'success' => false,
                'error' => 'Nie udało się połączyć z API Zadarma.',
                'http_code' => null,
                'response' => null,
            ];
        }
    }

    private function buildAuthorizationHeader(string $path, string $paramsStr): string
    {
        $line = $path . $paramsStr . md5($paramsStr);
        $signature = base64_encode(hash_hmac('sha1', $line, $this->apiSecret));
        return $this->apiKey . ':' . $signature;
    }

    private function postWithCurl(string $url, string $paramsStr, string $authorization): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'success' => false,
                'error' => 'Nie udało się zainicjować połączenia z API Zadarma.',
                'http_code' => null,
                'response' => null,
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $paramsStr,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeoutSeconds),
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $authorization,
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            error_log('zadarma_api: curl error: ' . $this->sanitizeError($error));
            return [
                'success' => false,
                'error' => 'Błąd połączenia z API Zadarma.',
                'http_code' => $httpCode > 0 ? $httpCode : null,
                'response' => null,
            ];
        }

        return $this->decodeResponse((string)$raw, $httpCode);
    }

    private function postWithStream(string $url, string $paramsStr, string $authorization): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => $this->timeoutSeconds,
                'header' => implode("\r\n", [
                    'Authorization: ' . $authorization,
                    'Content-Type: application/x-www-form-urlencoded',
                    'Content-Length: ' . strlen($paramsStr),
                ]),
                'content' => $paramsStr,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        $httpCode = null;
        if (!empty($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $headerLine) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$headerLine, $m)) {
                    $httpCode = (int)$m[1];
                    break;
                }
            }
        }

        if ($raw === false) {
            error_log('zadarma_api: stream request failed');
            return [
                'success' => false,
                'error' => 'Błąd połączenia z API Zadarma.',
                'http_code' => $httpCode,
                'response' => null,
            ];
        }

        return $this->decodeResponse((string)$raw, $httpCode);
    }

    private function decodeResponse(string $raw, ?int $httpCode): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            error_log('zadarma_api: invalid json response http=' . (string)$httpCode);
            return [
                'success' => false,
                'error' => 'API Zadarma zwróciło nieprawidłową odpowiedź.',
                'http_code' => $httpCode,
                'response' => ['raw' => mb_substr($raw, 0, 2000)],
            ];
        }

        if ($httpCode !== null && ($httpCode < 200 || $httpCode >= 300)) {
            error_log('zadarma_api: http error ' . $httpCode);
            return [
                'success' => false,
                'error' => 'API Zadarma zwróciło błąd HTTP ' . $httpCode . '.',
                'http_code' => $httpCode,
                'response' => $decoded,
            ];
        }

        $status = strtolower((string)($decoded['status'] ?? ''));
        return [
            'success' => $status === 'success',
            'error' => $status === 'success' ? null : $this->extractApiError($decoded),
            'http_code' => $httpCode,
            'response' => $decoded,
        ];
    }

    private function extractApiError(array $decoded): string
    {
        foreach (['message', 'error', 'error_message'] as $key) {
            $value = trim((string)($decoded[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return 'API Zadarma zwróciło błąd wysyłki.';
    }

    private function sanitizeError(string $message): string
    {
        $message = str_replace([$this->apiKey, $this->apiSecret], '[hidden]', $message);
        return trim($message);
    }
}
