<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

start_secure_session();
$userId = require_login();
$db = get_db();

// ── Recommender: blended feed ranking ──────────────────────────────────
// Falls back gracefully at every stage: brand-new users with no likes yet
// (or a young app with too little data for ALS/Bridge) simply get pure
// recency, same as before. Nothing here breaks the feed if the
// recommender tables are empty.

const FRESHNESS_LAMBDA = 0.05; // larger = faster decay
const FEED_SIZE = 20;
const SLOT_ITEM = 10;   // ~5:3:2 ratio scaled to FEED_SIZE
const SLOT_USER = 6;
const SLOT_BRIDGE = 4;

function freshness_weight(string $createdAt): float {
    $ageHours = (time() - strtotime($createdAt)) / 3600.0;
    return exp(-FRESHNESS_LAMBDA * max($ageHours, 0));
}

// Lightweight lookup of every post's cluster_id + created_at, used to score
// candidates before we fetch full row data. Fine at current scale — add a
// created_at recency window here once the posts table grows large.
$metaStmt = $db->query("SELECT id, cluster_id, created_at FROM news_posts");
$postMeta = [];
foreach ($metaStmt->fetchAll() as $row) {
    $postMeta[(int) $row['id']] = $row;
}

// User's historically liked clusters — drives content fallback + bridge lookups
$likedClustersStmt = $db->prepare(
    "SELECT DISTINCT n.cluster_id FROM news_reactions r
     JOIN news_posts n ON n.id = r.news_id
     WHERE r.user_id = :me AND r.reaction = 'like'
           AND n.cluster_id IS NOT NULL AND n.cluster_id != -1"
);
$likedClustersStmt->execute([':me' => $userId]);
$likedClusters = array_column($likedClustersStmt->fetchAll(), 'cluster_id');

function scored_pool(array $rows, string $idKey, string $scoreKey, array $postMeta): array {
    $scored = [];
    foreach ($rows as $r) {
        $id = (int) $r[$idKey];
        if (!isset($postMeta[$id])) continue;
        $fresh = freshness_weight($postMeta[$id]['created_at']);
        $scored[$id] = (float) $r[$scoreKey] * $fresh;
    }
    arsort($scored);
    return array_keys($scored);
}

// 1. ALS item-based / user-based candidates (precomputed by train_recommender.py)
$itemStmt = $db->prepare(
    "SELECT news_id, als_score FROM news_als_candidates WHERE user_id = :me AND source = 'item_based'"
);
$itemStmt->execute([':me' => $userId]);
$itemPool = scored_pool($itemStmt->fetchAll(), 'news_id', 'als_score', $postMeta);

$userStmt = $db->prepare(
    "SELECT news_id, als_score FROM news_als_candidates WHERE user_id = :me AND source = 'user_based'"
);
$userStmt->execute([':me' => $userId]);
$userPool = scored_pool($userStmt->fetchAll(), 'news_id', 'als_score', $postMeta);

// 2. Bridge Apriori candidates — only meaningful once the user has liked history
$bridgePool = [];
if ($likedClusters) {
    $placeholders = implode(',', array_fill(0, count($likedClusters), '?'));
    $bridgeStmt = $db->prepare(
        "SELECT np.id AS news_id, br.lift AS als_score
         FROM news_bridge_rules br
         JOIN news_posts np ON np.cluster_id = br.consequent_cluster
         WHERE br.antecedent_cluster IN ($placeholders)"
    );
    $bridgeStmt->execute($likedClusters);
    $bridgePool = scored_pool($bridgeStmt->fetchAll(), 'news_id', 'als_score', $postMeta);
}

// 3. Content fallback — cluster overlap with what the user already likes, no ALS needed
$contentPool = [];
if ($likedClusters) {
    $placeholders = implode(',', array_fill(0, count($likedClusters), '?'));
    $contentStmt = $db->prepare(
        "SELECT id AS news_id, 1.0 AS als_score FROM news_posts WHERE cluster_id IN ($placeholders)"
    );
    $contentStmt->execute($likedClusters);
    $contentPool = scored_pool($contentStmt->fetchAll(), 'news_id', 'als_score', $postMeta);
}

