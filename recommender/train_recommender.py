"""
Trains item-based/user-based ALS and mines Bridge Apriori rules from real
interaction data, writing results into news_als_candidates and
news_bridge_rules for the PHP feed to read directly.

Run this AFTER cluster_posts.py (it depends on cluster_id being populated).

Usage:
    python3 train_recommender.py --db /var/www/app/data/app.sqlite
"""
import argparse
import sqlite3
from collections import defaultdict

import numpy as np
import scipy.sparse as sp
import implicit
from mlxtend.frequent_patterns import fpgrowth, association_rules
from mlxtend.preprocessing import TransactionEncoder
import pandas as pd

TOP_K = 15  # candidates stored per user, per source
MIN_USERS_FOR_ALS = 3
MIN_LIKES_FOR_ALS = 5
MIN_TRANSACTIONS_FOR_BRIDGE = 5


def train_als(conn):
    cur = conn.cursor()
    likes = cur.execute(
        "SELECT user_id, news_id FROM news_reactions WHERE reaction = 'like'"
    ).fetchall()

    n_users = len(set(r[0] for r in likes))
    if n_users < MIN_USERS_FOR_ALS or len(likes) < MIN_LIKES_FOR_ALS:
        print(f"Only {n_users} users / {len(likes)} likes — need at least "
              f"{MIN_USERS_FOR_ALS} users and {MIN_LIKES_FOR_ALS} likes for ALS. "
              f"Skipping ALS training this run; feed will fall back to "
              f"content/recency ranking.")
        return

    user_ids = sorted(set(r[0] for r in likes))
    item_ids = sorted(set(r[1] for r in likes))
    u_idx = {u: i for i, u in enumerate(user_ids)}
    i_idx = {n: i for i, n in enumerate(item_ids)}

    rows = [u_idx[r[0]] for r in likes]
    cols = [i_idx[r[1]] for r in likes]
    data = np.ones(len(likes))
    matrix = sp.coo_matrix((data, (rows, cols)),
                            shape=(len(user_ids), len(item_ids))).tocsr()

    factors = min(32, max(4, len(user_ids) // 2))  # scale down for tiny data
    model = implicit.als.AlternatingLeastSquares(factors=factors,
                                                   regularization=0.1,
                                                   iterations=15)
    model.fit(matrix)
    print(f"ALS trained: {len(user_ids)} users, {len(item_ids)} items, "
          f"{factors} factors")

    cur.execute("DELETE FROM news_als_candidates")

    inserts = []
    for user_id in user_ids:
        uidx = u_idx[user_id]

        # user-based: items similar users engaged with, excluding already-liked
        try:
            rec_ids, rec_scores = model.recommend(
                uidx, matrix[uidx], N=TOP_K, filter_already_liked_items=True
            )
            for iidx, score in zip(rec_ids, rec_scores):
                inserts.append((user_id, item_ids[iidx], 'user_based', float(score)))
        except Exception as e:
            print(f"  user-based recommend failed for user {user_id}: {e}")

        # item-based: twins of the user's most recently liked item
        user_liked = [r[1] for r in likes if r[0] == user_id]
        if user_liked:
            last_liked = user_liked[-1]
            if last_liked in i_idx:
                sim_ids, sim_scores = model.similar_items(i_idx[last_liked], N=TOP_K + 1)
                for iidx, score in zip(sim_ids, sim_scores):
                    if item_ids[iidx] != last_liked:
                        inserts.append((user_id, item_ids[iidx], 'item_based', float(score)))

    cur.executemany(
        "INSERT OR REPLACE INTO news_als_candidates (user_id, news_id, source, als_score) "
        "VALUES (?, ?, ?, ?)",
        inserts,
    )
    conn.commit()
    print(f"Wrote {len(inserts)} ALS candidate rows")


def mine_bridge_rules(conn):
    cur = conn.cursor()

    # Transactions: for each impression batch, the set of clusters the user
    # LIKED among posts shown in that batch (approximates MIND's per-session
    # click clusters).
    rows = cur.execute(
        """SELECT ni.batch_id, np.cluster_id
           FROM news_impressions ni
           JOIN news_reactions nr ON nr.news_id = ni.news_id AND nr.user_id = ni.user_id
                                   AND nr.reaction = 'like'
           JOIN news_posts np ON np.id = ni.news_id
           WHERE ni.batch_id IS NOT NULL AND np.cluster_id IS NOT NULL
                 AND np.cluster_id != -1"""
    ).fetchall()

    batches = defaultdict(set)
    for batch_id, cluster_id in rows:
        batches[batch_id].add(cluster_id)
    transactions = [list(c) for c in batches.values() if len(c) >= 2]

    if len(transactions) < MIN_TRANSACTIONS_FOR_BRIDGE:
        print(f"Only {len(transactions)} multi-cluster transactions — need at "
              f"least {MIN_TRANSACTIONS_FOR_BRIDGE} for Bridge Apriori. Skipping "
              f"this run; feed will rely on ALS/content/recency only.")
        cur.execute("DELETE FROM news_bridge_rules")
        conn.commit()
        return

    te = TransactionEncoder()
    te_array = te.fit(transactions).transform(transactions)
    trans_df = pd.DataFrame(te_array, columns=te.columns_)

    frequent = fpgrowth(trans_df, min_support=0.02, use_colnames=True)
    if frequent.empty:
        print("No frequent itemsets found at this support level. Skipping.")
        cur.execute("DELETE FROM news_bridge_rules")
        conn.commit()
        return

    rules = association_rules(frequent, metric="lift", min_threshold=1.5)
    rules = rules[rules["confidence"] >= 0.1].sort_values("lift", ascending=False)

    cur.execute("DELETE FROM news_bridge_rules")
    inserts = []
    for _, r in rules.iterrows():
        for ante in r["antecedents"]:
            for cons in r["consequents"]:
                inserts.append((int(ante), int(cons), float(r["support"]),
                                 float(r["confidence"]), float(r["lift"])))

    cur.executemany(
        "INSERT INTO news_bridge_rules (antecedent_cluster, consequent_cluster, "
        "support, confidence, lift) VALUES (?, ?, ?, ?, ?)",
        inserts,
    )
    conn.commit()
    print(f"Wrote {len(inserts)} bridge rules")


def main(db_path):
    conn = sqlite3.connect(db_path)
    train_als(conn)
    mine_bridge_rules(conn)
    conn.close()


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--db", required=True)
    args = parser.parse_args()
    main(args.db)
