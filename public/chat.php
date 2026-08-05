<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';

start_secure_session();
$userId = require_login();

$withPublicId = trim((string) ($_GET['with'] ?? ''));
if ($withPublicId === '') {
    header('Location: /messages.php');
    exit;
}

$db = get_db();

$stmt = $db->prepare(
    'SELECT public_id, fname, lname, username, avatar, status, phone, phone_visibility, email, email_visibility
     FROM users WHERE public_id = :pid'
);
$stmt->execute([':pid' => $withPublicId]);
$other = $stmt->fetch();
if (!$other) {
    header('Location: /messages.php');
    exit;
}

$stmt = $db->prepare('SELECT id FROM users WHERE id != :me AND public_id = :pid');
$stmt->execute([':me' => $userId, ':pid' => $withPublicId]);
$otherRow = $stmt->fetch();
$otherId = $otherRow ? (int) $otherRow['id'] : null;

$iFollowThem = false;
if ($otherId) {
    $stmt = $db->prepare('SELECT 1 FROM follows WHERE follower_id = :me AND followed_id = :them');
    $stmt->execute([':me' => $userId, ':them' => $otherId]);
    $iFollowThem = (bool) $stmt->fetch();
}

$selectedPublicId = $withPublicId;
$pageTitle = $other['fname'];
$activeNav = 'messages';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="messenger-shell">
  <div class="messenger-list messenger-list--paired">
    <?php require __DIR__ . '/../includes/dm_sidebar.php'; ?>
  </div>

  <div class="messenger-conversation" id="messenger-conversation">
    <div class="messenger-header">
      <a href="/messages.php" class="back-btn" aria-label="Back to conversations">←</a>
      <button type="button" class="header-contact-trigger" id="open-contact-info" aria-label="View contact info">
        <img src="/uploads/<?= e($other['avatar']) ?>" alt="">
        <div>
          <div class="messenger-header-name"><?= e($other['fname'] . ' ' . $other['lname']) ?></div>
          <div class="messenger-header-status" id="peer-status">
            <span class="status-dot <?= $other['status'] === 'Active now' ? 'online' : '' ?>"></span>
            <span id="peer-status-text"><?= $other['status'] === 'Active now' ? 'Online' : 'Offline' ?></span>
          </div>
        </div>
      </button>
      <div class="messenger-header-icons">
        <button type="button" class="icon-btn" aria-disabled="true" title="Voice calling isn't available yet">📞</button>
        <button type="button" class="icon-btn" aria-disabled="true" title="Video calling isn't available yet">🎥</button>
        <button type="button" class="icon-btn" id="peer-more-btn" title="More">⋮</button>
      </div>
    </div>

    <div id="chat-messages" class="chat-messages"></div>

    <form id="chat-composer-form" class="messenger-composer">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="text" id="chat-input" placeholder="Type a message..." autocomplete="off" required maxlength="4000">
      <button type="button" class="composer-emoji-btn" id="emoji-btn" aria-label="Emoji">😊</button>
      <button type="submit" class="composer-send-btn">Send</button>
    </form>
  </div>

  <div class="messenger-info" id="messenger-info">
    <div class="messenger-info-header">
      <button type="button" class="back-btn" id="close-contact-info" aria-label="Back to conversation">←</button>
      <span>Contact Info</span>
    </div>
    <div class="messenger-info-body">
      <img class="messenger-info-avatar" src="/uploads/<?= e($other['avatar']) ?>" alt="">
      <div class="messenger-info-name"><?= e($other['fname'] . ' ' . $other['lname']) ?></div>
      <div class="messenger-info-status">
        <span class="status-dot <?= $other['status'] === 'Active now' ? 'online' : '' ?>"></span>
        <?= $other['status'] === 'Active now' ? 'Online' : 'Offline' ?>
      </div>

      <button type="button" class="btn-secondary btn-inline messenger-info-follow-btn" id="info-follow-btn"
              data-user="<?= e($withPublicId) ?>" data-following="<?= $iFollowThem ? '1' : '0' ?>">
        <?= $iFollowThem ? 'Following ✓' : '+ Follow' ?>
      </button>

      <div class="messenger-info-section">
        <div class="messenger-info-label">Account Info</div>
        <div class="messenger-info-row"><span>Username</span><span>@<?= e($other['username']) ?></span></div>
        <?php if ($other['phone_visibility'] === 'public' && $other['phone']): ?>
          <div class="messenger-info-row"><span>Phone</span><span><?= e($other['phone']) ?></span></div>
        <?php endif; ?>
        <?php if ($other['email_visibility'] === 'public'): ?>
          <div class="messenger-info-row"><span>Email</span><span><?= e($other['email']) ?></span></div>
        <?php endif; ?>
      </div>

      <a class="btn-secondary btn-inline" style="margin-top: var(--space-4);" href="/market/seller.php?id=<?= e($withPublicId) ?>">
        View listings
      </a>
    </div>
  </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
  window.WS_TOKEN = <?= json_encode(ensure_ws_session($db, $userId)) ?>;
  window.CHAT_WITH = <?= json_encode($withPublicId) ?>;
  window.CHAT_WITH_PUBLIC_ID = <?= json_encode($withPublicId) ?>;
</script>
<script src="<?= asset_url('/assets/js/time_format.js') ?>"></script>
<script src="<?= asset_url('/assets/js/messages.js') ?>"></script>
<script src="<?= asset_url('/assets/js/chat.js') ?>"></script>

<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
