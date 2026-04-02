<?php
/**
 * Teacher Dashboard Overview API (FRE-39)
 *
 * GET ?action=kpis            — KPI cards data with sparkline
 * GET ?action=top_content     — Top 5 most-watched content
 * GET ?action=needs_attention — Students inactive 7+ days
 * GET ?action=engagement      — Weekly engagement per day
 * GET ?action=insights        — Auto-generated teacher insights
 * GET ?action=device_health   — System health metrics
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

        case 'kpis':
            // Total students (non-teacher users)
            $totalStudents = (int) $pdo->query(
                "SELECT COUNT(*) FROM users WHERE user_type != 'teacher'"
            )->fetchColumn();

            // Active this week
            $activeWeek = (int) $pdo->query(
                "SELECT COUNT(DISTINCT user_id) FROM watch_history
                 WHERE last_watched >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
            )->fetchColumn();

            // Total watch time (seconds) this week
            $watchTimeSec = (int) $pdo->query(
                "SELECT COALESCE(SUM(LEAST(progress_seconds, duration_seconds)), 0)
                 FROM watch_history
                 WHERE last_watched >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
            )->fetchColumn();

            // Average completion percentage
            $avgCompletion = (float) $pdo->query(
                "SELECT COALESCE(AVG(
                    CASE WHEN duration_seconds > 0
                         THEN LEAST(progress_seconds * 100.0 / duration_seconds, 100)
                         ELSE 0 END
                ), 0) FROM watch_history"
            )->fetchColumn();

            // Content library size
            $contentCount = (int) $pdo->query(
                "SELECT COUNT(*) FROM content_meta"
            )->fetchColumn();

            // Weekly sparkline — last 7 days of watch time
            $sparkStmt = $pdo->query(
                "SELECT DATE(last_watched) AS d,
                        SUM(LEAST(progress_seconds, duration_seconds)) AS s
                 FROM watch_history
                 WHERE last_watched >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                 GROUP BY DATE(last_watched)
                 ORDER BY d ASC"
            );
            $sparkRaw = $sparkStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fill in missing days
            $sparkMap = [];
            foreach ($sparkRaw as $r) { $sparkMap[$r['d']] = (int) $r['s']; }
            $sparkline = [];
            for ($i = 6; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-{$i} days"));
                $sparkline[] = $sparkMap[$d] ?? 0;
            }

            // Previous week for delta comparison
            $prevActiveWeek = (int) $pdo->query(
                "SELECT COUNT(DISTINCT user_id) FROM watch_history
                 WHERE last_watched >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                   AND last_watched < DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
            )->fetchColumn();

            echo json_encode([
                'total_students'   => $totalStudents,
                'active_this_week' => $activeWeek,
                'prev_active_week' => $prevActiveWeek,
                'watch_time_sec'   => $watchTimeSec,
                'avg_completion'   => round($avgCompletion, 1),
                'content_count'    => $contentCount,
                'sparkline'        => $sparkline,
            ]);
            break;

        case 'top_content':
            $stmt = $pdo->query(
                "SELECT
                    wh.content_id,
                    COALESCE(wh.content_title, cm.title, wh.content_id) AS title,
                    COALESCE(cm.content_type, wh.content_type, 'video') AS content_type,
                    COUNT(*) AS views,
                    COALESCE(AVG(
                        CASE WHEN wh.duration_seconds > 0
                             THEN LEAST(wh.progress_seconds * 100.0 / wh.duration_seconds, 100)
                             ELSE 0 END
                    ), 0) AS avg_completion
                 FROM watch_history wh
                 LEFT JOIN content_meta cm ON cm.content_id COLLATE utf8mb4_0900_ai_ci = wh.content_id COLLATE utf8mb4_0900_ai_ci
                 GROUP BY wh.content_id
                 ORDER BY views DESC
                 LIMIT 5"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['views'] = (int) $r['views'];
                $r['avg_completion'] = round((float) $r['avg_completion'], 1);
            }
            echo json_encode($rows);
            break;

        case 'needs_attention':
            $stmt = $pdo->query(
                "SELECT
                    u.id,
                    u.display_name,
                    u.avatar_name,
                    u.user_type,
                    u.last_active,
                    DATEDIFF(CURDATE(), COALESCE(u.last_active, u.created_at)) AS days_inactive
                 FROM users u
                 WHERE u.user_type != 'teacher'
                   AND (u.last_active IS NULL
                        OR u.last_active < DATE_SUB(CURDATE(), INTERVAL 7 DAY))
                 ORDER BY days_inactive DESC
                 LIMIT 5"
            );
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'engagement':
            $stmt = $pdo->query(
                "SELECT
                    DATE(last_watched) AS watch_date,
                    DAYNAME(DATE(last_watched)) AS day_name,
                    SUM(LEAST(progress_seconds, duration_seconds)) AS total_seconds,
                    COUNT(DISTINCT user_id) AS unique_users
                 FROM watch_history
                 WHERE last_watched >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                 GROUP BY DATE(last_watched)
                 ORDER BY watch_date ASC"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fill missing days and find peak/low
            $dayMap = [];
            foreach ($rows as $r) {
                $dayMap[$r['watch_date']] = $r;
            }

            $result = [];
            $maxSec = 0;
            $minSec = PHP_INT_MAX;
            $peakDate = '';
            $lowDate = '';

            for ($i = 6; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-{$i} days"));
                $dayName = date('D', strtotime($d));
                if (isset($dayMap[$d])) {
                    $sec = (int) $dayMap[$d]['total_seconds'];
                    $users = (int) $dayMap[$d]['unique_users'];
                } else {
                    $sec = 0;
                    $users = 0;
                }
                $result[] = [
                    'date'      => $d,
                    'day_name'  => $dayName,
                    'seconds'   => $sec,
                    'users'     => $users,
                    'is_peak'   => false,
                    'is_low'    => false,
                ];
                if ($sec > $maxSec) { $maxSec = $sec; $peakDate = $d; }
                if ($sec < $minSec) { $minSec = $sec; $lowDate = $d; }
            }

            // Mark peak and low
            foreach ($result as &$entry) {
                if ($entry['date'] === $peakDate && $maxSec > 0) $entry['is_peak'] = true;
                if ($entry['date'] === $lowDate) $entry['is_low'] = true;
            }

            echo json_encode($result);
            break;

        case 'insights':
            $insights = [];

            // Students inactive 14+ days
            $longInactive = (int) $pdo->query(
                "SELECT COUNT(*) FROM users
                 WHERE user_type != 'teacher'
                   AND (last_active IS NULL OR last_active < DATE_SUB(CURDATE(), INTERVAL 14 DAY))"
            )->fetchColumn();
            if ($longInactive > 0) {
                $insights[] = [
                    'priority' => 'high',
                    'text'     => "{$longInactive} student" . ($longInactive > 1 ? 's have' : ' has') .
                                  " not been active in 14+ days. Consider reaching out.",
                ];
            }

            // Most popular category
            $topCategory = $pdo->query(
                "SELECT cm.category, COUNT(*) AS cnt
                 FROM watch_history wh
                 JOIN content_meta cm ON cm.content_id COLLATE utf8mb4_0900_ai_ci = wh.content_id COLLATE utf8mb4_0900_ai_ci
                 WHERE cm.category IS NOT NULL AND cm.category != ''
                 GROUP BY cm.category
                 ORDER BY cnt DESC
                 LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            if ($topCategory) {
                $insights[] = [
                    'priority' => 'low',
                    'text'     => "\"" . $topCategory['category'] . "\" is the most-watched category with " .
                                  $topCategory['cnt'] . " views.",
                ];
            }

            // Low completion content
            $lowCompletion = $pdo->query(
                "SELECT
                    COALESCE(wh.content_title, wh.content_id) AS title,
                    AVG(CASE WHEN wh.duration_seconds > 0
                         THEN LEAST(wh.progress_seconds * 100.0 / wh.duration_seconds, 100)
                         ELSE 0 END) AS avg_pct
                 FROM watch_history wh
                 GROUP BY wh.content_id
                 HAVING avg_pct < 30 AND COUNT(*) >= 2
                 ORDER BY avg_pct ASC
                 LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            if ($lowCompletion) {
                $insights[] = [
                    'priority' => 'medium',
                    'text'     => "\"" . $lowCompletion['title'] . "\" has only " .
                                  round($lowCompletion['avg_pct']) . "% avg completion. Students may be struggling with this content.",
                ];
            }

            // Active learners trend
            $prevWeekActive = (int) $pdo->query(
                "SELECT COUNT(DISTINCT user_id) FROM watch_history
                 WHERE last_watched >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                   AND last_watched < DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
            )->fetchColumn();
            $thisWeekActive = (int) $pdo->query(
                "SELECT COUNT(DISTINCT user_id) FROM watch_history
                 WHERE last_watched >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
            )->fetchColumn();
            if ($prevWeekActive > 0 && $thisWeekActive > $prevWeekActive) {
                $pctUp = round((($thisWeekActive - $prevWeekActive) / $prevWeekActive) * 100);
                $insights[] = [
                    'priority' => 'low',
                    'text'     => "Active learners are up {$pctUp}% compared to last week. Great momentum.",
                ];
            }

            if (empty($insights)) {
                $insights[] = [
                    'priority' => 'low',
                    'text'     => 'Not enough data yet to generate insights. Keep adding content and students.',
                ];
            }

            echo json_encode($insights);
            break;

        case 'device_health':
            $contentItems = (int) $pdo->query("SELECT COUNT(*) FROM content_meta")->fetchColumn();
            $totalUsers = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $watchRecords = (int) $pdo->query("SELECT COUNT(*) FROM watch_history")->fetchColumn();

            // Estimate DB size
            $dbSize = $pdo->query(
                "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
                 FROM information_schema.TABLES
                 WHERE table_schema = '" . DB_NAME . "'"
            )->fetchColumn();

            echo json_encode([
                'content_items'  => $contentItems,
                'total_users'    => $totalUsers,
                'watch_records'  => $watchRecords,
                'db_size_mb'     => $dbSize ?: '0.00',
                'status'         => 'healthy',
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use: kpis, top_content, needs_attention, engagement, insights, device_health']);
            break;
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'detail' => $e->getMessage()]);
}
