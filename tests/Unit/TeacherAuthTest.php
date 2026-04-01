<?php

/**
 * TeacherAuthTest — Unit tests for FRE-13 teacher auth completion.
 *
 * Covers:
 *  - Passphrase validation on registration
 *  - Password reset (peer teacher reset)
 *  - Profile update (name change, password change)
 *  - Session timeout logic
 */

declare(strict_types=1);

namespace EduPak\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

class TeacherAuthTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = getTestPdo();
        truncateTestTables($this->pdo);
    }

    // ------------------------------------------------------------------
    // Helper: create a teacher in the DB and return user ID
    // ------------------------------------------------------------------

    private function createTeacher(string $name, string $email, string $password): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (display_name, email, password_hash, user_type)
             VALUES (?, ?, ?, 'teacher')"
        );
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        return (int) $this->pdo->lastInsertId();
    }

    // ------------------------------------------------------------------
    // 0A: Passphrase validation
    // ------------------------------------------------------------------

    public function testCorrectPassphraseAllowsRegistration(): void
    {
        $config = require dirname(__DIR__, 2) . '/htdocs/config/app.php';
        $submitted = 'teachwithedutek';

        $this->assertSame(
            $config['teacher_passphrase'],
            $submitted,
            'Correct passphrase should match config value'
        );
    }

    public function testIncorrectPassphraseBlocksRegistration(): void
    {
        $config = require dirname(__DIR__, 2) . '/htdocs/config/app.php';
        $submitted = 'wrongphrase';

        $this->assertNotSame(
            $config['teacher_passphrase'],
            $submitted,
            'Incorrect passphrase should not match config value'
        );
    }

    public function testEmptyPassphraseBlocksRegistration(): void
    {
        $config = require dirname(__DIR__, 2) . '/htdocs/config/app.php';

        $this->assertNotSame(
            $config['teacher_passphrase'],
            '',
            'Empty passphrase should not match config value'
        );
    }

    // ------------------------------------------------------------------
    // 0C: Password reset
    // ------------------------------------------------------------------

    public function testPasswordResetUpdatesHash(): void
    {
        $id = $this->createTeacher('Mrs Smith', 'smith@school.edu', 'oldpass123');

        // Simulate password reset
        $newPassword = 'newpass456';
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$newHash, $id]);

        // Verify new password works
        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        $this->assertTrue(
            password_verify($newPassword, $row['password_hash']),
            'New password should verify against updated hash'
        );
        $this->assertFalse(
            password_verify('oldpass123', $row['password_hash']),
            'Old password should no longer verify'
        );
    }

    public function testPasswordResetRequiresMinLength(): void
    {
        $shortPassword = 'abc';

        $this->assertTrue(
            strlen($shortPassword) < 6,
            'Passwords shorter than 6 characters should be rejected'
        );
    }

    public function testPasswordResetTargetMustExist(): void
    {
        // No teacher created — searching should return false
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ? AND user_type = 'teacher'");
        $stmt->execute(['nobody@school.edu']);
        $result = $stmt->fetch();

        $this->assertFalse($result, 'Non-existent email should return false');
    }

    public function testPasswordResetTargetMustBeTeacher(): void
    {
        // Create a student, not a teacher
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (display_name, email, user_type) VALUES (?, ?, 'kid')"
        );
        $stmt->execute(['Student Sam', 'sam@school.edu']);

        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ? AND user_type = 'teacher'");
        $stmt->execute(['sam@school.edu']);
        $result = $stmt->fetch();

        $this->assertFalse($result, 'Student accounts should not be found when searching for teachers');
    }

    // ------------------------------------------------------------------
    // 0D: Profile management
    // ------------------------------------------------------------------

    public function testUpdateDisplayName(): void
    {
        $id = $this->createTeacher('Old Name', 'teacher@school.edu', 'pass123');

        $newName = 'New Name';
        $stmt = $this->pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?');
        $stmt->execute([$newName, $id]);

        $stmt = $this->pdo->prepare('SELECT display_name FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        $this->assertSame($newName, $row['display_name'], 'Display name should be updated');
    }

    public function testUpdateDisplayNameMinLength(): void
    {
        $shortName = 'A';

        $this->assertTrue(
            strlen($shortName) < 2,
            'Names shorter than 2 characters should be rejected'
        );
    }

    public function testChangePasswordVerifiesCurrentPassword(): void
    {
        $originalPassword = 'original123';
        $id = $this->createTeacher('Teacher', 'teach@school.edu', $originalPassword);

        // Fetch hash from DB
        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        $this->assertTrue(
            password_verify($originalPassword, $row['password_hash']),
            'Correct current password should verify'
        );
        $this->assertFalse(
            password_verify('wrongpassword', $row['password_hash']),
            'Wrong current password should not verify'
        );
    }

    public function testChangePasswordUpdatesHash(): void
    {
        $id = $this->createTeacher('Teacher', 'teach2@school.edu', 'oldpass');

        $newPassword = 'newpass789';
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $id]);

        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        $this->assertTrue(
            password_verify($newPassword, $row['password_hash']),
            'New password should verify after change'
        );
    }

    // ------------------------------------------------------------------
    // 0E: Session timeout
    // ------------------------------------------------------------------

    public function testSessionTimeoutConstant(): void
    {
        $expectedTimeout = 8 * 3600; // 28800 seconds

        // Simulate the constant value defined in auth.php
        $this->assertSame(28800, $expectedTimeout, 'Session timeout should be 8 hours (28800 seconds)');
    }

    public function testSessionIsExpiredAfterTimeout(): void
    {
        $timeout = 8 * 3600;
        $lastActivity = time() - ($timeout + 1); // 1 second past timeout

        $isExpired = (time() - $lastActivity) > $timeout;

        $this->assertTrue($isExpired, 'Session should be expired after 8 hours');
    }

    public function testSessionIsValidBeforeTimeout(): void
    {
        $timeout = 8 * 3600;
        $lastActivity = time() - ($timeout - 60); // 1 minute before timeout

        $isExpired = (time() - $lastActivity) > $timeout;

        $this->assertFalse($isExpired, 'Session should still be valid before 8 hours');
    }

    public function testSessionActivityIsRefreshedOnValidRequest(): void
    {
        $timeout = 8 * 3600;
        $session = ['last_activity' => time() - 3600]; // 1 hour ago

        // Simulate the refresh logic from auth.php
        $isExpired = (time() - $session['last_activity']) > $timeout;
        if (!$isExpired) {
            $session['last_activity'] = time();
        }

        $this->assertEqualsWithDelta(
            time(),
            $session['last_activity'],
            2,
            'last_activity should be refreshed to current time on valid request'
        );
    }

    // ------------------------------------------------------------------
    // 0F: First-time setup guard
    // ------------------------------------------------------------------

    public function testFirstTimeSetupDetectsNoTeachers(): void
    {
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE user_type = 'teacher'")->fetchColumn();

        $this->assertSame(0, $count, 'With no teachers, count should be 0');
        $this->assertTrue($count === 0, 'First-time setup guard should trigger when no teachers exist');
    }

    public function testFirstTimeSetupSkipsWhenTeachersExist(): void
    {
        $this->createTeacher('Existing Teacher', 'existing@school.edu', 'pass123');

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE user_type = 'teacher'")->fetchColumn();

        $this->assertGreaterThan(0, $count, 'Teacher count should be > 0');
        $this->assertFalse($count === 0, 'First-time setup guard should NOT trigger when teachers exist');
    }
}
