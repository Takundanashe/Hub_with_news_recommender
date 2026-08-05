<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';

start_secure_session();
$userId = require_login();
$db = get_db();

$stmt = $db->prepare('SELECT fname, username, avatar FROM users WHERE id = :id');
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

$stmt = $db->prepare(
    "SELECT l.public_id, l.title, l.price, l.currency, l.location,
            u.public_id AS seller_pid, u.fname, u.lname, u.avatar,
            (SELECT filename FROM listing_images WHERE listing_id = l.id ORDER BY sort_order LIMIT 1) AS thumb,
            (SELECT ROUND(AVG(rating), 1) FROM listing_reviews WHERE seller_id = u.id) AS avg_rating,
            (SELECT COUNT(*) FROM listing_reviews WHERE seller_id = u.id) AS review_count
     FROM listings l JOIN users u ON u.id = l.owner_id
     WHERE l.type = 'goods' AND l.status = 'active'
     ORDER BY l.created_at DESC LIMIT 60"
);
$stmt->execute();
$listings = $stmt->fetchAll();

$pageTitle = 'Market';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="card" style="padding: var(--space-4); margin-bottom: var(--space-4); display:flex; align-items:center; gap: var(--space-3);">
  <img src="/uploads/<?= e($user['avatar']) ?>" alt="" style="width:44px; height:44px; border-radius:50%; object-fit:cover;">
  <div style="flex:1;">
    <div style="font-size:13px; color:var(--color-ink-soft);">Hello, <?= e($user['fname']) ?> 👋</div>
    <div style="font-weight:700; font-size:16px;">What are you looking for today?</div>
  </div>
  <a href="/search.php" class="btn-secondary btn-sm">🔍 Search</a>
</div>

<div class="card-grid" style="grid-template-columns: repeat(auto-fill, minmax(90px,1fr)); margin-bottom: var(--space-5);">
  <a class="card" href="/market/create.php?type=goods" style="text-decoration:none; color:inherit; padding: var(--space-3); text-align:center;">
    <div style="font-size:22px;">➕</div><div style="font-size:12px; font-weight:600; margin-top:4px;">Sell</div>
  </a>
  <a class="card" href="/market/index.php?type=job" style="text-decoration:none; color:inherit; padding: var(--space-3); text-align:center;">
    <div style="font-size:22px;">💼</div><div style="font-size:12px; font-weight:600; margin-top:4px;">Jobs</div>
  </a>
  <a class="card" href="/market/index.php?type=lost_found" style="text-decoration:none; color:inherit; padding: var(--space-3); text-align:center;">
    <div style="font-size:22px;">🔎</div><div style="font-size:12px; font-weight:600; margin-top:4px;">Lost&amp;Found</div>
  </a>
  <a class="card" href="/market/index.php?type=housing" style="text-decoration:none; color:inherit; padding: var(--space-3); text-align:center;">
    <div style="font-size:22px;">🏠</div><div style="font-size:12px; font-weight:600; margin-top:4px;">Housing</div>
  </a>
</div>

<div class="page-header"><h1>Market</h1></div>

<?php if (!$listings): ?>
  <div class="card" style="padding: var(--space-5); text-align:center; color: var(--color-ink-soft);">
    Nothing here yet — be the first to post.
  </div>
<?php else: ?>
<div class="card-grid">
  <?php foreach ($listings as $l): ?>
    <a class="card listing-card" href="/market/detail.php?id=<?= e($l['public_id']) ?>" style="text-decoration:none; color:inherit;">
      <img class="thumb" src="/uploads/<?= e($l['thumb'] ?? 'default_listing.png') ?>" alt="" loading="lazy">
      <div class="listing-card-body">
        <?php if ($l['price'] !== null): ?>
          <p class="listing-price"><?= e($l['currency']) ?> <?= e(number_format((float) $l['price'], 0)) ?></p>
        <?php endif; ?>
        <p class="listing-title"><?= e($l['title']) ?></p>
        <div class="listing-seller">
          <img src="/uploads/<?= e($l['avatar']) ?>" alt="">
          <span><?= e($l['fname']) ?></span>
          <?php if ($l['avg_rating']): ?>
            <span class="rating-stars">★ <?= e((string) $l['avg_rating']) ?></span>
            <span>(<?= e((string) $l['review_count']) ?>)</span>
          <?php endif; ?>
        </div>
      </div>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
