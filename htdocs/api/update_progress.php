<?php
/**
 * Update Watch Progress API (FRE-10)
 *
 * POST — accepts JSON body from sendBeacon:
 *   content_id, content_title, content_type, thumbnail_path,
 *   progress_seconds, duration_seconds
 *
 * Uses INSERT ... ON DUPLICATE KEY UPDATE (requires uq_user_content index).
 */

include_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isLoggedIn() || isGuest()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$contentId       = trim($input['content_id'] ?? '');
$contentTitle    = trim($input['content_title'] ?? '');
$contentType     = trim($input['content_type'] ?? 'video');
$thumbnailPath   = trim($input['thumbnail_path'] ?? '');
$progressSeconds = (int) ($input['progress_seconds'] ?? 0);
$durationSeconds = (int) ($input['duration_seconds'] ?? 0);

if ($contentId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'content_id is required']);
    exit;
}

if ($durationSeconds <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'duration_seconds must be > 0']);
    exit;
}

$userId = (int) $_SESSION['user_id'];

try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        INSERT INTO watch_history
            (user_id, content_id, content_title, content_type, thumbnail_path, progress_seconds, duration_seconds)
        VALUES
            (:uid, :cid, :title, :type, :thumb, :prog, :dur)
        ON DUPLICATE KEY UPDATE
            progress_seconds = VALUES(progress_seconds),
            content_title    = VALUES(content_title),
            thumbnail_path   = VALUES(thumbnail_path),
            last_watched     = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        ':uid'   => $userId,
        ':cid'   => $contentId,
        ':title' => $contentTitle,
        ':type'  => $contentType,
        ':thumb' => $thumbnailPath,
        ':prog'  => $progressSeconds,
        ':dur'   => $durationSeconds,
    ]);

    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
