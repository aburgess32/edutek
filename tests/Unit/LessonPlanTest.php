<?php

/**
 * LessonPlanTest — Unit/Integration tests for lesson plan CRUD.
 *
 * Covers:
 *  - Creating a lesson plan linked to a teacher
 *  - Reading a lesson plan by ID
 *  - Updating a lesson plan title and content_ids
 *  - Deleting a lesson plan
 *  - Cascade delete when the owning teacher is removed
 */

declare(strict_types=1);

namespace EduPak\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

class LessonPlanTest extends TestCase
{
    private PDO $pdo;
    private int $teacherId;

    protected function setUp(): void
    {
        $this->pdo = getTestPdo();
        truncateTestTables($this->pdo);

        // Create a teacher user to own the lesson plans
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (display_name, user_type) VALUES ('Ms. Rivera', 'teacher')"
        );
        $stmt->execute();
        $this->teacherId = (int) $this->pdo->lastInsertId();
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function createLessonPlan(string $title, array $contentIds = []): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO lesson_plans (teacher_id, title, content_ids)
            VALUES (:tid, :title, :cids)
        ");
        $stmt->execute([
            ':tid'   => $this->teacherId,
            ':title' => $title,
            ':cids'  => json_encode($contentIds),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    /**
     * A lesson plan is created with a title and JSON content_ids,
     * and all fields are persisted correctly.
     */
    public function testCreateLessonPlanPersistsAllFields(): void
    {
        $contentIds = ['video-001', 'video-002', 'video-003'];
        $id = $this->createLessonPlan('Introduction to Fractions', $contentIds);

        $this->assertGreaterThan(0, $id);

        $row = $this->pdo
            ->query("SELECT * FROM lesson_plans WHERE id = {$id}")
            ->fetch();

        $this->assertNotFalse($row);
        $this->assertSame('Introduction to Fractions', $row['title']);
        $this->assertSame($this->teacherId, (int) $row['teacher_id']);

        $decoded = json_decode($row['content_ids'], true);
        $this->assertSame($contentIds, $decoded, 'content_ids JSON should decode to the original array');
    }

    /**
     * A lesson plan can be retrieved by its ID.
     */
    public function testReadLessonPlanById(): void
    {
        $id = $this->createLessonPlan('Solar System Overview', ['video-sol-1', 'video-sol-2']);

        $stmt = $this->pdo->prepare("SELECT * FROM lesson_plans WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        $this->assertNotFalse($row, 'Lesson plan should be retrievable by ID');
        $this->assertSame('Solar System Overview', $row['title']);
    }

    /**
     * A lesson plan's title and content list can be updated.
     */
    public function testUpdateLessonPlanTitleAndContentIds(): void
    {
        $id = $this->createLessonPlan('Draft Plan', ['video-A']);

        $newTitle      = 'Revised: Ecosystems Unit';
        $newContentIds = ['video-eco-1', 'video-eco-2', 'video-eco-3'];

        $stmt = $this->pdo->prepare("
            UPDATE lesson_plans
            SET title = :title, content_ids = :cids
            WHERE id = :id
        ");
        $stmt->execute([
            ':title' => $newTitle,
            ':cids'  => json_encode($newContentIds),
            ':id'    => $id,
        ]);

        $row = $this->pdo->query("SELECT * FROM lesson_plans WHERE id = {$id}")->fetch();

        $this->assertSame($newTitle, $row['title'], 'Title should be updated');
        $this->assertSame($newContentIds, json_decode($row['content_ids'], true), 'content_ids should be updated');
    }

    /**
     * A lesson plan can be deleted, and it no longer appears in query results.
     */
    public function testDeleteLessonPlan(): void
    {
        $id = $this->createLessonPlan('Plan to Delete');

        $this->pdo->prepare("DELETE FROM lesson_plans WHERE id = :id")->execute([':id' => $id]);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM lesson_plans WHERE id = :id");
        $stmt->execute([':id' => $id]);

        $this->assertSame(0, (int) $stmt->fetchColumn(), 'Deleted lesson plan should not exist');
    }

    /**
     * Deleting the owning teacher cascades to remove their lesson plans.
     */
    public function testLessonPlansAreCascadeDeletedWithTeacher(): void
    {
        $this->createLessonPlan('Plan A', ['video-1']);
        $this->createLessonPlan('Plan B', ['video-2']);

        $this->pdo->exec("DELETE FROM users WHERE id = {$this->teacherId}");

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM lesson_plans WHERE teacher_id = :tid");
        $stmt->execute([':tid' => $this->teacherId]);

        $this->assertSame(0, (int) $stmt->fetchColumn(), 'Lesson plans should cascade-delete with teacher');
    }
}
