<?php
/**
 * EduPak HTTP Content Indexer
 *
 * Triggers content indexing via HTTP request.
 * Protected by DEPLOY_SECRET from .env.
 *
 * Usage:
 *   GET /api/content-index.php?key=YOUR_DEPLOY_SECRET
 *   GET /api/content-index.php?key=YOUR_DEPLOY_SECRET&path=/custom/content/path
 *
 * @package EduPak
 */

header('Content-Type: application/json; charset=utf-8');

include_once __DIR__ . '/../includes/auth.php';

// -- Auth: require deploy secret --
$deploySecret = defined('DEPLOY_SECRET') ? DEPLOY_SECRET : env('DEPLOY_SECRET', '');
$providedKey = isset($_GET['key']) ? $_GET['key'] : '';

if ($deploySecret === '' || $providedKey !== $deploySecret) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid or missing deploy key']);
    exit;
}

// Load the content indexer function
$indexerPath = __DIR__ . '/../includes/content-indexer.php';
if (!file_exists($indexerPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Content indexer module not found']);
    exit;
}

require_once $indexerPath;

// Resolve content root
$contentRoot = isset($_GET['path']) ? $_GET['path'] : (defined('CONTENT_PATH') ? CONTENT_PATH : __DIR__ . '/../videos/');
$contentRoot = rtrim($contentRoot, '/');

$result = indexContent($contentRoot);

if (!$result['success']) {
    http_response_code(500);
    echo json_encode(['error' => $result['error']]);
    exit;
}

echo json_encode([
    'status' => 'ok',
    'content_root' => $contentRoot,
    'total' => $result['total'],
    'new' => $result['new'],
    'updated' => $result['updated'],
    'skipped' => $result['skipped'],
], JSON_PRETTY_PRINT);
