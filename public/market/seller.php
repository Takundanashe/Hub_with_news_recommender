<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

start_secure_session();
$userId = require_login();
$db = get_db();

$sellerPid = trim((string) ($_GET['id'] ?? ''));
$stmt = $db->prepare('SELECT id, fname, lname, avatar FROM users WHERE public_id = :pid');
$stmt->execute([':pid' => $sellerPid]);
$seller = $stmt->fetch();
if (!$seller) {
    header('Location: /market/index.php');
    exit;
}

$stmt = $db->prepare(
    "SELECT l.public_id, l.title, l.price, l.currency, l.type,
            (SELECT filename FROM listing_images WHERE listing_id = l.id ORDER BY sort_order LIMIT 1) AS thumb
     FROM listings l WHERE l.owner_id = :owner AND l.status = 'active' ORDER BY l.created_at DESC"
);
$stmt->execute([':owner' => $seller['id']]);
$listings = $stmt->fetchAll();

$pageTitle = $seller['fname'] . "'s listings";
require __DIR__ . '/../../includes/layout_top.php';
?>
<div class="page-header">
  <h1><?= e($seller['fname'] . ' ' . $seller['lname']) ?></h1>
</div>

<div class="card-grid">
  <?php foreach ($listings as $l): ?>
    <a class="card listing-card" href="/market/detail.php?id=<?= e($l['public_id']) ?>" style="text-decoration:none; color:inherit;">
      <img class="thumb" src="/uploads/<?= e($l['thumb'] ?? 'default_listing.png') ?>" alt="" loading="lazy">
      <div class="listing-card-body">
        <?php if ($l['price'] !== null): ?><p class="listing-price"><?= e($l['currency']) ?> <?= e(number_format((float) $l['price'], 0)) ?></p><?php endif; ?>
        <p class="listing-title"><?= e($l['title']) ?></p>
        <span class="pill"><?= e(str_replace('_', ' ', $l['type'])) ?></span>
      </div>
    </a>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/../../includes/layout_bottom.php'; ?>
