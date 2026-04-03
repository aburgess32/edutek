<?php
/**
 * Lesson Assignments CRUD API (FRE-49)
 *
 * Teacher auth required for all actions.
 *
 * GET  ?action=list                     — all assignments for current teacher
 * GET  ?action=detail&assignment_id=N   — single assignment with student list
 * GET  ?action=for_plan&plan_id=N       — assignments for a specific lesson plan
 * POST ?action=create                   — create new assignment
 * POST ?action=update                   — update assignment (status, notes, due_date)
 * POST ?action=delete                   — delete assignment
 */

include_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

requireTeacher();

$method = $_SERVER['REQUEST_METHOD'];
$action = '';

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
} elseif ($method === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['_csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid or missing CSRF token']);
        exit;
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$pdo = getDbConnection();

// Ensure tables exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS lesson_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lesson_plan_id INT NOT NULL,
        teacher_id INT NOT NULL,
        assigned_to INT DEFAULT NULL,
        mode ENUM('guided', 'individual') NOT NULL DEFAULT 'individual',
        status ENUM('active', 'completed', 'archived') NOT NULL DEFAULT 'active',
        due_date DATE DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_teacher_id (teacher_id),
        INDEX idx_assigned_to (assigned_to),
        INDEX idx_lesson_plan (lesson_plan_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

switch ($action) {
    case 'list':
        handleList($pdo);
        break;
    case 'detail':
        handleDetail($pdo);
        break;
    case 'for_plan':
        handleForPlan($pdo);
        break;
    case 'create':
        handleCreate($pdo);
        break;
    case 'update':
        handleUpdate($pdo);
        break;
    case 'delete':
        handleDelete($pdo);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}

// ─────────────────────────────────────────────────────────────────────────────

function handleList(PDO $pdo): void
{
    $teacherId = (int) $_SESSION['user_id'];
    $statusFilter = $_GET['status'] ?? '';

    $sql = "
        SELECT la.id, la.lesson_plan_id, la.teacher_id, la.assigned_to, la.mode,
               la.status, la.due_date, la.notes, la.created_at, la.updated_at,
               lp.title AS plan_title, lp.content_ids AS plan_content_ids,
               u.display_name AS assigned_to_name
        FROM lesson_assignments la
        JOIN lesson_plans lp ON lp.id = la.lesson_plan_id
        LEFT JOIN users u ON u.id = la.assigned_to
        WHERE la.teacher_id = :teacher_id
    ";
    $params = [':teacher_id' => $teacherId];

    if ($statusFilter && in_array($statusFilter, ['active', 'completed', 'archived'])) {
        $sql .= " AND la.status = :status";
        $params[':status'] = $statusFilter;
    }

    $sql .= " ORDER BY la.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $assignments = [];
    foreach ($rows as $row) {
        $contentIds = json_decode($row['plan_content_ids'], true);
        if (!is_array($contentIds)) $contentIds = [];

        $assignments[] = [
            'id'              => (int) $row['id'],
            'lesson_plan_id'  => (int) $row['lesson_plan_id'],
            'plan_title'      => $row['plan_title'],
            'item_count'      => count($contentIds),
            'teacher_id'      => (int) $row['teacher_id'],
            'assigned_to'     => $row['assigned_to'] ? (int) $row['assigned_to'] : null,
            'assigned_to_name' => $row['assigned_to_name'],
            'mode'            => $row['mode'],
            'status'          => $row['status'],
            'due_date'        => $row['due_date'],
            'notes'           => $row['notes'],
            'created_at'      => $row['created_at'],
            'updated_at'      => $row['updated_at'],
        ];
    }

    echo json_encode(['assignments' => $assignments]);
}

function handleDetail(PDO $pdo): void
{
    $assignmentId = (int) ($_GET['assignment_id'] ?? 0);
    if ($assignmentId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid assignment_id']);
        return;
    }

    $teacherId = (int) $_SESSION['user_id'];

    $stmt = $pdo->prepare("
        SELECT la.*, lp.title AS plan_title, lp.content_ids AS plan_content_ids
        FROM lesson_assignments la
        JOIN lesson_plans lp ON lp.id = la.lesson_plan_id
        WHERE la.id = :id AND la.teacher_id = :teacher_id
    ");
    $stmt->execute([':id' => $assignmentId, ':teacher_id' => $teacherId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Assignment not found']);
        return;
    }

    $contentIds = json_decode($row['plan_content_ids'], true);
    if (!is_array($contentIds)) $contentIds = [];

    // Get students for this assignment
    $students = getAssignmentStudents($pdo, $assignmentId, (int) $row['assigned_to'], $teacherId);

    // Get progress summary
    $progressStmt = $pdo->prepare("
        SELECT lp.student_id,
               COUNT(*) AS total_items,
               SUM(CASE WHEN lp.status = 'completed' THEN 1 ELSE 0 END) AS completed_items,
               SUM(lp.time_spent) AS total_time,
               MAX(lp.last_activity) AS last_activity
        FROM lesson_progress lp
        WHERE lp.assignment_id = :aid
        GROUP BY lp.student_id
    ");
    $progressStmt->execute([':aid' => $assignmentId]);
    $progressMap = [];
    foreach ($progressStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $progressMap[(int) $p['student_id']] = $p;
    }

    $studentList = [];
    foreach ($students as $s) {
        $sid = (int) $s['id'];
        $prog = $progressMap[$sid] ?? null;
        $totalItems = count($contentIds);
        $completedItems = $prog ? (int) $prog['completed_items'] : 0;
        $pct = $totalItems > 0 ? round(($completedItems / $totalItems) * 100) : 0;

        $daysInactive = null;
        if ($prog && $prog['last_activity']) {
            $daysInactive = (int) floor((time() - strtotime($prog['last_activity'])) / 86400);
        }

        $studentList[] = [
            'id'              => $sid,
            'display_name'    => $s['display_name'] ?? $s['avatar_name'] ?? 'Unknown',
            'avatar_color'    => $s['avatar_color'] ?? '#333',
            'completed_items' => $completedItems,
            'total_items'     => $totalItems,
            'progress_pct'    => $pct,
            'time_spent'      => $prog ? (int) $prog['total_time'] : 0,
            'last_activity'   => $prog ? $prog['last_activity'] : null,
            'days_inactive'   => $daysInactive,
            'at_risk'         => ($daysInactive !== null && $daysInactive >= 2 && $pct < 50),
        ];
    }

    echo json_encode([
        'assignment' => [
            'id'              => (int) $row['id'],
            'lesson_plan_id'  => (int) $row['lesson_plan_id'],
            'plan_title'      => $row['plan_title'],
            'content_ids'     => $contentIds,
            'item_count'      => count($contentIds),
            'mode'            => $row['mode'],
            'status'          => $row['status'],
            'due_date'        => $row['due_date'],
            'notes'           => $row['notes'],
            'created_at'      => $row['created_at'],
        ],
        'students' => $studentList,
    ]);
}

function handleForPlan(PDO $pdo): void
{
    $planId = (int) ($_GET['plan_id'] ?? 0);
    if ($planId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid plan_id']);
        return;
    }

    $teacherId = (int) $_SESSION['user_id'];
    $stmt = $pdo->prepare("
        SELECT la.id, la.mode, la.status, la.assigned_to, la.due_date, la.created_at,
               u.display_name AS assigned_to_name
        FROM lesson_assignments la
        LEFT JOIN users u ON u.id = la.assigned_to
        WHERE la.lesson_plan_id = :plan_id AND la.teacher_id = :teacher_id
        ORDER BY la.created_at DESC
    ");
    $stmt->execute([':plan_id' => $planId, ':teacher_id' => $teacherId]);

    echo json_encode(['assignments' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function handleCreate(PDO $pdo): void
{
    $teacherId = (int) $_SESSION['user_id'];
    $planId = (int) ($_POST['lesson_plan_id'] ?? 0);
    $mode = $_POST['mode'] ?? 'individual';
    $notes = trim($_POST['notes'] ?? '');
    $dueDate = trim($_POST['due_date'] ?? '');
    $studentIds = $_POST['student_ids'] ?? '';

    if ($planId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'lesson_plan_id is required']);
        return;
    }

    if (!in_array($mode, ['guided', 'individual'])) {
        http_response_code(400);
        echo json_encode(['error' => 'mode must be guided or individual']);
        return;
    }

    // Verify plan exists
    $check = $pdo->prepare("SELECT id, content_ids FROM lesson_plans WHERE id = :id");
    $check->execute([':id' => $planId]);
    $plan = $check->fetch(PDO::FETCH_ASSOC);
    if (!$plan) {
        http_response_code(404);
        echo json_encode(['error' => 'Lesson plan not found']);
        return;
    }

    $parsedStudents = $studentIds ? json_decode($studentIds, true) : [];
    if (!is_array($parsedStudents)) $parsedStudents = [];

    $validDueDate = null;
    if ($dueDate && $mode === 'individual') {
        $validDueDate = date('Y-m-d', strtotime($dueDate));
        if ($validDueDate === '1970-01-01') $validDueDate = null;
    }

    $createdIds = [];

    if (empty($parsedStudents)) {
        // Whole class assignment (assigned_to = NULL)
        $stmt = $pdo->prepare("
            INSERT INTO lesson_assignments (lesson_plan_id, teacher_id, assigned_to, mode, due_date, notes)
            VALUES (:plan_id, :teacher_id, NULL, :mode, :due_date, :notes)
        ");
        $stmt->execute([
            ':plan_id'    => $planId,
            ':teacher_id' => $teacherId,
            ':mode'       => $mode,
            ':due_date'   => $validDueDate,
            ':notes'      => $notes ?: null,
        ]);
        $createdIds[] = (int) $pdo->lastInsertId();
    } else {
        // Individual student assignments
        $stmt = $pdo->prepare("
            INSERT INTO lesson_assignments (lesson_plan_id, teacher_id, assigned_to, mode, due_date, notes)
            VALUES (:plan_id, :teacher_id, :assigned_to, :mode, :due_date, :notes)
        ");
        foreach ($parsedStudents as $sid) {
            $stmt->execute([
                ':plan_id'     => $planId,
                ':teacher_id'  => $teacherId,
                ':assigned_to' => (int) $sid,
                ':mode'        => $mode,
                ':due_date'    => $validDueDate,
                ':notes'       => $notes ?: null,
            ]);
            $createdIds[] = (int) $pdo->lastInsertId();
        }
    }

    // Initialize lesson_progress rows for each assignment
    $contentIds = json_decode($plan['content_ids'], true);
    if (is_array($contentIds) && count($contentIds) > 0) {
        initializeProgress($pdo, $createdIds, $parsedStudents, $contentIds, $teacherId);
    }

    echo json_encode([
        'ok'             => true,
        'assignment_ids' => $createdIds,
        'count'          => count($createdIds),
    ]);
}

function handleUpdate(PDO $pdo): void
{
    $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
    $teacherId = (int) $_SESSION['user_id'];

    if ($assignmentId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid assignment_id']);
        return;
    }

    $check = $pdo->prepare("SELECT id FROM lesson_assignments WHERE id = :id AND teacher_id = :tid");
    $check->execute([':id' => $assignmentId, ':tid' => $teacherId]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Assignment not found']);
        return;
    }

    $updates = [];
    $params = [':id' => $assignmentId];

    if (isset($_POST['status'])) {
        $status = $_POST['status'];
        if (in_array($status, ['active', 'completed', 'archived'])) {
            $updates[] = 'status = :status';
            $params[':status'] = $status;
        }
    }
    if (isset($_POST['notes'])) {
        $updates[] = 'notes = :notes';
        $params[':notes'] = trim($_POST['notes']) ?: null;
    }
    if (isset($_POST['due_date'])) {
        $dd = trim($_POST['due_date']);
        $updates[] = 'due_date = :due_date';
        $params[':due_date'] = $dd ? date('Y-m-d', strtotime($dd)) : null;
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'No fields to update']);
        return;
    }

    $sql = "UPDATE lesson_assignments SET " . implode(', ', $updates) . " WHERE id = :id";
    $pdo->prepare($sql)->execute($params);

    echo json_encode(['ok' => true]);
}

function handleDelete(PDO $pdo): void
{
    $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
    $teacherId = (int) $_SESSION['user_id'];

    if ($assignmentId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid assignment_id']);
        return;
    }

    $stmt = $pdo->prepare("DELETE FROM lesson_assignments WHERE id = :id AND teacher_id = :tid");
    $stmt->execute([':id' => $assignmentId, ':tid' => $teacherId]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Assignment not found']);
        return;
    }

    echo json_encode(['ok' => true]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function getAssignmentStudents(PDO $pdo, int $assignmentId, ?int $assignedTo, int $teacherId): array
{
    if ($assignedTo) {
        // Individual assignment
        $stmt = $pdo->prepare("SELECT id, display_name, avatar_name, avatar_color FROM users WHERE id = :id");
        $stmt->execute([':id' => $assignedTo]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Whole class — get all teacher's students
    $stmt = $pdo->prepare("
        SELECT u.id, u.display_name, u.avatar_name, u.avatar_color
        FROM teacher_students ts
        JOIN users u ON u.id = ts.student_id
        WHERE ts.teacher_id = :tid
        ORDER BY u.display_name
    ");
    $stmt->execute([':tid' => $teacherId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function initializeProgress(PDO $pdo, array $assignmentIds, array $studentIds, array $contentIds, int $teacherId): void
{
    // Ensure lesson_progress table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lesson_progress (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assignment_id INT NOT NULL,
            student_id INT NOT NULL,
            content_id VARCHAR(255) NOT NULL,
            status ENUM('not_started', 'in_progress', 'completed') NOT NULL DEFAULT 'not_started',
            progress_pct INT NOT NULL DEFAULT 0,
            time_spent INT NOT NULL DEFAULT 0,
            last_position INT NOT NULL DEFAULT 0,
            completed_at TIMESTAMP NULL DEFAULT NULL,
            last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_assignment_student_content (assignment_id, student_id, content_id),
            INDEX idx_student_id (student_id),
            INDEX idx_content_id (content_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // If no specific students, get all teacher's students
    if (empty($studentIds)) {
        $stmt = $pdo->prepare("SELECT student_id FROM teacher_students WHERE teacher_id = :tid");
        $stmt->execute([':tid' => $teacherId]);
        $studentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    if (empty($studentIds)) return;

    $insert = $pdo->prepare("
        INSERT IGNORE INTO lesson_progress (assignment_id, student_id, content_id)
        VALUES (:aid, :sid, :cid)
    ");

    foreach ($assignmentIds as $aid) {
        foreach ($studentIds as $sid) {
            foreach ($contentIds as $cid) {
                $cidStr = is_array($cid) ? ($cid['content_id'] ?? $cid['id'] ?? '') : (string) $cid;
                if ($cidStr === '') continue;
                $insert->execute([':aid' => $aid, ':sid' => (int) $sid, ':cid' => $cidStr]);
            }
        }
    }
}
