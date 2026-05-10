<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/crypto.php';

final class AiLeadSettingsService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array<string,mixed>
     */
    public function load(bool $withSecrets = false): array
    {
        $stmt = $this->pdo->query('SELECT * FROM konfiguracja_systemu WHERE id = 1 LIMIT 1');
        $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        $provider = $this->normalizeAiProvider((string)($row['ai_provider'] ?? 'disabled'));
        $searchProvider = $this->normalizeSearchProvider((string)($row['ai_search_provider'] ?? 'disabled'));
        $model = trim((string)($row['ai_model'] ?? ''));
        if ($model === '') {
            $model = $provider === 'claude' ? 'claude-3-5-sonnet' : 'gpt-4.1-mini';
        }

        $maxLimit = max(1, min(100, (int)($row['ai_max_generation_limit'] ?? 50)));
        $defaultLimit = max(1, min($maxLimit, (int)($row['ai_default_generation_limit'] ?? 20)));
        $defaultRadius = max(1, min(100, (int)($row['ai_default_radius_km'] ?? 30)));

        $settings = [
            'ai_provider' => $provider,
            'ai_model' => $model,
            'ai_api_key_configured' => trim((string)($row['ai_api_key_enc'] ?? '')) !== '',
            'ai_search_provider' => $searchProvider,
            'google_places_api_key_configured' => trim((string)($row['google_places_api_key_enc'] ?? '')) !== '' || trim((string)($row['google_maps_api_key'] ?? '')) !== '' || trim((string)(getenv('GOOGLE_MAPS_API_KEY') ?: '')) !== '',
            'ai_default_generation_limit' => $defaultLimit,
            'ai_max_generation_limit' => $maxLimit,
            'ai_default_radius_km' => $defaultRadius,
        ];

        if ($withSecrets) {
            $settings['ai_api_key'] = decryptSecret((string)($row['ai_api_key_enc'] ?? ''));
            $settings['google_places_api_key'] = $this->resolveGooglePlacesApiKey($row);
        }

        return $settings;
    }

    private function resolveGooglePlacesApiKey(array $row): string
    {
        $env = trim((string)(getenv('GOOGLE_PLACES_API_KEY') ?: ''));
        if ($env !== '') {
            return $env;
        }
        $envMaps = trim((string)(getenv('GOOGLE_MAPS_API_KEY') ?: ''));
        if ($envMaps !== '') {
            return $envMaps;
        }
        $encrypted = decryptSecret((string)($row['google_places_api_key_enc'] ?? ''));
        if ($encrypted !== '') {
            return $encrypted;
        }
        return trim((string)($row['google_maps_api_key'] ?? ''));
    }

    public function normalizeAiProvider(string $provider): string
    {
        return in_array($provider, ['openai', 'claude', 'disabled'], true) ? $provider : 'disabled';
    }

    public function normalizeSearchProvider(string $provider): string
    {
        return in_array($provider, ['google_places', 'disabled'], true) ? $provider : 'disabled';
    }

    public static function masked(bool $configured): string
    {
        return $configured ? '••••••••saved' : '';
    }
}
