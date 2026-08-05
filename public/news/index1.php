<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

start_secure_session();
$userId = require_login();
$db = get_db();

$stmt = $db->prepare(
    "SELECT n.public_id, n.body, n.image, n.comments_enabled, n.created_at,
            u.public_id AS author_pid, u.fname, u.lname, u.avatar,
            (SELECT COUNT(*) FROM news_reactions WHERE news_id = n.id AND reaction = 'like') AS likes,
            (SELECT COUNT(*) FROM news_reactions WHERE news_id = n.id AND reaction = 'dislike') AS dislikes,
            (SELECT COUNT(*) FROM news_echoes WHERE news_id = n.id) AS echoes,
            (SELECT reaction FROM news_reactions WHERE news_id = n.id AND user_id = :me) AS my_reaction,
            (SELECT COUNT(*) FROM news_echoes WHERE news_id = n.id AND user_id = :me) AS my_echo,
            (SELECT COUNT(*) FROM news_comments WHERE news_id = n.id) AS comment_count
     FROM news_posts n JOIN users u ON u.id = n.author_id
     ORDER BY n.created_at DESC LIMIT 50"
);
$stmt->execute([':me' => $userId]);
$posts = $stmt->fetchAll();

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
