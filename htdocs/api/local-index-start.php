<?php

/**
 * Local Home Index Hotkey
 *
 * Localhost-only endpoint for triggering the existing content indexer from
 * the EduTek Home page.
 *
 * This endpoint intentionally does not replace or weaken api/reindex.php,
 * which remains teacher/CSRF protected.
 *
 * POST /api/local-index-start.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content-indexer.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/**
 * Send one JSON response and stop execution.
 */
function localIndexRespond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

/**
 * Allow only direct loopback requests and this Docker Desktop development
 * bridge gateway, which is what Apache receives for Windows localhost:8080.
 *
 * Do not trust X-Forwarded-For or X-Real-IP here. This local setup does not
 * have a configured trusted reverse proxy that strips forged client headers.
 */
function localIndexIsLocalRequest(): bool
{
    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';

    return in_array($remoteAddress, [
        '127.0.0.1',
        '::1',
        '::ffff:127.0.0.1',

        // Exact measured Docker bridge gateway for Windows localhost requests.
        '172.19.0.1',
        '::ffff:172.19.0.1',
    ], true);
}

/**
 * Resolve the shared persistent status/report directory.
 */
function localIndexStatusDirectory(): string
{
    return rtrim(
        getenv('INDEX_STATUS_DIR') ?: '/system/index-status',
        '/'
    );
}

/**
 * Atomically write a JSON status file.
 *
 * The temporary file is written first, then renamed into place so readers do
 * not observe a partially written JSON document.
 */
function localIndexWriteJsonAtomically(string $path, array $payload): bool
{
    try {
        $suffix = bin2hex(random_bytes(6));
    } catch (Throwable $exception) {
        $suffix = str_replace('.', '', uniqid('', true));
    }

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

/**
 * Neutralize spreadsheet formula injection in CSV fields.
 *
 * Content metadata and paths may ultimately be influenced by filenames. A
 * leading =, +, -, or @ can be interpreted as a formula by spreadsheet tools.
 */
function localIndexCsvValue(string $value): string
{
    if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
        return "'" . $value;
    }

    return $value;
}

/**
 * Create a UI-oriented verification URL.
 *
 * The current directory page may evolve; this keeps a useful folder/context
 * URL in the verification CSV without accepting any client-provided path.
 */
