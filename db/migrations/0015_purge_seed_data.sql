-- 0015_purge_seed_data.sql
-- Purge seed/test data from analytics tables so the dashboard only shows live data.
-- Run this once to clean out any data inserted by the now-removed seed_dashboard.php.
--
-- WARNING: This truncates search_log and download_log entirely.
-- If you have real production data in these tables you want to keep,
-- review carefully before running. For a fresh start this is safe.

TRUNCATE TABLE search_log;
TRUNCATE TABLE download_log;
