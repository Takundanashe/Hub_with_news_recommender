<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

start_secure_session();
$userId = require_login();
$db = get_db();

$type = $_GET['type'] ?? 'job';
if ($type === 'goods') {
    header('Location: /index.php');
    exit;
}
if (!in_array($type, ['job', 'lost_found', 'housing'], true)) {
    $type = 'job';
}

$titles = ['goods' => 'Market', 'job' => 'Jobs', 'lost_found' => 'Lost & Found', 'housing' => 'Housing'];

$stmt = $db->prepare(
    "SELECT l.public_id, l.title, l.price, l.currency, l.location,
            u.public_id AS seller_pid, u.fname, u.lname, u.avatar,
            (SELECT filename FROM listing_images WHERE listing_id = l.id ORDER BY sort_order LIMIT 1) AS thumb,
            (SELECT ROUND(AVG(rating), 1) FROM listing_reviews WHERE seller_id = u.id) AS avg_rating,
            (SELECT COUNT(*) FROM listing_reviews WHERE seller_id = u.id) AS review_count
     FROM listings l JOIN users u ON u.id = l.owner_id
     WHERE l.type = :type AND l.status = 'active'
     ORDER BY l.created_at DESC LIMIT 60"
);
$stmt->execute([':type' => $type]);
$listings = $stmt->fetchAll();

$pageTitle = $titles[$type];
require __DIR__ . '/../../includes/layout_top.php';
?>
<div class="page-header">
  <h1><?= e($titles[$type]) ?></h1>
  <a class="btn-primary btn-inline" href="/market/create.php?type=<?= e($type) ?>">+ New listing</a>
</div>

<div style="display:flex; gap:8px; margin-bottom: var(--space-4); flex-wrap:wrap;">
  <a href="/index.php" class="pill">Market</a>
  <?php foreach ($titles as $t => $label): if ($t === 'goods') continue; ?>
    <a href="/market/index.php?type=<?= e($t) ?>" class="pill <?= $t === $type ? 'pill--accent' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

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

<?php require __DIR__ . '/../../includes/layout_bottom.php'; ?>
