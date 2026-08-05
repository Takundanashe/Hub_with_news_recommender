-- Run against your existing app.sqlite:
--   sqlite3 data/app.sqlite < sql/migrations/007_add_impression_batch_id.sql
--
-- Groups impressions from the same feed load together (like MIND's
-- Impression ID) — needed so Bridge Apriori can mine "which clusters get
-- engaged with together in one session" rather than treating every shown
-- post as an isolated event.

ALTER TABLE news_impressions ADD COLUMN batch_id TEXT;
CREATE INDEX IF NOT EXISTS idx_news_impressions_batch ON news_impressions(batch_id);
