<?php
/**
 * Dashboard Seed Data (FRE-39)
 *
 * POST — Seeds test data for the teacher dashboard if tables are mostly empty.
 * Teacher auth required. Safe to run multiple times (checks for existing data).
 */

require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Use POST to seed data']);
    exit;
}

if (!isTeacher()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = getDbConnection();
    $summary = [];

    // ── 1. Ensure analytics tables exist ──
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS search_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            query VARCHAR(255) NOT NULL,
            result_count INT DEFAULT 0,
            searched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_searched_at (searched_at),
            INDEX idx_query (query(100))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS download_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            content_id VARCHAR(255) NOT NULL,
            content_title VARCHAR(500) DEFAULT NULL,
            content_type VARCHAR(50) DEFAULT NULL,
            downloaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_downloaded_at (downloaded_at),
            INDEX idx_content_id (content_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // ── 2. Seed students ──
    $existingStudents = (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE user_type IN ('student', 'kid', 'teen', 'adult')"
    )->fetchColumn();

    $studentIds = [];

    if ($existingStudents < 3) {
        $students = [
            ['Amara', 'student', 'under_10'],
            ['Bayo', 'student', '10_14'],
            ['Chidinma', 'student', '10_14'],
            ['Dami', 'student', '15_19'],
            ['Emeka', 'student', 'under_10'],
            ['Folake', 'student', '15_19'],
            ['Gbenga', 'student', '20_plus'],
            ['Halima', 'student', '10_14'],
            ['Ife', 'student', 'under_10'],
            ['Jelani', 'student', '20_plus'],
        ];

        $stmt = $pdo->prepare(
            "INSERT INTO users (display_name, user_type, age_range, last_active, created_at)
             VALUES (?, ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY), DATE_SUB(NOW(), INTERVAL 30 DAY))"
        );

        foreach ($students as $i => $s) {
            // Vary last_active: some recent, some old (for needs-attention)
            $daysAgo = $i < 6 ? rand(0, 3) : rand(8, 21);
            $stmt->execute([$s[0], $s[1], $s[2], $daysAgo]);
            $studentIds[] = (int) $pdo->lastInsertId();
        }
        $summary['students_created'] = count($students);
    } else {
        // Use existing student IDs
        $rows = $pdo->query(
            "SELECT id FROM users WHERE user_type != 'teacher' ORDER BY id LIMIT 20"
        )->fetchAll(PDO::FETCH_COLUMN);
        $studentIds = $rows;
        $summary['students_created'] = 0;
        $summary['students_existing'] = count($studentIds);
    }

    // ── 3. Seed content_meta if empty ──
    $contentCount = (int) $pdo->query("SELECT COUNT(*) FROM content_meta")->fetchColumn();
    $contentIds = [];

    if ($contentCount < 5) {
        $contents = [
            ['math-fractions-101', 'Introduction to Fractions', 'Mathematics', 'video', 'primary', 720],
            ['science-solar-system', 'Our Solar System', 'Science', 'video', 'primary', 540],
            ['eng-grammar-basics', 'Grammar Basics', 'English', 'video', 'primary', 480],
            ['hist-ancient-egypt', 'Ancient Egypt', 'History', 'video', 'secondary', 660],
            ['art-color-theory', 'Color Theory for Beginners', 'Art', 'video', 'primary', 360],
            ['math-algebra-intro', 'Algebra Introduction', 'Mathematics', 'video', 'secondary', 900],
            ['sci-chemistry-101', 'Chemistry Basics', 'Science', 'video', 'secondary', 780],
            ['eng-creative-writing', 'Creative Writing Workshop', 'English', 'interactive', 'secondary', 600],
            ['geo-world-capitals', 'World Capitals Quiz', 'Geography', 'interactive', 'primary', 300],
            ['mus-instruments', 'Musical Instruments Guide', 'Music', 'audiobook', 'primary', 420],
            ['tech-coding-intro', 'Intro to Coding', 'Technology', 'interactive', 'secondary', 840],
            ['pe-exercises', 'Fun Exercises for Kids', 'Physical Education', 'video', 'primary', 240],
        ];

        $stmt = $pdo->prepare(
            "INSERT INTO content_meta (content_id, title, subject, content_type, grade_level, duration_seconds, file_path, category)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($contents as $c) {
            $stmt->execute([$c[0], $c[1], $c[2], $c[3], $c[4], $c[5], '/content/' . $c[0], $c[2]]);
            $contentIds[] = $c[0];
        }
        $summary['content_created'] = count($contents);
    } else {
        $contentIds = $pdo->query("SELECT content_id FROM content_meta LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
        $summary['content_created'] = 0;
    }

    // ── 4. Seed watch_history ──
    $watchCount = (int) $pdo->query("SELECT COUNT(*) FROM watch_history")->fetchColumn();

    if ($watchCount < 20 && count($studentIds) > 0 && count($contentIds) > 0) {
        $stmt = $pdo->prepare(
            "INSERT INTO watch_history (user_id, content_id, content_title, content_type, progress_seconds, duration_seconds, last_watched)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        $watchInserted = 0;
        // Generate 60-80 watch entries spread across 14 days and various hours
        $numEntries = rand(60, 80);
        for ($i = 0; $i < $numEntries; $i++) {
            $uid = $studentIds[array_rand($studentIds)];
            $cid = $contentIds[array_rand($contentIds)];

            // Get content info
            $cInfo = $pdo->prepare("SELECT title, content_type, duration_seconds FROM content_meta WHERE content_id = ? LIMIT 1");
            $cInfo->execute([$cid]);
            $info = $cInfo->fetch(PDO::FETCH_ASSOC);

            $duration = $info ? (int) $info['duration_seconds'] : rand(180, 900);
            $progress = rand((int) ($duration * 0.1), $duration);
            $title = $info ? $info['title'] : 'Content ' . $cid;
            $type = $info ? $info['content_type'] : 'video';

            // Random timestamp in last 14 days, various hours (bias toward school hours)
            $daysAgo = rand(0, 13);
            $hour = rand(0, 100) < 70 ? rand(8, 16) : rand(0, 23); // 70% during school hours
            $minute = rand(0, 59);
            $watchDate = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days {$hour}:{$minute}:00"));

            $stmt->execute([$uid, $cid, $title, $type, $progress, $duration, $watchDate]);
            $watchInserted++;
        }
        $summary['watch_history_created'] = $watchInserted;
    } else {
        $summary['watch_history_created'] = 0;
    }

    // ── 5. Seed search_log ──
    $searchCount = (int) $pdo->query("SELECT COUNT(*) FROM search_log")->fetchColumn();

    if ($searchCount < 5) {
        $searches = [
            ['fractions', 3], ['solar system', 5], ['grammar', 2], ['egypt', 4],
            ['math', 8], ['science', 6], ['writing', 1], ['coding', 7],
            ['music', 2], ['chemistry', 3], ['algebra', 4], ['quiz', 2],
            ['multiplication', 5], ['history', 3], ['art', 1], ['reading', 4],
            ['geography', 2], ['instruments', 1], ['exercise', 3], ['fractions', 6],
            ['solar', 2], ['grammar rules', 1], ['egypt pyramids', 3], ['color theory', 2],
            ['addition', 4], ['subtraction', 3], ['planets', 5], ['animals', 2],
            ['vocabulary', 3], ['spelling', 1],
        ];

        $stmt = $pdo->prepare(
            "INSERT INTO search_log (user_id, query, result_count, searched_at)
             VALUES (?, ?, ?, ?)"
        );

        foreach ($searches as $s) {
            $uid = count($studentIds) > 0 ? $studentIds[array_rand($studentIds)] : null;
            $daysAgo = rand(0, 13);
            $hour = rand(8, 20);
            $ts = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days {$hour}:00:00"));
            $stmt->execute([$uid, $s[0], $s[1], $ts]);
        }
        $summary['search_log_created'] = count($searches);
    } else {
        $summary['search_log_created'] = 0;
    }

    // ── 6. Seed download_log ──
    $dlCount = (int) $pdo->query("SELECT COUNT(*) FROM download_log")->fetchColumn();

    if ($dlCount < 5 && count($contentIds) > 0) {
        $stmt = $pdo->prepare(
            "INSERT INTO download_log (user_id, content_id, content_title, content_type, downloaded_at)
             VALUES (?, ?, ?, ?, ?)"
        );

        $dlInserted = 0;
        for ($i = 0; $i < 20; $i++) {
            $uid = count($studentIds) > 0 ? $studentIds[array_rand($studentIds)] : null;
            $cid = $contentIds[array_rand($contentIds)];

            $cInfo = $pdo->prepare("SELECT title, content_type FROM content_meta WHERE content_id = ? LIMIT 1");
            $cInfo->execute([$cid]);
            $info = $cInfo->fetch(PDO::FETCH_ASSOC);

            $title = $info ? $info['title'] : $cid;
            $type = $info ? $info['content_type'] : 'file';

            $daysAgo = rand(0, 13);
            $ts = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days " . rand(8, 20) . ":00:00"));

            $stmt->execute([$uid, $cid, $title, $type, $ts]);
            $dlInserted++;
        }
        $summary['download_log_created'] = $dlInserted;
    } else {
        $summary['download_log_created'] = 0;
    }

    echo json_encode([
        'status'  => 'ok',
        'summary' => $summary,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Seed failed: ' . $e->getMessage()]);
}
