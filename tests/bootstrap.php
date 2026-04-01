<?php

/**
 * PHPUnit Bootstrap File
 *
 * Sets up the test database connection and loads application config
 * for the EduPak test suite.
 */

declare(strict_types=1);

// Load Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// ---------------------------------------------------------------
// Test environment constants
// Override production config.php with test-safe values.
// These can also be set via environment variables (e.g. CI/CD).
// ---------------------------------------------------------------
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'edupak_test');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('SITE_TITLE', 'EduPak Learning Hub (Test)');
define('CONTENT_PATH', '/content/');
define('APP_ENV', 'testing');

// ---------------------------------------------------------------
// Test database connection helper
// ---------------------------------------------------------------

/**
 * Returns a PDO connection to the test database.
 * Creates the test database and schema if it does not exist.
 *
 * @throws \RuntimeException if connection fails
 * @return \PDO
 */
function getTestPdo(): \PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $host = DB_HOST;
    $user = DB_USER;
    $pass = DB_PASS;
    $dbName = DB_NAME;

    try {
        // Connect without selecting a database first so we can create it
        $pdo = new \PDO(
            "mysql:host={$host};charset=utf8mb4",
            $user,
            $pass,
            [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );

        // Create test DB if it doesn't exist
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$dbName}`");

        // Apply schema
        applyTestSchema($pdo);
    } catch (\PDOException $e) {
        throw new \RuntimeException(
            'EduPak test DB connection failed: ' . $e->getMessage() . "\n" .
            "Ensure XAMPP/MySQL is running and credentials are correct.\n" .
            "DB: {$dbName}@{$host} (user: {$user})",
            (int) $e->getCode(),
            $e
        );
    }

    return $pdo;
}

/**
 * Applies the EduPak schema to the test database.
 * Drops existing tables to ensure a clean slate on each test run.
 *
 * @param \PDO $pdo
 * @return void
 */
function applyTestSchema(\PDO $pdo): void
{
    // Disable FK checks during teardown/setup
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    $pdo->exec('DROP TABLE IF EXISTS lesson_plans');
    $pdo->exec('DROP TABLE IF EXISTS content_meta');
    $pdo->exec('DROP TABLE IF EXISTS watch_history');
    $pdo->exec('DROP TABLE IF EXISTS users');

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            display_name VARCHAR(100) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            password_hash VARCHAR(255) DEFAULT NULL,
            avatar_name VARCHAR(100) DEFAULT NULL,
            avatar_color VARCHAR(20) DEFAULT NULL,
            user_type ENUM('kid', 'teen', 'adult', 'teacher') DEFAULT 'kid',
            age_range VARCHAR(20) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_email (email)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS watch_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            content_id VARCHAR(255) NOT NULL,
            content_title VARCHAR(500),
            content_type VARCHAR(50),
            thumbnail_path VARCHAR(500),
            progress_seconds INT DEFAULT 0,
            duration_seconds INT DEFAULT 0,
            last_watched TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_last (user_id, last_watched DESC)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS content_meta (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            content_id       VARCHAR(255)  NOT NULL UNIQUE,
            title            VARCHAR(500)  NOT NULL,
            description      TEXT,
            subject          VARCHAR(100),
            grade_level      VARCHAR(50),
            content_type     VARCHAR(50),
            category         VARCHAR(100) DEFAULT '',
            subcategory      VARCHAR(100) DEFAULT '',
            source           VARCHAR(100) DEFAULT '',
            file_path        VARCHAR(1000) NOT NULL,
            thumbnail_path   VARCHAR(1000),
            duration_seconds INT           DEFAULT NULL,
            language         VARCHAR(10)   DEFAULT 'en',
            created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_subject_grade (subject, grade_level),
            INDEX idx_subject      (subject),
            INDEX idx_grade_level  (grade_level),
            INDEX idx_content_type (content_type),
            INDEX idx_language     (language),
            FULLTEXT ft_search (title, category, subcategory, source)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lesson_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT,
            title VARCHAR(255) NOT NULL,
            content_ids JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
}

/**
 * Wipes all rows from test tables between tests.
 * Call in setUp() to guarantee isolation.
 *
 * @param \PDO $pdo
 * @return void
 */
function truncateTestTables(\PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('TRUNCATE TABLE lesson_plans');
    $pdo->exec('TRUNCATE TABLE content_meta');
    $pdo->exec('TRUNCATE TABLE watch_history');
    $pdo->exec('TRUNCATE TABLE users');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}
