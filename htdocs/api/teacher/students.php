<?php
/**
 * Teacher Students API (FRE-47)
 *
 * GET  ?action=list                — List teacher's assigned students with stats (paginated)
 * GET  ?action=available           — List unassigned students on device (paginated)
 * GET  ?action=detail&id={id}      — Student detail + watch history
 * POST ?action=assign              — Assign student(s) to teacher  { student_ids: [1,2,3] }
 * POST ?action=remove              — Remove student from teacher   { student_id: 1 }
 * POST ?action=remove_bulk         — Remove multiple students      { student_ids: [1,2,3] }
 * POST ?action=import_csv          — Import students from CSV data { csv_text: "name\n..." }
 * POST ?action=batch_group         — Assign multiple students to group { student_ids: [...], group_id: N }
 */

require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isTeacher()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

/**
 * Build a playable URL from a content_id path.
 * content_id format: "videos/Category/Subcategory/filename.mp4"
 * Returns a direct link to the file, since tutorials/watch use encrypted params
 * and we just need a simple clickable path for the teacher's reference.
 */
function buildContentUrl($contentId) {
    if (!$contentId) return '';
    // content_id is already a relative path like "videos/Primary Multiplication/Five Times Table.mp4"
    return '/' . ltrim($contentId, '/');
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$teacherId = (int) ($_SESSION['user_id'] ?? 0);

if (!$teacherId) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

try {
    $pdo = getDbConnection();

    // ──────────────────────────────────────────────────────────
    // GET actions
    // ──────────────────────────────────────────────────────────
    if ($method === 'GET') {
        switch ($action) {

            case 'list':
                $groupFilter = isset($_GET['group_id']) ? (int) $_GET['group_id'] : null;
                $sort = in_array($_GET['sort'] ?? '', ['name', 'last_active', 'screen_time']) ? $_GET['sort'] : 'name';
                $search = trim($_GET['search'] ?? '');
                $page = max(1, (int) ($_GET['page'] ?? 1));
                $perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 50)));
                $offset = ($page - 1) * $perPage;

                $sql = "
                    SELECT
                        u.id,
                        u.display_name,
                        u.avatar_name,
                        u.avatar_color,
                        u.user_type,
                        u.last_active,
                        DATEDIFF(CURDATE(), COALESCE(u.last_active, u.created_at)) AS days_inactive,
                        COALESCE(stats.watch_count, 0) AS watch_count,
                        COALESCE(stats.screen_time_sec, 0) AS screen_time_sec,
                        sg.id AS group_id,
                        sg.name AS group_name,
                        sg.color AS group_color
                    FROM teacher_students ts
                    JOIN users u ON u.id = ts.student_id
                    LEFT JOIN (
                        SELECT user_id,
                               COUNT(*) AS watch_count,
                               SUM(LEAST(progress_seconds, duration_seconds)) AS screen_time_sec
                        FROM watch_history
                        GROUP BY user_id
                    ) stats ON stats.user_id = u.id
                    LEFT JOIN student_group_members sgm ON sgm.student_id = u.id
                    LEFT JOIN student_groups sg ON sg.id = sgm.group_id AND sg.teacher_id = ?
                    WHERE ts.teacher_id = ?
                ";
                $params = [$teacherId, $teacherId];

                if ($groupFilter !== null) {
                    if ($groupFilter === 0) {
                        // Ungrouped
                        $sql .= " AND sg.id IS NULL";
                    } else {
                        $sql .= " AND sg.id = ?";
                        $params[] = $groupFilter;
                    }
                }

                if ($search !== '') {
                    $sql .= " AND u.display_name LIKE ?";
                    $params[] = '%' . $search . '%';
                }

                switch ($sort) {
                    case 'last_active':
                        $sql .= " ORDER BY u.last_active DESC";
                        break;
                    case 'screen_time':
                        $sql .= " ORDER BY screen_time_sec DESC";
                        break;
                    default:
                        $sql .= " ORDER BY u.display_name ASC";
                }

                // Count total before pagination
                $countSql = preg_replace('/SELECT.*?FROM/s', 'SELECT COUNT(*) FROM', $sql, 1);
                $countSql = preg_replace('/ORDER BY.*$/s', '', $countSql);
                $countStmt = $pdo->prepare($countSql);
                $countStmt->execute($params);
                $totalCount = (int) $countStmt->fetchColumn();

                $sql .= " LIMIT " . (int) $perPage . " OFFSET " . (int) $offset;

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Cast numeric fields
                foreach ($students as &$s) {
                    $s['id'] = (int) $s['id'];
                    $s['watch_count'] = (int) $s['watch_count'];
                    $s['screen_time_sec'] = (int) $s['screen_time_sec'];
                    $s['days_inactive'] = (int) $s['days_inactive'];
                    $s['group_id'] = $s['group_id'] ? (int) $s['group_id'] : null;
                }

                $totalPages = max(1, (int) ceil($totalCount / $perPage));
                echo json_encode([
                    'students' => $students,
                    'count' => count($students),
                    'total' => $totalCount,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_pages' => $totalPages,
                ]);
                break;

            case 'available':
                // Students on this device NOT assigned to this teacher
                $stmt = $pdo->prepare("
                    SELECT
                        u.id,
                        u.display_name,
                        u.avatar_name,
                        u.avatar_color,
                        u.user_type,
                        u.last_active,
                        (SELECT GROUP_CONCAT(t.display_name SEPARATOR ', ')
                         FROM teacher_students ts2
                         JOIN users t ON t.id = ts2.teacher_id
                         WHERE ts2.student_id = u.id
                        ) AS other_teachers
                    FROM users u
                    WHERE u.user_type != 'teacher'
                      AND u.id NOT IN (
                          SELECT student_id FROM teacher_students WHERE teacher_id = ?
                      )
                    ORDER BY u.display_name ASC
                ");
                $stmt->execute([$teacherId]);
                $available = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($available as &$a) {
                    $a['id'] = (int) $a['id'];
                }

                echo json_encode(['students' => $available, 'count' => count($available)]);
                break;

            case 'detail':
                $studentId = (int) ($_GET['id'] ?? 0);
                if (!$studentId) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing student id']);
                    break;
                }

                // Verify student is assigned to this teacher
                $check = $pdo->prepare("SELECT 1 FROM teacher_students WHERE teacher_id = ? AND student_id = ?");
                $check->execute([$teacherId, $studentId]);
                if (!$check->fetchColumn()) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Student not found']);
                    break;
                }

                // Student info
                $stmt = $pdo->prepare("
                    SELECT id, display_name, avatar_name, avatar_color, user_type,
                           age_range, last_active, created_at
                    FROM users WHERE id = ?
                ");
                $stmt->execute([$studentId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);

                // Group membership
                $grpStmt = $pdo->prepare("
                    SELECT sg.id, sg.name, sg.color
                    FROM student_group_members sgm
                    JOIN student_groups sg ON sg.id = sgm.group_id AND sg.teacher_id = ?
                    WHERE sgm.student_id = ?
                ");
                $grpStmt->execute([$teacherId, $studentId]);
                $student['group'] = $grpStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                // Watch history (last 15)
                $whStmt = $pdo->prepare("
                    SELECT wh.content_id, wh.content_title, wh.content_type,
                           wh.progress_seconds, wh.duration_seconds, wh.last_watched,
                           CASE WHEN wh.duration_seconds > 0
                                THEN LEAST(ROUND(wh.progress_seconds * 100.0 / wh.duration_seconds), 100)
                                ELSE 0 END AS completion_pct
                    FROM watch_history wh
                    WHERE wh.user_id = ?
                    ORDER BY wh.last_watched DESC
                    LIMIT 15
                ");
                $whStmt->execute([$studentId]);
                $watchHistory = $whStmt->fetchAll(PDO::FETCH_ASSOC);

                // Build playable URL from content_id (e.g. "videos/Primary Multiplication/Sub/file.mp4")
                foreach ($watchHistory as &$wh) {
                    $wh['url'] = buildContentUrl($wh['content_id']);
                }
                $student['watch_history'] = $watchHistory;

                // Aggregate stats
                $statsStmt = $pdo->prepare("
                    SELECT
                        COUNT(*) AS total_watched,
                        COALESCE(SUM(LEAST(progress_seconds, duration_seconds)), 0) AS total_screen_time,
                        COALESCE(AVG(
                            CASE WHEN duration_seconds > 0
                                 THEN LEAST(progress_seconds * 100.0 / duration_seconds, 100)
                                 ELSE 0 END
                        ), 0) AS avg_completion
                    FROM watch_history WHERE user_id = ?
                ");
                $statsStmt->execute([$studentId]);
                $student['stats'] = $statsStmt->fetch(PDO::FETCH_ASSOC);

                // Top subjects
                $subjStmt = $pdo->prepare("
                    SELECT cm.category AS subject, COUNT(*) AS cnt
                    FROM watch_history wh
                    JOIN content_meta cm ON cm.content_id COLLATE utf8mb4_general_ci = wh.content_id COLLATE utf8mb4_general_ci
                    WHERE wh.user_id = ? AND cm.category IS NOT NULL AND cm.category != ''
                    GROUP BY cm.category
                    ORDER BY cnt DESC
                    LIMIT 5
                ");
                $subjStmt->execute([$studentId]);
                $student['top_subjects'] = $subjStmt->fetchAll(PDO::FETCH_ASSOC);

                $student['id'] = (int) $student['id'];
                echo json_encode($student);
                break;

            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action. Use: list, available, detail']);
                break;
        }
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // POST actions
    // ──────────────────────────────────────────────────────────
    if ($method === 'POST') {
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!csrf_verify($csrfToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF validation failed']);
            exit;
        }
        $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $action = $body['action'] ?? ($action ?: '');

        switch ($action) {

            case 'assign':
                $studentIds = $body['student_ids'] ?? [];
                if (!is_array($studentIds) || empty($studentIds)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'student_ids array required']);
                    break;
                }

                $stmt = $pdo->prepare(
                    "INSERT IGNORE INTO teacher_students (teacher_id, student_id) VALUES (?, ?)"
                );
                $assigned = 0;
                foreach ($studentIds as $sid) {
                    $sid = (int) $sid;
                    if ($sid > 0) {
                        $stmt->execute([$teacherId, $sid]);
                        $assigned += $stmt->rowCount();
                    }
                }

                echo json_encode(['success' => true, 'assigned' => $assigned]);
                break;

            case 'remove':
                $studentId = (int) ($body['student_id'] ?? 0);
                if (!$studentId) {
                    http_response_code(400);
                    echo json_encode(['error' => 'student_id required']);
                    break;
                }

                // Remove from teacher_students
                $stmt = $pdo->prepare("DELETE FROM teacher_students WHERE teacher_id = ? AND student_id = ?");
                $stmt->execute([$teacherId, $studentId]);

                // Also remove from any of this teacher's groups
                $pdo->prepare("
                    DELETE sgm FROM student_group_members sgm
                    JOIN student_groups sg ON sg.id = sgm.group_id
                    WHERE sg.teacher_id = ? AND sgm.student_id = ?
                ")->execute([$teacherId, $studentId]);

                echo json_encode(['success' => true]);
                break;

            case 'remove_bulk':
                $studentIds = $body['student_ids'] ?? [];
                if (!is_array($studentIds) || empty($studentIds)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'student_ids array required']);
                    break;
                }

                $removed = 0;
                $stmtDel = $pdo->prepare("DELETE FROM teacher_students WHERE teacher_id = ? AND student_id = ?");
                $stmtGrp = $pdo->prepare("
                    DELETE sgm FROM student_group_members sgm
                    JOIN student_groups sg ON sg.id = sgm.group_id
                    WHERE sg.teacher_id = ? AND sgm.student_id = ?
                ");
                foreach ($studentIds as $sid) {
                    $sid = (int) $sid;
                    if ($sid > 0) {
                        $stmtDel->execute([$teacherId, $sid]);
                        $removed += $stmtDel->rowCount();
                        $stmtGrp->execute([$teacherId, $sid]);
                    }
                }
                echo json_encode(['success' => true, 'removed' => $removed]);
                break;

            case 'batch_group':
                $studentIds = $body['student_ids'] ?? [];
                $groupId = isset($body['group_id']) ? (int) $body['group_id'] : null;
                if (!is_array($studentIds) || empty($studentIds)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'student_ids array required']);
                    break;
                }

                // Verify group belongs to this teacher (if not ungrouping)
                if ($groupId) {
                    $gCheck = $pdo->prepare("SELECT 1 FROM student_groups WHERE id = ? AND teacher_id = ?");
                    $gCheck->execute([$groupId, $teacherId]);
                    if (!$gCheck->fetchColumn()) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Group not found']);
                        break;
                    }
                }

                $moved = 0;
                foreach ($studentIds as $sid) {
                    $sid = (int) $sid;
                    if ($sid <= 0) continue;

                    // Remove from all of this teacher's groups first
                    $pdo->prepare("
                        DELETE sgm FROM student_group_members sgm
                        JOIN student_groups sg ON sg.id = sgm.group_id
                        WHERE sg.teacher_id = ? AND sgm.student_id = ?
                    ")->execute([$teacherId, $sid]);

                    // Add to new group if specified
                    if ($groupId) {
                        $pdo->prepare(
                            "INSERT IGNORE INTO student_group_members (group_id, student_id) VALUES (?, ?)"
                        )->execute([$groupId, $sid]);
                    }
                    $moved++;
                }
                echo json_encode(['success' => true, 'moved' => $moved]);
                break;

            case 'import_csv':
                $csvText = trim($body['csv_text'] ?? '');
                if ($csvText === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'csv_text required']);
                    break;
                }

                $lines = preg_split('/\r?\n/', $csvText);
                $created = 0;
                $assigned = 0;
                $skipped = 0;
                $errors = [];

                // Parse header row — look for 'name' column
                $header = str_getcsv(array_shift($lines));
                $header = array_map(function($h) { return strtolower(trim($h)); }, $header);
                $nameIdx = array_search('name', $header);
                $typeIdx = array_search('type', $header);
                if ($nameIdx === false) {
                    // No header — treat entire lines as names
                    array_unshift($lines, implode(',', $header));
                    $nameIdx = 0;
                    $typeIdx = false;
                }

                $stmtFind = $pdo->prepare("SELECT id FROM users WHERE display_name = ? AND user_type != 'teacher' LIMIT 1");
                $stmtCreate = $pdo->prepare(
                    "INSERT INTO users (display_name, user_type, created_at) VALUES (?, ?, NOW())"
                );
                $stmtAssign = $pdo->prepare(
                    "INSERT IGNORE INTO teacher_students (teacher_id, student_id) VALUES (?, ?)"
                );

                foreach ($lines as $lineNum => $line) {
                    $line = trim($line);
                    if ($line === '') continue;

                    $cols = str_getcsv($line);
                    $name = trim($cols[$nameIdx] ?? '');
                    $type = ($typeIdx !== false && isset($cols[$typeIdx]))
                        ? trim($cols[$typeIdx])
                        : 'student';

                    if ($name === '') { $skipped++; continue; }
                    if (mb_strlen($name) > 100) {
                        $errors[] = 'Line ' . ($lineNum + 2) . ': name too long';
                        $skipped++;
                        continue;
                    }

                    // Sanitize type
                    if (!in_array($type, ['kid', 'teen', 'adult', 'student'])) {
                        $type = 'student';
                    }

                    // Try to find existing student by name
                    $stmtFind->execute([$name]);
                    $existingId = $stmtFind->fetchColumn();

                    if ($existingId) {
                        $studentId = (int) $existingId;
                    } else {
                        $stmtCreate->execute([$name, $type]);
                        $studentId = (int) $pdo->lastInsertId();
                        $created++;
                    }

                    $stmtAssign->execute([$teacherId, $studentId]);
                    $assigned += $stmtAssign->rowCount();
                }

                echo json_encode([
                    'success' => true,
                    'created' => $created,
                    'assigned' => $assigned,
                    'skipped' => $skipped,
                    'errors' => $errors,
                ]);
                break;

            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action. Use: assign, remove, remove_bulk, import_csv, batch_group']);
                break;
        }
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'detail' => $e->getMessage()]);
}
