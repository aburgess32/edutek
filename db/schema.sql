-- EduPak Database Schema
-- Will be updated once existing DB structure is analyzed

CREATE DATABASE IF NOT EXISTS edupak;
USE edupak;

-- User profiles (Simple Name Login - Priority #3)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    display_name VARCHAR(100) NOT NULL,
    user_type ENUM('kid', 'teen', 'adult', 'teacher') DEFAULT 'kid',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Watch history (Continue Watching - Priority #2)
CREATE TABLE IF NOT EXISTS watch_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    content_id VARCHAR(255) NOT NULL,
    content_title VARCHAR(500),
    content_type VARCHAR(50),
    thumbnail_path VARCHAR(500),
    progress_seconds INT DEFAULT 0,
    duration_seconds INT DEFAULT 0,
    last_watched TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_last (user_id, last_watched DESC)
);

-- Offline content search index (FRE-13 Search Enhancement)
CREATE TABLE IF NOT EXISTS content_meta (
    id               INT AUTO_INCREMENT PRIMARY KEY,

    -- Reference to logical content item (file path or internal ID)
    content_id       VARCHAR(255)  NOT NULL UNIQUE,

    -- Searchable metadata
    title            VARCHAR(500)  NOT NULL,
    description      TEXT,
    subject          VARCHAR(100),
    grade_level      VARCHAR(50),
    content_type     VARCHAR(50),

    -- Category / source metadata (FRE-13)
    category         VARCHAR(100) DEFAULT '',
    subcategory      VARCHAR(100) DEFAULT '',
    source           VARCHAR(100) DEFAULT '',

    -- File system paths (relative to CONTENT_PATH)
    file_path        VARCHAR(1000) NOT NULL,
    thumbnail_path   VARCHAR(1000),

    -- Media properties
    duration_seconds INT           DEFAULT NULL,
    language         VARCHAR(10)   DEFAULT 'en',

    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_subject_grade (subject, grade_level),
    INDEX idx_subject      (subject),
    INDEX idx_grade_level  (grade_level),
    INDEX idx_content_type (content_type),
    INDEX idx_language     (language),

    -- Full-text index for search across title, category, subcategory, source
    FULLTEXT ft_search (title, category, subcategory, source),

    -- Title-only full-text index for title-priority ranking
    FULLTEXT ft_title (title)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Search query log (FRE-39 Teacher Dashboard analytics)
CREATE TABLE IF NOT EXISTS search_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    user_type VARCHAR(20) DEFAULT NULL,
    age_range VARCHAR(20) DEFAULT NULL,
    query VARCHAR(255) NOT NULL,
    result_count INT DEFAULT 0,
    searched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_searched_at (searched_at),
    INDEX idx_query (query(100)),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Download log (FRE-39 Teacher Dashboard analytics)
CREATE TABLE IF NOT EXISTS download_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    content_id VARCHAR(255) NOT NULL,
    content_title VARCHAR(500) DEFAULT NULL,
    content_type VARCHAR(50) DEFAULT NULL,
    downloaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_downloaded_at (downloaded_at),
    INDEX idx_content_id (content_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Saved lesson plans (Teacher Content Finder - Priority #5)
-- Publish columns added by migration 0007
CREATE TABLE IF NOT EXISTS lesson_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT,
    title VARCHAR(255) NOT NULL,
    content_ids JSON,
    published_segments JSON DEFAULT NULL
        COMMENT 'NULL or [] = private. Array of segment keys, e.g. ["knowledge_power","early_learners"]',
    icon VARCHAR(10) DEFAULT '📚',
    color VARCHAR(7) DEFAULT '#4ECDC4',
    description VARCHAR(500) DEFAULT '',
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
);
