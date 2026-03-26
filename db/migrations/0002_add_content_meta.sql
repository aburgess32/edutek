-- ============================================================
-- Migration: 0002_add_content_meta.sql
-- Description: Add content_meta table for offline search index.
--              Enables subject/grade filtering and full-text search
--              without requiring an external search engine.
-- Author: EduTek
-- ============================================================

-- UP

USE edupak;

CREATE TABLE IF NOT EXISTS content_meta (
    id               INT AUTO_INCREMENT PRIMARY KEY,

    -- Reference to logical content item (file path or internal ID)
    content_id       VARCHAR(255)  NOT NULL UNIQUE,

    -- Searchable metadata
    title            VARCHAR(500)  NOT NULL,
    description      TEXT,
    subject          VARCHAR(100),
    grade_level      VARCHAR(50),                            -- e.g. 'K', '1', '2', ..., '12', 'adult'
    content_type     VARCHAR(50),                            -- 'video', 'pdf', 'audio', 'image', 'quiz'

    -- File system paths (relative to CONTENT_PATH)
    file_path        VARCHAR(1000) NOT NULL,
    thumbnail_path   VARCHAR(1000),

    -- Media properties
    duration_seconds INT           DEFAULT NULL,             -- NULL for non-timed content (PDFs etc.)
    language         VARCHAR(10)   DEFAULT 'en',             -- ISO 639-1 language code

    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,

    -- Composite index for subject + grade filtering (most common filter combo)
    INDEX idx_subject_grade (subject, grade_level),

    -- Individual indexes for single-column filters
    INDEX idx_subject      (subject),
    INDEX idx_grade_level  (grade_level),
    INDEX idx_content_type (content_type),
    INDEX idx_language     (language),

    -- Full-text index for search across title and description
    FULLTEXT idx_search (title, description)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DOWN

USE edupak;

DROP TABLE IF EXISTS content_meta;
