<?php

/**
 * Teacher Profile Management API (FRE-13)
 *
 * POST endpoint with action param:
 *   - update_name: change display name
 *   - change_password: change own password
 */

include_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// Must be a logged-in teacher
requireTeacher();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// CSRF check
$token = filter_input(INPUT_POST, '_csrf_token', FILTER_DEFAULT) ?? '';
if (!csrf_verify($token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid form submission. Please try again.']);
    exit;
}

$action = filter_input(INPUT_POST, 'action', FILTER_DEFAULT) ?? '';
$userId = (int) $_SESSION['user_id'];
$pdo    = getDbConnection();

switch ($action) {
    case 'update_name':
        $newName = trim(filter_input(INPUT_POST, 'display_name', FILTER_DEFAULT) ?? '');

        if (strlen($newName) < 2) {
            http_response_code(400);
            echo json_encode(['error' => 'Name must be at least 2 characters.']);
            exit;
        }

        $sanitized = sanitize_name($newName);

        $stmt = $pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?');
        $stmt->execute([$sanitized, $userId]);

        // Sync session
        $_SESSION['display_name'] = $sanitized;

        echo json_encode(['ok' => true, 'display_name' => $sanitized]);
        break;

    case 'change_password':
        $currentPassword = filter_input(INPUT_POST, 'current_password', FILTER_DEFAULT) ?? '';
        $newPassword     = filter_input(INPUT_POST, 'new_password', FILTER_DEFAULT) ?? '';
        $confirmPassword = filter_input(INPUT_POST, 'confirm_password', FILTER_DEFAULT) ?? '';

        if ($newPassword !== $confirmPassword) {
            http_response_code(400);
            echo json_encode(['error' => 'New passwords do not match.']);
            exit;
        }

        if (strlen($newPassword) < 6) {
            http_response_code(400);
            echo json_encode(['error' => 'Password must be at least 6 characters.']);
            exit;
        }

        // Verify current password
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Current password is incorrect.']);
            exit;
        }

        // Update password
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([
            password_hash($newPassword, PASSWORD_DEFAULT),
            $userId,
        ]);

        echo json_encode(['ok' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action.']);
        break;
}
