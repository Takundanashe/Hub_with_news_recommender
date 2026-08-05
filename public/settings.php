<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/social.php';
require_once __DIR__ . '/../config/database.php';

start_secure_session();
$userId = require_login();
$db = get_db();

$stmt = $db->prepare('SELECT dm_permission, phone_visibility, email_visibility, phone FROM users WHERE id = :id');
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

// --- Location sharing data (moved here from the old standalone /location.php) ---
$stmt = $db->prepare(
    "SELECT u.public_id, u.fname, u.lname, u.avatar
     FROM follows f1
     JOIN follows f2 ON f2.follower_id = f1.followed_id AND f2.followed_id = f1.follower_id
     JOIN users u ON u.id = f1.followed_id
     WHERE f1.follower_id = :me"
);
$stmt->execute([':me' => $userId]);
$mutualFriends = $stmt->fetchAll();

$stmt = $db->prepare(
    "SELECT u.public_id, u.fname, u.lname, ls.expires_at
     FROM location_shares ls JOIN users u ON u.id = ls.viewer_id
     WHERE ls.sharer_id = :me AND ls.is_active = 1
       AND (ls.expires_at IS NULL OR ls.expires_at > datetime('now'))"
);
$stmt->execute([':me' => $userId]);
$mySharesOut = $stmt->fetchAll();

$stmt = $db->prepare(
    "SELECT u.public_id, u.fname, u.lname, ls.id AS share_id,
       (SELECT latitude FROM location_pings WHERE share_id = ls.id ORDER BY recorded_at DESC LIMIT 1) AS lat,
       (SELECT longitude FROM location_pings WHERE share_id = ls.id ORDER BY recorded_at DESC LIMIT 1) AS lng
     FROM location_shares ls JOIN users u ON u.id = ls.sharer_id
     WHERE ls.viewer_id = :me AND ls.is_active = 1
       AND (ls.expires_at IS NULL OR ls.expires_at > datetime('now'))"
);
$stmt->execute([':me' => $userId]);
$sharedWithMe = $stmt->fetchAll();

$pageTitle = 'Settings';
$activeNav = 'settings';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header"><h1>Settings</h1></div>

<form class="card" id="settings-form" style="padding: var(--space-5); max-width: 480px; margin-bottom: var(--space-5);">
  <h2 style="margin-top:0; font-size:16px;">Privacy</h2>
  <div id="form-message" class="form-message"></div>
  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

  <div class="field">
    <label for="dm_permission">Who can send you direct messages</label>
    <select id="dm_permission" name="dm_permission">
      <option value="everyone" <?= $user['dm_permission'] === 'everyone' ? 'selected' : '' ?>>Everyone</option>
      <option value="followers" <?= $user['dm_permission'] === 'followers' ? 'selected' : '' ?>>People you follow back</option>
      <option value="no_one" <?= $user['dm_permission'] === 'no_one' ? 'selected' : '' ?>>No one</option>
    </select>
  </div>

  <div class="field">
    <label for="phone">Phone number</label>
    <input id="phone" name="phone" type="tel" value="<?= e($user['phone'] ?? '') ?>">
  </div>

  <div class="field">
    <label for="phone_visibility">Phone number visibility</label>
    <select id="phone_visibility" name="phone_visibility">
      <option value="private" <?= $user['phone_visibility'] === 'private' ? 'selected' : '' ?>>Only me (others see username only)</option>
      <option value="public" <?= $user['phone_visibility'] === 'public' ? 'selected' : '' ?>>Visible to anyone</option>
    </select>
  </div>

  <div class="field">
    <label for="email_visibility">Email visibility</label>
    <select id="email_visibility" name="email_visibility">
      <option value="private" <?= $user['email_visibility'] === 'private' ? 'selected' : '' ?>>Only me (others see username only)</option>
      <option value="public" <?= $user['email_visibility'] === 'public' ? 'selected' : '' ?>>Visible to anyone</option>
    </select>
  </div>

  <button type="submit" class="btn-primary">Save privacy settings</button>
</form>

<div class="card" style="padding: var(--space-5); max-width: 480px; margin-bottom: var(--space-5);">
  <h2 style="margin-top:0; font-size:16px;">Location sharing</h2>
  <p style="color: var(--color-ink-soft); font-size:13px;">
    Only mutual friends can share locations with each other. Sharing is always visible while
    it's on, and you can stop it instantly at any time.
  </p>

  <?php if (!$mutualFriends): ?>
    <p style="color: var(--color-ink-soft); font-size:14px;">You don't have any mutual friends yet - follow each other with someone first.</p>
  <?php else: ?>
    <form id="start-share-form" class="field-row" style="align-items:flex-end;">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <div class="field" style="flex:2;">
        <label for="viewer_id">Share with</label>
        <select name="viewer_id" id="viewer_id" required>
          <?php foreach ($mutualFriends as $f): ?>
            <option value="<?= e($f['public_id']) ?>"><?= e($f['fname'] . ' ' . $f['lname']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field" style="flex:1;">
        <label for="duration">Duration</label>
        <select name="duration" id="duration">
          <option value="1_hour">1 hour</option>
          <option value="until_off">Until I turn it off</option>
        </select>
      </div>
      <div class="field" style="flex:0 0 auto;">
        <button type="submit" class="btn-primary btn-inline">Start sharing</button>
      </div>
    </form>
  <?php endif; ?>

  <div id="active-shares" style="margin-top:var(--space-3);">
    <?php foreach ($mySharesOut as $s): ?>
      <div class="pill pill--accent" style="margin: 4px 4px 0 0; display:inline-flex; gap:8px; align-items:center;">
        Sharing with <?= e($s['fname']) ?>
        <button class="stop-share-btn" data-viewer="<?= e($s['public_id']) ?>" style="border:none;background:none;cursor:pointer;color:inherit;">✕</button>
      </div>
    <?php endforeach; ?>
  </div>

  <h3 style="font-size:14px; margin-top: var(--space-4);">Shared with you</h3>
  <?php if (!$sharedWithMe): ?>
    <p style="color: var(--color-ink-soft); font-size:14px;" id="no-shares-msg">No one is currently sharing their location with you.</p>
  <?php else: foreach ($sharedWithMe as $s): ?>
    <div class="tx-row" data-sharer="<?= e($s['public_id']) ?>">
      <span><?= e($s['fname'] . ' ' . $s['lname']) ?></span>
      <span class="coords-cell" style="font-size:13px; color:var(--color-ink-soft);">
        <?= $s['lat'] !== null ? e(number_format((float) $s['lat'], 5) . ', ' . number_format((float) $s['lng'], 5)) : 'No location yet' ?>
      </span>
    </div>
  <?php endforeach; endif; ?>
</div>

<script nonce="<?= e(csp_nonce()) ?>">window.WS_TOKEN = <?= json_encode(ensure_ws_session($db, $userId)) ?>;</script>
<script src="<?= asset_url('/assets/js/settings.js') ?>"></script>
<script src="<?= asset_url('/assets/js/location.js') ?>"></script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
