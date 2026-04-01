#!/usr/bin/env php
<?php

/**
 * Content Index Script (FRE-13)
 *
 * One-time CLI script to walk the Beelink's content directory tree
 * and UPSERT metadata into the content_meta table.
 *
 * Directory structure expected:
 *   <content_root>/<Category>/<Subcategory>/<filename.ext>
 *
 * Source is detected from folder names containing known provider names
 * (e.g. "Khan Academy", "CK-12").
 *
 * Usage:
 *   php scripts/index-content.php [content_root_path]
 *
 *   If no path is given, uses CONTENT_PATH from config.
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
require_once __DIR__ . '/../htdocs/includes/auth.php';

// ──────────────────────────────────────────────────────────────────────────────
// Configuration
// ──────────────────────────────────────────────────────────────────────────────

// Content type mapping by file extension
$extensionMap = [
    'mp4'  => 'video',
    'webm' => 'video',
    'mkv'  => 'video',
    'avi'  => 'video',
    'mp3'  => 'audiobook',
    'ogg'  => 'audiobook',
    'wav'  => 'audiobook',
    'pdf'  => 'pdf',
];

// Known content sources detected by folder name patterns (case-insensitive)
$sourcePatterns = [
    'khan academy'  => 'Khan Academy',
    'khan_academy'  => 'Khan Academy',
    'khanacademy'   => 'Khan Academy',
    'ck-12'         => 'CK-12',
    'ck12'          => 'CK-12',
    'openstax'      => 'OpenStax',
    'mit'           => 'MIT',
    'crash course'  => 'Crash Course',
    'crashcourse'   => 'Crash Course',
    'ted-ed'        => 'TED-Ed',
    'teded'         => 'TED-Ed',
];

// ──────────────────────────────────────────────────────────────────────────────
// Resolve content root path
// ──────────────────────────────────────────────────────────────────────────────

$contentRoot = isset($argv[1]) ? rtrim($argv[1], '/') : rtrim(CONTENT_PATH, '/');

if (!is_dir($contentRoot)) {
    fwrite(STDERR, "Error: Content root directory not found: {$contentRoot}\n");
    fwrite(STDERR, "Usage: php scripts/index-content.php [content_root_path]\n");
    exit(1);
}

echo "Indexing content from: {$contentRoot}\n";

// ──────────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Detect content source from a file path by matching folder names against
 * known provider patterns.
 *
 * @param string $relativePath  Path relative to content root
 * @param array  $patterns      Source pattern map
 * @return string Source name or 'Local'
 */
function detectSource(string $relativePath, array $patterns): string
{
    $lower = strtolower($relativePath);
    foreach ($patterns as $pattern => $sourceName) {
        if (strpos($lower, $pattern) !== false) {
            return $sourceName;
        }
    }
    return 'Local';
}

/**
 * Derive a human-readable title from a filename.
 *
 * @param string $filename Basename without extension
 * @return string
 */
function titleFromFilename(string $filename): string
{
    // Remove leading numbers/dots/dashes (e.g. "001 - ", "01.", "1-")
    $clean = preg_replace('/^\d+[\s.\-_]+/', '', $filename);
    // Replace underscores and hyphens with spaces
    $clean = str_replace(['_', '-'], ' ', $clean);
    // Collapse multiple spaces
    $clean = preg_replace('/\s+/', ' ', trim($clean));
    // Title case
    return mb_convert_case($clean, MB_CASE_TITLE, 'UTF-8');
}

/**
 * Find a thumbnail file alongside a content file.
 *
 * @param string $filePath Full path to the content file
 * @param string $contentRoot Root content directory
 * @return string|null Relative thumbnail path or null
 */
function findThumbnail(string $filePath, string $contentRoot): ?string
{
    $dir  = dirname($filePath);
    $base = pathinfo($filePath, PATHINFO_FILENAME);
    $thumbExtensions = ['jpg', 'jpeg', 'png', 'webp'];

    foreach ($thumbExtensions as $ext) {
        $thumbPath = $dir . '/' . $base . '.' . $ext;
        if (file_exists($thumbPath)) {
            return ltrim(str_replace($contentRoot, '', $thumbPath), '/');
        }
    }

    // Check for a folder-level thumbnail
    foreach ($thumbExtensions as $ext) {
        $folderThumb = $dir . '/thumbnail.' . $ext;
        if (file_exists($folderThumb)) {
            return ltrim(str_replace($contentRoot, '', $folderThumb), '/');
        }
    }

    return null;
}

// ──────────────────────────────────────────────────────────────────────────────
// Walk directory tree and collect content files
// ──────────────────────────────────────────────────────────────────────────────

$pdo = getDbConnection();

$newCount     = 0;
$updatedCount = 0;
$skippedCount = 0;
$totalCount   = 0;

// Prepare UPSERT statement
$upsertStmt = $pdo->prepare("
    INSERT INTO content_meta
        (content_id, title, file_path, content_type, category, subcategory, source, thumbnail_path)
    VALUES
        (:content_id, :title, :file_path, :content_type, :category, :subcategory, :source, :thumbnail_path)
    ON DUPLICATE KEY UPDATE
        title          = VALUES(title),
        content_type   = VALUES(content_type),
        category       = VALUES(category),
        subcategory    = VALUES(subcategory),
        source         = VALUES(source),
        thumbnail_path = VALUES(thumbnail_path)
");

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
        $skippedCount++;
        continue;
    }

    $fullPath  = $fileInfo->getPathname();
    $extension = strtolower($fileInfo->getExtension());

    // Only index known content types
    if (!isset($extensionMap[$extension])) {
        continue;
    }

    $contentType  = $extensionMap[$extension];
    $relativePath = ltrim(str_replace($contentRoot, '', $fullPath), '/');
    $parts        = explode('/', $relativePath);

    // Extract category from first-level folder, subcategory from second-level
    $category    = (count($parts) >= 2) ? $parts[0] : '';
    $subcategory = (count($parts) >= 3) ? $parts[1] : '';

    // Content ID is the relative path
    $contentId = $relativePath;

    // Derive title from filename
    $filename = pathinfo($fileInfo->getFilename(), PATHINFO_FILENAME);
    $title    = titleFromFilename($filename);

    // Detect source
    $source = detectSource($relativePath, $sourcePatterns);

    // Find thumbnail
    $thumbnail = findThumbnail($fullPath, $contentRoot);

    try {
        $upsertStmt->execute([
            ':content_id'     => $contentId,
            ':title'          => $title,
            ':file_path'      => $relativePath,
            ':content_type'   => $contentType,
            ':category'       => $category,
            ':subcategory'    => $subcategory,
            ':source'         => $source,
            ':thumbnail_path' => $thumbnail,
        ]);

        $rowCount = $upsertStmt->rowCount();
        // rowCount: 1 = insert, 2 = update (MySQL UPSERT semantics)
        if ($rowCount === 1) {
            $newCount++;
        } elseif ($rowCount === 2) {
            $updatedCount++;
        }
        $totalCount++;
    } catch (PDOException $e) {
        fwrite(STDERR, "Warning: Failed to index {$relativePath}: {$e->getMessage()}\n");
        $skippedCount++;
    }

    // Print progress every 100 items
    if ($totalCount > 0 && $totalCount % 100 === 0) {
        echo "  Progress: {$totalCount} items indexed...\n";
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Summary
// ──────────────────────────────────────────────────────────────────────────────

echo "\n";
echo "Indexing complete.\n";
echo "Indexed {$totalCount} items, {$newCount} new, {$updatedCount} updated";
if ($skippedCount > 0) {
    echo ", {$skippedCount} skipped";
}
echo "\n";
