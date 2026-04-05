<?php
/**
 * EduPak HTTP Migration Runner
 *
 * Triggers database migrations via HTTP request.
 * Protected by DEPLOY_SECRET from .env.
 *
 * Usage:
 *   GET /api/migrate-runner.php?action=up&key=YOUR_DEPLOY_SECRET
 *   GET /api/migrate-runner.php?action=status&key=YOUR_DEPLOY_SECRET
 *
 * Actions:
 *   up     — Apply all pending migrations
 *   status — Show applied/pending migration counts
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

$action = isset($_GET['action']) ? $_GET['action'] : 'status';

try {
    $pdo = getDbConnection();

    // Ensure migrations table exists
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS migrations (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            applied_at     DATETIME     NOT NULL,
            INDEX idx_name (migration_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');

    $migrationsDir = dirname(__DIR__, 2) . '/db/migrations';

    // Get all migration files
    $files = glob($migrationsDir . '/[0-9][0-9][0-9][0-9]_*.sql');
    if ($files === false) {
        $files = [];
    }
    sort($files);

    // Get applied migrations
    $stmt = $pdo->query('SELECT migration_name FROM migrations ORDER BY applied_at ASC');
    $appliedNames = $stmt ? array_column($stmt->fetchAll(), 'migration_name') : [];

    // Find pending
    $pending = array_filter($files, function ($f) use ($appliedNames) {
        return !in_array(basename($f), $appliedNames, true);
    });

    if ($action === 'status') {
        echo json_encode([
            'total_files' => count($files),
            'applied' => count($appliedNames),
            'pending' => count($pending),
            'pending_files' => array_map('basename', array_values($pending)),
        ], JSON_PRETTY_PRINT);
        exit;
    }

    if ($action !== 'up') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action. Use: up, status']);
        exit;
    }

    // Apply pending migrations
    $results = [];
    foreach ($pending as $file) {
        $name = basename($file);
        $content = file_get_contents($file);

        // Parse UP section
        $upSql = '';
        if (preg_match('/^--\s*UP\s*$(.*?)^--\s*DOWN\s*$/ims', $content, $m)) {
            $upSql = trim($m[1]);
        } elseif (preg_match('/^--\s*UP\s*$(.*)/ims', $content, $m)) {
            $upSql = trim($m[1]);
        }

        if (empty($upSql)) {
            $results[] = ['file' => $name, 'status' => 'skipped', 'reason' => 'No UP section'];
            continue;
        }

        try {
            $pdo->beginTransaction();
            // Execute each statement
            $statements = array_filter(array_map('trim', explode(';', $upSql)), function ($s) {
                return $s !== '';
            });
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }
            $stmt = $pdo->prepare('INSERT INTO migrations (migration_name, applied_at) VALUES (?, NOW())');
            $stmt->execute([$name]);
            $pdo->commit();
            $results[] = ['file' => $name, 'status' => 'applied'];
        } catch (Exception $e) {
            $pdo->rollBack();
            $results[] = ['file' => $name, 'status' => 'error', 'message' => $e->getMessage()];
            // Stop on first error
            break;
        }
    }

    echo json_encode([
        'action' => 'up',
        'results' => $results,
        'applied_count' => count(array_filter($results, function ($r) {
            return $r['status'] === 'applied';
        })),
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
