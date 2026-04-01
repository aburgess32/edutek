<?php
/**
 * Lesson Plans CRUD API (FRE-13 Phase 4)
 *
 * All actions require teacher authentication.
 * Collaborative: any teacher can edit/delete any plan.
 *
 * GET  ?action=list                — all plans with creator info
 * GET  ?action=detail&plan_id=N    — single plan with full data
 * POST ?action=create              — create new plan
 * POST ?action=update              — update any plan
 * POST ?action=delete              — delete any plan
 * POST ?action=reorder_items       — reorder content within a plan
 */

include_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// Require teacher auth on all actions
requireTeacher();

$method = $_SERVER['REQUEST_METHOD'];
$action = '';

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
} elseif ($method === 'POST') {
    $action = $_POST['action'] ?? '';
    // Verify CSRF on all POST actions
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

switch ($action) {
    case 'list':
        handleList($pdo);
        break;
    case 'detail':
        handleDetail($pdo);
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
    case 'reorder_items':
        handleReorderItems($pdo);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}

// ─────────────────────────────────────────────────────────────────────────────
// Action Handlers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * GET ?action=list — return all plans with creator info.
 */
function handleList(PDO $pdo): void
{
    $stmt = $pdo->query("
        SELECT
            lp.id,
            lp.teacher_id,
            u.display_name AS creator_name,
            lp.title,
            lp.content_ids,
            lp.published_segments,
            lp.created_at
        FROM lesson_plans lp
        LEFT JOIN users u ON u.id = lp.teacher_id
        ORDER BY lp.created_at DESC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $plans = [];
    foreach ($rows as $row) {
        $contentIds = json_decode($row['content_ids'], true);
        if (!is_array($contentIds)) {
            $contentIds = [];
        }
        $plans[] = [
            'id'                 => (int) $row['id'],
            'teacher_id'         => (int) $row['teacher_id'],
            'creator_name'       => $row['creator_name'] ?? 'Unknown',
            'title'              => $row['title'],
            'content_ids'        => $contentIds,
            'item_count'         => count($contentIds),
            'published_segments' => $row['published_segments'] ?? '[]',
            'created_at'         => $row['created_at'],
        ];
    }

    echo json_encode($plans);
}

/**
 * GET ?action=detail&plan_id=N — single plan with full data.
 */
function handleDetail(PDO $pdo): void
{
    $planId = (int) ($_GET['plan_id'] ?? 0);
    if ($planId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid plan_id']);
        return;
    }

    $stmt = $pdo->prepare("
        SELECT
            lp.id,
            lp.teacher_id,
            u.display_name AS creator_name,
            lp.title,
            lp.content_ids,
            lp.published_segments,
            lp.icon,
            lp.color,
            lp.description,
            lp.created_at
        FROM lesson_plans lp
        LEFT JOIN users u ON u.id = lp.teacher_id
        WHERE lp.id = :plan_id
    ");
    $stmt->execute([':plan_id' => $planId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Plan not found']);
        return;
    }

    $contentIds = json_decode($row['content_ids'], true);
    if (!is_array($contentIds)) {
        $contentIds = [];
    }

    echo json_encode([
        'id'                 => (int) $row['id'],
        'teacher_id'         => (int) $row['teacher_id'],
        'creator_name'       => $row['creator_name'] ?? 'Unknown',
        'title'              => $row['title'],
        'content_ids'        => $contentIds,
        'item_count'         => count($contentIds),
        'published_segments' => $row['published_segments'] ?? '[]',
        'icon'               => $row['icon'] ?? '',
        'color'              => $row['color'] ?? '',
        'description'        => $row['description'] ?? '',
        'created_at'         => $row['created_at'],
    ]);
}

/**
 * POST ?action=create — create a new lesson plan.
 */
function handleCreate(PDO $pdo): void
{
    $title = trim($_POST['title'] ?? '');
    $contentIdsRaw = $_POST['content_ids'] ?? '[]';

    // Validate title
    if ($title === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Title is required']);
        return;
    }
    if (mb_strlen($title) > 255) {
        http_response_code(400);
        echo json_encode(['error' => 'Title must be 255 characters or less']);
        return;
    }

    // Validate content_ids
    $contentIds = json_decode($contentIdsRaw, true);
    if (!is_array($contentIds)) {
        http_response_code(400);
        echo json_encode(['error' => 'content_ids must be a valid JSON array']);
        return;
    }
    if (count($contentIds) > 50) {
        http_response_code(400);
        echo json_encode(['error' => 'Maximum 50 items per plan']);
        return;
    }

    $teacherId = (int) $_SESSION['user_id'];

    $stmt = $pdo->prepare("
        INSERT INTO lesson_plans (teacher_id, title, content_ids)
        VALUES (:teacher_id, :title, :content_ids)
    ");
    $stmt->execute([
        ':teacher_id'  => $teacherId,
        ':title'       => $title,
        ':content_ids' => json_encode($contentIds),
    ]);

    echo json_encode([
        'ok'      => true,
        'plan_id' => (int) $pdo->lastInsertId(),
    ]);
}

/**
 * POST ?action=update — update any plan (collaborative).
 */
function handleUpdate(PDO $pdo): void
{
    $planId = (int) ($_POST['plan_id'] ?? 0);
    if ($planId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid plan_id']);
        return;
    }

    // Check plan exists
    $check = $pdo->prepare("SELECT id FROM lesson_plans WHERE id = :id");
    $check->execute([':id' => $planId]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Plan not found']);
        return;
    }

    $updates = [];
    $params = [':id' => $planId];

    // Title update
    if (isset($_POST['title'])) {
        $title = trim($_POST['title']);
        if ($title === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Title cannot be empty']);
            return;
        }
        if (mb_strlen($title) > 255) {
            http_response_code(400);
            echo json_encode(['error' => 'Title must be 255 characters or less']);
            return;
        }
        $updates[] = 'title = :title';
        $params[':title'] = $title;
    }

    // Content IDs update
    if (isset($_POST['content_ids'])) {
        $contentIds = json_decode($_POST['content_ids'], true);
        if (!is_array($contentIds)) {
            http_response_code(400);
            echo json_encode(['error' => 'content_ids must be a valid JSON array']);
            return;
        }
        if (count($contentIds) > 50) {
            http_response_code(400);
            echo json_encode(['error' => 'Maximum 50 items per plan']);
            return;
        }
        $updates[] = 'content_ids = :content_ids';
        $params[':content_ids'] = json_encode($contentIds);
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'No fields to update']);
        return;
    }

    $sql = "UPDATE lesson_plans SET " . implode(', ', $updates) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['ok' => true]);
}

/**
 * POST ?action=delete — delete any plan (collaborative).
 */
function handleDelete(PDO $pdo): void
{
    $planId = (int) ($_POST['plan_id'] ?? 0);
    if ($planId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid plan_id']);
        return;
    }

    $stmt = $pdo->prepare("DELETE FROM lesson_plans WHERE id = :id");
    $stmt->execute([':id' => $planId]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Plan not found']);
        return;
    }

    echo json_encode(['ok' => true]);
}

/**
 * POST ?action=reorder_items — reorder content within a plan.
 */
function handleReorderItems(PDO $pdo): void
{
    $planId = (int) ($_POST['plan_id'] ?? 0);
    if ($planId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid plan_id']);
        return;
    }

    $contentIdsRaw = $_POST['content_ids'] ?? '';
    $contentIds = json_decode($contentIdsRaw, true);
    if (!is_array($contentIds)) {
        http_response_code(400);
        echo json_encode(['error' => 'content_ids must be a valid JSON array']);
        return;
    }
    if (count($contentIds) > 50) {
        http_response_code(400);
        echo json_encode(['error' => 'Maximum 50 items per plan']);
        return;
    }

    // Check plan exists
    $check = $pdo->prepare("SELECT id FROM lesson_plans WHERE id = :id");
    $check->execute([':id' => $planId]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Plan not found']);
        return;
    }

    $stmt = $pdo->prepare("UPDATE lesson_plans SET content_ids = :content_ids WHERE id = :id");
    $stmt->execute([
        ':content_ids' => json_encode($contentIds),
        ':id'          => $planId,
    ]);

    echo json_encode(['ok' => true]);
}
