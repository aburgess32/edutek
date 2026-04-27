<?php
/**
 * Video Duration & Health Scanner
 *
 * Batch-scans all video files in content_meta to:
 *  - Populate duration_seconds using ffprobe
 *  - Detect broken / unreadable files
 *  - Detect HEVC / H.265 codec (limited browser support)
 *
 * Usage (CLI inside Docker container):
 *   php /var/www/html/api/scan-videos.php
 *
 * Usage (web with admin key):
 *   /api/scan-videos.php?key=admin&limit=100
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/tiles.php';

$isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';

// Build PDO directly (avoid auth.php session logic in CLI)
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

$batchSize = isset($_GET['limit']) ? (int) $_GET['limit'] : ($isCli ? 500 : 100);
$batchSize = max(1, min($batchSize, 2000));

// Count remaining
$countStmt = $pdo->query("SELECT COUNT(*) FROM content_meta WHERE content_type = 'Video' AND duration_seconds IS NULL");
$remaining = (int) $countStmt->fetchColumn();

$totalStmt = $pdo->query("SELECT COUNT(*) FROM content_meta WHERE content_type = 'Video'");
$total = (int) $totalStmt->fetchColumn();

$selectStmt = $pdo->prepare("
    SELECT id, content_id, file_path
    FROM content_meta
    WHERE content_type = 'Video' AND duration_seconds IS NULL
    ORDER BY id
    LIMIT :limit
");
$selectStmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
$selectStmt->execute();

$updateStmt = $pdo->prepare("
    UPDATE content_meta
    SET duration_seconds = :dur, codec = :codec
    WHERE id = :id
");

$broken = [];
$hevc   = [];
$skippedForks = 0;
$processed = 0;
$updated   = 0;

while ($row = $selectStmt->fetch(PDO::FETCH_ASSOC)) {
    $processed++;
    if (empty($row['file_path'])) {
        $broken[] = ['id' => $row['id'], 'content_id' => $row['content_id'], 'reason' => 'empty_file_path'];
        $updateStmt->execute([':dur' => 0, ':codec' => null, ':id' => $row['id']]);
        continue;
    }

    // Skip macOS resource fork files (._filename)
    $baseName = basename($row['file_path']);
    if (strpos($baseName, '._') === 0) {
        $skippedForks++;
        $updateStmt->execute([':dur' => 0, ':codec' => null, ':id' => $row['id']]);
        continue;
    }

    $filePath = contentFilePath($row['file_path']);

    if (!file_exists($filePath) || filesize($filePath) === 0) {
        $broken[] = ['id' => $row['id'], 'content_id' => $row['content_id'], 'reason' => 'missing_or_empty'];
        $updateStmt->execute([':dur' => 0, ':codec' => null, ':id' => $row['id']]);
        continue;
    }

    // ffprobe: duration
    $durCmd = sprintf(
        'ffprobe -v error -show_entries format=duration -of csv=p=0 %s 2>&1',
        escapeshellarg($filePath)
    );
    $durOutput = shell_exec($durCmd);
    if ($durOutput === null) {
        $broken[] = ['id' => $row['id'], 'content_id' => $row['content_id'], 'reason' => 'ffprobe_failed'];
        $updateStmt->execute([':dur' => 0, ':codec' => null, ':id' => $row['id']]);
        continue;
    }
    $durOutput = trim($durOutput);
    $duration = is_numeric($durOutput) ? (float) $durOutput : 0;

    if ($duration <= 0) {
        $broken[] = ['id' => $row['id'], 'content_id' => $row['content_id'], 'reason' => 'no_duration'];
        $updateStmt->execute([':dur' => 0, ':codec' => null, ':id' => $row['id']]);
        continue;
    }

    // ffprobe: video codec
    $codecCmd = sprintf(
        'ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of csv=s=x:p=0 %s 2>&1',
        escapeshellarg($filePath)
    );
    $codecOutput = shell_exec($codecCmd);
    $codecOutput = ($codecOutput !== null) ? trim($codecOutput) : '';

    $codec = ($codecOutput === 'hevc') ? 'hevc' : 'h264';
    if ($codecOutput === 'hevc') {
        $hevc[] = ['id' => $row['id'], 'content_id' => $row['content_id']];
    }

    $updateStmt->execute([
        ':dur' => (int) round($duration),
        ':codec' => $codec,
        ':id'  => $row['id']
    ]);
    $updated++;
}

$result = [
    'total_videos'      => $total,
    'remaining'         => $remaining,
    'batch_processed'   => $processed,
    'batch_updated'     => $updated,
    'skipped_forks'     => $skippedForks,
    'broken_detected'   => count($broken),
    'hevc_detected'     => count($hevc),
    'broken_samples'    => array_slice($broken, 0, 10),
    'hevc_samples'      => array_slice($hevc, 0, 10),
];

if ($isCli) {
    echo "Total videos:      {$result['total_videos']}\n";
    echo "Remaining (null):  {$result['remaining']}\n";
    echo "Batch processed:   {$result['batch_processed']}\n";
    echo "Batch updated:     {$result['batch_updated']}\n";
    echo "Skipped forks:     {$result['skipped_forks']}\n";
    echo "Broken detected:   {$result['broken_detected']}\n";
    echo "HEVC detected:     {$result['hevc_detected']}\n";
    if (!empty($broken)) {
        echo "\nBroken samples:\n";
        foreach ($result['broken_samples'] as $b) {
            echo "  - {$b['content_id']} ({$b['reason']})\n";
        }
    }
    if (!empty($hevc)) {
        echo "\nHEVC samples:\n";
        foreach ($result['hevc_samples'] as $h) {
            echo "  - {$h['content_id']}\n";
        }
    }
} else {
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);
}
