<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/db_schema.php';

class GusQueueMetrics
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function summary(): array
    {
        ensureGusRefreshQueue($this->pdo);
        $sql = "SELECT
            SUM(status='pending') AS pending_count,
            SUM(status='running') AS running_count,
            SUM(status='failed') AS failed_count,
            SUM(status='done' AND updated_at > DATE_SUB(NOW(), INTERVAL 1 DAY)) AS done_last_24h,
            SUM(status='running' AND locked_at IS NOT NULL AND locked_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)) AS stuck_count,
            MAX(TIMESTAMPDIFF(SECOND, next_run_at, NOW())) AS oldest_pending_age_sec,
            SUM(status='failed' AND updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS fail_last_1h,
            SUM(status='done' AND updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS ok_last_1h,
            MAX(CASE WHEN status='done' THEN COALESCE(finished_at, updated_at) END) AS last_done_at
        FROM gus_refresh_queue";
        $stmt = $this->pdo->query($sql);
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [];
        return [
            'pending_count' => (int)($row['pending_count'] ?? 0),
            'running_count' => (int)($row['running_count'] ?? 0),
            'failed_count' => (int)($row['failed_count'] ?? 0),
            'done_last_24h' => (int)($row['done_last_24h'] ?? 0),
            'stuck_count' => (int)($row['stuck_count'] ?? 0),
            'oldest_pending_age_sec' => $row['oldest_pending_age_sec'] !== null ? (int)$row['oldest_pending_age_sec'] : null,
            'fail_last_1h' => (int)($row['fail_last_1h'] ?? 0),
            'ok_last_1h' => (int)($row['ok_last_1h'] ?? 0),
            'last_done_at' => $row['last_done_at'] ?? null,
        ];
    }
}
