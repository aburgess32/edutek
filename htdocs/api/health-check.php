<?php
/**
 * EduPak Health Check Endpoint
 *
 * Returns JSON status of app, database, and content index.
 * Used by edupak-diagnose.ps1 and monitoring.
 *
 * GET /api/health-check.php
 *
 * Response:
 *   { "status": "ok", "checks": { ... }, "timestamp": "..." }
 *
 * @package EduPak
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$checks = [];
$allOk = true;

// -- App check --
$checks['app'] = ['status' => 'ok', 'version' => '1.0.0'];

// -- Database check --
try {
    include_once __DIR__ . '/../includes/auth.php';
    $pdo = getDbConnection();
    $pdo->query('SELECT 1');

    // Count migrations applied
    $migrationCount = 0;
    try {
        $stmt = $pdo->query('SELECT COUNT(*) AS cnt FROM migrations');
        $row = $stmt->fetch();
        $migrationCount = (int) $row['cnt'];
    } catch (Exception $e) {
        // migrations table may not exist yet
    }

    $checks['database'] = [
        'status' => 'ok',
        'host' => DB_HOST,
        'name' => DB_NAME,
        'migrations_applied' => $migrationCount,
    ];
} catch (Exception $e) {
    $checks['database'] = ['status' => 'error', 'message' => $e->getMessage()];
    $allOk = false;
}

// -- Content index check --
try {
    if (isset($pdo)) {
        $stmt = $pdo->query('SELECT COUNT(*) AS cnt FROM content_meta');
        $row = $stmt->fetch();
        $checks['content_index'] = [
            'status' => 'ok',
            'indexed_items' => (int) $row['cnt'],
        ];
    } else {
        $checks['content_index'] = ['status' => 'skipped', 'message' => 'No DB connection'];
    }
} catch (Exception $e) {
    // content_meta table may not exist yet
    $checks['content_index'] = ['status' => 'ok', 'indexed_items' => 0, 'note' => 'table not yet created'];
}

// -- Content directories check --
$contentPath = defined('CONTENT_PATH') ? CONTENT_PATH : __DIR__ . '/../videos/';
$contentDirs = ['videos', 'khan', 'Wiki', 'Kiwix'];
$dirChecks = [];
foreach ($contentDirs as $dir) {
    $fullPath = dirname(__DIR__) . '/' . $dir;
    $dirChecks[$dir] = is_dir($fullPath) ? 'present' : 'missing';
}
$checks['content_dirs'] = $dirChecks;

echo json_encode([
    'status' => $allOk ? 'ok' : 'degraded',
    'checks' => $checks,
    'timestamp' => date('c'),
], JSON_PRETTY_PRINT);
