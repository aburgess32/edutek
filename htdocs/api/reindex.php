<?php

/**
 * Reindex Content API (FRE-40)
 *
 * Teacher-only POST endpoint that triggers content reindexing
 * without needing CLI access.
 *
 * POST /api/reindex.php
 *   Header: X-CSRF-Token (or body _csrf_token)
 *   Returns JSON: { success, total, new, updated, skipped }
 *
 * @package EduPak
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/content-indexer.php';

header('Content-Type: application/json; charset=utf-8');

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Teacher only
if (!isTeacher()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

// CSRF validation — accept from header or POST body
$token = $_SERVER['HTTP_X_CSRF_TOKEN']
    ?? $_POST['_csrf_token']
    ?? '';

if (!csrf_verify($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Allow up to 5 minutes for large content directories
set_time_limit(300);

// Run the indexer using the configured CONTENT_PATH
$contentRoot = rtrim(CONTENT_PATH, '/');
$result = indexContent($contentRoot);

http_response_code($result['success'] ? 200 : 500);
echo json_encode($result);
