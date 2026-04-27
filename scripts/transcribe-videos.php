#!/usr/bin/env php
<?php
/**
 * Video Transcription Batch Script
 *
 * Usage:
 *   php scripts/transcribe-videos.php [--limit=N] [--content-path=PATH] [--category=NAME]
 *
 * Processes videos where transcript_status = 'pending'.
 * Uses a lock file to prevent concurrent runs.
 */

if (php_sapi_name() !== 'cli') {
    echo "CLI only.\n";
    exit(1);
}

require_once __DIR__ . '/../htdocs/includes/config.php';
require_once __DIR__ . '/../htdocs/includes/auth.php';
require_once __DIR__ . '/../htdocs/includes/content-indexer.php';

// Parse args
$limit = 0;
$category = '';
$categoryPrefix = '';
$contentRoot = rtrim(CONTENT_PATH, '/');
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, 8);
    }
    if (str_starts_with($arg, '--content-path=')) {
        $contentRoot = rtrim(substr($arg, 15), '/');
    }
    if (str_starts_with($arg, '--category=')) {
        $category = substr($arg, 11);
    }
    if (str_starts_with($arg, '--category-prefix=')) {
        $categoryPrefix = substr($arg, 18);
    }
}

// Lock file
$lockFile = sys_get_temp_dir() . '/edupak_transcription.lock';
if (file_exists($lockFile)) {
    $age = time() - filemtime($lockFile);
    if ($age < 300) {
        echo "Another transcription process is running (lock age: {$age}s).\n";
        exit(1);
    }
    echo "Stale lock found (age: {$age}s). Removing and continuing.\n";
    @unlink($lockFile);
}
touch($lockFile);

// Checks
if (!isFfmpegAvailable()) {
    fwrite(STDERR, "ffmpeg not available.\n");
    @unlink($lockFile);
    exit(1);
}
if (!isWhisperAvailable()) {
    fwrite(STDERR, "whisper-cli not available.\n");
    @unlink($lockFile);
    exit(1);
}
$modelPath = getWhisperModelPath();
if (!file_exists($modelPath)) {
    fwrite(STDERR, "Model not found: {$modelPath}\nRun download from admin panel first.\n");
    @unlink($lockFile);
    exit(1);
}

// Database
try {
    $pdo = getDbConnection();
} catch (PDOException $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    @unlink($lockFile);
    exit(1);
}

// Reset stale processing rows (>1 hour)
$pdo->exec("UPDATE content_meta SET transcript_status = 'pending' WHERE transcript_status = 'processing' AND (transcript_updated_at IS NULL OR transcript_updated_at < DATE_SUB(NOW(), INTERVAL 1 HOUR))");

// Query pending videos
$where = "content_type = 'video' AND transcript_status = 'pending'";
$params = [];
if ($category !== '') {
    $where .= " AND category = :category";
    $params[':category'] = $category;
}
if ($categoryPrefix !== '') {
    $where .= " AND category LIKE :prefix";
    $params[':prefix'] = $categoryPrefix . '%';
}

$sql = "SELECT content_id, file_path FROM content_meta WHERE {$where} ORDER BY content_id ASC";
if ($limit > 0) {
    $sql .= " LIMIT {$limit}";
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$videos = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($videos)) {
    echo "No pending videos.\n";
    @unlink($lockFile);
    exit(0);
}

echo "Processing " . count($videos) . " video(s)...\n\n";

$updateStmt = $pdo->prepare("
    UPDATE content_meta
    SET transcript_snippet = :snippet,
        transcript_status = :status,
        transcript_updated_at = NOW()
    WHERE content_id = :content_id
");

$done = 0;
$failed = 0;

foreach ($videos as $video) {
    $contentId = $video['content_id'];
    $filePath = $contentRoot . '/' . ltrim($video['file_path'], '/');

    if (!file_exists($filePath)) {
        echo "[SKIP] File not found: {$contentId}\n";
        $updateStmt->execute([':snippet' => null, ':status' => 'failed', ':content_id' => $contentId]);
        $failed++;
        continue;
    }

    // Mark as processing
    $updateStmt->execute([':snippet' => null, ':status' => 'processing', ':content_id' => $contentId]);

    echo "[PROCESS] {$contentId}\n";
    $snippet = transcribeVideoSnippet($filePath, $modelPath, 90);

    if ($snippet !== null && $snippet !== '') {
        $updateStmt->execute([':snippet' => $snippet, ':status' => 'done', ':content_id' => $contentId]);
        $preview = mb_substr($snippet, 0, 80);
        echo "  -> {$preview}...\n";
        $done++;
    } else {
        $updateStmt->execute([':snippet' => null, ':status' => 'failed', ':content_id' => $contentId]);
        echo "  -> FAILED\n";
        $failed++;
    }

    touch($lockFile);
}

@unlink($lockFile);

echo "\nBatch complete. Done: {$done}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
