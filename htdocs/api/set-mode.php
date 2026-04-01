<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$mode = filter_input(INPUT_POST, 'mode', FILTER_SANITIZE_SPECIAL_CHARS);
$allowed = ['phone', 'tablet', 'screen'];

if (!in_array($mode, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid mode']);
    exit;
}

$_SESSION['mode'] = $mode;
setcookie('edupak_mode', $mode, time() + 86400 * 30, '/', '', false, false);
echo json_encode(['ok' => true, 'mode' => $mode]);
