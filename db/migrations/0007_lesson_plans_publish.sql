-- Migration 0007: Add publish/segment columns to lesson_plans
-- Enables publishing lesson plans to student-facing segments (multi-segment support)

-- UP
ALTER TABLE lesson_plans
    ADD COLUMN published_segments JSON DEFAULT NULL
        COMMENT 'NULL or [] = private. Array of segment keys, e.g. ["knowledge_power","early_learners"]',
    ADD COLUMN icon        VARCHAR(10) DEFAULT '📚',
    ADD COLUMN color       VARCHAR(7)  DEFAULT '#4ECDC4',
    ADD COLUMN description VARCHAR(500) DEFAULT '',
    ADD COLUMN sort_order  INT DEFAULT 0,
    ADD COLUMN updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- DOWN
ALTER TABLE lesson_plans
    DROP COLUMN updated_at,
    DROP COLUMN sort_order,
    DROP COLUMN description,
    DROP COLUMN color,
    DROP COLUMN icon,
    DROP COLUMN published_segments;
