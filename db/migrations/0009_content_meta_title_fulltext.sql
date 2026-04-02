-- Migration: 0009_content_meta_title_fulltext.sql
-- Description: Add a FULLTEXT index on title only for title-priority search ranking.

-- UP
ALTER TABLE content_meta
    ADD FULLTEXT INDEX ft_title (title);

-- DOWN
ALTER TABLE content_meta
    DROP INDEX ft_title;
