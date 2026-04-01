<?php
/**
 * Recent Videos API (FRE-10)
 *
 * GET — returns JSON array of up to 5 most recently watched videos
 * for the current logged-in user (both in-progress and completed).
 * Each item includes a pre-built encrypted watch.php URL.
 *
 * Also returns total screen time (sum of duration_seconds * progress ratio).
 */

include_once __DIR__ . '/../includes/auth.php';
include_once __DIR__ . '/../includes/tiles.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isLoggedIn() || isGuest()) {
    echo json_encode(['videos' => [], 'screen_time_seconds' => 0]);
    exit;
}

$userId = (int) $_SESSION['user_id'];

try {
    $pdo = getDbConnection();

    // Recent videos (all, regardless of progress)
    $stmt = $pdo->prepare("
        SELECT
            content_id,
            content_title,
            progress_seconds,
            duration_seconds,
            last_watched
        FROM watch_history
        WHERE user_id = :uid
          AND duration_seconds > 0
        ORDER BY last_watched DESC
        LIMIT 5
    ");
    $stmt->execute([':uid' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Pre-compute encrypted param keys (same as index.php)
    $encKeyVideolink  = tileEncrypt('videolink');
    $encKeyVideoname  = tileEncrypt('videoname');
    $encKeyVideolink1 = tileEncrypt('videolink1');
    $encKeyVideoname1 = tileEncrypt('videoname1');

    $videos = [];
    foreach ($rows as $row) {
        $contentId  = $row['content_id'];
        $folderPath = dirname($contentId) . '/';
        $folderName = basename(dirname($contentId));
        $fileName   = basename($contentId);

        // Build encrypted watch.php URL
        $url = 'watch.php?&' . $encKeyVideolink . '=' . tileEncrypt($folderPath)
             . '&' . $encKeyVideoname . '=' . tileEncrypt($folderName)
             . '&' . $encKeyVideolink1 . '=' . tileEncrypt($contentId)
             . '&' . $encKeyVideoname1 . '=' . tileEncrypt($fileName);

        $pct = ($row['duration_seconds'] > 0)
            ? (int) round(($row['progress_seconds'] / $row['duration_seconds']) * 100)
            : 0;

        $videos[] = [
            'content_id'   => $contentId,
            'content_title' => $row['content_title'] ?: $fileName,
            'progress_pct' => $pct,
            'last_watched' => $row['last_watched'],
            'url'          => $url,
        ];
    }

    // Screen time: sum of (duration_seconds * progress ratio) across all history
    $stStmt = $pdo->prepare("
        SELECT COALESCE(SUM(
            CASE WHEN duration_seconds > 0
                 THEN LEAST(progress_seconds, duration_seconds)
                 ELSE 0
            END
        ), 0) AS total_seconds
        FROM watch_history
        WHERE user_id = :uid
    ");
    $stStmt->execute([':uid' => $userId]);
    $screenTime = (int) $stStmt->fetchColumn();

    echo json_encode([
        'videos'             => $videos,
        'screen_time_seconds' => $screenTime,
    ]);
} catch (PDOException $e) {
    echo json_encode(['videos' => [], 'screen_time_seconds' => 0]);
}
