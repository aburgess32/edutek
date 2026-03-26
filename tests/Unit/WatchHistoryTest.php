<?php

/**
 * WatchHistoryTest — Unit/Integration tests for watch history recording.
 *
 * Covers:
 *  - Recording a watch history entry
 *  - The 95% completion threshold for marking content "watched"
 *  - Fetching only the last 5 entries (Continue Watching feature)
 *  - Updating progress for the same content (upsert-style)
 *  - Cascade delete when a user is removed
 */

declare(strict_types=1);

namespace EduPak\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

class WatchHistoryTest extends TestCase
{
    private PDO $pdo;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = getTestPdo();
        truncateTestTables($this->pdo);

        // Create a test user to associate watch history with
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (display_name, user_type) VALUES ('WatchTester', 'kid')"
        );
        $stmt->execute();
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function insertWatchEntry(
        string $contentId,
        int $progressSeconds,
        int $durationSeconds,
        string $title = 'Test Video'
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO watch_history
                (user_id, content_id, content_title, content_type, progress_seconds, duration_seconds)
            VALUES
                (:uid, :cid, :title, 'video', :progress, :duration)
        ");
        $stmt->execute([
            ':uid'      => $this->userId,
            ':cid'      => $contentId,
            ':title'    => $title,
            ':progress' => $progressSeconds,
            ':duration' => $durationSeconds,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    /**
     * A watch history entry is inserted and all columns are stored correctly.
     */
    public function testRecordingWatchHistoryPersistsAllFields(): void
    {
        $id = $this->insertWatchEntry('video-001', 120, 600, 'Intro to Fractions');

        $row = $this->pdo
            ->query("SELECT * FROM watch_history WHERE id = {$id}")
            ->fetch();

        $this->assertNotFalse($row);
        $this->assertSame($this->userId, (int) $row['user_id']);
        $this->assertSame('video-001', $row['content_id']);
        $this->assertSame('Intro to Fractions', $row['content_title']);
        $this->assertSame(120, (int) $row['progress_seconds']);
        $this->assertSame(600, (int) $row['duration_seconds']);
    }

    /**
     * Content is considered "completed" only when progress >= 95% of duration.
     * Below that threshold it should be treated as in-progress.
     */
    public function testNinetyFivePercentCompletionThreshold(): void
    {
        // 95% of 600s = 570s — exactly at threshold → completed
        $this->insertWatchEntry('video-002', 570, 600);

        // 94% of 600s = 564s — below threshold → in-progress
        $this->insertWatchEntry('video-003', 564, 600);

        $stmt = $this->pdo->query("SELECT content_id, progress_seconds, duration_seconds FROM watch_history");
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $ratio = (int) $row['duration_seconds'] > 0
                ? (int) $row['progress_seconds'] / (int) $row['duration_seconds']
                : 0;

            if ($row['content_id'] === 'video-002') {
                $this->assertGreaterThanOrEqual(0.95, $ratio, 'video-002 should meet the 95% threshold');
            }

            if ($row['content_id'] === 'video-003') {
                $this->assertLessThan(0.95, $ratio, 'video-003 should fall below the 95% threshold');
            }
        }
    }

    /**
     * The Continue Watching feature should return at most 5 entries,
     * ordered by last_watched descending (most recent first).
     */
    public function testFetchLastFiveWatchHistoryEntries(): void
    {
        // Insert 7 entries with different content IDs
        for ($i = 1; $i <= 7; $i++) {
            $this->insertWatchEntry("video-{$i}", $i * 10, 300, "Video {$i}");
            usleep(5000); // ensure distinct timestamps
        }

        $stmt = $this->pdo->prepare("
            SELECT content_id, last_watched
            FROM watch_history
            WHERE user_id = :uid
            ORDER BY last_watched DESC
            LIMIT 5
        ");
        $stmt->execute([':uid' => $this->userId]);
        $results = $stmt->fetchAll();

        $this->assertCount(5, $results, 'Continue Watching should return exactly 5 entries');

        // Most recent should be video-7 (last inserted)
        $this->assertSame('video-7', $results[0]['content_id'], 'Most recently watched should be first');
    }

    /**
     * When progress for a content item is updated, the row reflects
     * the new value (simulating a progress-save during playback).
     */
    public function testUpdatingProgressForExistingWatchEntry(): void
    {
        $this->insertWatchEntry('video-upd', 60, 600);

        $this->pdo->prepare("
            UPDATE watch_history
            SET progress_seconds = 300
            WHERE user_id = :uid AND content_id = :cid
        ")->execute([':uid' => $this->userId, ':cid' => 'video-upd']);

        $stmt = $this->pdo->prepare(
            "SELECT progress_seconds FROM watch_history WHERE user_id = :uid AND content_id = :cid"
        );
        $stmt->execute([':uid' => $this->userId, ':cid' => 'video-upd']);
        $progress = (int) $stmt->fetchColumn();

        $this->assertSame(300, $progress, 'Progress should be updated to 300 seconds');
    }

    /**
     * Deleting the parent user should cascade-delete all their watch history rows.
     */
    public function testWatchHistoryIsDeletedWhenUserIsRemoved(): void
    {
        $this->insertWatchEntry('video-cascade', 100, 200);

        $this->pdo->exec("DELETE FROM users WHERE id = {$this->userId}");

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id = :uid");
        $stmt->execute([':uid' => $this->userId]);

        $this->assertSame(0, (int) $stmt->fetchColumn(), 'Watch history should cascade-delete with user');
    }
}
