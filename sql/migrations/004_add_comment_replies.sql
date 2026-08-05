-- Run against your existing app.sqlite:
--   sqlite3 data/app.sqlite < sql/migrations/004_add_comment_replies.sql
--
-- SQLite can't add a column with a REFERENCES clause via a simple
-- ALTER TABLE ADD COLUMN in older versions in a way that's enforced, but
-- foreign_keys enforcement in this app checks on write, not on the column
-- definition itself, so a plain ADD COLUMN is fine here.
ALTER TABLE news_comments ADD COLUMN parent_comment_id INTEGER NULL REFERENCES news_comments(id) ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS idx_news_comments_parent ON news_comments(parent_comment_id);
