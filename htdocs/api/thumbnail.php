<?php
/**
 * Lazy Thumbnail Generator API
 *
 * GET /api/thumbnail.php?content_id=<content_id>
 *
 * Checks for an existing thumbnail on disk. If none exists, generates one
 * using ffmpeg and updates the content_meta table.
 *
 * Returns JSON:
 *   {
 *     "success": true,
 *     "thumbnail_url": "content/Category/Sub/file.jpg",
 *     "generated": false
 *   }
 *
 * Or on error:
 *   {
 *     "success": false,
 *     "fallback": "images/sample.png"
 *   }
 *
 * Concurrency is managed via a lock file so two simultaneous requests
 * for the same video never spawn duplicate ffmpeg processes.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content-indexer.php';
require_once __DIR__ . '/../includes/tiles.php';

header('Content-Type: application/json; charset=utf-8');

$contentId = isset($_GET['content_id']) ? trim($_GET['content_id']) : '';

if ($contentId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'fallback' => 'images/sample.png']);
    exit;
}

try {
    $pdo = getDbConnection();

    // Look up the video file path
    $stmt = $pdo->prepare('SELECT file_path, thumbnail_path, content_type FROM content_meta WHERE content_id = ?');
    $stmt->execute([$contentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'fallback' => 'images/sample.png']);
        exit;
    }

    // Non-video types — no thumbnail generation needed
    if ($row['content_type'] !== 'video') {
        echo json_encode(['success' => true, 'thumbnail_url' => '', 'generated' => false]);
        exit;
    }

    $contentRoot = rtrim(CONTENT_PATH, '/');
    $filePath = $row['file_path'];

    // Strip content/ prefix to get the absolute filesystem path
    $relativePath = ltrim($filePath, '/');
    if (strpos($relativePath, basename($contentRoot) . '/') === 0) {
        $relativePath = substr($relativePath, strlen(basename($contentRoot)) + 1);
    }

    $fullPath = $contentRoot . '/' . $relativePath;

    // If a thumbnail is already recorded in the DB, verify it exists
    $dbThumb = $row['thumbnail_path'] ?? '';
    if ($dbThumb !== '') {
        $thumbDiskPath = $contentRoot . '/' . ltrim(str_replace(basename($contentRoot) . '/', '', $dbThumb), '/');
        if (file_exists($thumbDiskPath) && filesize($thumbDiskPath) > 100) {
            echo json_encode(['success' => true, 'thumbnail_url' => resolveContentUrl($dbThumb), 'generated' => false]);
            exit;
        }
    }

    // Check for a sibling thumbnail with matching basename
    $dir = dirname($fullPath);
    $base = pathinfo($fullPath, PATHINFO_FILENAME);
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $candidate = $dir . '/' . $base . '.' . $ext;
        if (file_exists($candidate) && filesize($candidate) > 100) {
            $webPath = resolveContentUrl(basename($contentRoot) . '/' . str_replace($contentRoot . '/', '', $candidate));
            // Update DB so next query is fast
            $up = $pdo->prepare('UPDATE content_meta SET thumbnail_path = ? WHERE content_id = ?');
            $up->execute([$webPath, $contentId]);
            echo json_encode(['success' => true, 'thumbnail_url' => $webPath, 'generated' => false]);
            exit;
        }
    }

    // No thumbnail on disk — generate one
    if (!isFfmpegAvailable() || !file_exists($fullPath)) {
        echo json_encode(['success' => false, 'fallback' => 'images/sample.png']);
        exit;
    }

    // Concurrency lock: per-content-id lock file
    $lockDir = sys_get_temp_dir() . '/edupak-thumbs';
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0777, true);
    }
    $lockFile = $lockDir . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $contentId) . '.lock';

    // If another process is generating, wait briefly then check again
    $waitStart = microtime(true);
    while (file_exists($lockFile) && (microtime(true) - $waitStart) < 10) {
        usleep(200000); // 200ms
    }

    // Double-check after waiting
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $candidate = $dir . '/' . $base . '.' . $ext;
        if (file_exists($candidate) && filesize($candidate) > 100) {
            $webPath = resolveContentUrl(basename($contentRoot) . '/' . str_replace($contentRoot . '/', '', $candidate));
            $up = $pdo->prepare('UPDATE content_meta SET thumbnail_path = ? WHERE content_id = ?');
            $up->execute([$webPath, $contentId]);
            echo json_encode(['success' => true, 'thumbnail_url' => $webPath, 'generated' => false]);
            exit;
        }
    }

    // Create lock file
    @file_put_contents($lockFile, (string) time());

    $generatedPath = generateThumbnail($fullPath, 15);

    @unlink($lockFile);

    if ($generatedPath !== null && file_exists($generatedPath) && filesize($generatedPath) > 100) {
        $webPath = resolveContentUrl(basename($contentRoot) . '/' . str_replace($contentRoot . '/', '', $generatedPath));
        $up = $pdo->prepare('UPDATE content_meta SET thumbnail_path = ? WHERE content_id = ?');
        $up->execute([$webPath, $contentId]);
        echo json_encode(['success' => true, 'thumbnail_url' => $webPath, 'generated' => true]);
        exit;
    }

    // Generation failed
    echo json_encode(['success' => false, 'fallback' => 'images/sample.png']);

} catch (Exception $e) {
    error_log('Thumbnail API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'fallback' => 'images/sample.png']);
}
