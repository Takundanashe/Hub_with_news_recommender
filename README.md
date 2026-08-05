# App Hub — Social, Marketplace & News Platform

A self-hosted community hub combining social features, a marketplace, real-time messaging, and a personalized news feed — built on a lightweight nginx + PHP + SQLite stack with a Python-based hybrid recommendation engine powering the News section.

## Features

- **Auth & profiles** — signup/login, follow system, privacy controls, location sharing
- **Real-time messaging** — DMs and Groups over WebSocket (Ratchet), no page reloads
- **Marketplace** — Market, Jobs, Lost & Found, and Housing listings, each with reviews
- **News feed** — short-form posts with like/dislike, Echo (repost), and comments, ranked by a hybrid recommendation engine (see below)
- **Wallet** — in-app credits system with MoneyID and QR-based transfers
- **Mobile-first UI** — fixed bottom tab bar on small screens, full top nav on desktop, reduced-motion-aware transitions throughout

## News Feed Recommender

The News feed doesn't rely on predefined categories — posts are short, tweet-like text with no title/category field. Instead, the ranking pipeline combines:

| Component | Method | Purpose |
|---|---|---|
| Topic discovery | BERTopic (sentence-transformer + UMAP + HDBSCAN) | Unsupervised clustering of post text into topic groups — a substitute for hand-labeled categories |
| Item-based CF | Implicit ALS | "Twin posts" — recommends based on co-engagement patterns |
| User-based CF | Implicit ALS | Recommends what behaviorally similar users engaged with |
| Bridge discovery | FP-Growth (low support, high lift) | Mines rare, cross-topic association rules to surface unexpected content and avoid echo chambers |
| Cold-start fallback | Cluster/content matching + recency | Keeps the feed working from day one, before enough interaction data exists for CF/Bridge to activate |
| Freshness | Exponential time decay | Recent posts are weighted higher, applied live at request time |

The pipeline is designed to **degrade gracefully** — with little data (a new install, few users), the feed falls back to content-matching and recency automatically; ALS and Bridge Apriori activate on their own once enough likes and impressions accumulate.

Prototyped and validated offline against the MIND-small news dataset before being ported to this app's schema.

## Tech Stack

- **Backend:** PHP 8.4-FPM, SQLite3 (PDO)
- **Web server:** nginx
- **Real-time:** Ratchet (PHP WebSocket server), running as its own long-lived process
- **Recommender:** Python (sentence-transformers, BERTopic, implicit, mlxtend), run as a scheduled batch pipeline, decoupled from the live PHP app
- **Frontend:** Vanilla JS/CSS, mobile-first, no framework

## Architecture

```
Browser
   │
   ▼
 nginx ──────► PHP-FPM (app logic) ──────► SQLite (data/app.sqlite)
   │                                              ▲
   ▼                                              │
Ratchet WebSocket server (DMs/Groups,        news_posts.cluster_id,
long-lived process, localhost only)          news_als_candidates,
                                              news_bridge_rules
                                                    ▲
                                                    │
                                    recommender/ (Python, cron-scheduled)
                                    cluster_posts.py → train_recommender.py
```

## Setup

### 1. Install packages
```bash
sudo apt update
sudo apt install -y nginx php8.4-fpm php8.4-sqlite3 php8.4-gd php8.4-mbstring \
                     sqlite3 composer
```

### 2. Place the project
```bash
sudo mkdir -p /var/www/app
sudo cp -r ./* /var/www/app/
cd /var/www/app
```

### 3. Create the database
The SQLite file lives in `data/`, outside `public/` (the web root), so it's never reachable by direct path guessing.
```bash
mkdir -p data logs
sqlite3 data/app.sqlite < sql/schema.sql
for f in sql/migrations/*.sql; do sqlite3 data/app.sqlite < "$f"; done
```

### 4. Permissions
PHP-FPM runs as `www-data` and needs write access to `data/` (DB + WAL files), `logs/`, and `public/uploads/`.
```bash
sudo chown -R www-data:www-data /var/www/app/data /var/www/app/logs /var/www/app/public/uploads
sudo chmod 770 /var/www/app/data
```

