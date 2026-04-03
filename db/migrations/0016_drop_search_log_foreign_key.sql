-- Drop the FOREIGN KEY on search_log.user_id
--
-- The FK constraint (added in 0008_dashboard_analytics.sql) causes INSERT
-- failures for guest users. Guest sessions set user_id=0, which has no
-- matching row in the users table, so the FK rejects the insert.
-- This silently prevents ALL guest search queries from being logged,
-- which is why the teacher dashboard shows "No searches recorded yet."
--
-- Fix: drop the FK and rely on application-level logic instead.
-- Guest searches now insert user_id=NULL (handled in api/search.php).

-- UP

-- MySQL names the FK automatically; find and drop it.
-- The constraint name is typically 'search_log_ibfk_1' but we look it up
-- dynamically to be safe.

SET @fk_name = (
    SELECT CONSTRAINT_NAME
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'search_log'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    LIMIT 1
);

SET @drop_sql = IF(
    @fk_name IS NOT NULL,
    CONCAT('ALTER TABLE search_log DROP FOREIGN KEY ', @fk_name),
    'SELECT 1'
);

PREPARE stmt FROM @drop_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- DOWN
-- ALTER TABLE search_log ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;
