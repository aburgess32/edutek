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

/**
 * Check whether content needs reindexing and trigger it if so.
 *
 * Strategy:
 * 1. Skip if checked less than 5 minutes ago (lock file).
 * 2. Compare file count in videos/ vs rows in content_meta.
 * 3. If counts differ, run the full indexer.
 * 4. On first run (empty DB + files exist), index immediately.
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

    if (!$isFirstRun && file_exists($lockFile)) {
        $lastCheck = (int) file_get_contents($lockFile);
        if (time() - $lastCheck < $interval) {
            return;
        }
    }

    // Quick file count comparison
    $fileCount = countContentFiles($contentRoot);

    // Update lock timestamp (create data dir if needed)
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0755, true);
    }
    @file_put_contents($lockFile, (string) time());

    // Only reindex if counts differ (or first run with files present)
    if ($fileCount === $dbCount && !$isFirstRun) {
        return;
    }

    // Nothing to index if no files exist
    if ($fileCount === 0) {
        return;
    }

    // Run the indexer
    require_once __DIR__ . '/content-indexer.php';

    // Use register_shutdown_function to run after the response is sent
    // so it doesn't block the page load. On XAMPP/Apache this still runs
    // before the connection closes, but it only fires when counts mismatch
    // (which should be rare).
    register_shutdown_function(function () use ($contentRoot) {
        indexContent($contentRoot);
    });
}
