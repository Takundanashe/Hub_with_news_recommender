<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';

start_secure_session();
$userId = require_login();
$db = get_db();

$publicId = trim((string) ($_GET['id'] ?? ''));

$stmt = $db->prepare(
    "SELECT l.*, u.id AS seller_user_id, u.public_id AS seller_pid, u.fname, u.lname, u.avatar
     FROM listings l JOIN users u ON u.id = l.owner_id
     WHERE l.public_id = :pid"
);
$stmt->execute([':pid' => $publicId]);
$listing = $stmt->fetch();
if (!$listing) {
    header('Location: /market/index.php');
    exit;
}

// Log a view - raw signal for future ML training, no scoring logic reads it yet.
$log = $db->prepare("INSERT INTO listing_events (listing_id, user_id, event_type) VALUES (:lid, :uid, 'view')");
$log->execute([':lid' => $listing['id'], ':uid' => $userId]);

$images = $db->prepare('SELECT filename FROM listing_images WHERE listing_id = :id ORDER BY sort_order');
$images->execute([':id' => $listing['id']]);
$images = $images->fetchAll();

$reviews = $db->prepare(
    'SELECT r.rating, r.body, r.created_at, u.fname, u.lname
     FROM listing_reviews r JOIN users u ON u.id = r.reviewer_id
     WHERE r.seller_id = :seller ORDER BY r.created_at DESC LIMIT 20'
);
$reviews->execute([':seller' => $listing['seller_user_id']]);
$reviews = $reviews->fetchAll();

$avgRating = $db->prepare('SELECT ROUND(AVG(rating),1) AS avg, COUNT(*) AS n FROM listing_reviews WHERE seller_id = :seller');
$avgRating->execute([':seller' => $listing['seller_user_id']]);
$ratingRow = $avgRating->fetch();

$pageTitle = $listing['title'];
require __DIR__ . '/../../includes/layout_top.php';
?>
<div class="card">
  <div class="seller-header">
    <button class="author-btn" data-user="<?= e($listing['seller_pid']) ?>">
      <img src="/uploads/<?= e($listing['avatar']) ?>" alt="">
      <div style="text-align:left;">
        <div style="font-weight:700;" class="meta-name"><?= e($listing['fname'] . ' ' . $listing['lname']) ?></div>
        <?php if ($ratingRow['n']): ?>
          <div style="font-size:13px; color:var(--color-ink-soft);">
            <span class="rating-stars">★ <?= e((string) $ratingRow['avg']) ?></span> (<?= e((string) $ratingRow['n']) ?> reviews)
          </div>
        <?php endif; ?>
      </div>
    </button>
    <div style="flex:1;"></div>
    <a class="btn-secondary btn-sm" href="/market/seller.php?id=<?= e($listing['seller_pid']) ?>">View more from seller</a>
  </div>

  <?php if ($images): ?>
    <div class="gallery">
      <?php foreach ($images as $img): ?>
        <img src="/uploads/<?= e($img['filename']) ?>" alt="">
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div style="padding: var(--space-4);">
    <?php if ($listing['price'] !== null): ?>
      <p class="listing-price" style="font-size:24px;"><?= e($listing['currency']) ?> <?= e(number_format((float) $listing['price'], 0)) ?></p>
    <?php endif; ?>
    <h1 style="font-size:20px; margin: 4px 0 8px;"><?= e($listing['title']) ?></h1>
    <p class="pill"><?= e(str_replace('_', ' ', $listing['type'])) ?></p>
    <?php if ($listing['location']): ?><p style="color:var(--color-ink-soft); font-size:14px;">📍 <?= e($listing['location']) ?></p><?php endif; ?>
    <p style="white-space: pre-wrap; margin-top: var(--space-3);"><?= e($listing['description']) ?></p>

    <?php if ($listing['seller_user_id'] !== $userId): ?>
      <a class="btn-primary btn-inline" href="/chat.php?with=<?= e($listing['seller_pid']) ?>">Message seller</a>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="margin-top: var(--space-5); padding: var(--space-5);">
  <h2 style="margin-top:0; font-size:16px;">Reviews</h2>

  <?php if ($listing['seller_user_id'] !== $userId): ?>
  <form id="review-form" style="margin-bottom: var(--space-4);">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="seller_id" value="<?= e($listing['seller_pid']) ?>">
    <input type="hidden" name="listing_id" value="<?= e($publicId) ?>">
    <div id="review-message" class="form-message"></div>
    <div class="field-row">
      <div class="field" style="flex:0 0 120px;">
        <label for="rating">Rating</label>
        <select id="rating" name="rating">
          <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= $i ?> ★</option><?php endfor; ?>
        </select>
      </div>
      <div class="field" style="flex:1;">
        <label for="body">Review</label>
        <input id="body" name="body" type="text" maxlength="500">
      </div>
    </div>
    <button type="submit" class="btn-secondary btn-inline">Submit review</button>
  </form>
  <?php endif; ?>

  <?php foreach ($reviews as $r): ?>
    <div class="comment-row">
      <strong><?= e($r['fname']) ?></strong> <span class="rating-stars">★<?= e((string) $r['rating']) ?></span>
      <p style="margin: 4px 0 0;"><?= e($r['body']) ?></p>
    </div>
  <?php endforeach; ?>
</div>

<script src="<?= asset_url('/assets/js/review_form.js') ?>"></script>
<?php require __DIR__ . '/../../includes/layout_bottom.php'; ?>
