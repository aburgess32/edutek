<?php

/**
 * Local Catalog Cleanup
 *
 * Localhost-only endpoint that removes content_meta records whose underlying
 * content files no longer exist. This is intentionally separate from the full
 * content indexer so administrators can remove deleted files without waiting
 * for a complete library rescan.
 *
 * POST /api/local-catalog-cleanup-start.php
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content-indexer.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function cleanupRespond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

function cleanupIsLocalRequest(): bool
{
    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';

    return in_array($remoteAddress, [
        '127.0.0.1',
        '::1',
        '::ffff:127.0.0.1',
        '172.19.0.1',
        '::ffff:172.19.0.1',
    ], true);
}

function cleanupStatusDirectory(): string
{
    return rtrim(
        getenv('INDEX_STATUS_DIR') ?: '/system/index-status',
        '/'
    );
}

function cleanupWriteJsonAtomically(string $path, array $payload): bool
{
    $suffix = bin2hex(random_bytes(6));
    $temporaryPath = $path . '.' . $suffix . '.tmp';

    $json = json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    if ($json === false) {
        return false;
    }

    if (@file_put_contents($temporaryPath, $json . PHP_EOL, LOCK_EX) === false) {
        @unlink($temporaryPath);
        return false;
    }

    if (!@rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        return false;
    }

    return true;
}

function cleanupCsvValue(string $value): string
{
    if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
        return "'" . $value;
    }

    return $value;
}

function cleanupResolveAbsolutePath(string $webPath): ?string
{
    $webPath = str_replace('\\', '/', ltrim($webPath, '/'));
    $contentRoot = rtrim(str_replace('\\', '/', CONTENT_PATH), '/');
    $contentPrefix = basename($contentRoot) . '/';
    $htdocsRoot = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..')), '/');

    if ($contentPrefix !== '/' && strpos($webPath, $contentPrefix) === 0) {
        return $contentRoot . '/' . substr($webPath, strlen($contentPrefix));
    }

    if (strpos($webPath, 'videos/') === 0 && $htdocsRoot !== '') {
        return $htdocsRoot . '/' . $webPath;
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cleanupRespond(405, [
        'success' => false,
        'error' => 'Method not allowed.',
    ]);
}

if (!cleanupIsLocalRequest()) {
    cleanupRespond(403, [
        'success' => false,
        'error' => 'This cleanup action is available only from localhost.',
    ]);
}

$statusDirectory = cleanupStatusDirectory();

if (!is_dir($statusDirectory) && !@mkdir($statusDirectory, 0775, true)) {
    cleanupRespond(500, [
        'success' => false,
        'error' => 'The cleanup status directory could not be created.',
    ]);
}

if (!is_writable($statusDirectory)) {
    cleanupRespond(500, [
        'success' => false,
        'error' => 'The cleanup status directory is not writable.',
    ]);
}

$lockPath = $statusDirectory . '/local-catalog-cleanup.lock';
$statusPath = $statusDirectory . '/local-catalog-cleanup.json';

$lockHandle = @fopen($lockPath, 'c');

if (!is_resource($lockHandle)) {
    cleanupRespond(500, [
        'success' => false,
        'error' => 'The cleanup lock could not be opened.',
    ]);
}

$wouldBlock = false;

if (!@flock($lockHandle, LOCK_EX | LOCK_NB, $wouldBlock)) {
    fclose($lockHandle);

    cleanupRespond(409, [
        'success' => false,
        'status' => 'running',
        'error' => 'A catalog cleanup is already running.',
    ]);
}

try {
    $runId = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $startedAt = gmdate(DATE_ATOM);

    cleanupWriteJsonAtomically($statusPath, [
        'status' => 'running',
        'run_id' => $runId,
        'started_at' => $startedAt,
    ]);

    set_time_limit(300);

    $pdo = getDbConnection();

    $reportPath = $statusDirectory . '/local-catalog-cleanup-' . $runId . '.csv';
    $reportHandle = @fopen($reportPath, 'wb');

    if (!is_resource($reportHandle)) {
        throw new RuntimeException('The cleanup report could not be created.');
    }

    fputcsv($reportHandle, [
        'timestamp',
        'content_id',
        'file_path',
        'content_type',
        'category',
        'subcategory',
        'title',
        'thumbnail_path',
        'message',
    ]);

    $batchSize = 500;
    $lastContentId = '';
    $checkedCount = 0;
    $removedCount = 0;
    $errorCount = 0;
    $unresolvedCount = 0;

    $selectStatement = $pdo->prepare("
        SELECT
            content_id,
            file_path,
            content_type,
            category,
            subcategory,
            title,
            thumbnail_path
        FROM content_meta
        WHERE content_id > :last_content_id
        ORDER BY content_id
        LIMIT :batch_size
    ");

    $selectStatement->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);

    $deleteStatement = $pdo->prepare("
        DELETE FROM content_meta
        WHERE content_id = :content_id
    ");

    while (true) {
        $selectStatement->bindValue(':last_content_id', $lastContentId, PDO::PARAM_STR);
        $selectStatement->execute();

        $rows = $selectStatement->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            break;
        }

        foreach ($rows as $row) {
            $contentId = (string) ($row['content_id'] ?? '');
            $storedPath = (string) ($row['file_path'] ?? $contentId);

            $lastContentId = $contentId;
            $checkedCount++;

            if ($contentId === '') {
                $errorCount++;
                continue;
            }

            $absolutePath = cleanupResolveAbsolutePath($storedPath);

            if ($absolutePath === null) {
                $unresolvedCount++;
                continue;
            }

            if (is_file($absolutePath)) {
                continue;
            }

            try {
                $deleteStatement->execute([
                    ':content_id' => $contentId,
                ]);

                if ($deleteStatement->rowCount() > 0) {
                    $removedCount++;

                    fputcsv($reportHandle, [
                        cleanupCsvValue(gmdate(DATE_ATOM)),
                        cleanupCsvValue($contentId),
                        cleanupCsvValue($storedPath),
                        cleanupCsvValue((string) ($row['content_type'] ?? '')),
                        cleanupCsvValue((string) ($row['category'] ?? '')),
                        cleanupCsvValue((string) ($row['subcategory'] ?? '')),
                        cleanupCsvValue((string) ($row['title'] ?? '')),
                        cleanupCsvValue((string) ($row['thumbnail_path'] ?? '')),
                        cleanupCsvValue('Content file no longer exists on disk.'),
                    ]);
                }
            } catch (PDOException $exception) {
                $errorCount++;

                fputcsv($reportHandle, [
                    cleanupCsvValue(gmdate(DATE_ATOM)),
                    cleanupCsvValue($contentId),
                    cleanupCsvValue($storedPath),
                    cleanupCsvValue((string) ($row['content_type'] ?? '')),
                    cleanupCsvValue((string) ($row['category'] ?? '')),
                    cleanupCsvValue((string) ($row['subcategory'] ?? '')),
                    cleanupCsvValue((string) ($row['title'] ?? '')),
                    cleanupCsvValue((string) ($row['thumbnail_path'] ?? '')),
                    cleanupCsvValue('Could not remove stale catalog record: ' . $exception->getMessage()),
                ]);
            }
        }

        if (count($rows) < $batchSize) {
            break;
        }
    }

    fclose($reportHandle);

    $reportUrl = '/api/local-catalog-cleanup-report.php?run=' . rawurlencode($runId);

    cleanupWriteJsonAtomically($statusPath, [
        'status' => 'complete',
        'run_id' => $runId,
        'started_at' => $startedAt,
        'completed_at' => gmdate(DATE_ATOM),
        'checked' => $checkedCount,
        'removed' => $removedCount,
        'errors' => $errorCount,
        'unresolved' => $unresolvedCount,
        'verification_report_url' => $reportUrl,
    ]);

    cleanupRespond(200, [
        'success' => true,
        'status' => 'complete',
        'run_id' => $runId,
        'checked' => $checkedCount,
        'removed' => $removedCount,
        'errors' => $errorCount,
        'unresolved' => $unresolvedCount,
        'verification_report_url' => $reportUrl,
    ]);
} catch (Throwable $exception) {
    error_log('Local catalog cleanup failed: ' . $exception->getMessage());

    cleanupWriteJsonAtomically($statusPath, [
        'status' => 'failed',
        'run_id' => $runId ?? '',
        'started_at' => $startedAt ?? '',
        'completed_at' => gmdate(DATE_ATOM),
        'error' => 'Unexpected server error while cleaning the content catalog.',
    ]);

    cleanupRespond(500, [
        'success' => false,
        'status' => 'failed',
        'run_id' => $runId ?? '',
        'error' => 'Catalog cleanup failed due to an unexpected server error.',
    ]);
} finally {
    @flock($lockHandle, LOCK_UN);
    @fclose($lockHandle);
}
