<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

start_secure_session();
$userId = require_login();
$db = get_db();

$postPid = trim((string) ($_GET['post'] ?? ''));

$stmt = $db->prepare(
    "SELECT n.public_id, n.body, n.image, n.comments_enabled, n.created_at,
            u.public_id AS author_pid, u.fname, u.lname, u.avatar,
            (SELECT COUNT(*) FROM news_reactions WHERE news_id = n.id AND reaction = 'like') AS likes,
            (SELECT COUNT(*) FROM news_reactions WHERE news_id = n.id AND reaction = 'dislike') AS dislikes,
            (SELECT COUNT(*) FROM news_echoes WHERE news_id = n.id) AS echoes,
            (SELECT reaction FROM news_reactions WHERE news_id = n.id AND user_id = :me) AS my_reaction,
            (SELECT COUNT(*) FROM news_echoes WHERE news_id = n.id AND user_id = :me2) AS my_echo,
            (SELECT COUNT(*) FROM news_comments WHERE news_id = n.id) AS comment_count
     FROM news_posts n JOIN users u ON u.id = n.author_id
     WHERE n.public_id = :pid"
);
$stmt->execute([':me' => $userId, ':me2' => $userId, ':pid' => $postPid]);
$post = $stmt->fetch();

if (!$post) {
    header('Location: /news/index.php');
    exit;
}

// Flat list, built into a parent -> children tree below. news_id is fixed
// per query (this page is scoped to one post), so no need to filter here.
$stmt = $db->prepare(
    "SELECT c.id, c.parent_comment_id, c.body, c.created_at, u.public_id AS user_pid, u.fname, u.avatar,
            (SELECT COUNT(*) FROM news_comment_likes WHERE comment_id = c.id) AS like_count,
            (SELECT COUNT(*) FROM news_comment_likes WHERE comment_id = c.id AND user_id = :me) AS liked_by_me
     FROM news_comments c JOIN users u ON u.id = c.user_id
     WHERE c.news_id = (SELECT id FROM news_posts WHERE public_id = :pid)
     ORDER BY c.created_at ASC"
);
$stmt->execute([':me' => $userId, ':pid' => $postPid]);
$flatComments = $stmt->fetchAll();

$childrenByParent = [];
$byId = [];
foreach ($flatComments as $c) {
    $byId[(int) $c['id']] = $c;
    $parent = $c['parent_comment_id'] !== null ? (int) $c['parent_comment_id'] : 0;
    $childrenByParent[$parent][] = $c;
}

const MAX_VISUAL_DEPTH = 3;

/**
 * Depth-first pre-order render: each comment appears immediately after its
 * parent and before the parent's next sibling, so replies read in order
 * without needing real DOM nesting. Visual indent caps at MAX_VISUAL_DEPTH -
 * deeper replies still carry their true $depth (and parent's name) so the
 * thread relationship stays correct even once indentation stops increasing.
 */
function render_comment_node(array $c, array $childrenByParent, array $byId, int $depth, int $userId): void
{
    $visualDepth = min($depth, MAX_VISUAL_DEPTH);
    $parentAuthor = null;
    if ($depth > MAX_VISUAL_DEPTH && $c['parent_comment_id'] !== null && isset($byId[(int) $c['parent_comment_id']])) {
        $parentAuthor = $byId[(int) $c['parent_comment_id']]['fname'];
    }
    ?>
    <div class="comment-node" data-comment-id="<?= (int) $c['id'] ?>" data-depth="<?= $depth ?>" style="margin-left: <?= $visualDepth * 22 ?>px;">
      <img class="comment-avatar" src="/uploads/<?= e($c['avatar']) ?>" alt="">
      <div class="comment-card-body">
        <div class="comment-card-header">
          <button class="author-btn" data-user="<?= e($c['user_pid']) ?>" style="display:inline;">
            <span class="comment-card-name"><?= e($c['fname']) ?></span>
          </button>
          <span class="comment-card-time" data-created="<?= e($c['created_at']) ?>"></span>
        </div>
        <?php if ($parentAuthor !== null): ?>
          <div class="comment-replying-to">↳ replying to <?= e($parentAuthor) ?></div>
        <?php endif; ?>
        <div class="comment-card-text"><?= e($c['body']) ?></div>
        <div class="comment-card-actions">
          <button class="comment-like-btn <?= $c['liked_by_me'] ? 'active' : '' ?>" data-comment="<?= (int) $c['id'] ?>">
            ♥ <span class="like-count"><?= (int) $c['like_count'] ?></span>
          </button>
          <button type="button" class="comment-reply-hint" data-reply-to="<?= (int) $c['id'] ?>">Reply</button>
        </div>
        <div class="comment-reply-form-slot" id="reply-slot-<?= (int) $c['id'] ?>"></div>
      </div>
    </div>
    <?php
    foreach ($childrenByParent[(int) $c['id']] ?? [] as $child) {
        render_comment_node($child, $childrenByParent, $byId, $depth + 1, $userId);
    }
}

