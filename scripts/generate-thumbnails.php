#!/usr/bin/env php
<?php

/**
 * Thumbnail Generator Script (FRE-40)
 *
 * CLI script to generate thumbnail JPGs for all video files in the
 * content directory using ffmpeg.
 *
 * Usage:
 *   php scripts/generate-thumbnails.php [content_root_path]
 *
 *   If no path is given, uses CONTENT_PATH from config.
 *
 * Requires ffmpeg to be installed and available in PATH.
 *
 * @package EduPak
 */

// ──────────────────────────────────────────────────────────────────────────────
// Bootstrap
// ──────────────────────────────────────────────────────────────────────────────

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/../htdocs/includes/config.php';
require_once __DIR__ . '/../htdocs/includes/content-indexer.php';

// ──────────────────────────────────────────────────────────────────────────────
// Check ffmpeg availability
// ──────────────────────────────────────────────────────────────────────────────

if (!isFfmpegAvailable()) {
    fwrite(STDERR, "Error: ffmpeg is not installed or not in PATH.\n");
    fwrite(STDERR, "Install ffmpeg and try again.\n");
    exit(1);
}

echo "ffmpeg found.\n";

// ──────────────────────────────────────────────────────────────────────────────
// Resolve content root path
// ──────────────────────────────────────────────────────────────────────────────

$contentRoot = isset($argv[1]) ? rtrim($argv[1], '/') : rtrim(CONTENT_PATH, '/');

if (!is_dir($contentRoot)) {
    fwrite(STDERR, "Error: Content directory not found: {$contentRoot}\n");
    fwrite(STDERR, "Usage: php scripts/generate-thumbnails.php [content_root_path]\n");
    exit(1);
}

echo "Scanning: {$contentRoot}\n\n";

// ──────────────────────────────────────────────────────────────────────────────
// Scan for video files and generate thumbnails
// ──────────────────────────────────────────────────────────────────────────────

$videoExtensions = ['mp4', 'webm', 'mkv', 'avi'];
$totalVideos  = 0;
$generated    = 0;
$skipped      = 0;
$errors       = 0;

try {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $contentRoot,
            RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
} catch (Exception $e) {
    fwrite(STDERR, "Error: Cannot read directory: {$e->getMessage()}\n");
    exit(1);
}

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || !$fileInfo->isReadable()) {
        continue;
    }

    $ext = strtolower($fileInfo->getExtension());
    if (!in_array($ext, $videoExtensions, true)) {
        continue;
    }

    $totalVideos++;
    $fullPath = $fileInfo->getPathname();
    $relativePath = ltrim(str_replace($contentRoot, '', $fullPath), '/');
    $thumbPath = preg_replace('/\.[^.]+$/', '.jpg', $fullPath);

    // Skip if thumbnail already exists
    if (file_exists($thumbPath)) {
        $relativeThumb = ltrim(str_replace($contentRoot, '', $thumbPath), '/');
        echo "Skipped: {$relativeThumb} (already exists)\n";
        $skipped++;
        continue;
    }

    $result = generateThumbnail($fullPath);

    if ($result !== null) {
        $relativeThumb = ltrim(str_replace($contentRoot, '', $result), '/');
        echo "Generated: {$relativeThumb}\n";
        $generated++;
    } else {
        echo "Error: failed to generate thumbnail for {$relativePath}\n";
        $errors++;
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Summary
// ──────────────────────────────────────────────────────────────────────────────

echo "\n";
echo "Thumbnail generation complete.\n";
echo "Total videos: {$totalVideos}\n";
echo "Generated:    {$generated}\n";
echo "Skipped:      {$skipped}\n";
echo "Errors:       {$errors}\n";

exit($errors > 0 ? 1 : 0);
