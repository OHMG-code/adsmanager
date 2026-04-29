<?php
declare(strict_types=1);

require_once __DIR__ . '/ZadarmaApiClient.php';

final class ZadarmaSmsService
{
    private ZadarmaApiClient $client;
    private string $defaultSender;
    private bool $dryRun;

    public function __construct(?ZadarmaApiClient $client = null, ?string $defaultSender = null, ?bool $dryRun = null)
    {
        $this->client = $client ?? new ZadarmaApiClient();
        $this->defaultSender = trim((string)($defaultSender ?? (defined('ZADARMA_SMS_SENDER') ? ZADARMA_SMS_SENDER : '')));
        $this->dryRun = $dryRun ?? (defined('SMS_DRY_RUN') && SMS_DRY_RUN);
    }

    public static function fromDatabase(PDO $pdo): self
    {
        $config = self::loadDatabaseConfig($pdo);
        $apiKey = trim((string)($config['zadarma_api_key'] ?? (defined('ZADARMA_API_KEY') ? ZADARMA_API_KEY : '')));
        $apiSecret = trim((string)($config['zadarma_api_secret'] ?? (defined('ZADARMA_API_SECRET') ? ZADARMA_API_SECRET : '')));
        $baseUrl = trim((string)($config['zadarma_api_base_url'] ?? (defined('ZADARMA_API_BASE_URL') ? ZADARMA_API_BASE_URL : 'https://api.zadarma.com')));
        $sender = trim((string)($config['zadarma_sms_sender'] ?? (defined('ZADARMA_SMS_SENDER') ? ZADARMA_SMS_SENDER : '')));
        $dryRunRaw = $config['sms_dry_run'] ?? (defined('SMS_DRY_RUN') ? SMS_DRY_RUN : false);
        $dryRun = is_bool($dryRunRaw)
            ? $dryRunRaw
            : in_array(strtolower(trim((string)$dryRunRaw)), ['1', 'true', 'yes', 'on'], true);

        return new self(new ZadarmaApiClient($apiKey, $apiSecret, $baseUrl), $sender, $dryRun);
    }

    public static function loadDatabaseConfig(PDO $pdo): array
    {
        try {
            $stmt = $pdo->query('SELECT zadarma_api_key, zadarma_api_secret, zadarma_sms_sender, zadarma_api_base_url, sms_dry_run FROM konfiguracja_systemu WHERE id = 1 LIMIT 1');
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            return is_array($row) ? $row : [];
        } catch (Throwable $e) {
            error_log('zadarma_sms: cannot load db config: ' . $e->getMessage());
            return [];
        }
    }

    public function sendSms(string $number, string $message, ?string $sender = null): array
    {
        return $this->sendBulkSms([$number], $message, $sender);
    }

