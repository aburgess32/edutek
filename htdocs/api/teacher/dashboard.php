<?php
/**
 * Teacher Dashboard API (FRE-13)
 *
 * GET ?action=students       — All non-teacher users with activity totals
 * GET ?action=watched        — Paginated watch list for a student
 * GET ?action=screen_time    — Daily screen time for a student
 * GET ?action=screen_time_all — Aggregate daily screen time across all students
 * GET ?action=sessions       — Session count per day for a student
 */

require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require teacher auth
if (!isTeacher()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    $pdo = getDbConnection();

    switch ($action) {

        case 'students':
            $stmt = $pdo->query("
                SELECT
                    u.id,
                    u.display_name,
                    u.user_type,
                    COUNT(wh.id) AS total_watched,
                    COALESCE(SUM(LEAST(wh.progress_seconds, wh.duration_seconds)), 0) AS total_screen_time_sec,
                    COUNT(DISTINCT DATE(wh.last_watched)) AS total_sessions,
                    MAX(wh.last_watched) AS last_active
                FROM users u
                LEFT JOIN watch_history wh ON wh.user_id = u.id
                WHERE u.user_type != 'teacher'
                GROUP BY u.id
                ORDER BY last_active DESC
            ");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'watched':
            $studentId = filter_input(INPUT_GET, 'student_id', FILTER_VALIDATE_INT);
            if (!$studentId) {
                http_response_code(400);
                echo json_encode(['error' => 'student_id is required']);
                exit;
            }
            $offset = max(0, (int) ($_GET['offset'] ?? 0));

            $stmt = $pdo->prepare("
                SELECT
                    content_id,
                    content_title,
                    progress_seconds,
                    duration_seconds,
                    ROUND((progress_seconds / duration_seconds) * 100) AS progress_pct,
                    last_watched
                FROM watch_history
                WHERE user_id = :student_id
                  AND duration_seconds > 0
                ORDER BY last_watched DESC
                LIMIT 50 OFFSET :offset
            ");
            $stmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'screen_time':
            $studentId = filter_input(INPUT_GET, 'student_id', FILTER_VALIDATE_INT);
            if (!$studentId) {
                http_response_code(400);
                echo json_encode(['error' => 'student_id is required']);
                exit;
            }
            $days = max(1, min(90, (int) ($_GET['days'] ?? 14)));

            $stmt = $pdo->prepare("
                SELECT
                    DATE(last_watched) AS watch_date,
                    SUM(LEAST(progress_seconds, duration_seconds)) AS seconds
                FROM watch_history
                WHERE user_id = :student_id
                  AND last_watched >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                GROUP BY DATE(last_watched)
                ORDER BY watch_date ASC
            ");
            $stmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
            $stmt->bindValue(':days', $days, PDO::PARAM_INT);
            $stmt->execute();
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'screen_time_all':
            $days = max(1, min(90, (int) ($_GET['days'] ?? 14)));

            $stmt = $pdo->prepare("
                SELECT
                    DATE(last_watched) AS watch_date,
                    SUM(LEAST(progress_seconds, duration_seconds)) AS seconds
                FROM watch_history
                WHERE last_watched >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                GROUP BY DATE(last_watched)
                ORDER BY watch_date ASC
            ");
            $stmt->bindValue(':days', $days, PDO::PARAM_INT);
            $stmt->execute();
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'sessions':
            $studentId = filter_input(INPUT_GET, 'student_id', FILTER_VALIDATE_INT);
            if (!$studentId) {
                http_response_code(400);
                echo json_encode(['error' => 'student_id is required']);
                exit;
            }
            $days = max(1, min(90, (int) ($_GET['days'] ?? 14)));

            $stmt = $pdo->prepare("
                SELECT
                    DATE(last_watched) AS watch_date,
                    COUNT(DISTINCT content_id) AS session_count
                FROM watch_history
                WHERE user_id = :student_id
                  AND last_watched >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                GROUP BY DATE(last_watched)
                ORDER BY watch_date ASC
            ");
            $stmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
            $stmt->bindValue(':days', $days, PDO::PARAM_INT);
            $stmt->execute();
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use: students, watched, screen_time, screen_time_all, sessions']);
            break;
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