function localIndexOpenUrl(
    string $contentType,
    string $category,
    string $subcategory,
    string $filePath
): string {
    $directoryPath = trim(
        str_replace('\\', '/', dirname($filePath)),
        '.'
    );

    if ($directoryPath === '' || $directoryPath === '/') {
        $directoryPath = $filePath;
    }

    $query = http_build_query(
        [
            'category' => $category,
            'subcategory' => $subcategory,
            'path' => $directoryPath,
            'type' => $contentType,
        ],
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    return '/directory.php?' . $query;
}

/**
 * Convert the existing scan audit CSV into a verification CSV for this one run.
 *
 * The existing indexer audit can include skipped database errors. This derived
 * report deliberately includes only records whose action is "new" or "updated".
 *
 * @return array{
 *     success: bool,
 *     changed_count: int,
 *     error: string
 * }
 */
function localIndexBuildVerificationCsv(
    string $auditPath,
    string $statusDirectory,
    string $runId
): array {
    if ($auditPath === '' || !is_file($auditPath) || !is_readable($auditPath)) {
        return [
            'success' => false,
            'changed_count' => 0,
            'error' => 'The index completed, but its audit report could not be read.',
        ];
    }

    $verificationPath = $statusDirectory
        . '/local-index-verification-'
        . $runId
        . '.csv';

    $input = @fopen($auditPath, 'rb');
    $output = @fopen($verificationPath, 'wb');

    if (!is_resource($input) || !is_resource($output)) {
        if (is_resource($input)) {
            fclose($input);
        }

        if (is_resource($output)) {
            fclose($output);
        }

        @unlink($verificationPath);

        return [
            'success' => false,
            'changed_count' => 0,
            'error' => 'The verification CSV could not be created.',
        ];
    }

    fputcsv($output, [
        'timestamp',
        'action',
        'content_type',
        'category',
        'subcategory',
        'title',
        'file_path',
        'thumbnail_path',
        'open_url',
    ]);

    // Read and discard the existing audit CSV header row.
    fgetcsv($input);

    $changedCount = 0;

    while (($row = fgetcsv($input)) !== false) {
        // Existing audit columns:
        // timestamp, action, content_type, category, subcategory,
        // file_path, title, thumbnail_path, message
        if (count($row) < 9) {
            continue;
        }

        $timestamp = (string) $row[0];
        $action = (string) $row[1];
        $contentType = (string) $row[2];
        $category = (string) $row[3];
        $subcategory = (string) $row[4];
        $filePath = (string) $row[5];
        $title = (string) $row[6];
        $thumbnailPath = (string) $row[7];

        // Exclude unchanged content and skipped/error records.
        if (!in_array($action, ['new', 'updated'], true)) {
            continue;
        }

        $openUrl = localIndexOpenUrl(
            $contentType,
            $category,
            $subcategory,
            $filePath
        );

        fputcsv($output, [
            localIndexCsvValue($timestamp),
            localIndexCsvValue($action),
            localIndexCsvValue($contentType),
            localIndexCsvValue($category),
            localIndexCsvValue($subcategory),
            localIndexCsvValue($title),
            localIndexCsvValue($filePath),
            localIndexCsvValue($thumbnailPath),
            localIndexCsvValue($openUrl),
        ]);

        $changedCount++;
    }

    fclose($input);
    fclose($output);

    return [
        'success' => true,
        'changed_count' => $changedCount,
        'error' => '',
    ];
}

/*
|--------------------------------------------------------------------------
| Request validation
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    localIndexRespond(405, [
        'success' => false,
        'error' => 'Method not allowed.',
    ]);
}

if (!localIndexIsLocalRequest()) {
    localIndexRespond(403, [
        'success' => false,
        'error' => 'This indexing action is available only from localhost.',
    ]);
}

$statusDirectory = localIndexStatusDirectory();

if (!is_dir($statusDirectory) && !@mkdir($statusDirectory, 0775, true)) {
    localIndexRespond(500, [
        'success' => false,
        'error' => 'The index status directory could not be created.',
    ]);
}

if (!is_writable($statusDirectory)) {
    localIndexRespond(500, [
        'success' => false,
        'error' => 'The index status directory is not writable.',
    ]);
}

/*
|--------------------------------------------------------------------------
| Exclusive duplicate-run lock
|--------------------------------------------------------------------------
*/

$lockPath = $statusDirectory . '/local-hotkey-index.lock';
$statusPath = $statusDirectory . '/local-hotkey-index.json';

$lockHandle = @fopen($lockPath, 'c');

if (!is_resource($lockHandle)) {
    localIndexRespond(500, [
        'success' => false,
        'error' => 'The index lock could not be opened.',
    ]);
}

$wouldBlock = false;

if (!@flock($lockHandle, LOCK_EX | LOCK_NB, $wouldBlock)) {
    fclose($lockHandle);

    localIndexRespond(409, [
        'success' => false,
        'status' => 'running',
        'error' => 'An index is already running.',
    ]);
}

/*
|--------------------------------------------------------------------------
| Run existing indexer and publish result
|--------------------------------------------------------------------------
*/

try {
    try {
        $runId = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
    } catch (Throwable $exception) {
        $runId = gmdate('Ymd_His')
            . '_'
            . str_replace('.', '', uniqid('', true));
    }

    $startedAt = gmdate(DATE_ATOM);

    localIndexWriteJsonAtomically($statusPath, [
        'status' => 'running',
        'run_id' => $runId,
        'started_at' => $startedAt,
    ]);

    // Match the timeout behavior of the existing protected reindex endpoint.
    set_time_limit(300);

    // Reuse the established shared indexer. Do not duplicate scan logic here.
    $contentRoot = rtrim(CONTENT_PATH, '/');
    $result = indexContent($contentRoot);

    if (!$result['success']) {
        $message = (string) (
            $result['error']
            ?? 'The indexer stopped with an unknown error.'
        );

        localIndexWriteJsonAtomically($statusPath, [
            'status' => 'failed',
            'run_id' => $runId,
            'started_at' => $startedAt,
            'completed_at' => gmdate(DATE_ATOM),
            'error' => $message,
        ]);

        localIndexRespond(500, [
            'success' => false,
            'status' => 'failed',
            'run_id' => $runId,
            'error' => 'Index failed: ' . $message,
        ]);
    }

    $verification = localIndexBuildVerificationCsv(
        (string) ($result['audit_path'] ?? ''),
        $statusDirectory,
        $runId
    );

    $reportUrl = '';

    if ($verification['success']) {
        $reportUrl = '/api/local-index-report.php?run='
            . rawurlencode($runId);
    }

    localIndexWriteJsonAtomically($statusPath, [
        'status' => 'complete',
        'run_id' => $runId,
        'started_at' => $startedAt,
        'completed_at' => gmdate(DATE_ATOM),
        'total' => (int) ($result['total'] ?? 0),
        'new' => (int) ($result['new'] ?? 0),
        'updated' => (int) ($result['updated'] ?? 0),
        'skipped' => (int) ($result['skipped'] ?? 0),
        'thumbnails_generated' => (int) (
            $result['thumbnails_generated']
            ?? 0
        ),
        'verification_report_url' => $reportUrl,
        'verification_error' => (string) $verification['error'],
    ]);

    localIndexRespond(200, [
        'success' => true,
        'status' => 'complete',
        'run_id' => $runId,
        'total' => (int) ($result['total'] ?? 0),
        'new' => (int) ($result['new'] ?? 0),
        'updated' => (int) ($result['updated'] ?? 0),
        'skipped' => (int) ($result['skipped'] ?? 0),
        'thumbnails_generated' => (int) (
            $result['thumbnails_generated']
            ?? 0
        ),
        'verification_report_url' => $reportUrl,
        'verification_error' => (string) $verification['error'],
    ]);
} catch (Throwable $exception) {
    error_log('Local index hotkey failed: ' . $exception->getMessage());

    localIndexWriteJsonAtomically($statusPath, [
        'status' => 'failed',
        'run_id' => $runId ?? '',
        'started_at' => $startedAt ?? '',
        'completed_at' => gmdate(DATE_ATOM),
        'error' => 'Unexpected server error while indexing content.',
    ]);

    localIndexRespond(500, [
        'success' => false,
        'status' => 'failed',
        'run_id' => $runId ?? '',
        'error' => 'Index failed due to an unexpected server error.',
    ]);
} finally {
    @flock($lockHandle, LOCK_UN);
    @fclose($lockHandle);
}