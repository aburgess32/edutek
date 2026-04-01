<?php

/**
 * Teacher Password Reset API (FRE-13)
 *
 * Allows a logged-in teacher to reset another teacher's password.
 * POST endpoint — peer teacher reset (no email flow needed offline).
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

$targetEmail = trim(filter_input(INPUT_POST, 'target_email', FILTER_SANITIZE_EMAIL) ?? '');
$newPassword = filter_input(INPUT_POST, 'new_password', FILTER_DEFAULT) ?? '';

if ($targetEmail === '' || !filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Please provide a valid email address.']);
    exit;
}

if (strlen($newPassword) < 6) {
    http_response_code(400);
    echo json_encode(['error' => 'Password must be at least 6 characters.']);
    exit;
}

$pdo = getDbConnection();

// Verify target exists and is a teacher
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND user_type = 'teacher'");
$stmt->execute([$targetEmail]);
$target = $stmt->fetch();

if (!$target) {
    http_response_code(404);
    echo json_encode(['error' => 'No teacher account found with that email.']);
    exit;
}

// Update password hash
$stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
$stmt->execute([
    password_hash($newPassword, PASSWORD_DEFAULT),
    $target['id'],
]);

echo json_encode(['ok' => true]);
