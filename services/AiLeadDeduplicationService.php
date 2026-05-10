<?php
declare(strict_types=1);

final class AiLeadDeduplicationService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<string,mixed> $leadData
     * @return array{matches:array<int,array<string,mixed>>,score:int,recommended_status:string}
     */
    public function checkDuplicates(array $leadData): array
    {
        $normalizedLead = [
            'company_name' => self::normalizeCompanyName((string)($leadData['company_name'] ?? '')),
            'city' => self::normalizeText((string)($leadData['city'] ?? '')),
            'phone' => self::normalizePhone((string)($leadData['phone'] ?? '')),
            'website_domain' => self::normalizeWebsiteDomain((string)($leadData['website'] ?? '')),
            'nip' => self::normalizeNip((string)($leadData['nip'] ?? '')),
        ];

        $matches = array_merge(
            $this->findMatchesInLeads($normalizedLead),
            $this->findMatchesInClients($normalizedLead)
        );

        usort($matches, static function (array $a, array $b): int {
            return (int)$b['match_score'] <=> (int)$a['match_score'];
        });

        $topScore = isset($matches[0]) ? (int)$matches[0]['match_score'] : 0;

        return [
            'matches' => $matches,
            'score' => $topScore,
            'recommended_status' => $topScore >= 75 ? 'duplicate' : 'safe',
        ];
    }

    /**
     * @param array<string,string> $lead
     * @return array<int,array<string,mixed>>
     */
    private function findMatchesInLeads(array $lead): array
    {
        if (!$this->tableExists('leady')) {
            return [];
        }

        $stmt = $this->pdo->query(
            "SELECT id, nazwa_firmy, nip, telefon, miasto, email
             FROM leady
             ORDER BY id DESC
             LIMIT 1000"
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return $this->scoreRows($lead, $rows, 'lead', [
            'name' => 'nazwa_firmy',
            'nip' => 'nip',
            'phone' => 'telefon',
            'city' => 'miasto',
            'website' => null,
        ]);
    }

    /**
     * @param array<string,string> $lead
     * @return array<int,array<string,mixed>>
     */
    private function findMatchesInClients(array $lead): array
    {
        if (!$this->tableExists('klienci')) {
            return [];
        }

        $stmt = $this->pdo->query(
            "SELECT id, nazwa_firmy, nip, telefon, miejscowosc, strona_www, email
             FROM klienci
             ORDER BY id DESC
             LIMIT 1000"
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return $this->scoreRows($lead, $rows, 'client', [
            'name' => 'nazwa_firmy',
            'nip' => 'nip',
            'phone' => 'telefon',
            'city' => 'miejscowosc',
            'website' => 'strona_www',
        ]);
    }

    /**
     * @param array<string,string> $lead
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,string|null> $columns
     * @return array<int,array<string,mixed>>
     */
    private function scoreRows(array $lead, array $rows, string $type, array $columns): array
    {
        $matches = [];

        foreach ($rows as $row) {
            $score = 0;
            $reasons = [];

            $rowNip = self::normalizeNip((string)($row[$columns['nip']] ?? ''));
            if ($lead['nip'] !== '' && $rowNip !== '' && $lead['nip'] === $rowNip) {
                $score = 100;
                $reasons[] = 'same_nip';
            }

            $rowPhone = self::normalizePhone((string)($row[$columns['phone']] ?? ''));
            if ($lead['phone'] !== '' && $rowPhone !== '' && $lead['phone'] === $rowPhone) {
                $score = max($score, 80);
                $reasons[] = 'same_phone';
            }

            $websiteColumn = $columns['website'];
            $rowDomain = $websiteColumn ? self::normalizeWebsiteDomain((string)($row[$websiteColumn] ?? '')) : '';
            if ($lead['website_domain'] !== '' && $rowDomain !== '' && $lead['website_domain'] === $rowDomain) {
                $score = max($score, 75);
                $reasons[] = 'same_website_domain';
            }

            $rowName = self::normalizeCompanyName((string)($row[$columns['name']] ?? ''));
            $rowCity = self::normalizeText((string)($row[$columns['city']] ?? ''));
            if ($lead['company_name'] !== '' && $rowName !== '' && $lead['city'] !== '' && $lead['city'] === $rowCity) {
                $similarity = $this->nameSimilarityPercent($lead['company_name'], $rowName);
                if ($similarity >= 70) {
                    $nameScore = max(60, (int)round($similarity * 0.8));
                    $score = max($score, min(74, $nameScore));
                    $reasons[] = 'similar_name_same_city';
                }
            }

            if ($score <= 0) {
                continue;
            }

            $matches[] = [
                'matched_type' => $type,
                'matched_id' => (int)$row['id'],
                'match_score' => $score,
                'reason' => implode(',', array_values(array_unique($reasons))),
                'company_name' => (string)($row[$columns['name']] ?? ''),
            ];
        }

        return $matches;
    }

    private function tableExists(string $table): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return false;
        }

        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE " . $this->pdo->quote($table));
            return $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function nameSimilarityPercent(string $left, string $right): float
    {
        similar_text($left, $right, $percent);
        return (float)$percent;
    }

    public static function normalizeCompanyName(string $value): string
    {
        $value = self::normalizeText($value);
        $patterns = [
            '/\bspolka z ograniczona odpowiedzialnoscia\b/u',
            '/\bsp z o o\b/u',
            '/\bsp zoo\b/u',
            '/\bs a\b/u',
            '/\bsa\b/u',
            '/\bsc\b/u',
            '/\bs c\b/u',
        ];
        $value = preg_replace($patterns, ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return trim($value);
    }

    public static function normalizeText(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($transliterated) && $transliterated !== '') {
            $value = $transliterated;
        }
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return trim($value);
    }

    public static function normalizePhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '48')) {
            return substr($digits, 2);
        }
        return $digits;
    }

    public static function normalizeNip(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    public static function normalizeWebsiteDomain(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        if ($value === '') {
            return '';
        }
        if (!preg_match('#^https?://#', $value)) {
            $value = 'https://' . $value;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        return trim($host);
    }
}
