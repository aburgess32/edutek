-- Remove seed/test data from analytics tables
-- The seed_dashboard.php script has been removed; the teacher dashboard
-- now displays only live production data.
--
-- This migration truncates the search_log and download_log tables so the
-- dashboard starts fresh with real user activity.  Run manually if you
-- prefer a targeted cleanup instead of a full truncate:
--
--   DELETE FROM search_log  WHERE user_id IN (SELECT id FROM users WHERE display_name IN ('Amara','Bayo','Chidinma','Dami','Emeka','Folake','Gbenga','Halima','Ife','Jelani'));
--   DELETE FROM download_log WHERE user_id IN (SELECT id FROM users WHERE display_name IN ('Amara','Bayo','Chidinma','Dami','Emeka','Folake','Gbenga','Halima','Ife','Jelani'));

TRUNCATE TABLE search_log;
TRUNCATE TABLE download_log;
