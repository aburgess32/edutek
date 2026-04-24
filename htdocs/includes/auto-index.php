<?php

/**
 * Auto-Index Content on Page Load (FRE-40)
 *
 * Lightweight staleness check that triggers a content reindex only when
 * the file count in the videos directory diverges from the content_meta
 * table row count. A lock file throttles checks to once every 5 minutes
 * so page loads stay fast.
 *
 * Include this file from navbar includes (navbar.php / navhome.php) —
 * NOT from API endpoints.
 *
 * @package EduPak
 */

/**
 * Count content files (known extensions only) in a directory tree.
 * Fast: uses glob-style iteration, no metadata extraction.
 */
function countContentFiles(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }

    $extensions = ['mp4', 'webm', 'mkv', 'avi', 'mp3', 'ogg', 'wav', 'pdf'];
    $count = 0;

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $dir,
                RecursiveDirectoryIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, $extensions, true)) {
                    $count++;
                }
            }
        }
    } catch (Exception $e) {
        // Directory unreadable — treat as zero files
        return 0;
    }

    return $count;
}

/**
 * Get the row count from content_meta.
 */
function getContentMetaCount(): int
{
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->query('SELECT COUNT(*) FROM content_meta');
        return (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        // Table may not exist yet — treat as zero
        return 0;
    }
}

// Maximum thumbnails to generate per auto-index run to avoid slow page loads.
// Use the CLI script (scripts/generate-thumbnails.php) for bulk generation.
if (!defined('MAX_THUMBNAILS_PER_RUN')) {
    define('MAX_THUMBNAILS_PER_RUN', 10);
}

/**
 * Check whether content needs reindexing and trigger it if so.
 *
 * Strategy:
 * 1. Skip if checked less than 5 minutes ago (lock file).
 * 2. Compare file count in videos/ vs rows in content_meta.
 * 3. If counts differ, run the full indexer.
 * 4. On first run (empty DB + files exist), index immediately.
 *
 * Thumbnail generation is batched to MAX_THUMBNAILS_PER_RUN per run.
 */
function checkAndReindex(): void
{
    // Ensure CONTENT_PATH is defined (set in config.php)
    if (!defined('CONTENT_PATH')) {
        return;
    }

    $contentRoot = rtrim(CONTENT_PATH, '/');

    // No content directory — nothing to index
    if (!is_dir($contentRoot)) {
        return;
    }

    $dataDir  = __DIR__ . '/../data';
    $lockFile = $dataDir . '/index-lock.txt';
    $interval = 300; // 5 minutes between checks

    $dbCount = getContentMetaCount();

    // First-run handling: if DB is empty, skip the interval check
    // so content is indexed immediately on first page load.
    $isFirstRun = ($dbCount === 0);

    // If DB has content, rely on the lock-file interval only.
    // Never walk the filesystem on every request — on large content
    // libraries (3TB+) the recursive scan blocks Apache for all users.
    if (!$isFirstRun) {
        if (file_exists($lockFile)) {
            $lastCheck = (int) file_get_contents($lockFile);
            if (time() - $lastCheck < $interval) {
                return;
            }
        }
        // Lock interval elapsed but DB is populated — write a new lock and
        // skip the expensive file-count walk. Manual reindex via admin panel.
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0755, true);
        }
        @file_put_contents($lockFile, (string) time());
        return;
    }

    // Nothing to index if no files exist
    $fileCount = countContentFiles($contentRoot);
    if ($fileCount === 0) {
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0755, true);
        }
        @file_put_contents($lockFile, (string) time());
        return;
    }

    // Run the indexer inline — only executes when counts mismatch (rare),
    // so the inline cost is acceptable. Running in register_shutdown_function
    // caused silent failures on XAMPP/Apache where the PDO connection could
    // be garbage-collected before the shutdown handler executed.
    require_once __DIR__ . '/content-indexer.php';

    try {
        $result = indexContent($contentRoot, MAX_THUMBNAILS_PER_RUN);

        if (is_array($result) && isset($result['success']) && !$result['success']) {
            $errorMsg = $result['error'] ?? 'unknown error';
            error_log("Auto-indexer failed: $errorMsg");
        }
    } catch (Exception $e) {
        error_log('Auto-indexer error: ' . $e->getMessage());
    }

    // Write lock AFTER indexer completes so a failure allows retry on next load
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0755, true);
    }
    @file_put_contents($lockFile, (string) time());
}
