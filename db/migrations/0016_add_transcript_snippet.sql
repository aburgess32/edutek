-- UP
ALTER TABLE content_meta
    ADD COLUMN transcript_snippet TEXT DEFAULT NULL AFTER description,
    ADD COLUMN transcript_status ENUM('pending','processing','done','failed') DEFAULT NULL AFTER transcript_snippet,
    ADD COLUMN transcript_updated_at TIMESTAMP NULL DEFAULT NULL AFTER transcript_status,
    DROP INDEX ft_search,
    ADD FULLTEXT INDEX ft_search (title, description, category, subcategory, source, transcript_snippet);

UPDATE content_meta SET transcript_status = 'pending' WHERE content_type = 'video';

-- DOWN
ALTER TABLE content_meta
    DROP COLUMN transcript_snippet,
    DROP COLUMN transcript_status,
    DROP COLUMN transcript_updated_at,
    DROP INDEX ft_search,
    ADD FULLTEXT INDEX ft_search (title, category, subcategory, source);
