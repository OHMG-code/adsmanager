<?php
declare(strict_types=1);

require_once __DIR__ . '/AiLeadDeduplicationService.php';

final class AiLeadImportService
{
    private PDO $pdo;
    private AiLeadDeduplicationService $deduplicationService;

    public function __construct(PDO $pdo, ?AiLeadDeduplicationService $deduplicationService = null)
    {
        $this->pdo = $pdo;
        $this->deduplicationService = $deduplicationService ?? new AiLeadDeduplicationService($pdo);
    }

    /**
     * @param array<int,array<string,mixed>> $leads
     * @return array<int,array<string,mixed>>
     */
    public function importGeneratedLeads(array $leads, int $userId): array
    {
        $saved = [];

        $this->pdo->beginTransaction();
        try {
            foreach ($leads as $lead) {
                if (!is_array($lead)) {
                    continue;
                }

                $cleanLead = $this->normalizeLeadPayload($lead);
                if ($cleanLead['company_name'] === '' || $cleanLead['city'] === '' || $cleanLead['industry'] === '') {
                    continue;
                }

                $dedupe = $this->deduplicationService->checkDuplicates($cleanLead);
                $status = $dedupe['recommended_status'] === 'duplicate' ? 'duplicate' : 'new';

                $insertData = [
                    'company_name' => $cleanLead['company_name'],
                    'city' => $cleanLead['city'],
                    'phone' => $cleanLead['phone'] !== '' ? $cleanLead['phone'] : null,
                    'email' => $cleanLead['email'] !== '' ? $cleanLead['email'] : null,
                    'website' => $cleanLead['website'] !== '' ? $cleanLead['website'] : null,
                    'industry' => $cleanLead['industry'],
                    'score' => $cleanLead['score'],
                    'source' => $cleanLead['source'],
                    'status' => $status,
                    'assigned_user_id' => $userId > 0 ? $userId : null,
                    'external_id' => $cleanLead['external_id'] !== '' ? $cleanLead['external_id'] : null,
                    'recommended_package' => $cleanLead['recommended_package'] !== '' ? $cleanLead['recommended_package'] : null,
                    'opening_argument' => $cleanLead['opening_argument'] !== '' ? $cleanLead['opening_argument'] : null,
                    'short_reason' => $cleanLead['short_reason'] !== '' ? $cleanLead['short_reason'] : null,
                    'suggested_next_action' => $cleanLead['suggested_next_action'] !== '' ? $cleanLead['suggested_next_action'] : null,
                    'enrichment_status' => $cleanLead['enrichment_status'] !== '' ? $cleanLead['enrichment_status'] : null,
                    'raw_source_data' => $cleanLead['raw_source_data'] !== '' ? $cleanLead['raw_source_data'] : null,
                ];
                $insertData = $this->filterColumns('ai_leads_import', $insertData);
                $columns = array_keys($insertData);
                $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
                $stmt = $this->pdo->prepare(
                    "INSERT INTO ai_leads_import (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")"
                );
                $params = [];
                foreach ($insertData as $column => $value) {
                    $params[':' . $column] = $value;
                }
                $stmt->execute($params);

                $aiLeadId = (int)$this->pdo->lastInsertId();
                foreach ($dedupe['matches'] as $match) {
                    $this->insertDuplicate($aiLeadId, $match);
                }

                $saved[] = [
                    'id' => $aiLeadId,
                    'status' => $status,
                    'duplicate_score' => $dedupe['score'],
                    'duplicates' => $dedupe['matches'],
                    'lead' => $cleanLead,
                ];
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $saved;
    }

    /**
     * @param array<string,mixed> $match
     */
    private function insertDuplicate(int $aiLeadId, array $match): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO ai_leads_duplicates
                (ai_lead_id, matched_type, matched_id, match_score, reason)
             VALUES
                (:ai_lead_id, :matched_type, :matched_id, :match_score, :reason)"
        );
        $stmt->execute([
            ':ai_lead_id' => $aiLeadId,
            ':matched_type' => (string)$match['matched_type'],
            ':matched_id' => (int)$match['matched_id'],
            ':match_score' => (int)$match['match_score'],
            ':reason' => substr((string)$match['reason'], 0, 255),
        ]);
    }

    /**
     * @param array<string,mixed> $lead
     * @return array<string,mixed>
     */
    private function normalizeLeadPayload(array $lead): array
    {
        return [
            'company_name' => trim((string)($lead['company_name'] ?? '')),
            'city' => trim((string)($lead['city'] ?? '')),
            'phone' => trim((string)($lead['phone'] ?? '')),
            'email' => trim((string)($lead['email'] ?? '')),
            'website' => trim((string)($lead['website'] ?? '')),
            'industry' => trim((string)($lead['industry'] ?? '')),
            'score' => max(0, min(100, (int)($lead['score'] ?? 0))),
            'source' => trim((string)($lead['source'] ?? 'ai_generated')) ?: 'ai_generated',
            'nip' => trim((string)($lead['nip'] ?? '')),
            'external_id' => trim((string)($lead['external_id'] ?? '')),
            'recommended_package' => trim((string)($lead['recommended_package'] ?? '')),
            'opening_argument' => trim((string)($lead['opening_argument'] ?? '')),
            'short_reason' => trim((string)($lead['short_reason'] ?? '')),
            'suggested_next_action' => trim((string)($lead['suggested_next_action'] ?? '')),
            'enrichment_status' => trim((string)($lead['enrichment_status'] ?? '')),
            'raw_source_data' => array_key_exists('raw_source_data', $lead)
                ? (is_string($lead['raw_source_data']) ? (string)$lead['raw_source_data'] : (json_encode($lead['raw_source_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''))
                : '',
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function filterColumns(string $table, array $data): array
    {
        $stmt = $this->pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "`");
        $columns = [];
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns[strtolower((string)$row['Field'])] = true;
            }
        }
        return array_filter($data, static fn (string $column): bool => isset($columns[strtolower($column)]), ARRAY_FILTER_USE_KEY);
    }
}
