<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/db_schema.php';

function ensureAdminAudit(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_actions_audit (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INT NULL,
        username VARCHAR(80) NULL,
        action VARCHAR(50) NOT NULL,
        scope VARCHAR(20) NOT NULL,
        filters_json TEXT NULL,
        ids_json TEXT NULL,
        affected_count INT NOT NULL DEFAULT 0,
        ip VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    );");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admin_actions_action ON admin_actions_audit(action)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_admin_actions_created_at ON admin_actions_audit(created_at)');
}

function adminAuditLog(PDO $pdo, string $action, string $scope, array $user, array $filters, array $ids, int $count, array $meta = []): void
{
    ensureAdminAudit($pdo);
    $stmt = $pdo->prepare('INSERT INTO admin_actions_audit (user_id, username, action, scope, filters_json, ids_json, affected_count, ip, user_agent) VALUES (:uid, :uname, :action, :scope, :filters, :ids, :cnt, :ip, :ua)');
    $stmt->execute([
        ':uid' => $user['id'] ?? null,
        ':uname' => $user['login'] ?? ($user['username'] ?? null),
        ':action' => $action,
        ':scope' => $scope,
        ':filters' => $filters ? json_encode($filters, JSON_UNESCAPED_UNICODE) : null,
        ':ids' => $ids ? json_encode($ids, JSON_UNESCAPED_UNICODE) : null,
        ':cnt' => $count,
        ':ip' => $meta['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
        ':ua' => $meta['ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
    ]);
}

function adminAuditLast(PDO $pdo, int $limit = 20): array
{
    ensureAdminAudit($pdo);
    $stmt = $pdo->prepare('SELECT * FROM admin_actions_audit ORDER BY created_at DESC, id DESC LIMIT :lim');
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