**If you (or a cron job) will also write to the database as a different user** (e.g. running the recommender scripts as yourself rather than `www-data`), add that user to the `www-data` group:
```bash
sudo usermod -aG www-data <your-username>
```
Also make sure PHP-FPM creates new files (including SQLite's WAL/SHM sidecar files) as group-writable, or this permission gap can silently reappear later:
```bash
sudo EDITOR=nano systemctl edit php8.4-fpm
```
Add:
```
[Service]
UMask=0002
```
Then:
```bash
sudo systemctl daemon-reload
sudo systemctl restart php8.4-fpm
```

### 5. PHP-FPM pool
```bash
sudo cp server/php-fpm-pool.conf /etc/php/8.4/fpm/pool.d/app.conf
sudo systemctl restart php8.4-fpm
```

### 6. nginx
Edit `server/nginx.conf` — set `server_name` to your local hostname (e.g. `app.local`, added to `/etc/hosts`), and add the `limit_req_zone` line into the `http {}` block of `/etc/nginx/nginx.conf` (see comment at the bottom of `server/nginx.conf`).
```bash
sudo cp server/nginx.conf /etc/nginx/sites-available/app.conf
sudo ln -s /etc/nginx/sites-available/app.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 7. WebSocket server (Ratchet)
Runs as its own long-lived process, separate from PHP-FPM.
```bash
cd server/websocket
composer install
php server.php
```
For production, run this under systemd rather than a plain terminal so it survives reboots/crashes.

### 8. Recommender pipeline
```bash
cd recommender
python3 -m venv venv          # or use a conda env — either works
source venv/bin/activate
pip install torch --index-url https://download.pytorch.org/whl/cpu   # CPU-only machines
pip install -r requirements.txt
```

Run once manually to confirm it works end-to-end before scheduling it:
```bash
python3 cluster_posts.py --db /var/www/app/data/app.sqlite --min-cluster-size 3
python3 train_recommender.py --db /var/www/app/data/app.sqlite
```

### 9. Try it
Visit `http://app.local/signup.php`, create an account, and you'll land on the home hub with links to Market, Jobs, Lost & Found, Housing, News, Groups, Messages, Wallet, and Location.

## Scheduling the recommender (cron)

The recommender is intentionally decoupled from the live app — it runs as a periodic batch job, not a live service, which keeps the PHP app simple and means a slow/failed training run never affects page load times.

`recommender/run_recommender.sh` runs both stages in the correct order (cluster assignment must complete before ALS/Bridge training, since the latter depends on `cluster_id` being populated) and logs everything to `logs/recommender.log`.

```bash
chmod +x /var/www/app/recommender/run_recommender.sh
crontab -e
```

Add:
```
0 3 * * * /var/www/app/recommender/run_recommender.sh
```

| Schedule | When to use it |
|---|---|
| `0 3 * * *` (daily, 3 AM) | Recommended default — enough for most community-scale apps; low traffic overnight |
| `0 */6 * * *` (every 6 hours) | Higher-activity apps with frequent new posts/likes |
| `*/2 * * * *` (every 2 minutes) | **Testing only** — confirms cron is actually firing; switch back to a real schedule once verified |

**Verifying it's working:**
```bash
crontab -l                                   # confirm the job is registered
grep CRON /var/log/syslog | tail -10         # confirm cron actually triggered it
cat /var/www/app/logs/recommender.log        # confirm the run completed cleanly

sqlite3 data/app.sqlite \
  "SELECT COUNT(*), MAX(computed_at) FROM news_als_candidates;"
sqlite3 data/app.sqlite \
  "SELECT COUNT(*), MAX(computed_at) FROM news_bridge_rules;"
```
Non-zero counts with a recent `computed_at` confirm the pipeline is producing real output. Zero counts with no errors in the log usually just means there isn't enough interaction data yet — both scripts are designed to skip gracefully rather than fail when that's the case.

## Security notes

- CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, and a restrictive Permissions-Policy are sent on every request (`includes/security.php`). CSP uses a per-request nonce for inline bootstrap `<script>` tags — no `unsafe-inline` anywhere.
- All page JS uses `textContent`/`createTextNode`, never `innerHTML`, when rendering user-generated content — a second layer behind server-side `htmlspecialchars()` escaping.
- Any external links must be paired with `rel="noopener noreferrer"` to prevent tabnabbing.
- The internal WebSocket push channel (`127.0.0.1:8081`) must never be exposed through nginx or opened to any interface other than localhost.
- The recommender's Python environment and trained artifacts are entirely separate from the PHP app's runtime — a compromised or crashed training job cannot affect the live site.

## What's next

- Dwell-time tracking (currently only impressions and reactions are logged, not time-on-post)
- Diversity re-ranking pass (MMR) on the live feed query
- Dynamic slot ratios (time of day, weekend, breaking-content boosts) instead of the current fixed blend
- Adding market items recommender,  