$pageTitle = 'Comments';
require __DIR__ . '/../../includes/layout_top.php';
?>
<div class="page-header">
  <div style="display:flex; align-items:center; gap:12px;">
    <a href="/news/index.php" class="back-btn back-btn--inline" aria-label="Back to feed">←</a>
    <h1>Comments (<?= (int) $post['comment_count'] ?>)</h1>
  </div>
</div>

<article class="card feed-item comment-post-context" data-post="<?= e($post['public_id']) ?>">
  <div class="feed-item-header">
    <button class="author-btn" data-user="<?= e($post['author_pid']) ?>">
      <img src="/uploads/<?= e($post['avatar']) ?>" alt="">
      <div>
        <div class="meta-name"><?= e($post['fname'] . ' ' . $post['lname']) ?></div>
        <div class="meta-time"><?= e($post['created_at']) ?></div>
      </div>
    </button>
  </div>
  <p style="white-space: pre-wrap;"><?= e($post['body']) ?></p>
  <?php if ($post['image']): ?><img src="/uploads/<?= e($post['image']) ?>" alt="" style="border-radius: var(--radius-md); margin-top:8px;"><?php endif; ?>

  <div class="feed-actions">
    <button class="react-btn <?= $post['my_reaction'] === 'like' ? 'active' : '' ?>" data-reaction="like">👍 <span class="like-count"><?= (int) $post['likes'] ?></span></button>
    <button class="react-btn <?= $post['my_reaction'] === 'dislike' ? 'active' : '' ?>" data-reaction="dislike">👎 <span class="dislike-count"><?= (int) $post['dislikes'] ?></span></button>
    <button class="echo-btn <?= $post['my_echo'] ? 'active' : '' ?>" <?= $post['my_echo'] ? 'disabled' : '' ?>>🔁 Echo <span class="echo-count"><?= (int) $post['echoes'] ?></span></button>
  </div>
</article>

<div class="card comment-thread-panel">
  <?php if (!$post['comments_enabled']): ?>
    <p style="color: var(--color-ink-soft); font-size:14px;">Comments are off for this post.</p>
  <?php else: ?>
    <div class="comment-thread" id="comment-thread">
      <?php foreach ($childrenByParent[0] ?? [] as $root): render_comment_node($root, $childrenByParent, $byId, 0, $userId); endforeach; ?>
      <?php if (!$flatComments): ?>
        <p class="comment-thread-empty" style="color: var(--color-ink-soft); font-size:14px;">No comments yet - be the first to reply.</p>
      <?php endif; ?>
    </div>

    <form class="comment-form comment-toplevel-form" id="toplevel-comment-form" style="display:flex; gap:8px; margin-top:12px;">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="text" name="body" placeholder="Write a comment…" maxlength="1000" style="flex:1;">
      <button type="submit" class="btn-secondary btn-sm">Reply</button>
    </form>
  <?php endif; ?>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
  window.WS_TOKEN = <?= json_encode(ensure_ws_session($db, $userId)) ?>;
  window.POST_ID = <?= json_encode($post['public_id']) ?>;
</script>
<script src="<?= asset_url('/assets/js/time_format.js') ?>"></script>
<script src="<?= asset_url('/assets/js/comments.js') ?>"></script>
<?php require __DIR__ . '/../../includes/layout_bottom.php'; ?>
