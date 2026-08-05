"""
Assigns cluster_id to every row in news_posts by clustering post bodies
with BERTopic. Meant to be run periodically (cron) — refits fresh each run,
which is appropriate at this app's current scale (small post volume).
Once post volume grows large enough that a full refit becomes slow, this
is the point to switch to BERTopic's incremental/online update mode instead.

Usage:
    python3 cluster_posts.py --db /var/www/app/data/app.sqlite
"""
import argparse
import sqlite3
from pathlib import Path

from sentence_transformers import SentenceTransformer
from bertopic import BERTopic
from sklearn.feature_extraction.text import CountVectorizer
from hdbscan import HDBSCAN
from umap import UMAP

MODEL_DIR = Path(__file__).parent / "models" / "bertopic_model"


def main(db_path: str, min_cluster_size: int):
    conn = sqlite3.connect(db_path)
    conn.row_factory = sqlite3.Row
    cur = conn.cursor()

    rows = cur.execute("SELECT id, body FROM news_posts").fetchall()
    if len(rows) < min_cluster_size * 2:
        print(f"Only {len(rows)} posts — need at least {min_cluster_size * 2} "
              f"for meaningful clustering. Skipping this run.")
        return

    ids = [r["id"] for r in rows]
    bodies = [r["body"] or "" for r in rows]

    print(f"Embedding {len(bodies)} posts...")
    embed_model = SentenceTransformer("all-MiniLM-L6-v2")
    embeddings = embed_model.encode(bodies, show_progress_bar=True, batch_size=256)

    vectorizer_model = CountVectorizer(stop_words="english", min_df=1)
    umap_model = UMAP(n_neighbors=15, n_components=5, min_dist=0.0,
                       metric="cosine", random_state=42)
    hdbscan_model = HDBSCAN(min_cluster_size=min_cluster_size, min_samples=3,
                            metric="euclidean", cluster_selection_method="eom",
                            prediction_data=True)

    topic_model = BERTopic(embedding_model=embed_model, umap_model=umap_model,
                            hdbscan_model=hdbscan_model,
                            vectorizer_model=vectorizer_model,
                            min_topic_size=min_cluster_size,
                            calculate_probabilities=False)

    print("Clustering...")
    cluster_ids, _ = topic_model.fit_transform(bodies, embeddings)

    n_real_clusters = len(set(cluster_ids)) - (1 if -1 in cluster_ids else 0)
    noise_count = sum(1 for c in cluster_ids if c == -1)

    if n_real_clusters == 0:
        print(f"No genuine clusters found yet — all {len(bodies)} posts landed in "
              f"the noise bucket. This usually means you don't have enough posts "
              f"clustered tightly enough around shared topics yet. Try a smaller "
              f"--min-cluster-size (e.g. 3-5), or accumulate more posts before "
              f"running this again. Skipping outlier reassignment for this run.")
    elif noise_count > 0:
        print(f"Reassigning {noise_count} noise-bucket posts...")
        cluster_ids = topic_model.reduce_outliers(bodies, cluster_ids, strategy="c-tf-idf")
        topic_model.update_topics(bodies, topics=cluster_ids)

    n_clusters = len(set(cluster_ids)) - (1 if -1 in cluster_ids else 0)
    print(f"Discovered {n_clusters} clusters "
          f"({sum(1 for c in cluster_ids if c == -1)} still unclustered)")

    print("Writing cluster_id back to news_posts...")
    cur.executemany(
        "UPDATE news_posts SET cluster_id = ? WHERE id = ?",
        [(int(c), int(i)) for c, i in zip(cluster_ids, ids)],
    )
    conn.commit()
    conn.close()

    MODEL_DIR.parent.mkdir(parents=True, exist_ok=True)
    topic_model.save(str(MODEL_DIR), serialization="safetensors",
                      save_embedding_model=True)
    print(f"Model saved to {MODEL_DIR}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--db", required=True, help="Path to app.sqlite")
    parser.add_argument("--min-cluster-size", type=int, default=10,
                         help="Lower than the MIND prototype's 15 — your "
                              "hub will have far fewer posts at first")
    args = parser.parse_args()
    main(args.db, args.min_cluster_size)
