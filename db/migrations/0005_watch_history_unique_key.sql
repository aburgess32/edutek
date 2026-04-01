-- ============================================================
-- Migration: 0005_watch_history_unique_key.sql
-- Description: Add UNIQUE constraint on (user_id, content_id)
--              to watch_history for ON DUPLICATE KEY UPDATE support
--              (FRE-10 Continue Watching)
-- Author: EduTek
-- ============================================================

-- UP

USE edupak;

-- Remove duplicate rows before adding constraint (keep most recent)
DELETE wh1 FROM watch_history wh1
INNER JOIN watch_history wh2
    ON wh1.user_id = wh2.user_id
    AND wh1.content_id = wh2.content_id
    AND wh1.last_watched < wh2.last_watched;

-- Handle exact timestamp ties (keep lowest id)
DELETE wh1 FROM watch_history wh1
INNER JOIN watch_history wh2
    ON wh1.user_id = wh2.user_id
    AND wh1.content_id = wh2.content_id
    AND wh1.last_watched = wh2.last_watched
    AND wh1.id > wh2.id;

-- Add unique constraint for upsert support
ALTER TABLE watch_history
    ADD UNIQUE INDEX uq_user_content (user_id, content_id);

-- DOWN

ALTER TABLE watch_history
    DROP INDEX uq_user_content;
