<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/db_schema.php';

function ensureWorkerLocksTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS worker_locks (
        name VARCHAR(50) PRIMARY KEY,
        locked_by VARCHAR(120) NULL,
        locked_at DATETIME NULL,
        heartbeat_at DATETIME NULL,
        expires_at DATETIME NULL,
        meta_json TEXT NULL
    );");
}

class WorkerLock
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        ensureWorkerLocksTable($pdo);
    }

    public function acquire(string $name, string $ownerId, int $ttlSeconds = 600): array
    {
        $now = time();
        $nowStr = date('Y-m-d H:i:s', $now);
        $expiresStr = date('Y-m-d H:i:s', $now + $ttlSeconds);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT locked_by, expires_at, locked_at, heartbeat_at FROM worker_locks WHERE name = :name LIMIT 1');
            $stmt->execute([':name' => $name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $stmt->closeCursor();
            $expired = !$row || empty($row['expires_at']) || strtotime((string)$row['expires_at']) < $now;

            if ($expired) {
                if ($row) {
                    $stmt = $this->pdo->prepare('UPDATE worker_locks SET locked_by=:by, locked_at=:now, heartbeat_at=:now2, expires_at=:exp WHERE name=:name');
                    $stmt->execute([
                        ':by' => $ownerId,
                        ':now' => $nowStr,
                        ':now2' => $nowStr,
                        ':exp' => $expiresStr,
                        ':name' => $name,
                    ]);
                } else {
                    $stmt = $this->pdo->prepare('INSERT INTO worker_locks (name, locked_by, locked_at, heartbeat_at, expires_at) VALUES (:name, :by, :now, :now2, :exp)');
                    $stmt->execute([
                        ':name' => $name,
                        ':by' => $ownerId,
                        ':now' => $nowStr,
                        ':now2' => $nowStr,
                        ':exp' => $expiresStr,
                    ]);
                }
                $this->pdo->commit();
                return ['ok' => true, 'reason' => null, 'lock' => $this->getStatus($name)];
            }

            $this->pdo->rollBack();
            return ['ok' => false, 'reason' => 'already_locked', 'lock' => $row];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function heartbeat(string $name, string $ownerId, int $ttlSeconds = 600): void
    {
        $now = time();
        $nowStr = date('Y-m-d H:i:s', $now);
        $expiresStr = date('Y-m-d H:i:s', $now + $ttlSeconds);
        $stmt = $this->pdo->prepare('UPDATE worker_locks SET heartbeat_at = :hb, expires_at = :exp WHERE name = :name AND locked_by = :by');
        $stmt->execute([':hb' => $nowStr, ':exp' => $expiresStr, ':name' => $name, ':by' => $ownerId]);
    }

    public function release(string $name, string $ownerId): void
    {
        $stmt = $this->pdo->prepare('UPDATE worker_locks SET locked_by=NULL, locked_at=NULL, heartbeat_at=NULL, expires_at=NULL WHERE name = :name AND locked_by = :by');
        $stmt->execute([':name' => $name, ':by' => $ownerId]);
    }

    public function getStatus(string $name): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM worker_locks WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt->closeCursor();
        if (!$row) {
            return ['locked' => false, 'locked_by' => null, 'locked_at' => null, 'heartbeat_at' => null, 'expires_at' => null];
        }
        $locked = !empty($row['locked_by']) && !empty($row['expires_at']) && strtotime((string)$row['expires_at']) > time();
        return [
            'locked' => $locked,
            'locked_by' => $row['locked_by'],
            'locked_at' => $row['locked_at'],
            'heartbeat_at' => $row['heartbeat_at'],
            'expires_at' => $row['expires_at'],
            'meta_json' => $row['meta_json'] ?? null,
        ];
    }
}
