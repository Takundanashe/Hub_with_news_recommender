-- Run against your existing app.sqlite:
--   sqlite3 data/app.sqlite < sql/migrations/005_add_group_last_read.sql
--ALTER TABLE group_members ADD COLUMN last_read_at TEXT DEFAULT NULL;

-- Existing members: treat "now" as their baseline so they don't suddenly
-- see every historical group message counted as unread.
UPDATE group_members SET last_read_at = datetime('now') WHERE last_read_at IS NULL;
