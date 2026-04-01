-- ============================================================
-- Migration: 0006_content_meta_categories.sql
-- Description: Add category, subcategory, source columns to
--              content_meta and a FULLTEXT index for enhanced
--              search across all metadata fields (FRE-13).
-- Author: EduTek
-- ============================================================

-- UP

USE edupak;

-- Add search-related columns to content_meta
ALTER TABLE content_meta
    ADD COLUMN category    VARCHAR(100) DEFAULT '' AFTER content_type,
    ADD COLUMN subcategory VARCHAR(100) DEFAULT '' AFTER category,
    ADD COLUMN source      VARCHAR(100) DEFAULT '' AFTER subcategory;

-- Drop the existing FULLTEXT index (title, description only)
ALTER TABLE content_meta
    DROP INDEX idx_search;

-- Create new FULLTEXT index spanning title, category, subcategory, source
ALTER TABLE content_meta
    ADD FULLTEXT INDEX ft_search (title, category, subcategory, source);

-- DOWN

USE edupak;

-- Remove the new FULLTEXT index
ALTER TABLE content_meta
    DROP INDEX ft_search;

-- Restore original FULLTEXT index on (title, description)
ALTER TABLE content_meta
    ADD FULLTEXT INDEX idx_search (title, description);

-- Drop the added columns
ALTER TABLE content_meta
    DROP COLUMN category,
    DROP COLUMN subcategory,
    DROP COLUMN source;
