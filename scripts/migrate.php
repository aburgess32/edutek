#!/usr/bin/env php
<?php
/**
 * EduPak Database Migration Runner
 *
 * A simple, zero-dependency migration runner for offline XAMPP deployments.
 *
 * Migration files live in db/migrations/ and are named:
 *   NNNN_description.sql   (e.g. 0001_initial_schema.sql)
 *
 * Each file must contain:
 *   -- UP
 *   ... forward migration SQL ...
 *   -- DOWN
 *   ... rollback SQL ...
 *
 * Usage:
 *   php scripts/migrate.php up            Apply all pending migrations
 *   php scripts/migrate.php status        Show applied / pending migrations
 *   php scripts/migrate.php rollback      Undo the last applied migration
 *   php scripts/migrate.php rollback N    Undo the last N migrations
 *
 * @package EduPak
 * @version 1.0.0
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────────

define('PROJECT_ROOT', dirname(__DIR__));
define('MIGRATIONS_DIR', PROJECT_ROOT . '/db/migrations');
define('ENV_FILE', PROJECT_ROOT . '/.env');
define('MIGRATIONS_TABLE', 'migrations');

// Load .env if available
if (file_exists(ENV_FILE)) {
    require_once PROJECT_ROOT . '/htdocs/includes/env.php';
}

// Establish DB connection
$pdo = db_connect();

// Ensure the migrations tracking table exists
ensure_migrations_table($pdo);

// ─────────────────────────────────────────────────────────────────────────────
// CLI Dispatcher
// ─────────────────────────────────────────────────────────────────────────────

$command = $argv[1] ?? 'help';
$arg2    = $argv[2] ?? null;

match ($command) {
    'up'       => cmd_up($pdo),
    'status'   => cmd_status($pdo),
    'rollback' => cmd_rollback($pdo, (int) ($arg2 ?? 1)),
    default    => cmd_help(),
};

// ─────────────────────────────────────────────────────────────────────────────
// Commands
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Apply all pending migrations in ascending order.
 */
function cmd_up(PDO $pdo): void
{
    $pending = get_pending_migrations($pdo);

    if (empty($pending)) {
        output('✓ No pending migrations — database is up to date.', 'green');
        return;
    }

    output(sprintf('Applying %d migration(s)...', count($pending)));

    foreach ($pending as $file) {
        apply_migration($pdo, $file);
    }

    output('✓ All migrations applied.', 'green');
}

/**
 * Display migration status: applied vs pending.
 */
function cmd_status(PDO $pdo): void
{
    $all     = get_all_migration_files();
    $applied = get_applied_migrations($pdo);

    $appliedSet = array_flip(array_column($applied, 'migration_name'));

    output(str_pad('MIGRATION FILE', 45) . str_pad('STATUS', 12) . 'APPLIED AT');
    output(str_repeat('─', 80));

    foreach ($all as $file) {
        $name   = basename($file);
        $status = isset($appliedSet[$name]) ? 'applied' : 'pending';
        $colour = $status === 'applied' ? 'green' : 'yellow';
        $at     = $appliedSet[$name] ?? '—';

        output(
            str_pad($name, 45) .
            str_pad($status, 12) .
            (is_string($at) ? $at : ($applied[array_search($name, array_column($applied, 'migration_name'))]['applied_at'] ?? '')),
            $colour
        );
    }

    output(str_repeat('─', 80));
    output(sprintf(
        'Total: %d applied, %d pending',
        count($applied),
        count($all) - count($applied)
    ));
}

/**
 * Roll back the last N applied migrations.
 *
 * @param PDO $pdo
 * @param int $count Number of migrations to roll back.
 */
function cmd_rollback(PDO $pdo, int $count = 1): void
{
    if ($count < 1) {
        $count = 1;
    }

    $applied = get_applied_migrations($pdo);

    if (empty($applied)) {
        output('No migrations to roll back.', 'yellow');
        return;
    }

    // Roll back in reverse order (newest first)
    $toRollback = array_slice(array_reverse($applied), 0, $count);

    output(sprintf('Rolling back %d migration(s)...', count($toRollback)));

    foreach ($toRollback as $row) {
        $file = MIGRATIONS_DIR . '/' . $row['migration_name'];
        if (!file_exists($file)) {
            output("  [!] File not found: {$row['migration_name']} — skipping.", 'red');
            continue;
        }
        rollback_migration($pdo, $file, $row['migration_name']);
    }

    output('✓ Rollback complete.', 'green');
}

/**
 * Print usage information.
 */
