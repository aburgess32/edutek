-- FRE-49: Lesson Assignments table
-- Supports guided (teacher-led) and individual (self-paced) modes
-- assigned_to NULL = whole class assignment

CREATE TABLE IF NOT EXISTS lesson_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lesson_plan_id INT NOT NULL,
    teacher_id INT NOT NULL,
    assigned_to INT DEFAULT NULL COMMENT 'NULL = whole class, specific user_id = individual student',
    mode ENUM('guided', 'individual') NOT NULL DEFAULT 'individual',
    status ENUM('active', 'completed', 'archived') NOT NULL DEFAULT 'active',
    due_date DATE DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (lesson_plan_id) REFERENCES lesson_plans(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_teacher_id (teacher_id),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_lesson_plan (lesson_plan_id),
    INDEX idx_status (status),
    INDEX idx_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
