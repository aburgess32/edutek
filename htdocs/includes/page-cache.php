<?php
/**
 * Simple page-level output cache for heavy filesystem pages.
 * Caches rendered HTML to a temp file for 30 minutes (configurable).
 * Pass ?refresh=1 to bust the cache.
 *
 * Usage (at top of page, after session start):
 *   $cacheFile = pageCache_start('audiobooks');
 *   // ... page rendering ...
 *   pageCache_end($cacheFile);
 */

function pageCache_start(string $pageKey, int $ttlSeconds = 1800): ?string {
    $cacheDir = sys_get_temp_dir() . '/edupak-cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

    $cacheFile = $cacheDir . '/' . $pageKey . '.html';

    // Allow ?refresh=1 to bust the cache
    if (isset($_GET['refresh'])) {
        ob_start();
        return $cacheFile;
    }

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttlSeconds) {
        // Serve from cache
        readfile($cacheFile);
        return null; // Signal: page already served
    }

    ob_start();
    return $cacheFile;
}

function pageCache_end(?string $cacheFile): void {
    if ($cacheFile === null) return; // Was served from cache
    $html = ob_get_flush();
    @file_put_contents($cacheFile, $html);
}
