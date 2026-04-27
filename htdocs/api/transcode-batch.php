#!/usr/bin/env php
<?php
/**
 * Batch HEVC to H.264 Transcoder
 *
 * Iterates through all videos marked as HEVC in content_meta and
 * transcodes them one at a time.  Safe to run in the background
 * or via cron.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

$isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
if (!$isCli) {
    http_response_code(403);
    echo "CLI only.\n";
    exit;
}

$batchSize = isset($argv[1]) ? max(1, (int) $argv[1]) : 10;

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

$stmt = $pdo->prepare("
    SELECT content_id, file_path
    FROM content_meta
    WHERE codec = 'hevc'
      AND (transcoded_path IS NULL OR transcoded_path = '')
    ORDER BY id
    LIMIT :limit
");
$stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
$stmt->execute();

$processed = 0;
$skipped   = 0;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $contentId = $row['content_id'];
    $lockPath = dirname(CONTENT_PATH . '/' . ltrim($row['file_path'], '/')) .
                '/' . pathinfo(basename($row['file_path']), PATHINFO_FILENAME) . '.h264.mp4.transcoding';

    // Skip if already in-progress
    if (file_exists($lockPath)) {
        echo "  SKIP (already transcoding): {$contentId}\n";
        $skipped++;
        continue;
    }

    echo "  START: {$contentId}\n";

    // Call transcode.php via internal request
    $_GET['id'] = $contentId;
    ob_start();
    require __DIR__ . '/transcode.php';
    $out = ob_get_clean();
    $result = json_decode($out, true) ?: [];

    if (($result['status'] ?? '') === 'transcoding') {
        echo "    → started\n";
    } elseif (($result['status'] ?? '') === 'ready') {
        echo "    → already done\n";
    } else {
        echo "    → error: " . ($result['message'] ?? 'unknown') . "\n";
    }
    $processed++;
}

echo "\nBatch done. Queued: {$processed}, Skipped: {$skipped}\n";
