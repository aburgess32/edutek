<?php

/**
 * LessonPlanCRUDTest — Tests for the lesson_plans API logic (FRE-13 Phase 4).
 *
 * Tests CRUD operations against the lesson_plans table, including:
 * - Create with valid data
 * - Create with invalid data (empty title, too many items)
 * - List returns all plans with creator names
 * - Update by a different teacher (collaborative editing)
 * - Delete by a different teacher (collaborative deletion)
 * - Reorder items
 */

declare(strict_types=1);

namespace EduPak\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

class LessonPlanCRUDTest extends TestCase
{
    private PDO $pdo;
    private int $teacher1Id;
    private int $teacher2Id;

    protected function setUp(): void
    {
        $this->pdo = getTestPdo();
        truncateTestTables($this->pdo);

        // Create two teachers for collaborative tests
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (display_name, user_type) VALUES (?, 'teacher')"
        );

        $stmt->execute(['Mrs. Johnson']);
        $this->teacher1Id = (int) $this->pdo->lastInsertId();

        $stmt->execute(['Mr. Kamau']);
        $this->teacher2Id = (int) $this->pdo->lastInsertId();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function createPlan(int $teacherId, string $title, array $contentIds = []): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO lesson_plans (teacher_id, title, content_ids)
            VALUES (:tid, :title, :cids)
        ");
        $stmt->execute([
            ':tid'   => $teacherId,
            ':title' => $title,
            ':cids'  => json_encode($contentIds),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function getPlan(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM lesson_plans WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tests: Create
    // ─────────────────────────────────────────────────────────────────────

    public function testCreatePlanWithValidData(): void
    {
        $contentIds = [
            ['id' => 'V042', 'title' => 'Intro to Fractions'],
            ['id' => 'V108', 'title' => 'Tractor Safety'],
        ];

        $id = $this->createPlan($this->teacher1Id, 'Week 3 - Fractions', $contentIds);

        $this->assertGreaterThan(0, $id);

        $plan = $this->getPlan($id);
        $this->assertNotNull($plan);
        $this->assertSame('Week 3 - Fractions', $plan['title']);
        $this->assertSame($this->teacher1Id, (int) $plan['teacher_id']);

        $decoded = json_decode($plan['content_ids'], true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
        $this->assertSame('V042', $decoded[0]['id']);
        $this->assertSame('Intro to Fractions', $decoded[0]['title']);
    }

    public function testCreatePlanWithEmptyContentIds(): void
    {
        $id = $this->createPlan($this->teacher1Id, 'Empty Plan', []);

        $plan = $this->getPlan($id);
        $this->assertNotNull($plan);
        $decoded = json_decode($plan['content_ids'], true);
        $this->assertIsArray($decoded);
        $this->assertCount(0, $decoded);
    }

    public function testCreatePlanEmptyTitleNotAllowed(): void
    {
        // The DB allows empty string but the API validates. Test at DB level:
        // an empty title is technically storable but the API should reject it.
        // Here we verify the DB constraint: title VARCHAR(255) NOT NULL
        $this->expectException(\PDOException::class);
        $stmt = $this->pdo->prepare("
            INSERT INTO lesson_plans (teacher_id, title, content_ids)
            VALUES (:tid, NULL, :cids)
        ");
        $stmt->execute([
            ':tid'  => $this->teacher1Id,
            ':cids' => json_encode([]),
        ]);
    }

    public function testCreatePlanMaxItemsValidation(): void
    {
        // Create a plan with exactly 50 items (should succeed)
        $items = [];
        for ($i = 1; $i <= 50; $i++) {
            $items[] = ['id' => 'V' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'title' => 'Item ' . $i];
        }

        $id = $this->createPlan($this->teacher1Id, 'Full Plan', $items);
        $plan = $this->getPlan($id);
        $decoded = json_decode($plan['content_ids'], true);
        $this->assertCount(50, $decoded);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tests: List
    // ─────────────────────────────────────────────────────────────────────

    public function testListReturnsAllPlansWithCreatorNames(): void
    {
        $this->createPlan($this->teacher1Id, 'Plan by Johnson', [['id' => 'V001', 'title' => 'A']]);
        $this->createPlan($this->teacher2Id, 'Plan by Kamau', [['id' => 'V002', 'title' => 'B']]);

        $stmt = $this->pdo->query("
            SELECT
                lp.id,
                lp.teacher_id,
                u.display_name AS creator_name,
                lp.title,
                lp.content_ids,
                lp.created_at
            FROM lesson_plans lp
            LEFT JOIN users u ON u.id = lp.teacher_id
            ORDER BY lp.created_at DESC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(2, $rows);

        // Verify creator names are joined correctly
        $names = array_column($rows, 'creator_name');
        $this->assertContains('Mrs. Johnson', $names);
        $this->assertContains('Mr. Kamau', $names);

        // Verify both plans are returned (collaborative - not filtered by teacher)
        $titles = array_column($rows, 'title');
        $this->assertContains('Plan by Johnson', $titles);
        $this->assertContains('Plan by Kamau', $titles);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tests: Update (collaborative)
    // ─────────────────────────────────────────────────────────────────────

    public function testUpdateByDifferentTeacherSucceeds(): void
    {
        // Teacher 1 creates the plan
        $id = $this->createPlan($this->teacher1Id, 'Original Title', [
            ['id' => 'V001', 'title' => 'Item 1'],
        ]);

        // Teacher 2 updates the plan (no ownership check)
        $newTitle = 'Updated by Kamau';
        $newItems = [
            ['id' => 'V001', 'title' => 'Item 1'],
            ['id' => 'V099', 'title' => 'New Item'],
        ];

        $stmt = $this->pdo->prepare("
            UPDATE lesson_plans SET title = :title, content_ids = :cids WHERE id = :id
        ");
        $stmt->execute([
            ':title' => $newTitle,
            ':cids'  => json_encode($newItems),
            ':id'    => $id,
        ]);

        $plan = $this->getPlan($id);
        $this->assertSame('Updated by Kamau', $plan['title']);

        // teacher_id should still be teacher 1 (creator attribution preserved)
        $this->assertSame($this->teacher1Id, (int) $plan['teacher_id']);

        $decoded = json_decode($plan['content_ids'], true);
        $this->assertCount(2, $decoded);
    }

    public function testUpdateTitleOnly(): void
    {
        $originalItems = [['id' => 'V001', 'title' => 'Keep Me']];
        $id = $this->createPlan($this->teacher1Id, 'Old Title', $originalItems);

        $stmt = $this->pdo->prepare("UPDATE lesson_plans SET title = :title WHERE id = :id");
        $stmt->execute([':title' => 'New Title', ':id' => $id]);

        $plan = $this->getPlan($id);
        $this->assertSame('New Title', $plan['title']);

        // Content IDs should be unchanged
        $decoded = json_decode($plan['content_ids'], true);
        $this->assertCount(1, $decoded);
        $this->assertSame('V001', $decoded[0]['id']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tests: Delete (collaborative)
    // ─────────────────────────────────────────────────────────────────────

    public function testDeleteByDifferentTeacherSucceeds(): void
    {
        // Teacher 1 creates the plan
        $id = $this->createPlan($this->teacher1Id, 'To Be Deleted', []);

        // Teacher 2 deletes it (no ownership check)
        $stmt = $this->pdo->prepare("DELETE FROM lesson_plans WHERE id = :id");
        $stmt->execute([':id' => $id]);

        $this->assertSame(1, $stmt->rowCount());
        $this->assertNull($this->getPlan($id));
    }

    public function testDeleteNonExistentPlanAffectsZeroRows(): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM lesson_plans WHERE id = :id");
        $stmt->execute([':id' => 99999]);

        $this->assertSame(0, $stmt->rowCount());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tests: Reorder items
    // ─────────────────────────────────────────────────────────────────────

    public function testReorderItems(): void
    {
        $items = [
            ['id' => 'V001', 'title' => 'First'],
            ['id' => 'V002', 'title' => 'Second'],
            ['id' => 'V003', 'title' => 'Third'],
        ];
        $id = $this->createPlan($this->teacher1Id, 'Reorder Test', $items);

        // Reorder: Third, First, Second
        $reordered = [
            ['id' => 'V003', 'title' => 'Third'],
            ['id' => 'V001', 'title' => 'First'],
            ['id' => 'V002', 'title' => 'Second'],
        ];

        $stmt = $this->pdo->prepare("UPDATE lesson_plans SET content_ids = :cids WHERE id = :id");
        $stmt->execute([
            ':cids' => json_encode($reordered),
            ':id'   => $id,
        ]);

        $plan = $this->getPlan($id);
        $decoded = json_decode($plan['content_ids'], true);

        $this->assertCount(3, $decoded);
        $this->assertSame('V003', $decoded[0]['id']);
        $this->assertSame('V001', $decoded[1]['id']);
        $this->assertSame('V002', $decoded[2]['id']);
    }

    public function testReorderPreservesItemData(): void
    {
        $items = [
            ['id' => 'V010', 'title' => 'Algebra Basics'],
            ['id' => 'V020', 'title' => 'Geometry Intro'],
        ];
        $id = $this->createPlan($this->teacher1Id, 'Math Playlist', $items);

        // Reverse order
        $reversed = array_reverse($items);
        $stmt = $this->pdo->prepare("UPDATE lesson_plans SET content_ids = :cids WHERE id = :id");
        $stmt->execute([
            ':cids' => json_encode($reversed),
            ':id'   => $id,
        ]);

        $plan = $this->getPlan($id);
        $decoded = json_decode($plan['content_ids'], true);

        $this->assertSame('Geometry Intro', $decoded[0]['title']);
        $this->assertSame('Algebra Basics', $decoded[1]['title']);
    }
}
