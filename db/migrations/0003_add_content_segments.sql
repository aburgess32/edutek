-- Migration 0003: Add content_segments table for FRE-9 Visual Home Tiles
-- Tracks which content belongs to which audience segments
-- Supports multiple segments per content item

CREATE TABLE IF NOT EXISTS content_segments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    content_path VARCHAR(255) NOT NULL COMMENT 'Relative path to content (e.g., videos/Primary Multiplication)',
    content_type ENUM('video_group', 'video', 'service', 'resource', 'book', 'audio') NOT NULL DEFAULT 'video_group',
    segment VARCHAR(50) NOT NULL COMMENT 'Segment key: early_learners, explorers, advanced, educators, knowledge_power',
    label VARCHAR(100) DEFAULT NULL COMMENT 'Display label override (null = use folder name)',
    icon VARCHAR(50) DEFAULT NULL COMMENT 'Emoji or icon class for tile display',
    color_hex CHAR(7) DEFAULT NULL COMMENT 'Accent color for tile (e.g., #4ECDC4)',
    image_path VARCHAR(255) DEFAULT NULL COMMENT 'Path to tile thumbnail image',
    href VARCHAR(255) DEFAULT NULL COMMENT 'Link override (null = auto-generated)',
    sort_order TINYINT UNSIGNED DEFAULT 0 COMMENT 'Display order within segment',
    suggested_by ENUM('ai', 'admin', 'keyword', 'system') DEFAULT 'system',
    confirmed TINYINT(1) DEFAULT 1 COMMENT '0 = pending admin confirmation, 1 = confirmed',
    active TINYINT(1) DEFAULT 1 COMMENT '0 = hidden, 1 = visible',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_content_segment (content_path, segment),
    INDEX idx_segment (segment),
    INDEX idx_active (active, segment)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: Early Learners
INSERT INTO content_segments (content_path, content_type, segment, label, sort_order) VALUES
('videos/Primary Multiplication', 'video_group', 'early_learners', 'Primary Multiplication', 1),
('videos/Comic Books', 'video_group', 'early_learners', 'Comic Books', 2),
('videos/Audiobooks', 'video_group', 'early_learners', 'Audiobooks', 3),
('service/khan', 'service', 'early_learners', 'Khan Academy', 4),
('service/kiwix-khan', 'service', 'early_learners', 'Kiwix Khan', 5);

-- Seed: Explorers
INSERT INTO content_segments (content_path, content_type, segment, label, sort_order) VALUES
('videos/Audiobooks', 'video_group', 'explorers', 'Audiobooks', 1),
('videos/Comic Books', 'video_group', 'explorers', 'Comic Books', 2),
('videos/Generative AI with LLMs', 'video_group', 'explorers', 'Generative AI', 3),
('service/kiwix-wiki', 'service', 'explorers', 'Wiki', 4),
('service/khan', 'service', 'explorers', 'Khan Academy', 5),
('service/ai-tools', 'service', 'explorers', 'AI Tools', 6);

-- Seed: Advanced
INSERT INTO content_segments (content_path, content_type, segment, label, sort_order) VALUES
('videos/Electrical Engineering', 'video_group', 'advanced', 'Electrical Engineering', 1),
('videos/Hotel Management & Catering', 'video_group', 'advanced', 'Hotel Management', 2),
('videos/Generative AI with LLMs', 'video_group', 'advanced', 'Generative AI', 3),
('service/kiwix-medical', 'service', 'advanced', 'Kiwix Medical', 4),
('service/kiwix-wiki', 'service', 'advanced', 'Wiki', 5);

-- Seed: Educators
INSERT INTO content_segments (content_path, content_type, segment, label, sort_order) VALUES
('service/khan', 'service', 'educators', 'Khan Academy', 1),
('service/kiwix-khan', 'service', 'educators', 'Kiwix Khan', 2),
('service/kiwix-wiki', 'service', 'educators', 'Wiki', 3),
('service/kiwix-medical', 'service', 'educators', 'Kiwix Medical', 4);

-- Knowledge is Power: starts empty (local admins populate per deployment)
