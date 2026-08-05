-- Run against your existing app.sqlite:
--   sqlite3 data/app.sqlite < sql/migrations/002_add_comment_likes.sql
CREATE TABLE IF NOT EXISTS news_comment_likes (
    comment_id      INTEGER NOT NULL REFERENCES news_comments(id) ON DELETE CASCADE,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (comment_id, user_id)
);
