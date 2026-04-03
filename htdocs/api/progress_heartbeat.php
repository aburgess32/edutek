<?php
/**
 * Progress Heartbeat API (FRE-51)
 *
 * Lightweight POST endpoint called every 30 seconds during video playback.
 * Updates time_spent and last_position in lesson_progress.
 *
 * JSON body: { assignment_id, content_id, last_position, duration_seconds }
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

$userId = (int) $_SESSION['user_id'];
$assignmentId = (int) ($input['assignment_id'] ?? 0);
$contentId = trim($input['content_id'] ?? '');
$lastPosition = (int) ($input['last_position'] ?? 0);
$durationSeconds = (int) ($input['duration_seconds'] ?? 0);

if ($assignmentId <= 0 || $contentId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'assignment_id and content_id are required']);
    exit;
}

try {
    $pdo = getDbConnection();

    // Calculate progress percentage
    $progressPct = 0;
    if ($durationSeconds > 0) {
        $progressPct = min(100, (int) round(($lastPosition / $durationSeconds) * 100));
    }

    // Determine status based on 80% threshold
    $status = 'not_started';
    if ($progressPct >= 80) {
        $status = 'completed';
    } elseif ($progressPct > 0) {
        $status = 'in_progress';
    }

    // Update progress — increment time_spent by 30s (heartbeat interval)
    $stmt = $pdo->prepare("
        UPDATE lesson_progress
        SET time_spent = time_spent + 30,
            last_position = :pos,
            progress_pct = GREATEST(progress_pct, :pct),
            status = CASE
                WHEN :status1 = 'completed' THEN 'completed'
                WHEN status = 'completed' THEN 'completed'
                WHEN :status2 = 'in_progress' THEN 'in_progress'
                ELSE status
            END,
            completed_at = CASE
                WHEN :status3 = 'completed' AND completed_at IS NULL THEN NOW()
                ELSE completed_at
            END
        WHERE assignment_id = :aid AND student_id = :sid AND content_id = :cid
    ");
    $stmt->execute([
        ':pos'     => $lastPosition,
        ':pct'     => $progressPct,
        ':status1' => $status,
        ':status2' => $status,
        ':status3' => $status,
        ':aid'     => $assignmentId,
        ':sid'     => $userId,
        ':cid'     => $contentId,
    ]);

    // If completed, check whole assignment completion
    if ($status === 'completed') {
        $checkStmt = $pdo->prepare("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed
            FROM lesson_progress
            WHERE assignment_id = :aid AND student_id = :sid
        ");
        $checkStmt->execute([':aid' => $assignmentId, ':sid' => $userId]);
        $row = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($row && (int) $row['total'] > 0 && (int) $row['completed'] >= (int) $row['total']) {
            $aStmt = $pdo->prepare("SELECT assigned_to FROM lesson_assignments WHERE id = :id");
            $aStmt->execute([':id' => $assignmentId]);
            $aRow = $aStmt->fetch(PDO::FETCH_ASSOC);
            if ($aRow && (int) ($aRow['assigned_to'] ?? 0) === $userId) {
                $pdo->prepare("UPDATE lesson_assignments SET status = 'completed' WHERE id = :id")
                    ->execute([':id' => $assignmentId]);
            }
        }
    }

    echo json_encode(['ok' => true, 'progress_pct' => $progressPct, 'status' => $status]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
