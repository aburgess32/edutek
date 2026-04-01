<?php
/**
 * Continue Watching API (FRE-10)
 *
 * GET — returns JSON array of up to 5 in-progress videos
 * for the current logged-in user. Returns [] for guests/unauthenticated.
 */

include_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isLoggedIn() || isGuest()) {
    echo json_encode([]);
    exit;
}

$userId = (int) $_SESSION['user_id'];

try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT
            content_id,
            content_title,
            thumbnail_path,
            progress_seconds,
            duration_seconds,
            last_watched
        FROM watch_history
        WHERE user_id = :uid
          AND duration_seconds > 0
        ORDER BY last_watched DESC
        LIMIT 10
    ");
    $stmt->execute([':uid' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($rows as $row) {
        $pct = round(($row['progress_seconds'] / $row['duration_seconds']) * 100);
        if ($pct >= 95) {
            continue;
        }

        $thumbPath = __DIR__ . '/../' . ltrim($row['thumbnail_path'], '/');
        $row['thumb_ok'] = file_exists($thumbPath);
        $row['progress_pct'] = (int) $pct;
        $results[] = $row;

        if (count($results) >= 5) {
            break;
        }
    }

    echo json_encode($results);
} catch (PDOException $e) {
    echo json_encode([]);
}
