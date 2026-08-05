-- ============================================================
-- Community App - SQLite Schema
-- Phase 1 tables are fully wired up in this pass.
-- Later-phase tables exist now so we never have to migrate
-- live data mid-project, but are not yet exposed in the UI.
-- ============================================================

PRAGMA foreign_keys = ON;

-- ---------- Core identity ----------

CREATE TABLE IF NOT EXISTS users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id       TEXT UNIQUE NOT NULL,      -- random opaque id used in URLs (never expose autoincrement id)
    username        TEXT UNIQUE NOT NULL,
    fname           TEXT NOT NULL,
    lname           TEXT NOT NULL,
    email           TEXT UNIQUE NOT NULL,
    phone           TEXT,
    password_hash   TEXT NOT NULL,
    avatar          TEXT DEFAULT 'default.png',
    bio             TEXT DEFAULT '',
    status          TEXT DEFAULT 'offline',
    -- privacy settings
    dm_permission   TEXT NOT NULL DEFAULT 'everyone' CHECK (dm_permission IN ('everyone','followers','no_one')),
    phone_visibility  TEXT NOT NULL DEFAULT 'private' CHECK (phone_visibility IN ('public','private')),
    email_visibility  TEXT NOT NULL DEFAULT 'private' CHECK (email_visibility IN ('public','private')),
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);

