-- ============================================================
-- Migration: 0010_teacher_students.sql
-- Description: Add teacher-student associations and student
--              groups for FRE-47 Manage Students
-- Author: EduTek
-- ============================================================

-- UP

USE edupak;

-- Teacher ↔ Student many-to-many
CREATE TABLE IF NOT EXISTS teacher_students (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id  INT NOT NULL,
    student_id  INT NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_teacher_student (teacher_id, student_id),
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_student_id (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Named groups (classes/periods) per teacher
CREATE TABLE IF NOT EXISTS student_groups (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id  INT NOT NULL,
    name        VARCHAR(100) NOT NULL,
    color       CHAR(7) DEFAULT '#4ECDC4',
    sort_order  INT DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_teacher_groups (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Group membership (one student per group per teacher)
CREATE TABLE IF NOT EXISTS student_group_members (
    group_id    INT NOT NULL,
    student_id  INT NOT NULL,
    PRIMARY KEY (group_id, student_id),
    FOREIGN KEY (group_id) REFERENCES student_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DOWN

DROP TABLE IF EXISTS student_group_members;
DROP TABLE IF EXISTS student_groups;
DROP TABLE IF EXISTS teacher_students;
