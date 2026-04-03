<?php
/**
 * Lesson Progress API (FRE-51)
 *
 * GET  ?action=summary&assignment_id=N   — per-student summary for an assignment
 * GET  ?action=student_detail            — per-item detail for a student+assignment
 * GET  ?action=my_assignments            — student's own assignments with progress
 * GET  ?action=guided_live               — live status for guided mode (5s polling)
 * POST ?action=update                    — update progress for a content item
 */

include_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || isGuest()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = '';

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
} elseif ($method === 'POST') {
    $action = $_POST['action'] ?? '';
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$pdo = getDbConnection();

switch ($action) {
    case 'summary':
        requireTeacher();
        handleSummary($pdo);
        break;
    case 'student_detail':
        handleStudentDetail($pdo);
        break;
    case 'my_assignments':
        handleMyAssignments($pdo);
        break;
    case 'guided_live':
        requireTeacher();
        handleGuidedLive($pdo);
        break;
    case 'update':
        handleUpdateProgress($pdo);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}

// ─────────────────────────────────────────────────────────────────────────────

function handleSummary(PDO $pdo): void
{
    $assignmentId = (int) ($_GET['assignment_id'] ?? 0);
    if ($assignmentId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing assignment_id']);
        return;
    }

    $teacherId = (int) $_SESSION['user_id'];

    // Verify ownership
    $check = $pdo->prepare("SELECT id, lesson_plan_id FROM lesson_assignments WHERE id = :id AND teacher_id = :tid");
    $check->execute([':id' => $assignmentId, ':tid' => $teacherId]);
    $assignment = $check->fetch(PDO::FETCH_ASSOC);
    if (!$assignment) {
        http_response_code(404);
        echo json_encode(['error' => 'Assignment not found']);
        return;
    }

    // Get plan item count
    $planStmt = $pdo->prepare("SELECT content_ids FROM lesson_plans WHERE id = :id");
    $planStmt->execute([':id' => $assignment['lesson_plan_id']]);
    $planRow = $planStmt->fetch(PDO::FETCH_ASSOC);
    $contentIds = $planRow ? json_decode($planRow['content_ids'], true) : [];
    $totalItems = is_array($contentIds) ? count($contentIds) : 0;

    $stmt = $pdo->prepare("
        SELECT lp.student_id,
               u.display_name, u.avatar_name, u.avatar_color,
               COUNT(*) AS tracked_items,
               SUM(CASE WHEN lp.status = 'completed' THEN 1 ELSE 0 END) AS completed_items,
               SUM(CASE WHEN lp.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_items,
               ROUND(AVG(lp.progress_pct)) AS avg_progress,
               SUM(lp.time_spent) AS total_time,
               MAX(lp.last_activity) AS last_activity
        FROM lesson_progress lp
        JOIN users u ON u.id = lp.student_id
        WHERE lp.assignment_id = :aid
        GROUP BY lp.student_id, u.display_name, u.avatar_name, u.avatar_color
        ORDER BY u.display_name
    ");
    $stmt->execute([':aid' => $assignmentId]);

    $students = [];
    $totalCompleted = 0;
    $totalStudents = 0;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $completed = (int) $row['completed_items'];
        $pct = $totalItems > 0 ? round(($completed / $totalItems) * 100) : 0;

        $daysInactive = null;
        if ($row['last_activity']) {
            $daysInactive = (int) floor((time() - strtotime($row['last_activity'])) / 86400);
        }

        $isComplete = ($completed >= $totalItems && $totalItems > 0);
        if ($isComplete) $totalCompleted++;
        $totalStudents++;

        $students[] = [
            'student_id'      => (int) $row['student_id'],
            'display_name'    => $row['display_name'] ?? $row['avatar_name'] ?? 'Unknown',
            'avatar_color'    => $row['avatar_color'] ?? '#333',
            'completed_items' => $completed,
            'in_progress'     => (int) $row['in_progress_items'],
            'total_items'     => $totalItems,
            'progress_pct'    => $pct,
            'time_spent'      => (int) $row['total_time'],
            'last_activity'   => $row['last_activity'],
            'days_inactive'   => $daysInactive,
            'at_risk'         => ($daysInactive !== null && $daysInactive >= 2 && $pct < 50),
            'is_complete'     => $isComplete,
        ];
    }

    $overallPct = $totalStudents > 0 ? round(($totalCompleted / $totalStudents) * 100) : 0;

    echo json_encode([
        'total_students'   => $totalStudents,
        'completed_count'  => $totalCompleted,
        'overall_pct'      => $overallPct,
        'total_items'      => $totalItems,
        'students'         => $students,
    ]);
}

function handleStudentDetail(PDO $pdo): void
{
    $assignmentId = (int) ($_GET['assignment_id'] ?? 0);
    $studentId = (int) ($_GET['student_id'] ?? 0);

    if ($assignmentId <= 0 || $studentId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing assignment_id or student_id']);
        return;
    }

    // Teachers can view any student; students can only view their own
    $userId = (int) $_SESSION['user_id'];
    if (!isTeacher() && $userId !== $studentId) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }

    $stmt = $pdo->prepare("
        SELECT lp.content_id, lp.status, lp.progress_pct, lp.time_spent,
               lp.last_position, lp.last_activity, lp.completed_at,
               cm.title AS content_title, cm.content_type, cm.duration_seconds,
               cm.thumbnail_path
        FROM lesson_progress lp
        LEFT JOIN content_meta cm ON cm.content_id = lp.content_id
        WHERE lp.assignment_id = :aid AND lp.student_id = :sid
        ORDER BY lp.id ASC
    ");
    $stmt->execute([':aid' => $assignmentId, ':sid' => $studentId]);

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = [
            'content_id'     => $row['content_id'],
            'title'          => $row['content_title'] ?? $row['content_id'],
            'content_type'   => $row['content_type'] ?? 'video',
            'duration'       => (int) ($row['duration_seconds'] ?? 0),
            'thumbnail'      => $row['thumbnail_path'] ?? '',
            'status'         => $row['status'],
            'progress_pct'   => (int) $row['progress_pct'],
            'time_spent'     => (int) $row['time_spent'],
            'last_position'  => (int) $row['last_position'],
            'last_activity'  => $row['last_activity'],
            'completed_at'   => $row['completed_at'],
        ];
    }

    echo json_encode(['items' => $items]);
}

function handleMyAssignments(PDO $pdo): void
{
    $userId = (int) $_SESSION['user_id'];

    // Get assignments where student is specifically assigned OR whole-class assignments
    // from teachers who have this student
    $stmt = $pdo->prepare("
        SELECT la.id AS assignment_id, la.lesson_plan_id, la.mode, la.status AS assignment_status,
               la.due_date, la.notes, la.created_at,
               lp.title AS plan_title, lp.content_ids AS plan_content_ids,
               u.display_name AS teacher_name
        FROM lesson_assignments la
        JOIN lesson_plans lp ON lp.id = la.lesson_plan_id
        JOIN users u ON u.id = la.teacher_id
        WHERE la.status = 'active'
          AND (
            la.assigned_to = :uid1
            OR (la.assigned_to IS NULL AND la.teacher_id IN (
                SELECT teacher_id FROM teacher_students WHERE student_id = :uid2
            ))
          )
        ORDER BY la.created_at DESC
    ");
    $stmt->execute([':uid1' => $userId, ':uid2' => $userId]);

    $assignments = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $contentIds = json_decode($row['plan_content_ids'], true);
        if (!is_array($contentIds)) $contentIds = [];
        $totalItems = count($contentIds);

        // Get progress for this student on this assignment
        $progStmt = $pdo->prepare("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                   SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
                   MAX(last_activity) AS last_activity
            FROM lesson_progress
            WHERE assignment_id = :aid AND student_id = :sid
        ");
        $progStmt->execute([':aid' => $row['assignment_id'], ':sid' => $userId]);
        $prog = $progStmt->fetch(PDO::FETCH_ASSOC);

        $completed = $prog ? (int) $prog['completed'] : 0;
        $pct = $totalItems > 0 ? round(($completed / $totalItems) * 100) : 0;

        // Find next incomplete content item for Continue button
        $nextStmt = $pdo->prepare("
            SELECT content_id, last_position
            FROM lesson_progress
            WHERE assignment_id = :aid AND student_id = :sid AND status != 'completed'
            ORDER BY id ASC
            LIMIT 1
        ");
        $nextStmt->execute([':aid' => $row['assignment_id'], ':sid' => $userId]);
        $next = $nextStmt->fetch(PDO::FETCH_ASSOC);

        $assignments[] = [
            'assignment_id'   => (int) $row['assignment_id'],
            'lesson_plan_id'  => (int) $row['lesson_plan_id'],
            'plan_title'      => $row['plan_title'],
            'teacher_name'    => $row['teacher_name'],
            'mode'            => $row['mode'],
            'due_date'        => $row['due_date'],
            'notes'           => $row['notes'],
            'item_count'      => $totalItems,
            'completed_items' => $completed,
            'progress_pct'    => $pct,
            'last_activity'   => $prog ? $prog['last_activity'] : null,
            'next_content_id' => $next ? $next['content_id'] : null,
            'next_position'   => $next ? (int) $next['last_position'] : 0,
        ];
    }

    echo json_encode(['assignments' => $assignments]);
}

function handleGuidedLive(PDO $pdo): void
{
    $assignmentId = (int) ($_GET['assignment_id'] ?? 0);
    if ($assignmentId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing assignment_id']);
        return;
    }

    $teacherId = (int) $_SESSION['user_id'];

    // Verify this is a guided assignment owned by this teacher
    $check = $pdo->prepare("
        SELECT id FROM lesson_assignments
        WHERE id = :id AND teacher_id = :tid AND mode = 'guided'
    ");
    $check->execute([':id' => $assignmentId, ':tid' => $teacherId]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Guided assignment not found']);
        return;
    }

    // Get real-time status — students active in last 60s are "online"
    $stmt = $pdo->prepare("
        SELECT lp.student_id,
               u.display_name, u.avatar_name, u.avatar_color,
               lp.content_id,
               lp.status,
               lp.progress_pct,
               lp.last_activity,
               CASE WHEN lp.last_activity >= DATE_SUB(NOW(), INTERVAL 60 SECOND) THEN 1 ELSE 0 END AS is_online
        FROM lesson_progress lp
        JOIN users u ON u.id = lp.student_id
        WHERE lp.assignment_id = :aid
        ORDER BY u.display_name, lp.id
    ");
    $stmt->execute([':aid' => $assignmentId]);

    $studentMap = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sid = (int) $row['student_id'];
        if (!isset($studentMap[$sid])) {
            $studentMap[$sid] = [
                'student_id'   => $sid,
                'display_name' => $row['display_name'] ?? $row['avatar_name'] ?? 'Unknown',
                'avatar_color' => $row['avatar_color'] ?? '#333',
                'is_online'    => false,
                'current_item' => null,
                'items'        => [],
            ];
        }
        if ((int) $row['is_online']) {
            $studentMap[$sid]['is_online'] = true;
        }
        if ($row['status'] === 'in_progress') {
            $studentMap[$sid]['current_item'] = $row['content_id'];
        }
        $studentMap[$sid]['items'][] = [
            'content_id'   => $row['content_id'],
            'status'       => $row['status'],
            'progress_pct' => (int) $row['progress_pct'],
        ];
    }

    echo json_encode(['students' => array_values($studentMap)]);
}

function handleUpdateProgress(PDO $pdo): void
{
    $userId = (int) $_SESSION['user_id'];
    $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
    $contentId = trim($_POST['content_id'] ?? '');
    $progressPct = (int) ($_POST['progress_pct'] ?? 0);
    $lastPosition = (int) ($_POST['last_position'] ?? 0);

    if ($assignmentId <= 0 || $contentId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        return;
    }

    $progressPct = max(0, min(100, $progressPct));

    $status = 'not_started';
    if ($progressPct >= 80) {
        $status = 'completed';
    } elseif ($progressPct > 0) {
        $status = 'in_progress';
    }

    $stmt = $pdo->prepare("
        UPDATE lesson_progress
        SET status = :status,
            progress_pct = :pct,
            last_position = :pos,
            completed_at = CASE WHEN :status2 = 'completed' AND completed_at IS NULL THEN NOW() ELSE completed_at END
        WHERE assignment_id = :aid AND student_id = :sid AND content_id = :cid
    ");
    $stmt->execute([
        ':status'  => $status,
        ':status2' => $status,
        ':pct'     => $progressPct,
        ':pos'     => $lastPosition,
        ':aid'     => $assignmentId,
        ':sid'     => $userId,
        ':cid'     => $contentId,
    ]);

    // Check if all items are completed — if so, mark assignment as completed
    if ($status === 'completed') {
        checkAssignmentCompletion($pdo, $assignmentId, $userId);
    }

    echo json_encode(['ok' => true, 'status' => $status]);
}

function checkAssignmentCompletion(PDO $pdo, int $assignmentId, int $studentId): void
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed
        FROM lesson_progress
        WHERE assignment_id = :aid AND student_id = :sid
    ");
    $stmt->execute([':aid' => $assignmentId, ':sid' => $studentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && (int) $row['total'] > 0 && (int) $row['completed'] >= (int) $row['total']) {
        // All items completed — check if this was an individual assignment
        $aStmt = $pdo->prepare("SELECT assigned_to FROM lesson_assignments WHERE id = :id");
        $aStmt->execute([':id' => $assignmentId]);
        $aRow = $aStmt->fetch(PDO::FETCH_ASSOC);

        if ($aRow && (int) ($aRow['assigned_to'] ?? 0) === $studentId) {
            // Individual assignment — mark completed
            $pdo->prepare("UPDATE lesson_assignments SET status = 'completed' WHERE id = :id")
                ->execute([':id' => $assignmentId]);
        }
    }
}
