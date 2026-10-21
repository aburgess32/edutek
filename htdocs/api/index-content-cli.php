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

$onlyPaths = null;
foreach (array_slice($argv, 2) as $arg) {
    if (strpos($arg, '--files=') === 0) {
        $manifest = substr($arg, 8);
        $data = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;
        if (!is_array($data)) {
            fwrite(STDERR, "Error: cannot read file list {$manifest}\n");
            exit(1);
        }
        $onlyPaths = array_values(array_filter($data, 'is_string'));
        echo 'Indexing only ' . count($onlyPaths) . " listed files\n";
    }
}
$result = indexContent($contentRoot, 0, $onlyPaths);

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