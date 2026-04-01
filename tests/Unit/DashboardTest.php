<?php

/**
 * DashboardTest — Unit/Integration tests for the Teacher Dashboard API queries.
 *
 * Covers:
 *  - Student list with totals (watched count, screen time, sessions, last active)
 *  - Edge case: no students exist
 *  - Edge case: student with 0 watch history
 *  - Watched list pagination
 *  - Screen time daily aggregation
 *  - Screen time all-students aggregation
 *  - Screen time capping (LEAST of progress vs duration)
 *  - Teachers are excluded from student list
 */

declare(strict_types=1);

namespace EduPak\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

class DashboardTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = getTestPdo();
        truncateTestTables($this->pdo);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function createUser(string $name, string $type = 'kid'): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (display_name, user_type) VALUES (?, ?)"
        );
        $stmt->execute([$name, $type]);
        return (int) $this->pdo->lastInsertId();
    }

    private function insertWatch(
        int $userId,
        string $contentId,
        int $progress,
        int $duration,
        string $title = 'Video',
        ?string $watchedAt = null
    ): void {
        if ($watchedAt) {
            $stmt = $this->pdo->prepare("
                INSERT INTO watch_history
                    (user_id, content_id, content_title, content_type, progress_seconds, duration_seconds, last_watched)
                VALUES (?, ?, ?, 'video', ?, ?, ?)
            ");
            $stmt->execute([$userId, $contentId, $title, $progress, $duration, $watchedAt]);
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO watch_history
                    (user_id, content_id, content_title, content_type, progress_seconds, duration_seconds)
                VALUES (?, ?, ?, 'video', ?, ?)
            ");
            $stmt->execute([$userId, $contentId, $title, $progress, $duration]);
        }
    }

    /**
     * Runs the "students" query from dashboard.php and returns results.
     */
    private function queryStudents(): array
    {
        $stmt = $this->pdo->query("
            SELECT
                u.id,
                u.display_name,
                u.user_type,
                COUNT(wh.id) AS total_watched,
                COALESCE(SUM(LEAST(wh.progress_seconds, wh.duration_seconds)), 0) AS total_screen_time_sec,
                COUNT(DISTINCT DATE(wh.last_watched)) AS total_sessions,
                MAX(wh.last_watched) AS last_active
            FROM users u
            LEFT JOIN watch_history wh ON wh.user_id = u.id
            WHERE u.user_type != 'teacher'
            GROUP BY u.id
            ORDER BY last_active DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Runs the "watched" query from dashboard.php for a student.
     */
    private function queryWatched(int $studentId, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                content_id,
                content_title,
                progress_seconds,
                duration_seconds,
                ROUND((progress_seconds / duration_seconds) * 100) AS progress_pct,
                last_watched
            FROM watch_history
            WHERE user_id = :student_id
              AND duration_seconds > 0
            ORDER BY last_watched DESC
            LIMIT 50 OFFSET :offset
        ");
        $stmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Runs the "screen_time" query for a student.
     */
    private function queryScreenTime(int $studentId, int $days = 14): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                DATE(last_watched) AS watch_date,
                SUM(LEAST(progress_seconds, duration_seconds)) AS seconds
            FROM watch_history
            WHERE user_id = :student_id
              AND last_watched >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY DATE(last_watched)
            ORDER BY watch_date ASC
        ");
        $stmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Runs the "screen_time_all" aggregate query.
     */
    private function queryScreenTimeAll(int $days = 14): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                DATE(last_watched) AS watch_date,
                SUM(LEAST(progress_seconds, duration_seconds)) AS seconds
            FROM watch_history
            WHERE last_watched >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY DATE(last_watched)
            ORDER BY watch_date ASC
        ");
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------
    // Tests: Student List
    // ------------------------------------------------------------------

    /**
     * When no non-teacher users exist, the student list is empty.
     */
    public function testStudentListEmptyWhenNoStudents(): void
    {
        // Only a teacher exists
        $this->createUser('Mrs. Smith', 'teacher');

        $result = $this->queryStudents();
        $this->assertCount(0, $result);
    }

    /**
     * A student with zero watch history still appears in the list with 0 totals.
     */
    public function testStudentWithZeroWatchHistoryAppearsWithZeros(): void
    {
        $this->createUser('Alice', 'kid');

        $result = $this->queryStudents();
        $this->assertCount(1, $result);

        $student = $result[0];
        $this->assertSame('Alice', $student['display_name']);
        $this->assertSame(0, (int) $student['total_watched']);
        $this->assertSame(0, (int) $student['total_screen_time_sec']);
        $this->assertSame(0, (int) $student['total_sessions']);
        $this->assertNull($student['last_active']);
    }

    /**
     * Teachers are excluded from the student list.
     */
    public function testTeachersExcludedFromStudentList(): void
    {
        $this->createUser('Mrs. Smith', 'teacher');
        $this->createUser('Alice', 'kid');
        $this->createUser('Bob', 'teen');

        $result = $this->queryStudents();
        $this->assertCount(2, $result);

        $names = array_column($result, 'display_name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Bob', $names);
        $this->assertNotContains('Mrs. Smith', $names);
    }

    /**
     * Student totals are calculated correctly: watched count, screen time, sessions.
     */
    public function testStudentTotalsCalculatedCorrectly(): void
    {
        $studentId = $this->createUser('Charlie', 'kid');

        // Two videos watched on the same day
        $today = date('Y-m-d H:i:s');
        $this->insertWatch($studentId, 'v1', 300, 600, 'Video 1', $today);
        $this->insertWatch($studentId, 'v2', 120, 200, 'Video 2', $today);

        $result = $this->queryStudents();
        $this->assertCount(1, $result);

        $s = $result[0];
        $this->assertSame(2, (int) $s['total_watched']);
        // Screen time: LEAST(300,600) + LEAST(120,200) = 300 + 120 = 420
        $this->assertSame(420, (int) $s['total_screen_time_sec']);
        // Both on same date → 1 session
        $this->assertSame(1, (int) $s['total_sessions']);
    }

    /**
     * Screen time uses LEAST(progress, duration) — progress exceeding duration is capped.
     */
    public function testScreenTimeCapsProgressAtDuration(): void
    {
        $studentId = $this->createUser('Diane', 'kid');

        // progress_seconds (700) > duration_seconds (600) — should be capped to 600
        $this->insertWatch($studentId, 'v1', 700, 600, 'Over-progress');

        $result = $this->queryStudents();
        $this->assertSame(600, (int) $result[0]['total_screen_time_sec']);
    }

    /**
     * Multiple days of activity produce distinct session counts.
     */
    public function testMultipleDaysCountAsDistinctSessions(): void
    {
        $studentId = $this->createUser('Eve', 'kid');

        $this->insertWatch($studentId, 'v1', 100, 200, 'Day1 Video', '2026-03-28 10:00:00');
        $this->insertWatch($studentId, 'v2', 100, 200, 'Day2 Video', '2026-03-29 10:00:00');
        $this->insertWatch($studentId, 'v3', 100, 200, 'Day3 Video', '2026-03-30 10:00:00');

        $result = $this->queryStudents();
        $this->assertSame(3, (int) $result[0]['total_sessions']);
    }

    // ------------------------------------------------------------------
    // Tests: Watched List
    // ------------------------------------------------------------------

    /**
     * Watched list returns entries sorted by last_watched DESC with correct fields.
     */
    public function testWatchedListReturnsSortedEntries(): void
    {
        $studentId = $this->createUser('Frank', 'kid');

        $this->insertWatch($studentId, 'v1', 150, 300, 'Early Video', '2026-03-28 08:00:00');
        $this->insertWatch($studentId, 'v2', 200, 400, 'Later Video', '2026-03-29 09:00:00');

        $result = $this->queryWatched($studentId);
        $this->assertCount(2, $result);

        // Most recent first
        $this->assertSame('v2', $result[0]['content_id']);
        $this->assertSame('v1', $result[1]['content_id']);

        // Progress percentage: 200/400 = 50%
        $this->assertSame(50, (int) $result[0]['progress_pct']);
    }

    /**
     * Watched list excludes items with duration_seconds = 0.
     */
    public function testWatchedListExcludesZeroDuration(): void
    {
        $studentId = $this->createUser('Grace', 'kid');

        $this->insertWatch($studentId, 'v1', 100, 300, 'Valid');
        $this->insertWatch($studentId, 'v2', 50, 0, 'Zero Duration');

        $result = $this->queryWatched($studentId);
        $this->assertCount(1, $result);
        $this->assertSame('v1', $result[0]['content_id']);
    }

    /**
     * Watched list pagination via offset works correctly.
     */
    public function testWatchedListPagination(): void
    {
        $studentId = $this->createUser('Hannah', 'kid');

        // Insert 3 entries
        for ($i = 1; $i <= 3; $i++) {
            $this->insertWatch($studentId, "v{$i}", $i * 10, 300, "Video {$i}");
            usleep(5000);
        }

        // First page of 50 — gets all 3
        $page1 = $this->queryWatched($studentId, 0);
        $this->assertCount(3, $page1);

        // Offset 2 — gets 1
        $page2 = $this->queryWatched($studentId, 2);
        $this->assertCount(1, $page2);
    }

    // ------------------------------------------------------------------
    // Tests: Screen Time
    // ------------------------------------------------------------------

    /**
     * Screen time aggregates by day for a specific student.
     */
    public function testScreenTimeGroupsByDay(): void
    {
        $studentId = $this->createUser('Iris', 'kid');

        $this->insertWatch($studentId, 'v1', 100, 200, 'Morning', date('Y-m-d') . ' 08:00:00');
        $this->insertWatch($studentId, 'v2', 150, 300, 'Afternoon', date('Y-m-d') . ' 14:00:00');

        $result = $this->queryScreenTime($studentId, 1);
        $this->assertCount(1, $result);

        // Both on today → summed: LEAST(100,200) + LEAST(150,300) = 100 + 150 = 250
        $this->assertSame(250, (int) $result[0]['seconds']);
    }

    /**
     * screen_time_all aggregates across all students.
     */
    public function testScreenTimeAllAggregatesAcrossStudents(): void
    {
        $s1 = $this->createUser('Jack', 'kid');
        $s2 = $this->createUser('Kate', 'teen');

        $today = date('Y-m-d') . ' 10:00:00';
        $this->insertWatch($s1, 'v1', 100, 200, 'Jack Video', $today);
        $this->insertWatch($s2, 'v2', 200, 400, 'Kate Video', $today);

        $result = $this->queryScreenTimeAll(1);
        $this->assertCount(1, $result);

        // LEAST(100,200) + LEAST(200,400) = 100 + 200 = 300
        $this->assertSame(300, (int) $result[0]['seconds']);
    }

    /**
     * Screen time with no data returns empty array.
     */
    public function testScreenTimeEmptyWhenNoData(): void
    {
        $studentId = $this->createUser('Liam', 'kid');

        $result = $this->queryScreenTime($studentId, 14);
        $this->assertCount(0, $result);
    }

    /**
     * Old data outside the day window is excluded from screen time.
     */
    public function testScreenTimeExcludesOldData(): void
    {
        $studentId = $this->createUser('Mia', 'kid');

        // Data from 30 days ago
        $oldDate = date('Y-m-d H:i:s', strtotime('-30 days'));
        $this->insertWatch($studentId, 'v1', 100, 200, 'Old Video', $oldDate);

        // Query last 7 days — should be empty
        $result = $this->queryScreenTime($studentId, 7);
        $this->assertCount(0, $result);

        // Query last 31 days — should include it
        $result = $this->queryScreenTime($studentId, 31);
        $this->assertCount(1, $result);
    }
}
