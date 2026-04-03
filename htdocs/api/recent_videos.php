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

// Optional content type filter: videos (default), audiobooks, media (catch-all)
$type = isset($_GET['type']) ? $_GET['type'] : 'videos';
$allowedTypes = ['videos', 'audiobooks', 'media'];
if (!in_array($type, $allowedTypes, true)) {
    $type = 'videos';
}

try {
    $pdo = getDbConnection();

    // Build type filter clause
    if ($type === 'media') {
        // Media = anything NOT starting with videos/ or audiobooks/
        $typeClause = "AND wh.content_id NOT LIKE 'videos/%' AND wh.content_id NOT LIKE 'audiobooks/%'";
        $innerTypeClause = "AND content_id NOT LIKE 'videos/%' AND content_id NOT LIKE 'audiobooks/%'";
        $params = [':uid1' => $userId, ':uid2' => $userId];
    } else {
        $typeClause = "AND wh.content_id LIKE :prefix1";
        $innerTypeClause = "AND content_id LIKE :prefix2";
        $params = [':uid1' => $userId, ':uid2' => $userId, ':prefix1' => $type . '/%', ':prefix2' => $type . '/%'];
    }

    // Recent content (all, regardless of progress) — deduplicated by content_id
    $stmt = $pdo->prepare("
        SELECT
            wh.content_id,
            wh.content_title,
            wh.progress_seconds,
            wh.duration_seconds,
            wh.last_watched
        FROM watch_history wh
        INNER JOIN (
            SELECT content_id, MAX(last_watched) AS max_lw
            FROM watch_history
            WHERE user_id = :uid1 AND duration_seconds > 0
              $innerTypeClause
            GROUP BY content_id
        ) latest ON wh.content_id = latest.content_id
                   AND wh.last_watched = latest.max_lw
        WHERE wh.user_id = :uid2
          AND wh.duration_seconds > 0
          $typeClause
        ORDER BY wh.last_watched DESC
        LIMIT 5
    ");
    $stmt->execute($params);
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

    // Screen time: sum across all history (not filtered by type)
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