// 4. Recency fallback — always available, guarantees the feed is never empty
$recencyStmt = $db->query("SELECT id FROM news_posts ORDER BY created_at DESC LIMIT 100");
$recencyPool = array_column($recencyStmt->fetchAll(), 'id');
$recencyPool = array_map('intval', $recencyPool);

// Assemble final ordered id list: pull from each pool up to its slot budget,
// then fill any remaining space from content fallback, then recency.
$finalIds = [];
$seen = [];
$take = function (array $pool, int $limit) use (&$finalIds, &$seen) {
    $count = 0;
    foreach ($pool as $id) {
        if ($count >= $limit) break;
        if (isset($seen[$id])) continue;
        $finalIds[] = $id;
        $seen[$id] = true;
        $count++;
    }
};
$take($itemPool, SLOT_ITEM);
$take($userPool, SLOT_USER);
$take($bridgePool, SLOT_BRIDGE);
$take($contentPool, FEED_SIZE); // fills remaining space if slots above were short
$take($recencyPool, FEED_SIZE); // final guaranteed fallback
$finalIds = array_slice($finalIds, 0, FEED_SIZE);

// Fetch full row data for the final id list, then reorder to match
if ($finalIds) {
    $placeholders = implode(',', array_fill(0, count($finalIds), '?'));
    $stmt = $db->prepare(
        "SELECT n.id AS internal_id, n.public_id, n.body, n.image, n.comments_enabled, n.created_at,
                u.public_id AS author_pid, u.fname, u.lname, u.avatar,
                (SELECT COUNT(*) FROM news_reactions WHERE news_id = n.id AND reaction = 'like') AS likes,
                (SELECT COUNT(*) FROM news_reactions WHERE news_id = n.id AND reaction = 'dislike') AS dislikes,
                (SELECT COUNT(*) FROM news_echoes WHERE news_id = n.id) AS echoes,
                (SELECT reaction FROM news_reactions WHERE news_id = n.id AND user_id = ?) AS my_reaction,
                (SELECT COUNT(*) FROM news_echoes WHERE news_id = n.id AND user_id = ?) AS my_echo,
                (SELECT COUNT(*) FROM news_comments WHERE news_id = n.id) AS comment_count
         FROM news_posts n JOIN users u ON u.id = n.author_id
         WHERE n.id IN ($placeholders)"
    );
    $stmt->execute(array_merge([$userId, $userId], $finalIds));
    $rowsById = [];
    foreach ($stmt->fetchAll() as $row) {
        $rowsById[(int) $row['internal_id']] = $row;
    }
    $posts = [];
    foreach ($finalIds as $id) {
        if (isset($rowsById[$id])) $posts[] = $rowsById[$id];
    }
} else {
    $posts = [];
}

// Log an impression for every post shown in this feed load, grouped under
// one batch_id — this is what lets Bridge Apriori later mine "which
// clusters get engaged with together in one session."
if ($posts) {
    $batchId = bin2hex(random_bytes(8));
    $impStmt = $db->prepare(
        'INSERT INTO news_impressions (news_id, user_id, batch_id) VALUES (:nid, :uid, :bid)'
    );
    $db->beginTransaction();
    foreach ($posts as $p) {
        $impStmt->execute([':nid' => (int) $p['internal_id'], ':uid' => $userId, ':bid' => $batchId]);
    }
    $db->commit();
}

$meStmt = $db->prepare('SELECT public_id, username, fname, lname, avatar FROM users WHERE id = :id');
$meStmt->execute([':id' => $userId]);
$me = $meStmt->fetch();

$pageTitle = 'News';
require __DIR__ . '/../../includes/layout_top.php';
?>
<div class="page-header"><h1>News</h1></div>

<!-- Floating Action Button - always visible, anchors the composer -->
<button type="button" class="composer-fab" id="composer-fab" aria-label="Create post" aria-expanded="false" aria-controls="composer-panel">
  <span class="icon">✎</span>
