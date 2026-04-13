<?php

declare(strict_types=1);

final class AppUpdateMaintenanceGuard
{
    private const HEARTBEAT_INTERVAL_SECONDS = 30;

    /**
     * @return list<string>
     */
    public static function allowedPathHints(): array
    {
        return [
            '/admin/updates.php',
            '/admin/index.php',
            '/index.php',
            '/login.php',
            '/assets/*',
        ];
    }

    public static function enforce(?PDO $pdo, string $baseUrl = ''): void
    {
        if (PHP_SAPI === 'cli' || !$pdo instanceof PDO) {
            return;
        }

        $requestPath = self::normalizedPath($baseUrl);
        if (self::isWhitelistedPath($requestPath)) {
            return;
        }

        $columns = self::tableColumns($pdo, 'app_meta');
        $required = ['update_maintenance_mode', 'update_state', 'update_run_id', 'update_last_error'];
        if (count(array_intersect($required, $columns)) !== count($required)) {
            return;
        }

        $stmt = $pdo->query(
            'SELECT update_maintenance_mode, update_state, update_run_id, update_last_error
             FROM app_meta
             WHERE id = 1
             LIMIT 1'
        );
        $row = $stmt ? (array)($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        if ($stmt) {
            $stmt->closeCursor();
        }

        $maintenanceMode = !empty($row['update_maintenance_mode']);
        $state = trim((string)($row['update_state'] ?? ''));
        if (!$maintenanceMode || !in_array($state, ['running', 'failed'], true)) {
            return;
        }

        self::emitMaintenanceResponse($requestPath, $baseUrl, (string)($row['update_run_id'] ?? ''), (string)($row['update_last_error'] ?? ''));
    }

    private static function normalizedPath(string $baseUrl): string
    {
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $path = '/' . ltrim($path, '/');

        $normalizedBase = trim($baseUrl, '/');
        if ($normalizedBase !== '') {
            $prefix = '/' . $normalizedBase;
            if (strpos($path, $prefix) === 0) {
                $path = substr($path, strlen($prefix)) ?: '/';
            }
        }

        return '/' . ltrim($path, '/');
    }

    private static function isWhitelistedPath(string $path): bool
    {
        if ($path === '/' || $path === '/index.php') {
            return true;
        }

        foreach ([
            '/login.php',
            '/admin/index.php',
            '/admin/updates.php',
            '/assets/',
        ] as $allowedPrefix) {
            if ($allowedPrefix === '/assets/' && strpos($path, '/assets/') === 0) {
                return true;
            }
            if ($path === $allowedPrefix) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    private static function tableColumns(PDO $pdo, string $table): array
    {
        try {
            $existsStmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
            $exists = (bool)($existsStmt && $existsStmt->fetchColumn());
            if ($existsStmt) {
                $existsStmt->closeCursor();
            }
            if (!$exists) {
                return [];
            }

            $columnsStmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
            if ($columnsStmt === false) {
                return [];
            }

            $columns = [];
            while (($row = $columnsStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $field = trim((string)($row['Field'] ?? ''));
                if ($field !== '') {
                    $columns[] = $field;
                }
            }
            $columnsStmt->closeCursor();

            return $columns;
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function emitMaintenanceResponse(string $requestPath, string $baseUrl, string $runId, string $lastError): void
    {
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $wantsJson = strpos($accept, 'application/json') !== false || str_starts_with($requestPath, '/api/');
        $baseUrl = rtrim($baseUrl, '/');
        $loginHref = ($baseUrl !== '' ? $baseUrl : '') . '/index.php';
        $updatesHref = ($baseUrl !== '' ? $baseUrl : '') . '/admin/updates.php';

        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: ' . self::HEARTBEAT_INTERVAL_SECONDS);
        }

        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'maintenance_mode' => true,
                'message' => 'Trwa aktualizacja systemu. Ten endpoint jest tymczasowo niedostępny.',
                'run_id' => $runId,
                'last_error' => $lastError,
                'allowed_paths' => self::allowedPathHints(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8"><title>Aktualizacja systemu</title>';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<style>body{font-family:system-ui,sans-serif;background:#f6f3ee;color:#1f1b16;margin:0;padding:2rem}main{max-width:720px;margin:0 auto;background:#fff;padding:2rem;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.08)}a{color:#0d5b8f}code{background:#f2eee8;padding:.15rem .35rem;border-radius:6px}</style></head><body><main>';
        echo '<h1>Trwa aktualizacja systemu</h1>';
        echo '<p>Aplikacja jest chwilowo w maintenance mode, ponieważ trwa albo czeka na wznowienie finalizacja wdrożonej wersji.</p>';
        echo '<p>Dostępne podczas aktualizacji pozostają tylko: <code>/admin/updates.php</code>, <code>/admin/index.php</code>, ekran logowania i wymagane assety.</p>';
        if ($runId !== '') {
            echo '<p>Identyfikator runu: <code>' . htmlspecialchars($runId, ENT_QUOTES, 'UTF-8') . '</code></p>';
        }
        if ($lastError !== '') {
            echo '<p>Ostatni błąd: ' . htmlspecialchars($lastError, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        echo '<p><a href="' . htmlspecialchars($loginHref, ENT_QUOTES, 'UTF-8') . '">Przejdź do logowania</a> lub <a href="' . htmlspecialchars($updatesHref, ENT_QUOTES, 'UTF-8') . '">otwórz ekran aktualizacji</a>.</p>';
        echo '</main></body></html>';
        exit;
    }
}
