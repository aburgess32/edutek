-- FRE-48: Add target_type and target_ids to lesson_assignments
-- Fixes duplicate assignment cards by storing scope in a single row
-- instead of creating one row per student.
--
-- target_type: 'all' (whole class), 'group' (specific groups), 'individual' (specific students)
-- target_ids:  JSON array of student IDs or group IDs depending on target_type (NULL for 'all')

ALTER TABLE lesson_assignments
    ADD COLUMN target_type ENUM('all', 'group', 'individual') NOT NULL DEFAULT 'all' AFTER assigned_to,
    ADD COLUMN target_ids TEXT DEFAULT NULL COMMENT 'JSON array of student or group IDs' AFTER target_type;

-- Backfill existing rows:
-- Rows with assigned_to IS NULL are already 'all' (the default)
-- Rows with assigned_to set are legacy individual assignments
UPDATE lesson_assignments SET target_type = 'individual', target_ids = CONCAT('[', assigned_to, ']') WHERE assigned_to IS NOT NULL;
