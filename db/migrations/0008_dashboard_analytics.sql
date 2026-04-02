-- ============================================================
-- Migration: 0008_dashboard_analytics.sql
-- Description: Add search_log and download_log tables for
--              FRE-39 Teacher Dashboard analytics
-- Author: EduTek
-- ============================================================

-- UP

USE edupak;

CREATE TABLE IF NOT EXISTS search_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    query VARCHAR(255) NOT NULL,
    result_count INT DEFAULT 0,
    searched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_searched_at (searched_at),
    INDEX idx_query (query(100)),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- DOWN

DROP TABLE IF EXISTS download_log;
DROP TABLE IF EXISTS search_log;