    public function sendBulkSms(array $numbers, string $message, ?string $sender = null): array
    {
        $message = trim($message);
        if ($message === '') {
            return $this->errorResult('Wpisz treść SMS.');
        }
        if (mb_strlen($message) > 600) {
            return $this->errorResult('SMS nie może przekraczać 600 znaków.');
        }

        $normalizedNumbers = [];
        foreach ($numbers as $number) {
            $normalized = self::normalizePhoneNumber((string)$number);
            if ($normalized === null) {
                return $this->errorResult('Nieprawidłowy numer telefonu: ' . trim((string)$number));
            }
            $normalizedNumbers[] = $normalized;
        }
        $normalizedNumbers = array_values(array_unique($normalizedNumbers));
        if ($normalizedNumbers === []) {
            return $this->errorResult('Podaj numer telefonu.');
        }

        $parts = self::estimateSmsParts($message);
        $sender = trim((string)($sender ?? $this->defaultSender));

        $params = [
            'number' => implode(',', $normalizedNumbers),
            'message' => $message,
        ];
        if ($sender !== '') {
            $params['sender'] = $sender;
        }

        if ($this->dryRun) {
            return [
                'success' => true,
                'status' => 'dry_run',
                'message' => 'Tryb testowy: SMS nie został realnie wysłany.',
                'phone' => implode(',', $normalizedNumbers),
                'numbers' => $normalizedNumbers,
                'sender' => $sender,
                'cost' => null,
                'currency' => null,
                'parts' => $parts,
                'denied_numbers' => [],
                'provider_response' => [
                    'dry_run' => true,
                    'provider' => 'zadarma',
                    'params' => [
                        'number' => $params['number'],
                        'message_length' => mb_strlen($message),
                        'sender' => $sender,
                    ],
                ],
                'error' => null,
            ];
        }

        if (!$this->client->isConfigured()) {
            return $this->errorResult('Brak konfiguracji API Zadarma.', $normalizedNumbers, $sender, $parts);
        }

        $apiResult = $this->client->post('/v1/sms/send/', $params);
        $response = is_array($apiResult['response'] ?? null) ? $apiResult['response'] : [];
        $deniedNumbers = $this->extractDeniedNumbers($response);
        $status = 'failed';
        if (!empty($apiResult['success'])) {
            $status = $deniedNumbers ? 'partial' : 'sent';
        }

        return [
            'success' => $status === 'sent' || $status === 'partial',
            'status' => $status,
            'message' => $this->statusMessage($status),
            'phone' => implode(',', $normalizedNumbers),
            'numbers' => $normalizedNumbers,
            'sender' => $sender,
            'cost' => $this->extractCost($response),
            'currency' => isset($response['currency']) ? (string)$response['currency'] : null,
            'parts' => $parts,
            'denied_numbers' => $deniedNumbers,
            'provider_response' => $response,
            'error' => $status === 'failed' ? (string)($apiResult['error'] ?? 'Nie udało się wysłać SMS.') : null,
        ];
    }

    public static function normalizePhoneNumber(string $number): ?string
    {
        $number = trim($number);
        $number = str_replace([' ', '-', '(', ')', "\t", "\r", "\n"], '', $number);
        if (str_starts_with($number, '+')) {
            $number = substr($number, 1);
        }
        if (str_starts_with($number, '00')) {
            $number = substr($number, 2);
        }
        if (preg_match('/^\d{9}$/', $number) === 1) {
            $number = '48' . $number;
        }
        if (preg_match('/^[1-9]\d{10,14}$/', $number) !== 1) {
            return null;
        }
        return $number;
    }

    public static function estimateSmsParts(string $message): int
    {
        $length = mb_strlen($message);
        if ($length <= 160) {
            return 1;
        }
        return (int)ceil($length / 153);
    }

    private function errorResult(string $error, array $numbers = [], string $sender = '', int $parts = 0): array
    {
        return [
            'success' => false,
            'status' => 'failed',
            'message' => $error,
            'phone' => implode(',', $numbers),
            'numbers' => $numbers,
            'sender' => $sender,
            'cost' => null,
            'currency' => null,
            'parts' => $parts,
            'denied_numbers' => [],
            'provider_response' => null,
            'error' => $error,
        ];
    }

    private function extractDeniedNumbers(array $response): array
    {
        $denied = $response['denied_numbers'] ?? [];
        if (is_string($denied) && trim($denied) !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $denied))));
        }
        if (is_array($denied)) {
            return array_values(array_filter(array_map(static fn ($item): string => trim((string)$item), $denied)));
        }
        return [];
    }

    private function extractCost(array $response): ?float
    {
        if (isset($response['cost']) && is_numeric($response['cost'])) {
            return (float)$response['cost'];
        }
        return null;
    }

    private function statusMessage(string $status): string
    {
        if ($status === 'sent') {
            return 'SMS został wysłany.';
        }
        if ($status === 'partial') {
            return 'SMS został wysłany częściowo. Część numerów została odrzucona.';
        }
        return 'Nie udało się wysłać SMS.';
    }
}
