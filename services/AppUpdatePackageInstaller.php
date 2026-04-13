<?php

declare(strict_types=1);

require_once __DIR__ . '/ReleaseInfo.php';

final class AppUpdatePackageInstaller
{
    public const DEFAULT_EXCLUDE_PREFIXES = [
        'config/',
        'storage/',
        'uploads/',
        'public/uploads/',
    ];

    private string $rootDir;
    private string $workspaceDir;
    private string $backupDir;
    private int $connectTimeoutSeconds;
    private int $timeoutSeconds;
    private int $maxDownloadBytes;
    private int $maxExtractBytes;

    /**
     * @param array{workspace_dir?:string,backup_dir?:string,connect_timeout?:int,timeout?:int,max_download_bytes?:int,max_extract_bytes?:int} $options
     */
    public function __construct(?string $rootDir = null, array $options = [])
    {
        $this->rootDir = $rootDir !== null ? rtrim($rootDir, '/') : dirname(__DIR__);
        $this->workspaceDir = $options['workspace_dir'] ?? ($this->rootDir . '/storage/cache/app-update-runs');
        $this->backupDir = $options['backup_dir'] ?? ($this->rootDir . '/storage/backups');
        $this->connectTimeoutSeconds = max(1, (int)($options['connect_timeout'] ?? 10));
        $this->timeoutSeconds = max($this->connectTimeoutSeconds, (int)($options['timeout'] ?? 120));
        $this->maxDownloadBytes = max(1024 * 1024, (int)($options['max_download_bytes'] ?? 250 * 1024 * 1024));
        $this->maxExtractBytes = max($this->maxDownloadBytes, (int)($options['max_extract_bytes'] ?? 500 * 1024 * 1024));
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    public function resolveRemotePackage(array $manifest, string $localVersion): array
    {
        $latestVersion = trim((string)($manifest['latest_version'] ?? ''));
        $latestRelease = (array)($manifest['latest_release'] ?? []);
        $downloadUrl = trim((string)($latestRelease['download_url'] ?? ''));
        $changelog = $this->normalizeChangelog($latestRelease['changelog'] ?? []);

        $comparison = $this->compareVersions($latestVersion, trim($localVersion));
        $available = $comparison !== null && $comparison > 0;
        $downloadValid = $downloadUrl !== '' && ReleaseInfo::isValidHttpsUrl($downloadUrl);

        $error = '';
        if ($available && !$downloadValid) {
            $error = 'Manifest wskazuje nowszą wersję, ale nie zawiera poprawnego download_url (HTTPS).';
        }

        return [
            'available' => $available,
            'latest_version' => $latestVersion,
            'download_url' => $downloadUrl,
            'download_url_valid' => $downloadValid,
            'changelog' => $changelog,
            'error' => $error,
        ];
    }

    /**
     * @return array<string,string>
     */
    public function runWorkspace(string $runId): array
    {
        $safeRunId = preg_replace('/[^A-Za-z0-9._-]/', '-', $runId) ?: 'update-run';
        $runDir = $this->workspaceDir . '/' . $safeRunId;
        $extractDir = $runDir . '/extract';
        $downloadPath = $runDir . '/package.zip';

        $dbBackupPath = $this->backupDir . '/db-update-' . $safeRunId . '-' . date('Ymd_His') . '.sql';
        $filesBackupPath = $this->backupDir . '/files-update-' . $safeRunId . '-' . date('Ymd_His') . '.zip';

        $this->ensureDir($runDir);
        $this->ensureDir($extractDir);
        $this->ensureDir($this->backupDir);

        return [
            'run_dir' => $runDir,
            'extract_dir' => $extractDir,
            'download_path' => $downloadPath,
            'db_backup_path' => $dbBackupPath,
            'files_backup_path' => $filesBackupPath,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function downloadZip(string $url, string $destinationPath): array
    {
        $url = trim($url);
        if ($url === '' || !ReleaseInfo::isValidHttpsUrl($url)) {
            return [
                'ok' => false,
                'error' => 'Download URL musi być poprawnym adresem HTTPS.',
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'error' => 'Brakuje rozszerzenia cURL potrzebnego do pobrania paczki.',
            ];
        }

        $this->ensureDir(dirname($destinationPath));
        if (is_file($destinationPath)) {
            @unlink($destinationPath);
        }

        $fp = @fopen($destinationPath, 'wb');
        if ($fp === false) {
            return [
                'ok' => false,
                'error' => 'Nie udało się otworzyć pliku docelowego dla paczki.',
            ];
        }

        $downloaded = 0;
        $limit = $this->maxDownloadBytes;
        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fp);
            @unlink($destinationPath);
            return [
                'ok' => false,
                'error' => 'Nie udało się zainicjalizować pobierania.',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/octet-stream'],
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function (
                mixed $resource,
                float $downloadSize,
                float $downloadedNow
            ) use (&$downloaded, $limit): int {
                $downloaded = (int)$downloadedNow;
                if ($downloaded > $limit) {
                    return 1;
                }
                return 0;
            },
        ]);

        $ok = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        $size = is_file($destinationPath) ? (int)@filesize($destinationPath) : 0;

        if ($ok !== true) {
            @unlink($destinationPath);
            $msg = $curlError !== '' ? $curlError : 'Nie udało się pobrać paczki aktualizacji.';
            if ($downloaded > $limit) {
                $msg = 'Pobrana paczka przekroczyła bezpieczny limit rozmiaru.';
            }
            return [
                'ok' => false,
                'error' => $msg,
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            @unlink($destinationPath);
            return [
                'ok' => false,
                'error' => 'Serwer aktualizacji zwrócił HTTP ' . $httpCode . '.',
            ];
        }

        if ($size <= 0) {
            @unlink($destinationPath);
            return [
                'ok' => false,
                'error' => 'Pobrana paczka jest pusta.',
            ];
        }

        if ($size > $this->maxDownloadBytes) {
            @unlink($destinationPath);
            return [
                'ok' => false,
                'error' => 'Pobrana paczka przekroczyła bezpieczny limit rozmiaru.',
            ];
        }

        return [
            'ok' => true,
            'path' => $destinationPath,
            'bytes' => $size,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function extractZip(string $zipPath, string $extractDir): array
    {
        if (!class_exists('ZipArchive')) {
            return [
                'ok' => false,
                'error' => 'Brakuje rozszerzenia ZipArchive potrzebnego do rozpakowania paczki.',
            ];
        }

        if (!is_file($zipPath) || !is_readable($zipPath)) {
            return [
                'ok' => false,
                'error' => 'Nie znaleziono pobranej paczki ZIP.',
            ];
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            return [
                'ok' => false,
                'error' => 'Nie udało się otworzyć paczki ZIP.',
            ];
        }

        $validation = $this->validateZipEntries($zip);
        if (!$validation['ok']) {
            $zip->close();
            return $validation;
        }

        if (is_dir($extractDir)) {
            $this->removePath($extractDir);
        }
        $this->ensureDir($extractDir);

        $ok = $zip->extractTo($extractDir);
        $zip->close();

        if (!$ok) {
            return [
                'ok' => false,
                'error' => 'Błąd rozpakowania ZIP.',
            ];
        }

        $sourceRoot = $this->detectSourceRoot($extractDir);
        if ($sourceRoot === '') {
            return [
                'ok' => false,
                'error' => 'Rozpakowana paczka nie ma poprawnej struktury aplikacji.',
            ];
        }

        return [
            'ok' => true,
            'extract_dir' => $extractDir,
            'source_root' => $sourceRoot,
            'entry_count' => (int)($validation['entry_count'] ?? 0),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function backupDatabase(PDO $pdo, string $destinationPath): array
    {
        $this->ensureDir(dirname($destinationPath));
        $fp = @fopen($destinationPath, 'wb');
        if ($fp === false) {
            return [
                'ok' => false,
                'error' => 'Nie udało się utworzyć pliku backupu bazy danych.',
            ];
        }

        try {
            fwrite($fp, '-- CRM update backup\n');
            fwrite($fp, '-- generated_at: ' . gmdate('c') . "\n\n");
            fwrite($fp, 'SET NAMES utf8mb4;' . "\n");
            fwrite($fp, 'SET FOREIGN_KEY_CHECKS=0;' . "\n\n");

            $tableStmt = $pdo->query('SHOW TABLES');
            $tables = $tableStmt ? $tableStmt->fetchAll(PDO::FETCH_NUM) : [];
            if ($tableStmt) {
                $tableStmt->closeCursor();
            }

            $tableCount = 0;
            $rowCount = 0;

            foreach ($tables as $entry) {
                $table = trim((string)($entry[0] ?? ''));
                if ($table === '') {
                    continue;
                }

                $tableCount++;
                fwrite($fp, '-- ----------------------------' . "\n");
                fwrite($fp, '-- Table structure for `' . $table . '`' . "\n");
                fwrite($fp, '-- ----------------------------' . "\n");
                fwrite($fp, 'DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`;' . "\n");

                $createStmt = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`');
                $createRow = $createStmt ? $createStmt->fetch(PDO::FETCH_ASSOC) : [];
                if ($createStmt) {
                    $createStmt->closeCursor();
                }
                $createSql = (string)($createRow['Create Table'] ?? '');
                if ($createSql === '') {
                    throw new RuntimeException('Nie udało się pobrać definicji tabeli: ' . $table);
                }
                fwrite($fp, $createSql . ";\n\n");

                fwrite($fp, '-- ----------------------------' . "\n");
                fwrite($fp, '-- Data for table `' . $table . '`' . "\n");
                fwrite($fp, '-- ----------------------------' . "\n");

                $selectStmt = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`');
                if ($selectStmt === false) {
                    fwrite($fp, "\n");
                    continue;
                }

                $insertPrefix = null;
                $batchRows = [];
                while (($row = $selectStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                    if ($insertPrefix === null) {
                        $columns = array_keys($row);
                        $escapedColumns = array_map(
                            static fn (string $column): string => '`' . str_replace('`', '``', $column) . '`',
                            $columns
                        );
                        $insertPrefix = 'INSERT INTO `' . str_replace('`', '``', $table) . '` (' . implode(', ', $escapedColumns) . ') VALUES';
                    }

                    $rowCount++;
                    $values = [];
                    foreach ($row as $value) {
                        $values[] = $this->sqlValue($pdo, $value);
                    }
                    $batchRows[] = '(' . implode(', ', $values) . ')';

                    if (count($batchRows) >= 100) {
                        fwrite($fp, $insertPrefix . "\n" . implode(",\n", $batchRows) . ";\n");
                        $batchRows = [];
                    }
                }
                $selectStmt->closeCursor();

                if ($insertPrefix !== null && $batchRows !== []) {
                    fwrite($fp, $insertPrefix . "\n" . implode(",\n", $batchRows) . ";\n");
                }

                fwrite($fp, "\n");
            }

            fwrite($fp, 'SET FOREIGN_KEY_CHECKS=1;' . "\n");
            fclose($fp);

            return [
                'ok' => true,
                'path' => $destinationPath,
                'tables' => $tableCount,
                'rows' => $rowCount,
                'bytes' => (int)@filesize($destinationPath),
            ];
        } catch (Throwable $e) {
            fclose($fp);
            @unlink($destinationPath);
            return [
                'ok' => false,
                'error' => 'Błąd backupu bazy danych: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function backupFiles(string $destinationPath): array
    {
        if (!class_exists('ZipArchive')) {
            return [
                'ok' => false,
                'error' => 'Brakuje rozszerzenia ZipArchive potrzebnego do backupu plików.',
            ];
        }

        $this->ensureDir(dirname($destinationPath));
        $zip = new ZipArchive();
        $open = $zip->open($destinationPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($open !== true) {
            return [
                'ok' => false,
                'error' => 'Nie udało się utworzyć archiwum backupu plików.',
            ];
        }

        $count = 0;
        $rootLen = strlen($this->rootDir) + 1;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->rootDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }
            if ($item->isLink()) {
                continue;
            }

            $path = $item->getPathname();
            $relative = $this->normalizeRelativePath(substr($path, $rootLen));
            if ($relative === '' || $this->shouldSkipBackupPath($relative)) {
                continue;
            }

            if ($zip->addFile($path, $relative)) {
                $count++;
            }
        }

        $zip->close();

        return [
            'ok' => true,
            'path' => $destinationPath,
            'files' => $count,
            'bytes' => (int)@filesize($destinationPath),
        ];
    }

    /**
     * @param list<string> $excludePrefixes
     * @return array<string,mixed>
     */
    public function applyPackage(string $sourceRoot, array $excludePrefixes = self::DEFAULT_EXCLUDE_PREFIXES): array
    {
        if (!is_dir($sourceRoot) || !is_readable($sourceRoot)) {
            return [
                'ok' => false,
                'error' => 'Źródło paczki po rozpakowaniu nie istnieje lub jest niedostępne.',
            ];
        }

        $normalizedPrefixes = [];
        foreach ($excludePrefixes as $prefix) {
            $prefix = $this->normalizeRelativePath($prefix);
            if ($prefix === '') {
                continue;
            }
            if (!str_ends_with($prefix, '/')) {
                $prefix .= '/';
            }
            $normalizedPrefixes[] = $prefix;
        }

        $copied = 0;
        $skipped = 0;
        $rootLen = strlen(rtrim($sourceRoot, '/')) + 1;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }
            if ($item->isLink()) {
                continue;
            }

            $src = $item->getPathname();
            $relative = $this->normalizeRelativePath(substr($src, $rootLen));
            if ($relative === '' || $this->shouldSkipPackagePath($relative, $normalizedPrefixes)) {
                $skipped++;
                continue;
            }

            $dest = $this->rootDir . '/' . $relative;
            $destDir = dirname($dest);
            $this->ensureDir($destDir);

            $tmpDest = $dest . '.updtmp';
            if (!@copy($src, $tmpDest)) {
                @unlink($tmpDest);
                return [
                    'ok' => false,
                    'error' => 'Nie udało się nadpisać pliku: ' . $relative,
                ];
            }
            @chmod($tmpDest, $item->getPerms() & 0777);
            if (!@rename($tmpDest, $dest)) {
                @unlink($tmpDest);
                return [
                    'ok' => false,
                    'error' => 'Nie udało się przepiąć pliku po aktualizacji: ' . $relative,
                ];
            }

            $copied++;
        }

        return [
            'ok' => true,
            'copied' => $copied,
            'skipped' => $skipped,
        ];
    }

    public function cleanupWorkspace(string $runId): void
    {
        $workspace = $this->runWorkspace($runId);
        if (is_dir($workspace['run_dir'])) {
            $this->removePath($workspace['run_dir']);
        }
    }

    private function ensureDir(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Nie udało się utworzyć katalogu: ' . $path);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function validateZipEntries(ZipArchive $zip): array
    {
        $entryCount = 0;
        $totalUncompressed = 0;
        $hasReleaseJson = false;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            $normalized = $this->normalizeRelativePath($name);
            if ($normalized === '') {
                continue;
            }

            if ($this->isPathTraversal($normalized)) {
                return [
                    'ok' => false,
                    'error' => 'Paczka ZIP zawiera niedozwoloną ścieżkę: ' . $normalized,
                ];
            }

            if (str_ends_with($normalized, '/')) {
                continue;
            }

            $entryCount++;
            $stat = $zip->statIndex($i);
            if (is_array($stat)) {
                $totalUncompressed += (int)($stat['size'] ?? 0);
                if ($totalUncompressed > $this->maxExtractBytes) {
                    return [
                        'ok' => false,
                        'error' => 'Rozpakowana paczka przekroczyłaby bezpieczny limit rozmiaru.',
                    ];
                }
            }

            if (strtolower(basename($normalized)) === 'release.json') {
                $hasReleaseJson = true;
            }
        }

        if ($entryCount === 0) {
            return [
                'ok' => false,
                'error' => 'Paczka ZIP nie zawiera żadnych plików.',
            ];
        }

        if (!$hasReleaseJson) {
            return [
                'ok' => false,
                'error' => 'Paczka ZIP nie zawiera pliku release.json.',
            ];
        }

        return [
            'ok' => true,
            'entry_count' => $entryCount,
        ];
    }

    private function detectSourceRoot(string $extractDir): string
    {
        $entries = @scandir($extractDir);
        if (!is_array($entries)) {
            return '';
        }

        $items = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $items[] = $entry;
        }

        $candidate = $extractDir;
        if (count($items) === 1) {
            $first = $extractDir . '/' . $items[0];
            if (is_dir($first)) {
                $candidate = $first;
            }
        }

        if (is_file($candidate . '/release.json') && is_dir($candidate . '/public')) {
            return $candidate;
        }

        if (is_file($extractDir . '/release.json') && is_dir($extractDir . '/public')) {
            return $extractDir;
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function normalizeChangelog(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        }

        if (!is_array($raw)) {
            return [];
        }

        $lines = [];
        foreach ($raw as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    private function compareVersions(string $left, string $right): ?int
    {
        $left = trim($left);
        $right = trim($right);
        if ($left === '' || $right === '') {
            return null;
        }

        if (ReleaseInfo::isValidCalver($left) && ReleaseInfo::isValidCalver($right)) {
            return ReleaseInfo::compareVersions($left, $right);
        }

        return version_compare($left, $right);
    }

    private function sqlValue(PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        $quoted = $pdo->quote((string)$value);
        if ($quoted === false) {
            $escaped = addslashes((string)$value);
            return "'" . $escaped . "'";
        }

        return $quoted;
    }

    private function shouldSkipBackupPath(string $relative): bool
    {
        return $this->shouldSkipPackagePath($relative, [
            'storage/backups/',
            'storage/cache/app-update-runs/',
        ]);
    }

    /**
     * @param list<string> $prefixes
     */
    private function shouldSkipPackagePath(string $relative, array $prefixes): bool
    {
        $relative = $this->normalizeRelativePath($relative);
        foreach ($prefixes as $prefix) {
            $prefix = $this->normalizeRelativePath($prefix);
            if ($prefix === '') {
                continue;
            }
            if (!str_ends_with($prefix, '/')) {
                $prefix .= '/';
            }
            $trimmedPrefix = rtrim($prefix, '/');
            if ($relative === $trimmedPrefix || str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isPathTraversal(string $relative): bool
    {
        if ($relative === '') {
            return false;
        }

        if (str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:\//', $relative) === 1) {
            return true;
        }

        $parts = explode('/', $relative);
        foreach ($parts as $part) {
            if ($part === '..') {
                return true;
            }
        }

        return false;
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = trim($path);
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        return ltrim($path, '/');
    }

    private function removePath(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }

        $entries = @scandir($path);
        if (!is_array($entries)) {
            @rmdir($path);
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removePath($path . '/' . $entry);
        }

        @rmdir($path);
    }
}
