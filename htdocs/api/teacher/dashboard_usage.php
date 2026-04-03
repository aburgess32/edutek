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
            try {
                $stmt = $pdo->query(
                    "SELECT
                        content_id,
                        COALESCE(MAX(content_title), content_id) AS title,
                        COALESCE(MAX(content_type), 'file') AS content_type,
                        COUNT(*) AS downloads
                     FROM download_log
                     GROUP BY content_id
                     ORDER BY downloads DESC
                     LIMIT 10"
                );
                echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            } catch (PDOException $e) {
                echo json_encode([]);
            }
            break;

        case 'search_queries':
            try {
                // Ensure search_log table exists (auto-create if migrations haven't run)
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS search_log (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT DEFAULT NULL,
                        user_type VARCHAR(20) DEFAULT NULL,
                        age_range VARCHAR(20) DEFAULT NULL,
                        query VARCHAR(255) NOT NULL,
                        result_count INT DEFAULT 0,
                        searched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_searched_at (searched_at),
                        INDEX idx_query (query(100))
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");

                // Main aggregated query list
                $stmt = $pdo->query("
                    SELECT
                        query,
                        COUNT(*) AS searches,
                        ROUND(AVG(result_count), 1) AS avg_results,
                        MIN(searched_at) AS first_searched,
                        MAX(searched_at) AS last_searched
                    FROM search_log
                    GROUP BY query
                    ORDER BY searches DESC
                    LIMIT 20
                ");
                $queries = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Check if user_type/age_range columns exist before querying them
                $roleMap = [];
                $ageMap = [];
                try {
                    $byRole = $pdo->query("
                        SELECT
                            query,
                            COALESCE(user_type, 'guest') AS user_type,
                            COUNT(*) AS count
                        FROM search_log
                        GROUP BY query, user_type
                        ORDER BY count DESC
                    ")->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($byRole as $r) {
                        $roleMap[$r['query']][$r['user_type']] = (int)$r['count'];
                    }

                    $byAge = $pdo->query("
                        SELECT
                            query,
                            COALESCE(age_range, 'unknown') AS age_range,
                            COUNT(*) AS count
                        FROM search_log
                        GROUP BY query, age_range
                        ORDER BY count DESC
                    ")->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($byAge as $a) {
                        $ageMap[$a['query']][$a['age_range']] = (int)$a['count'];
                    }
                } catch (PDOException $e) {
                    // user_type/age_range columns may not exist yet
                    error_log('search_log role/age query failed (columns may be missing): ' . $e->getMessage());
                }

                // Merge breakdowns into main result
                foreach ($queries as &$q) {
                    $q['by_role'] = $roleMap[$q['query']] ?? [];
                    $q['by_age'] = $ageMap[$q['query']] ?? [];
                }

                // Recent searches (individual entries, most recent first)
                $recent = [];
                try {
                    $recent = $pdo->query("
                        SELECT
                            sl.query,
                            sl.result_count,
                            sl.searched_at,
                            COALESCE(sl.user_type, 'guest') AS user_type,
                            COALESCE(sl.age_range, 'unknown') AS age_range,
                            u.display_name,
                            u.avatar_name
                        FROM search_log sl
                        LEFT JOIN users u ON u.id = sl.user_id
                        ORDER BY sl.searched_at DESC
                        LIMIT 50
                    ")->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    // Fallback without user_type/age_range columns
                    try {
                        $recent = $pdo->query("
                            SELECT
                                sl.query,
                                sl.result_count,
                                sl.searched_at,
                                'guest' AS user_type,
                                'unknown' AS age_range,
                                u.display_name,
                                u.avatar_name
                            FROM search_log sl
                            LEFT JOIN users u ON u.id = sl.user_id
                            ORDER BY sl.searched_at DESC
                            LIMIT 50
                        ")->fetchAll(PDO::FETCH_ASSOC);
                    } catch (PDOException $e2) {
                        error_log('search_log recent query fallback failed: ' . $e2->getMessage());
                    }
                }

                echo json_encode([
                    'aggregated' => $queries,
                    'recent' => $recent
                ]);
            } catch (PDOException $e) {
                error_log('search_queries dashboard error: ' . $e->getMessage());
                echo json_encode(['aggregated' => [], 'recent' => []]);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use: age_groups, returning_users, peak_hours, downloads, search_queries']);
            break;
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'detail' => $e->getMessage()]);
}
