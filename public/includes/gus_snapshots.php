<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';

function gusMaskIdentifier(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    $len = strlen($digits);
    if ($len <= 4) {
        return $digits !== '' ? str_repeat('*', $len) : '';
    }
    return str_repeat('*', $len - 4) . substr($digits, -4);
}

class GusSnapshotRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        ensureGusSnapshotsTable($this->pdo);
    }

    public function save(array $data): int
    {
        $rawParsed = $data['raw_parsed'] ?? null;
        if (is_array($rawParsed)) {
            $rawParsed = json_encode($rawParsed, JSON_UNESCAPED_UNICODE);
        }

        $stmt = $this->pdo->prepare("INSERT INTO gus_snapshots
            (company_id, env, request_type, request_value, report_type, http_code, ok, error_code, error_message,
             fault_code, fault_string, raw_request, raw_response, raw_parsed, correlation_id, attempt_no, latency_ms, error_class)
            VALUES
            (:company_id, :env, :request_type, :request_value, :report_type, :http_code, :ok, :error_code, :error_message,
             :fault_code, :fault_string, :raw_request, :raw_response, :raw_parsed, :correlation_id, :attempt_no, :latency_ms, :error_class)");

        $stmt->execute([
            ':company_id' => isset($data['company_id']) && $data['company_id'] !== null ? (int)$data['company_id'] : null,
            ':env' => (string)($data['env'] ?? ''),
            ':request_type' => (string)($data['request_type'] ?? ''),
            ':request_value' => (string)($data['request_value'] ?? ''),
            ':report_type' => $data['report_type'] !== null ? (string)$data['report_type'] : null,
            ':http_code' => $data['http_code'] !== null ? (int)$data['http_code'] : null,
            ':ok' => !empty($data['ok']) ? 1 : 0,
            ':error_code' => $data['error_code'] !== null ? (string)$data['error_code'] : null,
            ':error_message' => $data['error_message'] !== null ? (string)$data['error_message'] : null,
            ':fault_code' => $data['fault_code'] !== null ? (string)$data['fault_code'] : null,
            ':fault_string' => $data['fault_string'] !== null ? (string)$data['fault_string'] : null,
            ':raw_request' => $data['raw_request'] !== null ? (string)$data['raw_request'] : null,
            ':raw_response' => $data['raw_response'] !== null ? (string)$data['raw_response'] : null,
            ':raw_parsed' => $rawParsed,
            ':correlation_id' => $data['correlation_id'] !== null ? (string)$data['correlation_id'] : null,
            ':attempt_no' => isset($data['attempt_no']) && $data['attempt_no'] !== null ? (int)$data['attempt_no'] : null,
            ':latency_ms' => isset($data['latency_ms']) && $data['latency_ms'] !== null ? (int)$data['latency_ms'] : null,
            ':error_class' => isset($data['error_class']) && $data['error_class'] !== null ? (string)$data['error_class'] : null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function search(array $filters, int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['env'])) {
            $where[] = 'env = :env';
            $params[':env'] = $filters['env'];
        }
        if (!empty($filters['request_value'])) {
            $where[] = 'request_value LIKE :request_value';
            $params[':request_value'] = '%' . $filters['request_value'] . '%';
        }
        if (isset($filters['ok']) && $filters['ok'] !== '') {
            $where[] = 'ok = :ok';
            $params[':ok'] = (int)$filters['ok'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= :date_from';
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= :date_to';
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $sql = 'SELECT * FROM gus_snapshots';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function count(array $filters): int
    {
        $where = [];
        $params = [];

        if (!empty($filters['env'])) {
            $where[] = 'env = :env';
            $params[':env'] = $filters['env'];
        }
        if (!empty($filters['request_value'])) {
            $where[] = 'request_value LIKE :request_value';
            $params[':request_value'] = '%' . $filters['request_value'] . '%';
        }
        if (isset($filters['ok']) && $filters['ok'] !== '') {
            $where[] = 'ok = :ok';
            $params[':ok'] = (int)$filters['ok'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= :date_from';
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= :date_to';
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $sql = 'SELECT COUNT(*) FROM gus_snapshots';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}
