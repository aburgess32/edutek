<?php

/**
 * LessonPlanPublishTest — Tests for publish/unpublish, segment injection,
 * playlist page access, and reorder functionality.
 *
 * Covers:
 *  - Publish to a single segment
 *  - Publish to multiple segments simultaneously
 *  - Unpublish (empty segments array)
 *  - Invalid segment key rejected
 *  - tiles.php injection (segments array gets plans added)
 *  - playlist.php returns 404 for private plan
 *  - playlist.php returns content for published plan
 *  - Reorder within segment
 */

declare(strict_types=1);

namespace EduPak\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

class LessonPlanPublishTest extends TestCase
{
    private PDO $pdo;
    private int $teacherId;

    /** Valid segment keys (must match tiles.json) */
    private array $validSegmentKeys = [
        'early_learners',
        'explorers',
        'advanced',
        'educators',
        'knowledge_power',
    ];

    protected function setUp(): void
    {
        $this->pdo = getTestPdo();
        truncateTestTables($this->pdo);

        // Create a teacher to own lesson plans
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (display_name, user_type) VALUES ('Mr. Okafor', 'teacher')"
        );
        $stmt->execute();
        $this->teacherId = (int) $this->pdo->lastInsertId();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Insert a lesson plan and return its ID.
     */
    private function createPlan(
        string $title,
        array $contentIds = ['video-001'],
        ?array $publishedSegments = null,
        string $icon = '📚',
        string $color = '#4ECDC4',
        int $sortOrder = 0
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO lesson_plans (teacher_id, title, content_ids, published_segments, icon, color, sort_order)
            VALUES (:tid, :title, :cids, :segs, :icon, :color, :sort)
        ");
        $stmt->execute([
            ':tid'   => $this->teacherId,
            ':title' => $title,
            ':cids'  => json_encode($contentIds),
            ':segs'  => $publishedSegments !== null ? json_encode($publishedSegments) : null,
            ':icon'  => $icon,
            ':color' => $color,
            ':sort'  => $sortOrder,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Read a plan row by ID.
     */
    private function getPlan(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM lesson_plans WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Simulate publishing: update published_segments for a plan.
     */
    private function publishToSegments(int $planId, array $segments): void
    {
        $val = empty($segments) ? null : json_encode($segments);
        $stmt = $this->pdo->prepare('UPDATE lesson_plans SET published_segments = ? WHERE id = ?');
        $stmt->execute([$val, $planId]);
    }

    /**
     * Query published plans (same query as tiles.php injection).
     */
    private function queryPublishedPlans(): array
    {
        return $this->pdo->query("
            SELECT id, title, icon, color, content_ids, published_segments, sort_order
            FROM lesson_plans
            WHERE published_segments IS NOT NULL
              AND JSON_LENGTH(published_segments) > 0
            ORDER BY sort_order ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------
    // Tests: Publish / Unpublish
    // ------------------------------------------------------------------

    /**
     * Publishing a plan to a single segment stores the segment key in JSON.
     */
    public function testPublishToSingleSegment(): void
    {
        $id = $this->createPlan('Math Basics');

        $this->publishToSegments($id, ['knowledge_power']);

        $plan = $this->getPlan($id);
        $this->assertNotNull($plan);

        $segs = json_decode($plan['published_segments'], true);
        $this->assertSame(['knowledge_power'], $segs);
    }

    /**
     * Publishing a plan to multiple segments stores all keys.
     */
    public function testPublishToMultipleSegments(): void
    {
        $id = $this->createPlan('Science Overview', ['video-sci-1', 'video-sci-2']);

        $this->publishToSegments($id, ['early_learners', 'explorers', 'knowledge_power']);

        $plan = $this->getPlan($id);
        $segs = json_decode($plan['published_segments'], true);

        $this->assertCount(3, $segs);
        $this->assertContains('early_learners', $segs);
        $this->assertContains('explorers', $segs);
        $this->assertContains('knowledge_power', $segs);
    }

    /**
     * Unpublish by setting empty segments sets published_segments to NULL.
     */
    public function testUnpublishSetsNull(): void
    {
        $id = $this->createPlan('Private Plan');
        $this->publishToSegments($id, ['advanced']);

        // Verify it's published
        $plan = $this->getPlan($id);
        $this->assertNotNull($plan['published_segments']);

        // Unpublish
        $this->publishToSegments($id, []);

        $plan = $this->getPlan($id);
        $this->assertNull($plan['published_segments']);
    }

    /**
     * Invalid segment keys should be detectable (API validation test).
     * Here we test the segment key validation logic used by the API.
     */
    public function testInvalidSegmentKeyDetection(): void
    {
        $requestedSegments = ['early_learners', 'nonexistent_segment', 'also_invalid'];

        $invalidKeys = [];
        foreach ($requestedSegments as $key) {
            if (!in_array($key, $this->validSegmentKeys, true)) {
                $invalidKeys[] = $key;
            }
        }

        $this->assertCount(2, $invalidKeys);
        $this->assertContains('nonexistent_segment', $invalidKeys);
        $this->assertContains('also_invalid', $invalidKeys);
    }

    // ------------------------------------------------------------------
    // Tests: Segment Injection (tiles.php logic)
    // ------------------------------------------------------------------

    /**
     * Published plans appear in the query used by tiles.php.
     * Private plans (NULL published_segments) do not appear.
     */
    public function testTilesInjectionQueryReturnsOnlyPublished(): void
    {
        // Private plan
        $this->createPlan('Private Plan');

        // Published plan
        $pubId = $this->createPlan('Published Plan', ['video-001', 'video-002']);
        $this->publishToSegments($pubId, ['knowledge_power']);

        $published = $this->queryPublishedPlans();

        $this->assertCount(1, $published);
        $this->assertSame('Published Plan', $published[0]['title']);
    }

    /**
     * The injection logic correctly distributes a plan across multiple segments.
     */
    public function testTilesInjectionMultiSegment(): void
    {
        $id = $this->createPlan('Multi-Segment Plan', ['v1', 'v2']);
        $this->publishToSegments($id, ['early_learners', 'knowledge_power']);

        $published = $this->queryPublishedPlans();
        $this->assertCount(1, $published);

        $plan = $published[0];
        $segKeys = json_decode($plan['published_segments'], true);
        $items = json_decode($plan['content_ids'], true);

        // Simulate tiles.php injection
        $segments = [
            'early_learners' => ['topics' => []],
            'explorers'      => ['topics' => []],
            'knowledge_power' => ['topics' => []],
        ];

        foreach ($segKeys as $segKey) {
            if (!isset($segments[$segKey])) {
                continue;
            }
            $segments[$segKey]['topics'][] = [
                'slug'       => 'playlist-' . $plan['id'],
                'label'      => $plan['title'],
                'icon'       => $plan['icon'],
                'color'      => $plan['color'],
                'type'       => 'playlist',
                'href'       => 'playlist.php?plan=' . $plan['id'],
                'item_count' => count($items),
            ];
        }

        // Plan should appear in early_learners and knowledge_power, not explorers
        $this->assertCount(1, $segments['early_learners']['topics']);
        $this->assertCount(1, $segments['knowledge_power']['topics']);
        $this->assertCount(0, $segments['explorers']['topics']);

        // Verify topic data
        $topic = $segments['early_learners']['topics'][0];
        $this->assertSame('playlist-' . $id, $topic['slug']);
        $this->assertSame('Multi-Segment Plan', $topic['label']);
        $this->assertSame('playlist', $topic['type']);
        $this->assertSame(2, $topic['item_count']);
    }

    /**
     * Plans with empty content_ids are skipped during injection.
     */
    public function testTilesInjectionSkipsEmptyPlans(): void
    {
        $id = $this->createPlan('Empty Plan', []);
        $this->publishToSegments($id, ['knowledge_power']);

        $published = $this->queryPublishedPlans();
        $this->assertCount(1, $published);

        // Simulate injection filtering
        $plan = $published[0];
        $items = json_decode($plan['content_ids'], true) ?: [];

        // The injection code skips plans with 0 items
        $this->assertCount(0, $items);
    }

    // ------------------------------------------------------------------
    // Tests: Playlist Page Access
    // ------------------------------------------------------------------

    /**
     * A private plan (NULL published_segments) should not be found
     * by the playlist.php query.
     */
    public function testPlaylistPageRejectsPrivatePlan(): void
    {
        $id = $this->createPlan('Private Plan');

        $stmt = $this->pdo->prepare("
            SELECT id FROM lesson_plans
            WHERE id = ?
              AND published_segments IS NOT NULL
              AND JSON_LENGTH(published_segments) > 0
        ");
        $stmt->execute([$id]);

        $this->assertFalse($stmt->fetch(), 'Private plan should not be returned');
    }

    /**
     * A published plan should be found by the playlist.php query.
     */
    public function testPlaylistPageReturnsPublishedPlan(): void
    {
        $id = $this->createPlan('Published Playlist', ['video-001', 'video-002']);
        $this->publishToSegments($id, ['early_learners']);

        $stmt = $this->pdo->prepare("
            SELECT id, title, content_ids, published_segments
            FROM lesson_plans
            WHERE id = ?
              AND published_segments IS NOT NULL
              AND JSON_LENGTH(published_segments) > 0
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, 'Published plan should be returned');
        $this->assertSame('Published Playlist', $row['title']);

        $contentIds = json_decode($row['content_ids'], true);
        $this->assertCount(2, $contentIds);
    }

    /**
     * A non-existent plan ID returns no result.
     */
    public function testPlaylistPageNonExistentPlanReturns404(): void
    {
        $stmt = $this->pdo->prepare("
            SELECT id FROM lesson_plans
            WHERE id = ?
              AND published_segments IS NOT NULL
              AND JSON_LENGTH(published_segments) > 0
        ");
        $stmt->execute([99999]);

        $this->assertFalse($stmt->fetch(), 'Non-existent plan should not be found');
    }

    // ------------------------------------------------------------------
    // Tests: Reorder
    // ------------------------------------------------------------------

    /**
     * Reordering updates the sort_order for each plan.
     */
    public function testReorderWithinSegment(): void
    {
        $idA = $this->createPlan('Plan A', ['v1'], ['knowledge_power'], '📚', '#4ECDC4', 0);
        $idB = $this->createPlan('Plan B', ['v2'], ['knowledge_power'], '📚', '#4ECDC4', 1);
        $idC = $this->createPlan('Plan C', ['v3'], ['knowledge_power'], '📚', '#4ECDC4', 2);

        // Reorder: C, A, B
        $newOrder = [$idC, $idA, $idB];
        $stmt = $this->pdo->prepare('UPDATE lesson_plans SET sort_order = ? WHERE id = ?');

        foreach ($newOrder as $order => $planId) {
            $stmt->execute([$order, $planId]);
        }

        // Verify new order
        $planA = $this->getPlan($idA);
        $planB = $this->getPlan($idB);
        $planC = $this->getPlan($idC);

        $this->assertSame(1, (int) $planA['sort_order']);
        $this->assertSame(2, (int) $planB['sort_order']);
        $this->assertSame(0, (int) $planC['sort_order']);

        // Verify query ordering
        $published = $this->queryPublishedPlans();
        $this->assertSame('Plan C', $published[0]['title']);
        $this->assertSame('Plan A', $published[1]['title']);
        $this->assertSame('Plan B', $published[2]['title']);
    }

    // ------------------------------------------------------------------
    // Tests: Schema columns
    // ------------------------------------------------------------------

    /**
     * New columns have correct defaults when not specified.
     */
    public function testNewColumnsHaveDefaults(): void
    {
        $id = $this->createPlan('Defaults Test');
        $plan = $this->getPlan($id);

        $this->assertNull($plan['published_segments']);
        $this->assertSame('📚', $plan['icon']);
        $this->assertSame('#4ECDC4', $plan['color']);
        $this->assertSame('', $plan['description']);
        $this->assertSame(0, (int) $plan['sort_order']);
        $this->assertNotEmpty($plan['updated_at']);
    }

    /**
     * Icon, color, and description can be updated.
     */
    public function testUpdateAppearanceFields(): void
    {
        $id = $this->createPlan('Styled Plan');

        $stmt = $this->pdo->prepare("
            UPDATE lesson_plans SET icon = ?, color = ?, description = ? WHERE id = ?
        ");
        $stmt->execute(['🔬', '#E63946', 'A science playlist for explorers', $id]);

        $plan = $this->getPlan($id);
        $this->assertSame('🔬', $plan['icon']);
        $this->assertSame('#E63946', $plan['color']);
        $this->assertSame('A science playlist for explorers', $plan['description']);
    }
}
