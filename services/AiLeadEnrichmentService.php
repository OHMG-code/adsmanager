<?php
declare(strict_types=1);

final class AiLeadEnrichmentService
{
    private array $settings;

    /**
     * @param array<string,mixed> $settings
     */
    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    /**
     * @param array<int,array<string,mixed>> $leads
     * @return array{leads:array<int,array<string,mixed>>,status:string,warnings:array<int,string>}
     */
    public function enrich(array $leads): array
    {
        $provider = (string)($this->settings['ai_provider'] ?? 'disabled');
        $apiKey = trim((string)($this->settings['ai_api_key'] ?? ''));

        if ($provider === 'disabled' || $apiKey === '') {
            return [
                'leads' => array_map(fn (array $lead): array => $this->applyHeuristic($lead, 'skipped'), $leads),
                'status' => 'skipped',
                'warnings' => ['AI enrichment skipped: AI provider disabled or API key missing.'],
            ];
        }

        try {
            $enriched = $provider === 'claude'
                ? $this->enrichWithClaude($leads, $apiKey)
                : $this->enrichWithOpenAi($leads, $apiKey);

            return ['leads' => $enriched, 'status' => 'ok', 'warnings' => []];
        } catch (Throwable $e) {
            return [
                'leads' => array_map(fn (array $lead): array => $this->applyHeuristic($lead, 'failed'), $leads),
                'status' => 'failed',
                'warnings' => ['AI enrichment failed; used heuristic scoring.'],
            ];
        }
    }

    /**
     * @param array<int,array<string,mixed>> $leads
     * @return array<int,array<string,mixed>>
     */
    private function enrichWithOpenAi(array $leads, string $apiKey): array
    {
        $payload = [
            'model' => (string)($this->settings['ai_model'] ?? 'gpt-4.1-mini'),
            'instructions' => $this->systemPrompt(),
            'input' => $this->userPayload($leads),
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'ai_lead_enrichment',
                    'strict' => true,
                    'schema' => $this->jsonSchema(),
                ],
            ],
        ];
        $response = $this->postJson('https://api.openai.com/v1/responses', [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ], $payload);
        $json = $this->extractOpenAiText($response);
        return $this->mergeAiResult($leads, $json);
    }

    /**
     * @param array<int,array<string,mixed>> $leads
     * @return array<int,array<string,mixed>>
     */
    private function enrichWithClaude(array $leads, string $apiKey): array
    {
        $payload = [
            'model' => (string)($this->settings['ai_model'] ?? 'claude-3-5-sonnet'),
            'max_tokens' => 2500,
            'system' => $this->systemPrompt(),
            'messages' => [
                ['role' => 'user', 'content' => $this->userPayload($leads)],
            ],
        ];
        $response = $this->postJson('https://api.anthropic.com/v1/messages', [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ], $payload);
        $text = '';
        foreach (($response['content'] ?? []) as $part) {
            if (($part['type'] ?? '') === 'text') {
                $text .= (string)($part['text'] ?? '');
            }
        }
        return $this->mergeAiResult($leads, $text);
    }

    private function systemPrompt(): string
    {
        return 'Wzbogacasz leady sprzedażowe dla lokalnej reklamy radiowej Radio Żuławy. Używaj tylko dostarczonych danych firmy. Nie wymyślaj numerów telefonu, emaili, NIP ani stron WWW. Zwróć wyłącznie ścisły JSON zgodny ze schematem. Odpowiadaj po polsku. Skup się na potencjale lokalnej reklamy radiowej.';
    }

    /**
     * @param array<int,array<string,mixed>> $leads
     */
    private function userPayload(array $leads): string
    {
        $compact = [];
        foreach ($leads as $idx => $lead) {
            $compact[] = [
                'index' => $idx,
                'company_name' => $lead['company_name'] ?? '',
                'city' => $lead['city'] ?? '',
                'phone' => $lead['phone'] ?? '',
                'website' => $lead['website'] ?? '',
                'industry' => $lead['industry'] ?? '',
                'source' => $lead['source'] ?? '',
            ];
        }
        return json_encode(['leads' => $compact], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"leads":[]}';
    }

    private function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['leads'],
            'properties' => [
                'leads' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['index', 'sales_score', 'recommended_package', 'opening_argument', 'short_reason', 'suggested_next_action'],
                        'properties' => [
                            'index' => ['type' => 'integer'],
                            'sales_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                            'recommended_package' => ['type' => 'string'],
                            'opening_argument' => ['type' => 'string'],
                            'short_reason' => ['type' => 'string'],
                            'suggested_next_action' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function mergeAiResult(array $leads, string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !is_array($decoded['leads'] ?? null)) {
            throw new RuntimeException('Invalid AI JSON.');
        }
        $byIndex = [];
        foreach ($decoded['leads'] as $item) {
            if (is_array($item) && isset($item['index'])) {
                $byIndex[(int)$item['index']] = $item;
            }
        }
        foreach ($leads as $idx => $lead) {
            $item = $byIndex[$idx] ?? null;
            if (!$item) {
                $leads[$idx] = $this->applyHeuristic($lead, 'partial');
                continue;
            }
            $leads[$idx]['score'] = max(0, min(100, (int)($item['sales_score'] ?? $lead['score'] ?? 0)));
            $leads[$idx]['recommended_package'] = trim((string)($item['recommended_package'] ?? ''));
            $leads[$idx]['opening_argument'] = trim((string)($item['opening_argument'] ?? ''));
            $leads[$idx]['short_reason'] = trim((string)($item['short_reason'] ?? ''));
            $leads[$idx]['suggested_next_action'] = trim((string)($item['suggested_next_action'] ?? ''));
            $leads[$idx]['enrichment_status'] = 'enriched';
        }
        return $leads;
    }

    private function applyHeuristic(array $lead, string $status): array
    {
        $score = (int)($lead['score'] ?? 45);
        if (!empty($lead['phone'])) {
            $score += 10;
        }
        if (!empty($lead['website'])) {
            $score += 10;
        }
        $lead['score'] = max(1, min(100, $score));
        $lead['recommended_package'] = $lead['recommended_package'] ?? 'Pakiet lokalny';
        $lead['opening_argument'] = $lead['opening_argument'] ?? 'Krótka propozycja dotarcia do klientów z okolicy przez Radio Żuławy.';
        $lead['short_reason'] = $lead['short_reason'] ?? 'Ocena heurystyczna na podstawie branży, lokalizacji i dostępnych danych kontaktowych.';
        $lead['suggested_next_action'] = $lead['suggested_next_action'] ?? 'Zweryfikować dane i wykonać pierwszy telefon.';
        $lead['enrichment_status'] = $status;
        return $lead;
    }

    private function postJson(string $url, array $headers, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Cannot encode AI request.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL is required for AI enrichment.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Cannot initialize AI HTTP client.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $responseBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($responseBody === false || $httpCode >= 400) {
            throw new RuntimeException('AI provider request failed.');
        }
        $decoded = json_decode((string)$responseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('AI provider returned invalid JSON.');
        }
        return $decoded;
    }

    private function extractOpenAiText(array $response): string
    {
        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return $response['output_text'];
        }
        $text = '';
        foreach (($response['output'] ?? []) as $output) {
            foreach (($output['content'] ?? []) as $content) {
                if (isset($content['text'])) {
                    $text .= (string)$content['text'];
                }
            }
        }
        return $text;
    }
}
