<?php
/**
 * Teacher Student Groups API (FRE-47)
 *
 * GET  ?action=list                       — List teacher's groups with member counts
 * POST ?action=create  { name, color? }   — Create group
 * POST ?action=update  { id, name, color, sort_order } — Update group
 * POST ?action=delete  { id }             — Delete group (members become ungrouped)
 * POST ?action=assign  { group_id, student_ids: [] }   — Assign students to group
 * POST ?action=unassign { student_id }    — Remove student from their group
 */

require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isTeacher()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
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
    // GET
    // ──────────────────────────────────────────────────────────
    if ($method === 'GET') {
        switch ($action) {

            case 'list':
                $stmt = $pdo->prepare("
                    SELECT
                        sg.id,
                        sg.name,
                        sg.color,
                        sg.sort_order,
                        COUNT(sgm.student_id) AS member_count
                    FROM student_groups sg
                    LEFT JOIN student_group_members sgm ON sgm.group_id = sg.id
                    WHERE sg.teacher_id = ?
                    GROUP BY sg.id, sg.name, sg.color, sg.sort_order
                    ORDER BY sg.sort_order ASC, sg.name ASC
                ");
                $stmt->execute([$teacherId]);
                $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($groups as &$g) {
                    $g['id'] = (int) $g['id'];
                    $g['sort_order'] = (int) $g['sort_order'];
                    $g['member_count'] = (int) $g['member_count'];
                }

                // Also count ungrouped students
                $ungrouped = (int) $pdo->prepare("
                    SELECT COUNT(*) FROM teacher_students ts
                    WHERE ts.teacher_id = ?
                      AND ts.student_id NOT IN (
                          SELECT sgm.student_id FROM student_group_members sgm
                          JOIN student_groups sg ON sg.id = sgm.group_id
                          WHERE sg.teacher_id = ?
                      )
                ")->execute([$teacherId, $teacherId]) ? 0 : 0;

                $ungStmt = $pdo->prepare("
                    SELECT COUNT(*) FROM teacher_students ts
                    WHERE ts.teacher_id = ?
                      AND ts.student_id NOT IN (
                          SELECT sgm.student_id FROM student_group_members sgm
                          JOIN student_groups sg ON sg.id = sgm.group_id
                          WHERE sg.teacher_id = ?
                      )
                ");
                $ungStmt->execute([$teacherId, $teacherId]);
                $ungroupedCount = (int) $ungStmt->fetchColumn();

                echo json_encode([
                    'groups' => $groups,
                    'ungrouped_count' => $ungroupedCount,
                ]);
                break;

            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action. Use: list']);
                break;
        }
        exit;
    }

    // ──────────────────────────────────────────────────────────
    // POST
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

            case 'create':
                $name = trim($body['name'] ?? '');
                $color = $body['color'] ?? '#4ECDC4';
                if ($name === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'Group name required']);
                    break;
                }
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                    $color = '#4ECDC4';
                }

                // Max 10 groups per teacher
                $cnt = (int) $pdo->prepare("SELECT COUNT(*) FROM student_groups WHERE teacher_id = ?")
                    ->execute([$teacherId]) ? 0 : 0;
                $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM student_groups WHERE teacher_id = ?");
                $cntStmt->execute([$teacherId]);
                $groupCount = (int) $cntStmt->fetchColumn();

                if ($groupCount >= 10) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Maximum 10 groups allowed']);
                    break;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO student_groups (teacher_id, name, color, sort_order)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$teacherId, $name, $color, $groupCount]);

                echo json_encode([
                    'success' => true,
                    'id' => (int) $pdo->lastInsertId(),
                    'name' => $name,
                    'color' => $color,
                ]);
                break;

            case 'update':
                $id = (int) ($body['id'] ?? 0);
                if (!$id) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Group id required']);
                    break;
                }

                // Verify ownership
                $own = $pdo->prepare("SELECT id FROM student_groups WHERE id = ? AND teacher_id = ?");
                $own->execute([$id, $teacherId]);
                if (!$own->fetchColumn()) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Group not found']);
                    break;
                }

                $updates = [];
                $params = [];
                if (isset($body['name'])) {
                    $updates[] = 'name = ?';
                    $params[] = trim($body['name']);
                }
                if (isset($body['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $body['color'])) {
                    $updates[] = 'color = ?';
                    $params[] = $body['color'];
                }
                if (isset($body['sort_order'])) {
                    $updates[] = 'sort_order = ?';
                    $params[] = (int) $body['sort_order'];
                }

                if (empty($updates)) {
                    echo json_encode(['success' => true, 'message' => 'Nothing to update']);
                    break;
                }

                $params[] = $id;
                $pdo->prepare("UPDATE student_groups SET " . implode(', ', $updates) . " WHERE id = ?")
                    ->execute($params);

                echo json_encode(['success' => true]);
                break;

            case 'delete':
                $id = (int) ($body['id'] ?? 0);
                if (!$id) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Group id required']);
                    break;
                }

                $stmt = $pdo->prepare("DELETE FROM student_groups WHERE id = ? AND teacher_id = ?");
                $stmt->execute([$id, $teacherId]);

                echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
                break;

            case 'assign':
                $groupId = (int) ($body['group_id'] ?? 0);
                $studentIds = $body['student_ids'] ?? [];
                if (!$groupId || !is_array($studentIds) || empty($studentIds)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'group_id and student_ids[] required']);
                    break;
                }

                // Verify group ownership
                $own = $pdo->prepare("SELECT id FROM student_groups WHERE id = ? AND teacher_id = ?");
                $own->execute([$groupId, $teacherId]);
                if (!$own->fetchColumn()) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Group not found']);
                    break;
                }

                // Remove students from any existing group of this teacher first
                // (one group per student per teacher)
                $removeSql = "
                    DELETE sgm FROM student_group_members sgm
                    JOIN student_groups sg ON sg.id = sgm.group_id
                    WHERE sg.teacher_id = ? AND sgm.student_id = ?
                ";
                $removeStmt = $pdo->prepare($removeSql);

                $insertStmt = $pdo->prepare(
                    "INSERT IGNORE INTO student_group_members (group_id, student_id) VALUES (?, ?)"
                );

                $moved = 0;
                foreach ($studentIds as $sid) {
                    $sid = (int) $sid;
                    if ($sid > 0) {
                        $removeStmt->execute([$teacherId, $sid]);
                        $insertStmt->execute([$groupId, $sid]);
                        $moved++;
                    }
                }

                echo json_encode(['success' => true, 'moved' => $moved]);
                break;

            case 'unassign':
                $studentId = (int) ($body['student_id'] ?? 0);
                if (!$studentId) {
                    http_response_code(400);
                    echo json_encode(['error' => 'student_id required']);
                    break;
                }

                $pdo->prepare("
                    DELETE sgm FROM student_group_members sgm
                    JOIN student_groups sg ON sg.id = sgm.group_id
                    WHERE sg.teacher_id = ? AND sgm.student_id = ?
                ")->execute([$teacherId, $studentId]);

                echo json_encode(['success' => true]);
                break;

            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action. Use: create, update, delete, assign, unassign']);
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
