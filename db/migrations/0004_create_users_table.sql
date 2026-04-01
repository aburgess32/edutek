-- ============================================================
-- Migration: 0004_create_users_table.sql
-- Description: Add avatar-based auth columns to users table
--              for FRE-11 Simple Name Login
-- Author: EduTek
-- ============================================================

-- UP

USE edupak;

-- Add new columns to existing users table
ALTER TABLE users
    ADD COLUMN avatar_name   VARCHAR(20)  DEFAULT NULL AFTER display_name,
    ADD COLUMN avatar_color  CHAR(7)      DEFAULT NULL AFTER avatar_name,
    ADD COLUMN email         VARCHAR(255) DEFAULT NULL AFTER avatar_color,
    ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL AFTER email,
    ADD COLUMN age_range     ENUM('under_10','10_14','15_19','20_plus') DEFAULT NULL AFTER password_hash,
    MODIFY COLUMN user_type  ENUM('kid','teen','adult','student','teacher') DEFAULT 'student';

-- Avatar names must be unique (one avatar per student)
ALTER TABLE users
    ADD UNIQUE INDEX uq_avatar_name (avatar_name);

-- Email must be unique for teacher accounts
ALTER TABLE users
    ADD UNIQUE INDEX uq_email (email);

-- Index for age range queries
ALTER TABLE users
    ADD INDEX idx_age_range (age_range);

-- Index for display name lookups (find-user A-Z)
ALTER TABLE users
    ADD INDEX idx_display_name (display_name);

-- DOWN

ALTER TABLE users
    DROP INDEX idx_display_name,
    DROP INDEX idx_age_range,
    DROP INDEX uq_email,
    DROP INDEX uq_avatar_name,
    DROP COLUMN age_range,
    DROP COLUMN password_hash,
    DROP COLUMN email,
    DROP COLUMN avatar_color,
    DROP COLUMN avatar_name;

ALTER TABLE users
    MODIFY COLUMN user_type ENUM('kid','teen','adult','teacher') DEFAULT 'kid';
