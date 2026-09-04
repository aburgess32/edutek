#!/usr/bin/env php
<?php

/**
 * Content Index Script (FRE-13 / FRE-40)
 *
 * CLI script to walk the content directory tree and UPSERT metadata
 * into the content_meta table using the shared indexContent() function.
 *
 * Usage:
 *   php scripts/index-content.php [content_root_path]
 *
 *   If no path is given, uses CONTENT_PATH from config.
 *
 * @package EduPak
 */

// -----------------------------------------------------------------------------
// Bootstrap
// -----------------------------------------------------------------------------

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content-indexer.php';

// -----------------------------------------------------------------------------
// Resolve content root path
// -----------------------------------------------------------------------------

$contentRoot = isset($argv[1])
    ? rtrim($argv[1], '/')
    : rtrim(CONTENT_PATH, '/');

echo "Indexing content from: {$contentRoot}\n";

// -----------------------------------------------------------------------------
// Run indexer
// -----------------------------------------------------------------------------

$result = indexContent($contentRoot);

if (!$result['success']) {
    fwrite(STDERR, "Error: {$result['error']}\n");
    fwrite(STDERR, "Usage: php scripts/index-content.php [content_root_path]\n");
    exit(1);
}

// -----------------------------------------------------------------------------
// Summary
// -----------------------------------------------------------------------------

echo "\n";
echo "Indexing complete.\n";
echo "Indexed {$result['total']} items, {$result['new']} new, {$result['updated']} updated";

if ($result['skipped'] > 0) {
    echo ", {$result['skipped']} skipped";
}

echo "\n";

if (!empty($result['audit_path'])) {
    echo "Audit report: {$result['audit_path']}\n";
}