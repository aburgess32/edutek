<?php
/**
 * HEVC to H.264 Transcoder — On-Demand + Background
 *
 * Serves a transcoded H.264 version of a video. If none exists, starts
 * a background ffmpeg job and returns a processing status.
 *
 * Query params:
 *   id  = content_id (file path, e.g. "Category/Sub/file.mp4")
 *
 * Returns JSON:
 *   { "status": "ready",     "url": "/content/Category/Sub/file.h264.mp4" }
 *   { "status": "transcoding", "url": "...", "started_at": "..." }
 *   { "status": "error",     "message": "..." }
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/tiles.php';

$contentId = $_GET['id'] ?? '';
if ($contentId === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing id parameter']);
    exit;
}

// Resolve file path using new helpers
$normalizedPath = normalizeContentPath($contentId);
$sourceFile     = rtrim(CONTENT_PATH, '/') . '/' . $normalizedPath;

if (!file_exists($sourceFile)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Source file not found']);
    exit;
}

// Determine transcoded output path: same dir, .h264.mp4 suffix
$dir      = dirname($sourceFile);
$baseName = basename($sourceFile, '.' . pathinfo($sourceFile, PATHINFO_EXTENSION));
$ext      = pathinfo($sourceFile, PATHINFO_EXTENSION);
$outputFile = $dir . '/' . $baseName . '.h264.' . $ext;
$lockFile   = $outputFile . '.transcoding';

// Helper: check if file is actually HEVC
function isHevc(string $filePath): bool
{
    $cmd = sprintf(
        'ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of csv=s=x:p=0 %s 2>&1',
        escapeshellarg($filePath)
    );
    $out = shell_exec($cmd);
    return ($out !== null && trim($out) === 'hevc');
}

// 1. Already transcoded → update DB if needed, then serve
if (file_exists($outputFile) && filesize($outputFile) > 0) {
    $webUrl = '/content/' . $normalizedPath;
    $webUrl = dirname($webUrl) . '/' . rawurlencode($baseName . '.h264.' . $ext);

    // Ensure DB knows about the transcoded file so the batch loop skips it
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        $stmt = $pdo->prepare(
            "UPDATE content_meta SET transcoded_path = :tp, codec = 'hevc' WHERE content_id = :id AND (transcoded_path IS NULL OR transcoded_path = '')"
        );
        $stmt->execute([':tp' => $webUrl, ':id' => $contentId]);
    } catch (PDOException $e) {
        error_log('Transcode DB update failed: ' . $e->getMessage());
    }

    echo json_encode(['status' => 'ready', 'url' => $webUrl]);
    exit;
}

// 2. Transcoding is in progress → report
if (file_exists($lockFile)) {
    $started = filemtime($lockFile) ?: time();
    $elapsed = time() - $started;
    
    // Stale lock (> 30 min) — treat as failed and restart
    if ($elapsed > 1800) {
        @unlink($lockFile);
    } else {
        echo json_encode([
            'status'      => 'transcoding',
            'message'     => 'Video is being converted for browser playback',
            'elapsed_sec' => $elapsed,
            'started_at'  => date('c', $started),
        ]);
        exit;
    }
}

// 3. Not HEVC → no conversion needed
if (!isHevc($sourceFile)) {
    // Update codec column if needed
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    $stmt = $pdo->prepare("UPDATE content_meta SET codec = 'h264', duration_seconds = COALESCE(duration_seconds, 0) WHERE content_id = :id AND (codec IS NULL OR codec != 'h264')");
    $stmt->execute([':id' => $contentId]);

    $webUrl = '/content/' . $normalizedPath;
    echo json_encode(['status' => 'ready', 'url' => $webUrl, 'codec' => 'h264']);
    exit;
}

// 4. HEVC and no transcoded file exists → start transcoding
$dirExists = file_exists($dir);
if (!$dirExists) {
    // Shouldn't happen if source exists, but be safe
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Directory missing']);
    exit;
}

// Update DB: mark as hevc
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);
$stmt = $pdo->prepare("UPDATE content_meta SET codec = 'hevc' WHERE content_id = :id");
$stmt->execute([':id' => $contentId]);

touch($lockFile);

// Build ffmpeg command: HEVC → H.264 (libx264), fast preset, copy audio, overwrite
// Using -movflags +faststart for progressive web playback
$ffmpegCmd = sprintf(
    'ffmpeg -y -i %s -c:v libx264 -preset fast -crf 23 -c:a copy -movflags +faststart %s 2>&1 && rm -f %s || (rm -f %s; echo "TRANSCODE_FAILED" >> %s)',
    escapeshellarg($sourceFile),
    escapeshellarg($outputFile),
    escapeshellarg($lockFile),
    escapeshellarg($outputFile),
    escapeshellarg($lockFile . '.log')
);

// Run in background so this request returns immediately
$logFile = $lockFile . '.log';
$bgCmd   = 'nohup sh -c ' . escapeshellarg($ffmpegCmd) . ' > ' . escapeshellarg($logFile) . ' 2>&1 &';
shell_exec($bgCmd);

// Also update DB with transcoded path
$transcodedRel = dirname($normalizedPath) . '/' . basename($outputFile);
$stmt = $pdo->prepare("UPDATE content_meta SET transcoded_path = :tp WHERE content_id = :id");
$stmt->execute([':tp' => $transcodedRel, ':id' => $contentId]);

echo json_encode([
    'status'      => 'transcoding',
    'message'     => 'Video conversion started. Please wait.',
    'started_at'  => date('c'),
]);
