<?php
/**
 * Teacher Dashboard Usage Analytics API (FRE-39)
 *
 * GET ?action=age_groups       — User count per age range
 * GET ?action=returning_users  — Returning vs one-time user counts
 * GET ?action=peak_hours       — Watch activity by hour of day
 * GET ?action=downloads        — Top downloaded content
 * GET ?action=search_queries   — Top search queries
 */

require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isTeacher()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    $pdo = getDbConnection();

    switch ($action) {

        case 'age_groups':
            $stmt = $pdo->query(
                "SELECT
                    COALESCE(age_range, 'unknown') AS age_range,
                    COUNT(*) AS count
                 FROM users
                 WHERE user_type != 'teacher'
                 GROUP BY age_range
                 ORDER BY FIELD(age_range, 'under_10', '10_14', '15_19', '20_plus', NULL)"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Map to friendly labels
            $labels = [
                'under_10' => 'Under 10',
                '10_14'    => '10 - 14',
                '15_19'    => '15 - 19',
                '20_plus'  => '20+',
                'unknown'  => 'Not Set',
            ];
            $result = [];
            foreach ($rows as $r) {
                $result[] = [
                    'age_range' => $r['age_range'],
                    'label'     => $labels[$r['age_range']] ?? $r['age_range'],
                    'count'     => (int) $r['count'],
                ];
            }
            echo json_encode($result);
            break;

        case 'returning_users':
            // Returning = users with 2+ distinct watch dates
            // One-time = users with 0-1 distinct watch dates
            $stmt = $pdo->query(
                "SELECT
                    SUM(CASE WHEN watch_days >= 2 THEN 1 ELSE 0 END) AS returning_users,
                    SUM(CASE WHEN watch_days < 2 THEN 1 ELSE 0 END) AS onetime_users
                 FROM (
                    SELECT u.id,
                           COUNT(DISTINCT DATE(wh.last_watched)) AS watch_days
                    FROM users u
                    LEFT JOIN watch_history wh ON wh.user_id = u.id
                    WHERE u.user_type != 'teacher'
                    GROUP BY u.id
                 ) sub"
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode([
                'returning' => (int) ($row['returning_users'] ?? 0),
                'onetime'   => (int) ($row['onetime_users'] ?? 0),
            ]);
            break;

        case 'peak_hours':
            $stmt = $pdo->query(
                "SELECT
                    HOUR(last_watched) AS hour,
                    COUNT(*) AS count
                 FROM watch_history
                 WHERE last_watched >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                 GROUP BY HOUR(last_watched)
                 ORDER BY hour ASC"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fill all 24 hours
            $hourMap = [];
            foreach ($rows as $r) { $hourMap[(int) $r['hour']] = (int) $r['count']; }
            $result = [];
            for ($h = 0; $h < 24; $h++) {
                $result[] = [
                    'hour'  => $h,
                    'label' => sprintf('%d%s', $h === 0 ? 12 : ($h > 12 ? $h - 12 : $h), $h < 12 ? 'am' : 'pm'),
                    'count' => $hourMap[$h] ?? 0,
                ];
            }
            echo json_encode($result);
            break;

        case 'downloads':
            // Check if download_log table exists
            $tableCheck = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'download_log'"
            )->fetchColumn();

            if (!$tableCheck) {
                echo json_encode([]);
                break;
            }

            $stmt = $pdo->query(
                "SELECT
                    content_id,
                    COALESCE(content_title, content_id) AS title,
                    COALESCE(content_type, 'file') AS content_type,
                    COUNT(*) AS downloads
                 FROM download_log
                 GROUP BY content_id
                 ORDER BY downloads DESC
                 LIMIT 10"
            );
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'search_queries':
            // Check if search_log table exists
            $tableCheck = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'search_log'"
            )->fetchColumn();

            if (!$tableCheck) {
                echo json_encode([]);
                break;
            }

            $stmt = $pdo->query(
                "SELECT
                    query,
                    COUNT(*) AS searches,
                    ROUND(AVG(result_count), 1) AS avg_results
                 FROM search_log
                 GROUP BY query
                 ORDER BY searches DESC
                 LIMIT 10"
            );
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use: age_groups, returning_users, peak_hours, downloads, search_queries']);
            break;
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
