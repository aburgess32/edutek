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
            user_type VARCHAR(20) DEFAULT NULL,
            age_range VARCHAR(20) DEFAULT NULL,
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
            ['addition', 4], ['subtraction', 3], ['planets', 5], ['animals', 0],
            ['vocabulary', 3], ['spelling', 1],
        ];

        $userTypes = ['student', 'student', 'student', 'teacher', 'guest'];
        $ageRanges = ['under_10', '10_14', '10_14', '15_19', '20_plus'];

        // Build a map of student user_type and age_range from users table
        $userMeta = [];
        if (count($studentIds) > 0) {
            $inPlaceholders = implode(',', array_fill(0, count($studentIds), '?'));
            $metaStmt = $pdo->prepare("SELECT id, user_type, age_range FROM users WHERE id IN ({$inPlaceholders})");
            $metaStmt->execute($studentIds);
            foreach ($metaStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $userMeta[(int)$row['id']] = $row;
            }
        }

        $stmt = $pdo->prepare(
            "INSERT INTO search_log (user_id, user_type, age_range, query, result_count, searched_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        foreach ($searches as $s) {
            $uid = count($studentIds) > 0 ? $studentIds[array_rand($studentIds)] : null;
            $userType = 'guest';
            $ageRange = null;
            if ($uid && isset($userMeta[$uid])) {
                $userType = $userMeta[$uid]['user_type'] ?? 'student';
                $ageRange = $userMeta[$uid]['age_range'] ?? null;
            } elseif ($uid) {
                // Fallback: pick random realistic values
                $userType = $userTypes[array_rand($userTypes)];
                $ageRange = $ageRanges[array_rand($ageRanges)];
            }
            $daysAgo = rand(0, 13);
            $hour = rand(8, 20);
            $ts = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days {$hour}:00:00"));
            $stmt->execute([$uid, $userType, $ageRange, $s[0], $s[1], $ts]);
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

    // ── 7. Seed lesson_assignments and lesson_progress (FRE-49/FRE-51) ──
    // Ensure tables exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lesson_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lesson_plan_id INT NOT NULL,
            teacher_id INT NOT NULL,
            assigned_to INT DEFAULT NULL,
            mode ENUM('guided', 'individual') NOT NULL DEFAULT 'individual',
            status ENUM('active', 'completed', 'archived') NOT NULL DEFAULT 'active',
            due_date DATE DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_teacher_id (teacher_id),
            INDEX idx_assigned_to (assigned_to),
            INDEX idx_lesson_plan (lesson_plan_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lesson_progress (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assignment_id INT NOT NULL,
            student_id INT NOT NULL,
            content_id VARCHAR(255) NOT NULL,
            status ENUM('not_started', 'in_progress', 'completed') NOT NULL DEFAULT 'not_started',
            progress_pct INT NOT NULL DEFAULT 0,
            time_spent INT NOT NULL DEFAULT 0,
            last_position INT NOT NULL DEFAULT 0,
            completed_at TIMESTAMP NULL DEFAULT NULL,
            last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_assignment_student_content (assignment_id, student_id, content_id),
            INDEX idx_student_id (student_id),
            INDEX idx_content_id (content_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $assignCount = (int) $pdo->query("SELECT COUNT(*) FROM lesson_assignments")->fetchColumn();

    if ($assignCount < 2 && count($studentIds) > 0) {
        $teacherId = (int) $_SESSION['user_id'];

        // Get existing lesson plans (or create sample ones)
        $planRows = $pdo->query("SELECT id, content_ids FROM lesson_plans ORDER BY id LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($planRows) && count($contentIds) >= 3) {
            // Create sample lesson plans
            $samplePlans = [
                ['Math Fundamentals', array_slice($contentIds, 0, 3)],
                ['Science Explorer', array_slice($contentIds, 1, 4)],
            ];
            $planInsert = $pdo->prepare("INSERT INTO lesson_plans (teacher_id, title, content_ids) VALUES (:tid, :title, :cids)");
            foreach ($samplePlans as $sp) {
                $planInsert->execute([':tid' => $teacherId, ':title' => $sp[0], ':cids' => json_encode($sp[1])]);
                $planRows[] = ['id' => (int) $pdo->lastInsertId(), 'content_ids' => json_encode($sp[1])];
            }
            $summary['lesson_plans_created'] = count($samplePlans);
        }

        // Assign teacher_students if not already
        $tsCount = (int) $pdo->query("SELECT COUNT(*) FROM teacher_students WHERE teacher_id = {$teacherId}")->fetchColumn();
        if ($tsCount === 0 && count($studentIds) > 0) {
            $tsInsert = $pdo->prepare("INSERT IGNORE INTO teacher_students (teacher_id, student_id) VALUES (:tid, :sid)");
            foreach ($studentIds as $sid) {
                $tsInsert->execute([':tid' => $teacherId, ':sid' => $sid]);
            }
            $summary['teacher_students_assigned'] = count($studentIds);
        }

        $assignmentsCreated = 0;
        $progressCreated = 0;

        foreach ($planRows as $planRow) {
            $planId = (int) $planRow['id'];
            $planContentIds = json_decode($planRow['content_ids'], true);
            if (!is_array($planContentIds)) continue;

            // Create a whole-class assignment (individual mode)
            $dueDate = date('Y-m-d', strtotime('+7 days'));
            $pdo->prepare("
                INSERT INTO lesson_assignments (lesson_plan_id, teacher_id, assigned_to, mode, status, due_date, notes)
                VALUES (:pid, :tid, NULL, 'individual', 'active', :due, 'Complete all items at your own pace')
            ")->execute([':pid' => $planId, ':tid' => $teacherId, ':due' => $dueDate]);
            $assignmentId = (int) $pdo->lastInsertId();
            $assignmentsCreated++;

            // Create progress rows for each student x content item
            $progInsert = $pdo->prepare("
                INSERT IGNORE INTO lesson_progress (assignment_id, student_id, content_id, status, progress_pct, time_spent, last_position)
                VALUES (:aid, :sid, :cid, :status, :pct, :time, :pos)
            ");

            foreach ($studentIds as $idx => $sid) {
                foreach ($planContentIds as $cidx => $cid) {
                    $cidStr = is_array($cid) ? ($cid['content_id'] ?? $cid['id'] ?? '') : (string) $cid;
                    if ($cidStr === '') continue;

                    // Vary progress: first students have more progress, later ones less
                    $basePct = max(0, 90 - ($idx * 12) - ($cidx * 15));
                    $pct = max(0, min(100, $basePct + rand(-10, 10)));
                    $status = 'not_started';
                    if ($pct >= 80) $status = 'completed';
                    elseif ($pct > 0) $status = 'in_progress';

                    $timeSpent = (int) ($pct * 5); // roughly proportional
                    $lastPos = (int) ($pct * 6);   // rough video position

                    $progInsert->execute([
                        ':aid'    => $assignmentId,
                        ':sid'    => (int) $sid,
                        ':cid'    => $cidStr,
                        ':status' => $status,
                        ':pct'    => $pct,
                        ':time'   => $timeSpent,
                        ':pos'    => $lastPos,
                    ]);
                    $progressCreated++;
                }
            }
        }

        // Create one guided mode assignment from first plan if available
        if (!empty($planRows)) {
            $guidedPlan = $planRows[0];
            $guidedContentIds = json_decode($guidedPlan['content_ids'], true);
            if (is_array($guidedContentIds)) {
                $pdo->prepare("
                    INSERT INTO lesson_assignments (lesson_plan_id, teacher_id, assigned_to, mode, status, notes)
                    VALUES (:pid, :tid, NULL, 'guided', 'active', 'Follow along in class today')
                ")->execute([':pid' => (int) $guidedPlan['id'], ':tid' => $teacherId]);
                $guidedAid = (int) $pdo->lastInsertId();
                $assignmentsCreated++;

                $progInsert = $pdo->prepare("
                    INSERT IGNORE INTO lesson_progress (assignment_id, student_id, content_id, status, progress_pct, time_spent)
                    VALUES (:aid, :sid, :cid, :status, :pct, :time)
                ");
                foreach ($studentIds as $sid) {
                    foreach ($guidedContentIds as $cid) {
                        $cidStr = is_array($cid) ? ($cid['content_id'] ?? $cid['id'] ?? '') : (string) $cid;
                        if ($cidStr === '') continue;
                        $pct = rand(0, 60);
                        $status = $pct >= 80 ? 'completed' : ($pct > 0 ? 'in_progress' : 'not_started');
                        $progInsert->execute([
                            ':aid'    => $guidedAid,
                            ':sid'    => (int) $sid,
                            ':cid'    => $cidStr,
                            ':status' => $status,
                            ':pct'    => $pct,
                            ':time'   => (int) ($pct * 3),
                        ]);
                        $progressCreated++;
                    }
                }
            }
        }

        $summary['assignments_created'] = $assignmentsCreated;
        $summary['progress_rows_created'] = $progressCreated;
    } else {
        $summary['assignments_created'] = 0;
    }

    echo json_encode([
        'status'  => 'ok',
        'summary' => $summary,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Seed failed: ' . $e->getMessage()]);
}
