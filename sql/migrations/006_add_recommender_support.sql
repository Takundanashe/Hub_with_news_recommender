-- Run against your existing app.sqlite:
--   sqlite3 data/app.sqlite < sql/migrations/006_add_recommender_support.sql
--
-- Adds unsupervised topic clustering + impression ("shown but not clicked")
-- tracking support to the news feed, needed for the recommender pipeline.

ALTER TABLE news_posts ADD COLUMN cluster_id INTEGER;

-- "Shown but not clicked" signal — logged whenever a post is rendered in a
-- user's feed. clicked flips to 1 if the user opens/expands the post
-- (wired up separately once we add that interaction on the frontend).
CREATE TABLE IF NOT EXISTS news_impressions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    news_id     INTEGER NOT NULL REFERENCES news_posts(id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    clicked     INTEGER NOT NULL DEFAULT 0,
    shown_at    TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_news_impressions_user ON news_impressions(user_id, shown_at);
CREATE INDEX IF NOT EXISTS idx_news_impressions_news ON news_impressions(news_id);

-- Bridge Apriori rules — small enough to query live from PHP at request time.
CREATE TABLE IF NOT EXISTS news_bridge_rules (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    antecedent_cluster  INTEGER NOT NULL,
    consequent_cluster  INTEGER NOT NULL,
    support             REAL NOT NULL,
    confidence          REAL NOT NULL,
    lift                REAL NOT NULL,
    computed_at         TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_bridge_rules_antecedent ON news_bridge_rules(antecedent_cluster);

-- Precomputed per-user item-based candidates from ALS, refreshed on each
-- retrain. Storing only top-K per user keeps this small — a full dense
-- user-item matrix has no place in SQLite.
CREATE TABLE IF NOT EXISTS news_als_candidates (
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    news_id     INTEGER NOT NULL REFERENCES news_posts(id) ON DELETE CASCADE,
    source      TEXT NOT NULL CHECK (source IN ('item_based', 'user_based')),
    als_score   REAL NOT NULL,
    computed_at TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (user_id, news_id, source)
);
CREATE INDEX IF NOT EXISTS idx_als_candidates_user ON news_als_candidates(user_id, source, als_score DESC);
