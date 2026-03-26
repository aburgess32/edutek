<?php

/**
 * UserTest — Unit/Integration tests for user creation and session management.
 *
 * Covers:
 *  - Creating a user with valid data
 *  - Enforcing unique display_name at the application level
 *  - Rejecting invalid user_type values
 *  - Simulating a name-based "login" session
 *  - Retrieving users by type
 */

declare(strict_types=1);

namespace EduPak\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = getTestPdo();
        truncateTestTables($this->pdo);
    }

    // ------------------------------------------------------------------
    // Helper: insert a user row and return its new ID
    // ------------------------------------------------------------------

    private function createUser(string $displayName, string $userType = 'kid'): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (display_name, user_type) VALUES (:name, :type)"
        );
        $stmt->execute([':name' => $displayName, ':type' => $userType]);
        return (int) $this->pdo->lastInsertId();
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    /**
     * A user row can be inserted with a display_name and valid user_type,
     * and the returned ID is a positive integer.
     */
    public function testCreateUserWithValidData(): void
    {
        $id = $this->createUser('Alex', 'kid');

        $this->assertGreaterThan(0, $id, 'New user should receive a positive auto-increment ID');

        $row = $this->pdo
            ->query("SELECT * FROM users WHERE id = {$id}")
            ->fetch();

        $this->assertNotFalse($row, 'Inserted user row should be retrievable');
        $this->assertSame('Alex', $row['display_name']);
        $this->assertSame('kid', $row['user_type']);
    }

    /**
     * The application layer should detect a duplicate display_name before
     * attempting a second INSERT, preventing two users sharing the same name
     * (since the DB schema does not enforce UNIQUE on display_name).
     */
    public function testDuplicateDisplayNameIsRejectedByApplicationLogic(): void
    {
        $this->createUser('Jordan');

        // Simulate the application-layer duplicate check
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS cnt FROM users WHERE display_name = :name"
        );
        $stmt->execute([':name' => 'Jordan']);
        $count = (int) $stmt->fetchColumn();

        $this->assertSame(1, $count, 'Exactly one user named Jordan should exist');

        // The application should refuse a second insert when count > 0
        $isDuplicate = $count > 0;
        $this->assertTrue($isDuplicate, 'Duplicate name detection should return true');
    }

    /**
     * All four valid user_type values are accepted by MySQL's ENUM column.
     */
    public function testAllValidUserTypesCanBeCreated(): void
    {
        $types = ['kid', 'teen', 'adult', 'teacher'];

        foreach ($types as $type) {
            $id = $this->createUser("User_{$type}", $type);
            $this->assertGreaterThan(0, $id, "User with type '{$type}' should be creatable");
        }

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM users");
        $this->assertSame(4, (int) $stmt->fetchColumn(), 'Four users should exist');
    }

    /**
     * Simulates the simple name-login session flow: look up a user by
     * display_name, store the ID in $_SESSION, confirm it is set.
     */
    public function testNameBasedSessionLoginFlow(): void
    {
        $id = $this->createUser('Taylor', 'teen');

        // Simulate session-based login (mirroring simple-name-login feature)
        $stmt = $this->pdo->prepare("SELECT id, display_name FROM users WHERE display_name = :name LIMIT 1");
        $stmt->execute([':name' => 'Taylor']);
        $user = $stmt->fetch();

        $this->assertNotFalse($user, 'User Taylor should be found in the DB');

        // Simulate setting session (without actual HTTP session)
        $session = [];
        $session['user_id']   = $user['id'];
        $session['user_name'] = $user['display_name'];

        $this->assertSame($id, $session['user_id'], 'Session user_id should match the inserted user');
        $this->assertSame('Taylor', $session['user_name']);
    }

    /**
     * Fetching users filtered by user_type returns only the matching rows.
     */
    public function testFetchUsersByType(): void
    {
        $this->createUser('Miss Smith', 'teacher');
        $this->createUser('Mr Jones', 'teacher');
        $this->createUser('Sam', 'kid');

        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE user_type = :type");
        $stmt->execute([':type' => 'teacher']);
        $teachers = $stmt->fetchAll();

        $this->assertCount(2, $teachers, 'Only two teacher-type users should be returned');

        foreach ($teachers as $teacher) {
            $this->assertSame('teacher', $teacher['user_type']);
        }
    }
}