CREATE TABLE IF NOT EXISTS sessions (
    id              TEXT PRIMARY KEY,          -- session token (random, stored hashed at rest is ideal; kept simple here)
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    ip_address      TEXT,
    user_agent      TEXT,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    expires_at      TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS login_attempts (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    identifier      TEXT NOT NULL,             -- email or IP, used for rate limiting
    attempted_at    TEXT NOT NULL DEFAULT (datetime('now')),
    success         INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_login_attempts_identifier ON login_attempts(identifier, attempted_at);

-- ---------- Social graph ----------

CREATE TABLE IF NOT EXISTS follows (
    follower_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    followed_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (follower_id, followed_id)
);
CREATE INDEX IF NOT EXISTS idx_follows_followed ON follows(followed_id);

-- ---------- Direct messages ----------

CREATE TABLE IF NOT EXISTS direct_messages (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id       TEXT UNIQUE NOT NULL,
    sender_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    recipient_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body            TEXT NOT NULL,
    is_deleted      INTEGER NOT NULL DEFAULT 0,
    read_at         TEXT,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_dm_thread ON direct_messages(sender_id, recipient_id, created_at);

-- ---------- Location sharing (mutual, opt-in, time-boxed) ----------

CREATE TABLE IF NOT EXISTS location_shares (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    sharer_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    viewer_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    is_active       INTEGER NOT NULL DEFAULT 1,
    expires_at      TEXT,                      -- NULL = until manually stopped
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (sharer_id, viewer_id)
);

CREATE TABLE IF NOT EXISTS location_pings (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    share_id        INTEGER NOT NULL REFERENCES location_shares(id) ON DELETE CASCADE,
    latitude        REAL NOT NULL,
    longitude       REAL NOT NULL,
    recorded_at     TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_location_pings_share ON location_pings(share_id, recorded_at);

-- ---------- Groups ----------

CREATE TABLE IF NOT EXISTS groups_table (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id       TEXT UNIQUE NOT NULL,
    name            TEXT NOT NULL,
    description     TEXT DEFAULT '',
    avatar          TEXT DEFAULT 'default_group.png',
    privacy         TEXT NOT NULL DEFAULT 'public' CHECK (privacy IN ('public','private')),
    creator_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS group_members (
    group_id        INTEGER NOT NULL REFERENCES groups_table(id) ON DELETE CASCADE,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role            TEXT NOT NULL DEFAULT 'member' CHECK (role IN ('owner','admin','member')),
    joined_at       TEXT NOT NULL DEFAULT (datetime('now')),
    last_read_at    TEXT DEFAULT (datetime('now')),
    PRIMARY KEY (group_id, user_id)
);

CREATE TABLE IF NOT EXISTS group_messages (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id        INTEGER NOT NULL REFERENCES groups_table(id) ON DELETE CASCADE,
    sender_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body            TEXT NOT NULL,
    is_deleted      INTEGER NOT NULL DEFAULT 0,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_group_messages_group ON group_messages(group_id, created_at);

-- ---------- News feed ----------

CREATE TABLE IF NOT EXISTS news_posts (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id       TEXT UNIQUE NOT NULL,
    author_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body            TEXT NOT NULL,
    image           TEXT,
    comments_enabled INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS news_reactions (
    news_id         INTEGER NOT NULL REFERENCES news_posts(id) ON DELETE CASCADE,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    reaction        TEXT NOT NULL CHECK (reaction IN ('like','dislike')),
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (news_id, user_id)
);

CREATE TABLE IF NOT EXISTS news_echoes (               -- "Echo" = our repost equivalent
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    news_id         INTEGER NOT NULL REFERENCES news_posts(id) ON DELETE CASCADE,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    comment         TEXT DEFAULT '',                    -- optional quote-echo text
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (news_id, user_id)
);

CREATE TABLE IF NOT EXISTS news_comments (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    news_id         INTEGER NOT NULL REFERENCES news_posts(id) ON DELETE CASCADE,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    parent_comment_id INTEGER NULL REFERENCES news_comments(id) ON DELETE CASCADE,
    body            TEXT NOT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_news_comments_news ON news_comments(news_id);
CREATE INDEX IF NOT EXISTS idx_news_comments_parent ON news_comments(parent_comment_id);

CREATE TABLE IF NOT EXISTS news_comment_likes (
    comment_id      INTEGER NOT NULL REFERENCES news_comments(id) ON DELETE CASCADE,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (comment_id, user_id)
);

-- ---------- Listings (Market / Jobs / Lost & Found / Housing) ----------

CREATE TABLE IF NOT EXISTS listings (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id       TEXT UNIQUE NOT NULL,
    owner_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type            TEXT NOT NULL CHECK (type IN ('goods','job','lost_found','housing')),
    title           TEXT NOT NULL,
    description     TEXT NOT NULL DEFAULT '',
    price           REAL,                       -- NULL where not applicable (e.g. lost & found)
    currency        TEXT DEFAULT 'USD',
    location        TEXT,
    status          TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','pending','closed')),
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_listings_type ON listings(type, status);
CREATE INDEX IF NOT EXISTS idx_listings_owner ON listings(owner_id);

CREATE TABLE IF NOT EXISTS listing_images (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    listing_id      INTEGER NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
    filename        TEXT NOT NULL,
    sort_order      INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS listing_details_job (
    listing_id      INTEGER PRIMARY KEY REFERENCES listings(id) ON DELETE CASCADE,
    employment_type TEXT,                       -- full_time / part_time / contract
    salary_min      REAL,
    salary_max      REAL,
    company_name    TEXT
);

CREATE TABLE IF NOT EXISTS listing_details_housing (
    listing_id      INTEGER PRIMARY KEY REFERENCES listings(id) ON DELETE CASCADE,
    listing_purpose TEXT CHECK (listing_purpose IN ('rent','sale')),
    bedrooms        INTEGER,
    bathrooms       INTEGER,
    lease_term      TEXT
);

CREATE TABLE IF NOT EXISTS listing_details_lost_found (
    listing_id      INTEGER PRIMARY KEY REFERENCES listings(id) ON DELETE CASCADE,
    report_type     TEXT CHECK (report_type IN ('lost','found')),
    last_seen_at    TEXT,
    last_seen_location TEXT
);

CREATE TABLE IF NOT EXISTS listing_reviews (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    seller_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    reviewer_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    listing_id      INTEGER REFERENCES listings(id) ON DELETE SET NULL,
    rating          INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
    body            TEXT DEFAULT '',
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_reviews_seller ON listing_reviews(seller_id);

-- Raw behavioural signals, logged now so future ML training has clean data.
-- No scoring/recommendation logic reads this yet.
CREATE TABLE IF NOT EXISTS listing_events (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    listing_id      INTEGER NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
    user_id         INTEGER REFERENCES users(id) ON DELETE SET NULL,
    event_type      TEXT NOT NULL CHECK (event_type IN ('view','save','contact_seller')),
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_listing_events_listing ON listing_events(listing_id, event_type);

-- ---------- Wallet (in-app credits; provider-swap seam) ----------

CREATE TABLE IF NOT EXISTS wallets (
    user_id         INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    money_id        TEXT UNIQUE NOT NULL,       -- e.g. "MID-AB12CD34"
    balance_cents   INTEGER NOT NULL DEFAULT 0, -- store as integer cents, never floats
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS wallet_transactions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id       TEXT UNIQUE NOT NULL,
    sender_id       INTEGER REFERENCES users(id) ON DELETE SET NULL,
    recipient_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    amount_cents    INTEGER NOT NULL CHECK (amount_cents > 0),
    memo            TEXT DEFAULT '',
    status          TEXT NOT NULL DEFAULT 'completed' CHECK (status IN ('pending','completed','failed','reversed')),
    -- provider fields are NULL for the in-app credits phase; populated once
    -- a licensed processor (Flutterwave/Paystack/M-Pesa/etc.) is wired in.
    provider        TEXT,
    provider_ref    TEXT,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_wallet_tx_sender ON wallet_transactions(sender_id);
CREATE INDEX IF NOT EXISTS idx_wallet_tx_recipient ON wallet_transactions(recipient_id);
