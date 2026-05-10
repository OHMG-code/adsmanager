<?php
declare(strict_types=1);

require_once __DIR__ . '/AiLeadSettingsService.php';
require_once __DIR__ . '/AiLeadEnrichmentService.php';
require_once __DIR__ . '/AiLeadSources/GooglePlacesLeadSource.php';

final class AiLeadGeneratorService
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<int,array<string,mixed>>|array{leads:array<int,array<string,mixed>>,enrichment_status:string,warnings:array<int,string>,errors:array<int,string>,settings:array<string,mixed>}
     */
    public function generateLeads(string $industry, string $location, int $limit, array $options = []): array
    {
        $industry = trim($industry);
        $location = trim($location);

        if ($industry === '' || $location === '') {
            throw new InvalidArgumentException('Industry and location are required.');
        }

        if ($this->pdo instanceof PDO) {
            return $this->generateConfigured($industry, $location, $limit, $options);
        }

        $limit = max(1, min(50, $limit));
        return $this->mockLeads($industry, $location, $limit);
    }

    /**
     * @param array<string,mixed> $options
     * @return array{leads:array<int,array<string,mixed>>,enrichment_status:string,warnings:array<int,string>,errors:array<int,string>,settings:array<string,mixed>}
     */
    private function generateConfigured(string $industry, string $location, int $limit, array $options): array
    {
        $settingsService = new AiLeadSettingsService($this->pdo);
        $settings = $settingsService->load(true);
        $maxLimit = (int)$settings['ai_max_generation_limit'];
        $limit = max(1, min($maxLimit, $limit > 0 ? $limit : (int)$settings['ai_default_generation_limit']));
        $radiusKm = max(1, min(100, (int)($options['radius_km'] ?? $settings['ai_default_radius_km'])));
        $mode = (string)($options['mode'] ?? 'google_ai');

        $warnings = [];
        $errors = [];
        $leads = [];

        if ($mode === 'test' || $settings['ai_search_provider'] === 'disabled') {
            $leads = $this->mockLeads($industry, $location, $limit);
            foreach ($leads as &$lead) {
                $lead['source'] = 'ai_generated';
                $lead['enrichment_status'] = 'skipped';
            }
            unset($lead);
            $warnings[] = 'Tryb testowy: użyto danych przykładowych.';
        } else {
            try {
                $source = new GooglePlacesLeadSource((string)($settings['google_places_api_key'] ?? ''));
                $leads = $source->search($industry, $location, $radiusKm, $limit);
                if ($leads === []) {
                    $warnings[] = 'Google Places nie zwrócił wyników dla podanych kryteriów.';
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage() === 'Google Places API key is not configured.'
                    ? 'Google Places API key is not configured.'
                    : 'Nie udało się pobrać leadów z Google Places.';
            }
        }

        $enrichmentStatus = 'skipped';
        if ($leads !== []) {
            if ($mode === 'google_only' || $mode === 'test') {
                $enrichment = [
                    'leads' => array_map(fn (array $lead): array => $this->heuristicEnrichment($lead), $leads),
                    'status' => 'skipped',
                    'warnings' => $mode === 'google_only' ? ['AI enrichment skipped by selected mode.'] : [],
                ];
            } else {
                $enrichment = (new AiLeadEnrichmentService($settings))->enrich($leads);
            }
            $leads = $enrichment['leads'];
            $enrichmentStatus = $enrichment['status'];
            $warnings = array_merge($warnings, $enrichment['warnings']);
        }

        unset($settings['ai_api_key'], $settings['google_places_api_key']);

        return [
            'leads' => $leads,
            'enrichment_status' => $enrichmentStatus,
            'warnings' => array_values(array_unique($warnings)),
            'errors' => $errors,
            'settings' => $settings,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function mockLeads(string $industry, string $location, int $limit): array
    {
        $limit = max(1, min(50, $limit));

        $templates = [
            ['prefix' => 'Centrum', 'phone' => '501 200 100', 'domain' => 'centrum'],
            ['prefix' => 'Studio', 'phone' => '502 300 200', 'domain' => 'studio'],
            ['prefix' => 'Pro', 'phone' => '503 400 300', 'domain' => 'pro'],
            ['prefix' => 'Expert', 'phone' => '504 500 400', 'domain' => 'expert'],
            ['prefix' => 'Premium', 'phone' => '505 600 500', 'domain' => 'premium'],
            ['prefix' => 'Partner', 'phone' => '506 700 600', 'domain' => 'partner'],
        ];

        $leads = [];
        for ($i = 0; $i < $limit; $i++) {
            $template = $templates[$i % count($templates)];
            $sequence = $i + 1;
            $slugIndustry = $this->slug($industry);
            $slugLocation = $this->slug($location);
            $score = 45;
            $score += $template['phone'] !== '' ? 15 : 0;
            $score += 20;
            $score += min(15, strlen($industry));

            $leads[] = [
                'company_name' => $template['prefix'] . ' ' . ucfirst($industry) . ' ' . $sequence,
                'city' => $location,
                'phone' => $this->incrementPhone($template['phone'], $i),
                'email' => 'kontakt@' . $template['domain'] . '-' . $slugIndustry . '-' . $sequence . '.example',
                'website' => 'https://' . $template['domain'] . '-' . $slugIndustry . '-' . $slugLocation . '-' . $sequence . '.example',
                'industry' => $industry,
                'score' => max(1, min(100, $score)),
                'source' => 'ai_generated',
            ];
        }

        return $leads;
    }

    private function heuristicEnrichment(array $lead): array
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
        $lead['opening_argument'] = $lead['opening_argument'] ?? 'Radio Żuławy może pomóc dotrzeć do klientów z okolicy.';
        $lead['short_reason'] = $lead['short_reason'] ?? 'Ocena bez AI na podstawie dostępnych danych.';
        $lead['suggested_next_action'] = $lead['suggested_next_action'] ?? 'Zweryfikować dane i wykonać pierwszy telefon.';
        $lead['enrichment_status'] = 'skipped';
        return $lead;
    }

    private function incrementPhone(string $phone, int $offset): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        $number = (int)$digits + $offset;
        return substr((string)$number, 0, 3) . ' ' . substr((string)$number, 3, 3) . ' ' . substr((string)$number, 6);
    }

    private function slug(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') {
            $value = $ascii;
        }
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
        return trim($value, '-') ?: 'lead';
    }
}
