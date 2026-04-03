-- FRE-51: Lesson Progress tracking table
-- One row per student per content item per assignment
-- Tracks watch progress, time spent, and completion status

CREATE TABLE IF NOT EXISTS lesson_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT NOT NULL,
    student_id INT NOT NULL,
    content_id VARCHAR(255) NOT NULL,
    status ENUM('not_started', 'in_progress', 'completed') NOT NULL DEFAULT 'not_started',
    progress_pct INT NOT NULL DEFAULT 0 COMMENT 'Watch progress percentage 0-100',
    time_spent INT NOT NULL DEFAULT 0 COMMENT 'Total seconds spent on this content',
    last_position INT NOT NULL DEFAULT 0 COMMENT 'Last playback position in seconds for resume',
    completed_at TIMESTAMP NULL DEFAULT NULL,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assignment_id) REFERENCES lesson_assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_assignment_student_content (assignment_id, student_id, content_id),
    INDEX idx_student_id (student_id),
    INDEX idx_content_id (content_id),
    INDEX idx_status (status),
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
