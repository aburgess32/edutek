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
 * Check whether ffmpeg is available on this system.
 * Caches the result so the check only runs once per request.
 *
 * @return bool
 */
function isFfmpegAvailable(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    $output = [];
    $code = -1;
    @exec('ffmpeg -version 2>&1', $output, $code);
    $available = ($code === 0);
    return $available;
}

/**
 * Get the duration of a video file in seconds using ffprobe.
 *
 * @param string $videoPath Absolute path to the video file
 * @return float Duration in seconds, or 0.0 on failure
 */
function getVideoDuration(string $videoPath): float
{
    $output = [];
    $code = -1;
    $cmd = sprintf(
        'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
        escapeshellarg($videoPath)
    );
    @exec($cmd, $output, $code);
    if ($code === 0 && !empty($output[0])) {
        return (float) $output[0];
    }
    return 0.0;
}

/**
 * Generate a thumbnail for a video file using ffmpeg.
 *
 * Extracts a single frame at 6 seconds into the video,
 * scales to 320px width, and saves as a JPEG alongside the video.
 * Uses proc_open for cross-platform timeout support (macOS + Linux).
 *
 * @param string $videoPath Absolute path to the video file
 * @param int    $timeout   Max seconds to allow ffmpeg to run
 * @return string|null Absolute path to the generated thumbnail, or null on failure
 */
function generateThumbnail(string $videoPath, int $timeout = 15): ?string
{
    if (!isFfmpegAvailable()) {
        return null;
    }

    if (!file_exists($videoPath) || !is_readable($videoPath)) {
        return null;
    }

    $thumbPath = preg_replace('/\.[^.]+$/', '.jpg', $videoPath);

    if (file_exists($thumbPath)) {
        return $thumbPath;
    }

    $seekSec = 6;

    $cmd = sprintf(
        'ffmpeg -ss %d -i %s -vframes 1 -update 1 -q:v 2 -vf "scale=320:-1" %s -y 2>&1',
        $seekSec,
        escapeshellarg($videoPath),
        escapeshellarg($thumbPath)
    );

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        return null;
    }

    fclose($pipes[0]);

    $startTime = time();
    $status = proc_get_status($process);

    while ($status['running']) {
        if (time() - $startTime > $timeout) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) fclose($pipe);
            }
            proc_terminate($process, 9);
            proc_close($process);
            @unlink($thumbPath);
            return null;
        }
        usleep(100000);
        $status = proc_get_status($process);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = $status['exitcode'];
    proc_close($process);

    if ($exitCode !== 0 || !file_exists($thumbPath) || filesize($thumbPath) < 100) {
        @unlink($thumbPath);
        return null;
    }

    return $thumbPath;
}

/**
 * Index all content files in a directory tree and UPSERT into content_meta.
 *
 * Paths stored in content_meta are relative to htdocs so they are
 * web-accessible (e.g. "videos/Math/Algebra/intro.mp4").
 *
 * @param string $contentRoot Absolute path to the content directory
 * @param int    $maxThumbnails Maximum thumbnails to generate per run (0 = unlimited)
 * @return array{success: bool, total: int, new: int, updated: int, skipped: int, thumbnails_generated: int, error?: string}
 */
function indexContent(string $contentRoot, int $maxThumbnails = 0): array
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
    // If the content dir is inside htdocs, strip htdocs path to get "videos/..."
    $webPrefix = '';
    if ($htdocsRoot && $realContentRoot && strpos($realContentRoot, $htdocsRoot) === 0) {
        $webPrefix = ltrim(substr($realContentRoot, strlen($htdocsRoot)), '/');
    }

    $pdo = getDbConnection();

    $newCount     = 0;
    $updatedCount = 0;
    $skippedCount = 0;
    $totalCount   = 0;
    $thumbsGenerated = 0;

    // Video extensions that support thumbnail generation
    $videoExtensions = ['mp4', 'webm', 'mkv', 'avi'];

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

        // Build web-accessible path (e.g. "videos/Math/Algebra/intro.mp4")
        $webPath = $webPrefix ? $webPrefix . '/' . $relativePath : $relativePath;

        // Content ID is the web-accessible relative path
        $contentId = $webPath;

        // Derive title from filename
        $filename = pathinfo($fileInfo->getFilename(), PATHINFO_FILENAME);
        $title    = titleFromFilename($filename);

        // Detect source
        $source = detectSource($relativePath);

        // Generate thumbnail for video files if ffmpeg is available
        if (in_array($extension, $videoExtensions, true)
            && ($maxThumbnails === 0 || $thumbsGenerated < $maxThumbnails)
        ) {
            $thumbFile = preg_replace('/\.[^.]+$/', '.jpg', $fullPath);
            $alreadyExists = file_exists($thumbFile);
            if (!$alreadyExists) {
                $generated = generateThumbnail($fullPath);
                if ($generated !== null) {
                    $thumbsGenerated++;
                }
            }
        }

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
        'success'              => true,
        'total'                => $totalCount,
        'new'                  => $newCount,
        'updated'              => $updatedCount,
        'skipped'              => $skippedCount,
        'thumbnails_generated' => $thumbsGenerated,
    ];
}
