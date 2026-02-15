<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/db_schema.php';
require_once __DIR__ . '/gus_refresh_queue.php';

class GusQueueStatus
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getForCompanyId(int $companyId): array
    {
        if ($companyId <= 0) {
            return [];
        }
        $sql = "SELECT c.id AS company_id,
                       c.gus_last_refresh_at,
                       c.gus_last_status,
                       c.gus_last_error_code,
                       c.gus_last_error_message,
                       q.status AS q_status,
                       q.next_run_at,
                       q.attempts,
                       q.last_error_code AS q_err_code,
                       q.last_error_message AS q_err_msg,
                       q.error_class,
                       c.gus_hold_until,
                       c.gus_hold_reason,
                       c.gus_not_found,
                       c.gus_not_found_at,
                       c.gus_last_error_class
                FROM companies c
                LEFT JOIN (
                    SELECT q1.* FROM gus_refresh_queue q1
                    WHERE q1.company_id = :cid
                    ORDER BY CASE q1.status WHEN 'running' THEN 1 WHEN 'pending' THEN 2 WHEN 'failed' THEN 3 ELSE 4 END,
                             q1.priority ASC, q1.next_run_at ASC
                    LIMIT 1
                ) q ON q.company_id = c.id
                WHERE c.id = :cid";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':cid' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stmt->closeCursor();
        return $this->mapRow($row);
    }

    public function enrichCompanies(array $companyIds): array
    {
        $companyIds = array_values(array_filter(array_map('intval', $companyIds), static fn($v) => $v > 0));
        if (!$companyIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($companyIds), '?'));
        $sql = "WITH best AS (
                    SELECT q.company_id,
                           q.status,
                           q.next_run_at,
                           q.attempts,
                           q.last_error_code,
                           q.last_error_message,
                           q.error_class,
                           ROW_NUMBER() OVER (PARTITION BY q.company_id ORDER BY CASE q.status WHEN 'running' THEN 1 WHEN 'pending' THEN 2 WHEN 'failed' THEN 3 ELSE 4 END, q.priority ASC, q.next_run_at ASC) AS rn
                    FROM gus_refresh_queue q
                    WHERE q.company_id IN ($in)
                )
                SELECT c.id AS company_id,
                       c.gus_last_refresh_at,
                       c.gus_last_status,
                       c.gus_last_error_code,
                       c.gus_last_error_message,
                       b.status AS q_status,
                       b.next_run_at,
                       b.attempts,
                       b.last_error_code AS q_err_code,
                       b.last_error_message AS q_err_msg,
                       b.error_class
                FROM companies c
                LEFT JOIN best b ON b.company_id = c.id AND b.rn = 1
                WHERE c.id IN ($in)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge($companyIds, $companyIds));
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[(int)$row['company_id']] = $this->mapRow($row);
        }
        $stmt->closeCursor();
        return $map;
    }

    private function mapRow(array $row): array
    {
        if (!$row) {
            return [];
        }
        $queueState = $row['q_status'] ?? null;
        $queueState = $queueState ?: 'none';
        $nextRun = $row['next_run_at'] ?? null;
        $eta = null;
        if ($queueState === 'pending' && $nextRun) {
            $etaSec = strtotime($nextRun) - time();
            if ($etaSec > 0) {
                $eta = (int)ceil($etaSec / 60);
            }
        }
        $holdUntil = $row['gus_hold_until'] ?? null;
        $notFound = !empty($row['gus_not_found']);
        return [
            'queue_state' => $queueState,
            'queue_next_run_at' => $nextRun,
            'queue_eta_minutes' => $eta,
            'queue_attempts' => isset($row['attempts']) ? (int)$row['attempts'] : null,
            'queue_last_error_code' => $row['q_err_code'] ?? null,
            'queue_last_error_message' => $this->shorten($row['q_err_msg'] ?? null),
            'queue_error_class' => $row['error_class'] ?? null,
            'last_refresh_at' => $row['gus_last_refresh_at'] ?? null,
            'last_status' => $row['gus_last_status'] ?? null,
            'last_error_code' => $row['gus_last_error_code'] ?? null,
            'last_error_message' => $this->shorten($row['gus_last_error_message'] ?? null),
            'gus_hold_until' => $holdUntil,
            'gus_hold_reason' => $row['gus_hold_reason'] ?? null,
            'gus_not_found' => $notFound ? 1 : 0,
            'gus_not_found_at' => $row['gus_not_found_at'] ?? null,
            'gus_last_error_class' => $row['gus_last_error_class'] ?? null,
        ];
    }

    private function shorten(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        return mb_strimwidth($text, 0, 200, '...');
    }
}
