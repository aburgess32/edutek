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

    // FRE-51: Auto-update lesson_progress for any active assignments containing this content
    try {
        $progressPct = $durationSeconds > 0 ? min(100, (int) round(($progressSeconds / $durationSeconds) * 100)) : 0;
        $lpStatus = 'not_started';
        if ($progressPct >= 80) {
            $lpStatus = 'completed';
        } elseif ($progressPct > 0) {
            $lpStatus = 'in_progress';
        }

        // Find all active lesson_progress rows for this user + content_id
        $lpStmt = $pdo->prepare("
            SELECT lp.id, lp.assignment_id, lp.progress_pct
            FROM lesson_progress lp
            JOIN lesson_assignments la ON la.id = lp.assignment_id
            WHERE lp.student_id = :uid
              AND lp.content_id = :cid
              AND la.status = 'active'
        ");
        $lpStmt->execute([':uid' => $userId, ':cid' => $contentId]);
        $lpRows = $lpStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($lpRows)) {
            $lpUpdate = $pdo->prepare("
                UPDATE lesson_progress
                SET progress_pct = GREATEST(progress_pct, :pct),
                    last_position = :pos,
                    status = CASE
                        WHEN :st1 = 'completed' THEN 'completed'
                        WHEN status = 'completed' THEN 'completed'
                        WHEN :st2 = 'in_progress' THEN 'in_progress'
                        ELSE status
                    END,
                    completed_at = CASE
                        WHEN :st3 = 'completed' AND completed_at IS NULL THEN NOW()
                        ELSE completed_at
                    END
                WHERE id = :id
            ");

            foreach ($lpRows as $lpRow) {
                $lpUpdate->execute([
                    ':pct' => $progressPct,
                    ':pos' => $progressSeconds,
                    ':st1' => $lpStatus,
                    ':st2' => $lpStatus,
                    ':st3' => $lpStatus,
                    ':id'  => (int) $lpRow['id'],
                ]);

                // Check if assignment is fully completed
                if ($lpStatus === 'completed') {
                    $checkStmt = $pdo->prepare("
                        SELECT COUNT(*) AS total,
                               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS done
                        FROM lesson_progress
                        WHERE assignment_id = :aid AND student_id = :sid
                    ");
                    $checkStmt->execute([':aid' => (int) $lpRow['assignment_id'], ':sid' => $userId]);
                    $checkRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
                    if ($checkRow && (int) $checkRow['total'] > 0 && (int) $checkRow['done'] >= (int) $checkRow['total']) {
                        $aStmt = $pdo->prepare("SELECT assigned_to FROM lesson_assignments WHERE id = :id");
                        $aStmt->execute([':id' => (int) $lpRow['assignment_id']]);
                        $aRow = $aStmt->fetch(PDO::FETCH_ASSOC);
                        if ($aRow && (int) ($aRow['assigned_to'] ?? 0) === $userId) {
                            $pdo->prepare("UPDATE lesson_assignments SET status = 'completed' WHERE id = :id")
                                ->execute([':id' => (int) $lpRow['assignment_id']]);
                        }
                    }
                }
            }
        }
    } catch (PDOException $e) {
        // Silently ignore lesson_progress errors — table may not exist yet
    }

    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
