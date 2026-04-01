USE edupak;

-- Check if avatar_name column exists; if not, add all FRE-11 columns
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='edupak' AND TABLE_NAME='users' AND COLUMN_NAME='avatar_name');

-- Just try adding — will error if exists but that's OK
ALTER TABLE users
    ADD COLUMN avatar_name   VARCHAR(50)  DEFAULT NULL,
    ADD COLUMN avatar_color  CHAR(7)      DEFAULT NULL,
    ADD COLUMN email         VARCHAR(255) DEFAULT NULL,
    ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL,
    ADD COLUMN age_range     ENUM('under_10','10_14','15_19','20_plus') DEFAULT NULL;
