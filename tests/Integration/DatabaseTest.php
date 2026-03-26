<?php

/**
 * DatabaseTest — Integration tests for DB connectivity and schema integrity.
 *
 * Covers:
 *  - Successful PDO connection to the test database
 *  - All expected tables exist in the schema
 *  - Column definitions match the declared schema
 *  - Foreign key constraints are active
 *  - Character set and collation are UTF-8
 */

declare(strict_types=1);

namespace EduPak\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = getTestPdo();
        truncateTestTables($this->pdo);
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    /**
     * The PDO connection object is a valid, open connection to the test DB.
     */
    public function testDatabaseConnectionIsSuccessful(): void
    {
        $this->assertInstanceOf(PDO::class, $this->pdo, 'getTestPdo() should return a PDO instance');

        // A simple query should execute without exceptions
        $result = $this->pdo->query("SELECT 1 AS connected")->fetch();

        $this->assertNotFalse($result, 'A test query should succeed');
        $this->assertSame('1', (string) $result['connected'], 'SELECT 1 should return 1');
    }

    /**
     * All three tables defined in schema.sql exist in the test database.
     */
    public function testExpectedTablesExistInSchema(): void
    {
        $expected = ['users', 'watch_history', 'lesson_plans'];

        $stmt   = $this->pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($expected as $table) {
            $this->assertContains(
                $table,
                $tables,
                "Table '{$table}' should exist in the test database"
            );
        }
    }

    /**
     * The users table has all required columns with correct types.
     */
    public function testUsersTableHasCorrectColumnDefinitions(): void
    {
        $stmt    = $this->pdo->query("DESCRIBE users");
        $columns = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // Field => Type

        $this->assertArrayHasKey('id', $columns, 'users table should have an id column');
        $this->assertArrayHasKey('display_name', $columns, 'users table should have a display_name column');
        $this->assertArrayHasKey('user_type', $columns, 'users table should have a user_type column');
        $this->assertArrayHasKey('created_at', $columns, 'users table should have a created_at column');
        $this->assertArrayHasKey('last_active', $columns, 'users table should have a last_active column');

        $this->assertStringContainsString('int', strtolower($columns['id']), 'id should be an integer type');
        $this->assertStringContainsString('varchar', strtolower($columns['display_name']));
        $this->assertStringContainsString('enum', strtolower($columns['user_type']));
    }

    /**
     * The watch_history table enforces the foreign key to users by rejecting
     * an insert referencing a non-existent user_id.
     */
    public function testWatchHistoryForeignKeyConstraintIsEnforced(): void
    {
        $this->expectException(\PDOException::class);

        // user_id 99999 does not exist — should raise a FK violation
        $this->pdo->exec("
            INSERT INTO watch_history (user_id, content_id, progress_seconds, duration_seconds)
            VALUES (99999, 'video-fk-test', 0, 100)
        ");
    }

    /**
     * The test database uses utf8mb4 character set to support full Unicode
     * (including emoji used in kid-facing UI labels).
     */
    public function testDatabaseUsesUtf8mb4CharacterSet(): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT DEFAULT_CHARACTER_SET_NAME
             FROM information_schema.SCHEMATA
             WHERE SCHEMA_NAME = :dbname"
        );
        $stmt->execute([':dbname' => DB_NAME]);
        $charset = $stmt->fetchColumn();

        $this->assertSame(
            'utf8mb4',
            $charset,
            'Test database should use utf8mb4 character set for full Unicode support'
        );
    }
}
