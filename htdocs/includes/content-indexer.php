<?php

/**
 * Content Indexer (FRE-40)
 *
 * Shared indexing logic used by both the CLI script (scripts/index-content.php)
 * and the web API endpoint (api/reindex.php).
 *
 * Walks a content directory tree, extracts metadata from the folder structure,
 * and UPSERTs into the content_meta table.
 *
 * Directory structure expected:
 *   <content_root>/<Category>/<Subcategory>/<filename.ext>
 *
 * @package EduPak
 */

// Content type mapping by file extension
define('CONTENT_EXTENSION_MAP', [
    'mp4'  => 'video',
    'webm' => 'video',
    'mkv'  => 'video',
    'avi'  => 'video',
    'mp3'  => 'audiobook',
    'ogg'  => 'audiobook',
    'wav'  => 'audiobook',
    'pdf'  => 'pdf',
]);

// Known content sources detected by folder name patterns (case-insensitive)
define('CONTENT_SOURCE_PATTERNS', [
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
]);

/**
 * Detect content source from a file path by matching folder names against
 * known provider patterns.
 *
 * @param string $relativePath Path relative to content root
 * @return string Source name or 'Local'
 */
function detectSource(string $relativePath): string
{
    $lower = strtolower($relativePath);
    foreach (CONTENT_SOURCE_PATTERNS as $pattern => $sourceName) {
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
 * @param string $filePath    Full path to the content file
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

/**
 * Index all content files in a directory tree and UPSERT into content_meta.
 *
 * Paths stored in content_meta are relative to htdocs so they are
 * web-accessible (e.g. "Videos/Math/Algebra/intro.mp4").
 *
 * @param string $contentRoot Absolute path to the content directory
 * @return array{success: bool, total: int, new: int, updated: int, skipped: int, error?: string}
 */
function indexContent(string $contentRoot): array
{
    $contentRoot = rtrim($contentRoot, '/');

    if (!is_dir($contentRoot)) {
        return [
            'success' => false,
            'total'   => 0,
            'new'     => 0,
            'updated' => 0,
            'skipped' => 0,
            'error'   => "Content directory not found: {$contentRoot}",
        ];
    }

    // Resolve htdocs root for computing web-accessible relative paths
    $htdocsRoot = realpath(__DIR__ . '/..');
    $realContentRoot = realpath($contentRoot);

    // Determine the web-relative prefix.
    // If the content dir is inside htdocs, strip htdocs path to get "Videos/..."
    $webPrefix = '';
    if ($htdocsRoot && $realContentRoot && strpos($realContentRoot, $htdocsRoot) === 0) {
        $webPrefix = ltrim(substr($realContentRoot, strlen($htdocsRoot)), '/');
    }

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
        return [
            'success' => false,
            'total'   => 0,
            'new'     => 0,
            'updated' => 0,
            'skipped' => 0,
            'error'   => "Cannot read directory: {$e->getMessage()}",
        ];
    }

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || !$fileInfo->isReadable()) {
            $skippedCount++;
            continue;
        }

        $fullPath  = $fileInfo->getPathname();
        $extension = strtolower($fileInfo->getExtension());

        // Only index known content types
        if (!isset(CONTENT_EXTENSION_MAP[$extension])) {
            continue;
        }

        $contentType  = CONTENT_EXTENSION_MAP[$extension];
        $relativePath = ltrim(str_replace($contentRoot, '', $fullPath), '/');
        $parts        = explode('/', $relativePath);

        // Extract category from first-level folder, subcategory from second-level
        $category    = (count($parts) >= 2) ? $parts[0] : '';
        $subcategory = (count($parts) >= 3) ? $parts[1] : '';

        // Build web-accessible path (e.g. "Videos/Math/Algebra/intro.mp4")
        $webPath = $webPrefix ? $webPrefix . '/' . $relativePath : $relativePath;

        // Content ID is the web-accessible relative path
        $contentId = $webPath;

        // Derive title from filename
        $filename = pathinfo($fileInfo->getFilename(), PATHINFO_FILENAME);
        $title    = titleFromFilename($filename);

        // Detect source
        $source = detectSource($relativePath);

        // Find thumbnail — store as web-relative path
        $thumbnail = findThumbnail($fullPath, $contentRoot);
        if ($thumbnail !== null && $webPrefix) {
            $thumbnail = $webPrefix . '/' . $thumbnail;
        }

        try {
            $upsertStmt->execute([
                ':content_id'     => $contentId,
                ':title'          => $title,
                ':file_path'      => $webPath,
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
            $skippedCount++;
        }
    }

    return [
        'success' => true,
        'total'   => $totalCount,
        'new'     => $newCount,
        'updated' => $updatedCount,
        'skipped' => $skippedCount,
    ];
}
