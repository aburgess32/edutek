-- Add user metadata columns to search_log for enhanced analytics
ALTER TABLE search_log
  ADD COLUMN user_type VARCHAR(20) DEFAULT NULL AFTER user_id,
  ADD COLUMN age_range VARCHAR(20) DEFAULT NULL AFTER user_type;
