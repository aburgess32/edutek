#!/usr/bin/env php
<?php
/**
 * EduPak CLI Content Indexer
 *
 * Scans content directories and populates the content_meta table.
 * Designed for first-time migration to index existing content
 * (videos, Khan Academy, Wikipedia, Kiwix) on EduPak devices.
 *
 * Usage:
 *   php scripts/content-indexer.php                    # Use CONTENT_PATH from .env
 *   php scripts/content-indexer.php D:\xampp\htdocs\Edutek  # Explicit path
 *
 * @package EduPak
 */

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/../htdocs/includes/config.php';
require_once __DIR__ . '/../htdocs/includes/auth.php';

// Check for content-indexer include
$indexerPath = __DIR__ . '/../htdocs/includes/content-indexer.php';
if (file_exists($indexerPath)) {
    require_once $indexerPath;
} else {
    // Fallback: use the existing index-content.php logic
    echo "Warning: content-indexer.php include not found, using fallback.\n";
    echo "Run: php scripts/index-content.php instead.\n";
    exit(1);
}

// Resolve content root
$contentRoot = isset($argv[1]) ? rtrim($argv[1], '/\\') : rtrim(CONTENT_PATH, '/\\');

echo "EduPak Content Indexer\n";
echo str_repeat('-', 50) . "\n";
echo "Content root: {$contentRoot}\n\n";

if (!is_dir($contentRoot)) {
    fwrite(STDERR, "Error: Directory not found: {$contentRoot}\n");
    exit(1);
}

$result = indexContent($contentRoot);

if (!$result['success']) {
    fwrite(STDERR, "Error: {$result['error']}\n");
    exit(1);
}

echo "\nIndexing complete.\n";
echo "  Total:   {$result['total']}\n";
echo "  New:     {$result['new']}\n";
echo "  Updated: {$result['updated']}\n";
if ($result['skipped'] > 0) {
    echo "  Skipped: {$result['skipped']}\n";
}
