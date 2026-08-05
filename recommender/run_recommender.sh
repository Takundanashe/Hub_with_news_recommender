#!/bin/bash
# Runs the full recommender pipeline in order: cluster assignment first
# (train_recommender.py depends on cluster_id being populated), then
# ALS + Bridge Apriori training. Logs everything with timestamps so cron
# runs are debuggable after the fact.

set -euo pipefail

APP_DIR="/var/www/app"
# Replace with the exact path printed by:
#   conda activate app_news_recommender && which python3
CONDA_PYTHON="/home/takunda/anaconda3/envs/app_news_recommender/bin/python3"
DB_PATH="$APP_DIR/data/app.sqlite"
LOG_DIR="$APP_DIR/logs"
LOG_FILE="$LOG_DIR/recommender.log"

mkdir -p "$LOG_DIR"

echo "===== $(date '+%Y-%m-%d %H:%M:%S') — recommender run starting =====" >> "$LOG_FILE"

echo "--- cluster_posts.py ---" >> "$LOG_FILE"
"$CONDA_PYTHON" "$APP_DIR/recommender/cluster_posts.py" --db "$DB_PATH" --min-cluster-size 3 >> "$LOG_FILE" 2>&1

echo "--- train_recommender.py ---" >> "$LOG_FILE"
"$CONDA_PYTHON" "$APP_DIR/recommender/train_recommender.py" --db "$DB_PATH" >> "$LOG_FILE" 2>&1

echo "===== $(date '+%Y-%m-%d %H:%M:%S') — recommender run finished =====" >> "$LOG_FILE"
echo "" >> "$LOG_FILE"
