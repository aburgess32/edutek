<?php

/**
 * Lesson Plans Publish API
 *
 * Handles publishing/unpublishing lesson plans to student-facing segments,
 * reordering plans within segments, and listing available segments.
 *
 * Separate file from lesson_plans.php to avoid merge conflicts with Phase 4.
 *
 * @package EduPak
 * @version 1.0.0
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tiles.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// GET endpoints don't require teacher auth
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'segments') {
    handleGetSegments();
    exit;
}

// All POST endpoints require teacher auth
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

requireTeacher();

switch ($action) {
    case 'publish':
        handlePublish();
        break;
    case 'reorder':
        handleReorder();
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}

// ---------------------------------------------------------------------------
// Handlers
// ---------------------------------------------------------------------------

/**
 * GET ?action=segments — return available segments from tiles.json.
 */
function handleGetSegments(): void
{
    $segments = getSegments();
    $result = [];

    foreach ($segments as $key => $seg) {
        $result[] = [
            'key'   => $key,
            'label' => $seg['label'] ?? $key,
            'icon'  => $seg['icon'] ?? '',
        ];
    }

    echo json_encode($result);
}

/**
 * POST ?action=publish — set published_segments for a plan.
 *
 * Params:
 *   plan_id  (int, required)
 *   segments (JSON array of segment keys, e.g. ["early_learners","knowledge_power"])
 *   icon     (string, optional)
 *   color    (string, optional — hex like #4ECDC4)
 *   description (string, optional)
 */
function handlePublish(): void
{
    $planId = (int) ($_POST['plan_id'] ?? 0);
    if ($planId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'plan_id is required']);
        return;
    }

    // Parse segments — accept JSON string or array
    $rawSegments = $_POST['segments'] ?? '[]';
    if (is_string($rawSegments)) {
        $segments = json_decode($rawSegments, true);
    } else {
        $segments = $rawSegments;
    }

    if (!is_array($segments)) {
        http_response_code(400);
        echo json_encode(['error' => 'segments must be a JSON array']);
        return;
    }

    // Validate each segment key exists in tiles.json
    $validSegments = getSegments();
    $validKeys = array_keys($validSegments);
    $invalidKeys = [];

    foreach ($segments as $segKey) {
        if (!is_string($segKey) || !in_array($segKey, $validKeys, true)) {
            $invalidKeys[] = $segKey;
        }
    }

    if (!empty($invalidKeys)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid segment keys: ' . implode(', ', $invalidKeys),
            'valid_keys' => $validKeys,
        ]);
        return;
    }

    $pdo = getDbConnection();

    // Verify plan exists
    $stmt = $pdo->prepare('SELECT id FROM lesson_plans WHERE id = ?');
    $stmt->execute([$planId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Lesson plan not found']);
        return;
    }

    // Empty array → unpublish (set to NULL)
    $publishedSegments = empty($segments) ? null : json_encode(array_values($segments));

    // Build update query with optional fields
    $updates = ['published_segments = ?'];
    $params = [$publishedSegments];

    if (isset($_POST['icon']) && $_POST['icon'] !== '') {
        $icon = mb_substr(trim($_POST['icon']), 0, 10);
        $updates[] = 'icon = ?';
        $params[] = $icon;
    }

    if (isset($_POST['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['color'])) {
        $updates[] = 'color = ?';
        $params[] = $_POST['color'];
    }

    if (isset($_POST['description'])) {
        $desc = mb_substr(trim($_POST['description']), 0, 500);
        $updates[] = 'description = ?';
        $params[] = $desc;
    }

    $params[] = $planId;
    $sql = 'UPDATE lesson_plans SET ' . implode(', ', $updates) . ' WHERE id = ?';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['ok' => true]);
}

/**
 * POST ?action=reorder — reorder plans within a segment.
 *
 * Params:
 *   segment  (string, required — segment key)
 *   plan_ids (JSON array of plan IDs in desired order)
 */
function handleReorder(): void
{
    $segment = trim($_POST['segment'] ?? '');
    if ($segment === '') {
        http_response_code(400);
        echo json_encode(['error' => 'segment is required']);
        return;
    }

    // Validate segment key
    $validSegments = getSegments();
    if (!isset($validSegments[$segment])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid segment key']);
        return;
    }

    // Parse plan_ids
    $rawPlanIds = $_POST['plan_ids'] ?? '[]';
    if (is_string($rawPlanIds)) {
        $planIds = json_decode($rawPlanIds, true);
    } else {
        $planIds = $rawPlanIds;
    }

    if (!is_array($planIds)) {
        http_response_code(400);
        echo json_encode(['error' => 'plan_ids must be a JSON array']);
        return;
    }

    $pdo = getDbConnection();
    $stmt = $pdo->prepare('UPDATE lesson_plans SET sort_order = ? WHERE id = ?');

    foreach ($planIds as $order => $planId) {
        $stmt->execute([(int) $order, (int) $planId]);
    }

    echo json_encode(['ok' => true]);
}