function cmd_help(): void
{
    echo <<<HELP

EduPak Migration Runner
-----------------------
Usage:
  php scripts/migrate.php up              Apply all pending migrations
  php scripts/migrate.php status          Show applied / pending migrations
  php scripts/migrate.php rollback        Undo the last migration
  php scripts/migrate.php rollback N      Undo the last N migrations

Migration files live in: db/migrations/NNNN_description.sql

Each file must contain both -- UP and -- DOWN sections.

HELP;
    exit(0);
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration Execution
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Apply a single migration file (run the UP section).
 *
 * @param PDO    $pdo      Database connection.
 * @param string $filePath Absolute path to the .sql file.
 */
function apply_migration(PDO $pdo, string $filePath): void
{
    $name = basename($filePath);
    output("  → Applying: {$name}");

    [$up] = parse_migration($filePath);

    if (empty(trim($up))) {
        output("  [!] No UP section found in {$name}", 'red');
        return;
    }

    try {
        $pdo->beginTransaction();
        execute_sql_block($pdo, $up);
        $stmt = $pdo->prepare(
            'INSERT INTO ' . MIGRATIONS_TABLE . ' (migration_name, applied_at) VALUES (?, NOW())'
        );
        $stmt->execute([$name]);
        $pdo->commit();
        output("  ✓ Applied: {$name}", 'green');
    } catch (Throwable $e) {
        $pdo->rollBack();
        output("  ✗ FAILED: {$name} — " . $e->getMessage(), 'red');
        exit(1);
    }
}

/**
 * Roll back a single migration file (run the DOWN section).
 *
 * @param PDO    $pdo  Database connection.
 * @param string $filePath Absolute path to the .sql file.
 * @param string $name Migration name as stored in the DB.
 */
function rollback_migration(PDO $pdo, string $filePath, string $name): void
{
    output("  ← Rolling back: {$name}");

    [, $down] = parse_migration($filePath);

    if (empty(trim($down))) {
        output("  [!] No DOWN section found in {$name}", 'red');
        return;
    }

    try {
        $pdo->beginTransaction();
        execute_sql_block($pdo, $down);
        $stmt = $pdo->prepare('DELETE FROM ' . MIGRATIONS_TABLE . ' WHERE migration_name = ?');
        $stmt->execute([$name]);
        $pdo->commit();
        output("  ✓ Rolled back: {$name}", 'green');
    } catch (Throwable $e) {
        $pdo->rollBack();
        output("  ✗ FAILED rollback: {$name} — " . $e->getMessage(), 'red');
        exit(1);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SQL Helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Parse a migration file into its UP and DOWN SQL blocks.
 *
 * @param  string   $filePath Absolute path to the .sql file.
 * @return string[] Array of [upSql, downSql].
 */
function parse_migration(string $filePath): array
{
    $content = file_get_contents($filePath);
    if ($content === false) {
        throw new RuntimeException("Cannot read migration file: {$filePath}");
    }

    $upSql   = '';
    $downSql = '';

    // Split on -- DOWN marker (case-insensitive)
    if (preg_match('/^--\s*UP\s*$(.*?)^--\s*DOWN\s*$(.*)/ims', $content, $m)) {
        $upSql   = trim($m[1]);
        $downSql = trim($m[2]);
    } elseif (preg_match('/^--\s*UP\s*$(.*)/ims', $content, $m)) {
        // UP only — no DOWN section
        $upSql = trim($m[1]);
    }

    return [$upSql, $downSql];
}

/**
 * Execute a block of SQL statements separated by semicolons.
 *
 * @param PDO    $pdo   Database connection.
 * @param string $sql   One or more SQL statements.
 */
function execute_sql_block(PDO $pdo, string $sql): void
{
    // Split on semicolons not inside strings (simple split adequate for migrations)
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn(string $s): bool => $s !== ''
    );

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Database & Migration State
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Create a PDO connection using env vars or config constants.
 *
 * @return PDO
 */
function db_connect(): PDO
{
    $host = getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : 'localhost');
    $name = getenv('DB_NAME') ?: (defined('DB_NAME') ? DB_NAME : 'edupak');
    $user = getenv('DB_USER') ?: (defined('DB_USER') ? DB_USER : 'root');
    $pass = getenv('DB_PASS') ?: (defined('DB_PASS') ? DB_PASS : '');
    $port = getenv('DB_PORT') ?: (defined('DB_PORT') ? DB_PORT : '3306');

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        output('✗ Database connection failed: ' . $e->getMessage(), 'red');
        exit(1);
    }
}

/**
 * Create the migrations tracking table if it does not exist.
 *
 * @param PDO $pdo
 */
function ensure_migrations_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS ' . MIGRATIONS_TABLE . ' (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            applied_at     DATETIME     NOT NULL,
            INDEX idx_name (migration_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
}

/**
 * Return all migration .sql files sorted by name (ascending).
 *
 * @return string[] Absolute file paths.
 */
function get_all_migration_files(): array
{
    $files = glob(MIGRATIONS_DIR . '/[0-9][0-9][0-9][0-9]_*.sql');
    if ($files === false) {
        return [];
    }
    sort($files);
    return $files;
}

/**
 * Return migration files that have NOT yet been applied.
 *
 * @param  PDO      $pdo
 * @return string[] Absolute file paths of pending migrations.
 */
function get_pending_migrations(PDO $pdo): array
{
    $all     = get_all_migration_files();
    $applied = get_applied_migrations($pdo);

    $appliedNames = array_column($applied, 'migration_name');

    return array_filter(
        $all,
        fn(string $f): bool => !in_array(basename($f), $appliedNames, true)
    );
}

/**
 * Return all applied migrations from the DB, ordered by application time.
 *
 * @param  PDO                       $pdo
 * @return array<int, array<string, mixed>>
 */
function get_applied_migrations(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT migration_name, applied_at FROM ' . MIGRATIONS_TABLE . ' ORDER BY applied_at ASC, migration_name ASC');
    return $stmt ? $stmt->fetchAll() : [];
}

// ─────────────────────────────────────────────────────────────────────────────
// Output Helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Print a coloured line to stdout.
 *
 * @param string $text   The message to print.
 * @param string $colour One of: green, yellow, red, or empty for default.
 */
function output(string $text, string $colour = ''): void
{
    $codes = ['green' => "\033[32m", 'yellow' => "\033[33m", 'red' => "\033[31m"];
    $reset = "\033[0m";

    if ($colour && isset($codes[$colour]) && posix_isatty(STDOUT)) {
        echo $codes[$colour] . $text . $reset . PHP_EOL;
    } else {
        echo $text . PHP_EOL;
    }
}