</button>

<!-- Expandable composer panel - expands leftward from the FAB, stays fixed above the feed -->
<div class="composer-panel card" id="composer-panel" aria-hidden="true">
  <div class="composer-panel-header">
    <img src="/uploads/<?= e($me['avatar']) ?>" alt="">
    <div class="composer-who">
      <div class="composer-name"><?= e($me['fname'] . ' ' . $me['lname']) ?></div>
      <select class="composer-visibility" aria-label="Post visibility">
        <option>Public</option>
        <option>Friends</option>
      </select>
    </div>
    <button type="button" class="composer-close" id="composer-close" aria-label="Close composer">✕</button>
  </div>

  <form id="news-form" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <div id="form-message" class="form-message"></div>

    <textarea name="body" id="composer-body" rows="3" placeholder="What is going on today?" required maxlength="3000"></textarea>

    <div class="composer-image-preview" id="composer-image-preview" hidden>
      <img id="composer-image-preview-img" src="" alt="Selected image preview">
      <button type="button" class="composer-image-remove" id="composer-image-remove" aria-label="Remove image">✕</button>
    </div>

    <div class="composer-panel-footer">
      <label class="composer-image-btn" title="Add an image">
        <input type="file" name="image" id="composer-image-input" accept="image/png, image/jpeg, image/webp" hidden>
        <span class="icon">🖼️</span>
      </label>
      <label class="composer-toggle">
        <input type="checkbox" name="comments_enabled" checked> Allow comments
      </label>
      <button type="submit" class="btn-primary btn-inline composer-submit" id="composer-submit">
        <span class="composer-submit-label">News</span>
        <span class="composer-submit-spinner" hidden></span>
      </button>
    </div>
  </form>
</div>

<div id="feed-list">
<?php foreach ($posts as $p): ?>
  <article class="card feed-item" data-post="<?= e($p['public_id']) ?>">
    <div class="feed-item-header">
      <button class="author-btn" data-user="<?= e($p['author_pid']) ?>">
        <img src="/uploads/<?= e($p['avatar']) ?>" alt="">
        <div>
          <div class="meta-name"><?= e($p['fname'] . ' ' . $p['lname']) ?></div>
          <div class="meta-time"><?= e($p['created_at']) ?></div>
        </div>
      </button>
    </div>
    <p style="white-space: pre-wrap;"><?= e($p['body']) ?></p>
    <?php if ($p['image']): ?><img src="/uploads/<?= e($p['image']) ?>" alt="" style="border-radius: var(--radius-md); margin-top:8px;"><?php endif; ?>

    <div class="feed-actions">
      <button class="react-btn <?= $p['my_reaction'] === 'like' ? 'active' : '' ?>" data-reaction="like">👍 <span class="like-count"><?= (int) $p['likes'] ?></span></button>
      <button class="react-btn <?= $p['my_reaction'] === 'dislike' ? 'active' : '' ?>" data-reaction="dislike">👎 <span class="dislike-count"><?= (int) $p['dislikes'] ?></span></button>
      <button class="echo-btn <?= $p['my_echo'] ? 'active' : '' ?>" <?= $p['my_echo'] ? 'disabled' : '' ?>>🔁 Echo <span class="echo-count"><?= (int) $p['echoes'] ?></span></button>
      <?php if ($p['comments_enabled']): ?>
        <a class="comments-toggle" href="/news/comments.php?post=<?= e($p['public_id']) ?>">💬 <span class="comment-count"><?= (int) $p['comment_count'] ?></span> comments</a>
      <?php else: ?>
        <span class="comments-toggle comments-toggle--disabled">Comments off</span>
      <?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>
</div>

<script nonce="<?= e(csp_nonce()) ?>">window.WS_TOKEN = <?= json_encode(ensure_ws_session($db, $userId)) ?>;</script>
<script src="<?= asset_url('/assets/js/time_format.js') ?>"></script>
<script src="<?= asset_url('/assets/js/news.js') ?>"></script>
<?php require __DIR__ . '/../../includes/layout_bottom.php'; ?>
