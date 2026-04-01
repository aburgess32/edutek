<?php
/**
 * AJAX Avatar Name Lookup + Quick Login
 *
 * GET  ?name=Simba  → Returns JSON {found, id, avatar_name, avatar_color, display_name}
 * POST {user_id}    → Logs the user in, returns JSON {ok}
 */

include_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// ── GET: Lookup by avatar name ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $name = trim($_GET['name'] ?? '');
    if ($name === '') {
        echo json_encode(['found' => false]);
        exit;
    }

    $pdo  = getDbConnection();
    $stmt = $pdo->prepare(
        "SELECT id, display_name, avatar_name, avatar_color
         FROM users WHERE avatar_name = ? AND user_type != 'teacher'"
    );
    $stmt->execute([$name]);
    $user = $stmt->fetch();

    if ($user) {
        echo json_encode([
            'found'        => true,
            'id'           => (int) $user['id'],
            'avatar_name'  => $user['avatar_name'],
            'avatar_color' => $user['avatar_color'] ?? '#333',
            'display_name' => $user['display_name'],
        ]);
    } else {
        echo json_encode(['found' => false]);
    }
    exit;
}

// ── POST: Quick login by user_id ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $userId = (int) ($input['user_id'] ?? 0);

    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid user ID']);
        exit;
    }

    $pdo  = getDbConnection();
    $stmt = $pdo->prepare(
        "SELECT id, display_name, avatar_name, avatar_color, age_range
         FROM users WHERE id = ? AND user_type != 'teacher'"
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'User not found']);
        exit;
    }

    loginUser(
        (int) $user['id'],
        $user['display_name'],
        $user['avatar_name'] ?? '',
        $user['avatar_color'] ?? '#333',
        'student',
        $user['age_range'] ?? ''
    );
    updateLastActive($pdo, (int) $user['id']);

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
