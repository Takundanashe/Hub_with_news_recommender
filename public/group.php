<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';

start_secure_session();
$userId = require_login();

$groupPublicId = trim((string) ($_GET['id'] ?? ''));
$db = get_db();

$stmt = $db->prepare('SELECT id, name, privacy, avatar FROM groups_table WHERE public_id = :pid');
$stmt->execute([':pid' => $groupPublicId]);
$group = $stmt->fetch();
if (!$group) {
    header('Location: /groups.php');
    exit;
}

$stmt = $db->prepare('SELECT 1 FROM group_members WHERE group_id = :gid AND user_id = :uid');
$stmt->execute([':gid' => $group['id'], ':uid' => $userId]);
$isMember = (bool) $stmt->fetch();

if ($isMember) {
    $db->prepare("UPDATE group_members SET last_read_at = datetime('now') WHERE group_id = :gid AND user_id = :uid")
       ->execute([':gid' => $group['id'], ':uid' => $userId]);
}

$stmt = $db->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = :gid');
$stmt->execute([':gid' => $group['id']]);
$memberCount = (int) $stmt->fetchColumn();

$stmt = $db->prepare(
    'SELECT gm.id, gm.body, gm.created_at, u.fname, u.lname, gm.sender_id
     FROM group_messages gm JOIN users u ON u.id = gm.sender_id
     WHERE gm.group_id = :gid AND gm.is_deleted = 0
     ORDER BY gm.id ASC LIMIT 200'
);
$stmt->execute([':gid' => $group['id']]);
$messages = $stmt->fetchAll();
$lastSeq = $messages ? (int) end($messages)['id'] : 0;

$pageTitle = e($group['name']);
$activeNav = 'groups';
require __DIR__ . '/../includes/layout_top.php';
?>

<div class="messenger-shell messenger-shell--standalone">
<div class="messenger-conversation messenger-conversation--standalone">
  <div class="messenger-header">
    <a href="/groups.php" class="back-btn" aria-label="Back to groups">←</a>
    <button type="button" class="header-contact-trigger" id="open-contact-info" aria-label="View group info">
      <img src="/uploads/<?= e($group['avatar'] ?? 'default_group.png') ?>" alt="">
      <div>
        <div class="messenger-header-name"><?= e($group['name']) ?></div>
        <div class="messenger-header-status"><?= e(ucfirst($group['privacy'])) ?> group</div>
      </div>
    </button>
    <div class="messenger-header-icons">
      <button type="button" class="icon-btn" aria-disabled="true" title="Voice calling isn't available yet">📞</button>
      <button type="button" class="icon-btn" aria-disabled="true" title="Video calling isn't available yet">🎥</button>
      <button type="button" class="icon-btn" title="More">⋮</button>
    </div>
  </div>

  <div class="chat-messages" id="chat-messages">
    <?php
    $prevSender = null; $prevDate = null;
    $todayStr = gmdate('Y-m-d');
    $yesterdayStr = gmdate('Y-m-d', strtotime('-1 day'));
    foreach ($messages as $m):
        $sender = (int) $m['sender_id'] === $userId ? 'mine' : 'theirs';
        $msgDate = substr((string) $m['created_at'], 0, 10);
        if ($msgDate !== $prevDate) { $prevSender = null; }
    ?>
      <?php if ($msgDate !== $prevDate): $prevDate = $msgDate;
          if ($msgDate === $todayStr) { $pillLabel = 'Today'; }
          elseif ($msgDate === $yesterdayStr) { $pillLabel = 'Yesterday'; }
          else { $pillLabel = date('M j', strtotime($msgDate)); }
      ?>
        <div class="date-pill"><?= e($pillLabel) ?></div>
      <?php endif; ?>
      <div class="msg-bubble <?= $sender ?> <?= $sender === $prevSender ? 'grouped' : '' ?>" data-msg-id="<?= (int) $m['id'] ?>">
        <?php if ($sender !== 'mine'): ?><strong style="display:block; font-size:11px; opacity:0.8;"><?= e($m['fname']) ?></strong><?php endif; ?>
        <div class="msg-body"><?= e($m['body']) ?></div>
        <span class="msg-time" data-created="<?= e($m['created_at']) ?>"></span>
      </div>
      <?php $prevSender = $sender; ?>
    <?php endforeach; ?>
  </div>

  <?php if ($isMember): ?>
    <form id="group-composer-form" class="messenger-composer">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="group_id" value="<?= e($groupPublicId) ?>">
      <input type="text" id="group-input" placeholder="Type a message..." required maxlength="4000">
      <button type="button" class="composer-emoji-btn" id="emoji-btn" aria-label="Emoji">😊</button>
      <button type="submit" class="composer-send-btn">Send</button>
    </form>
  <?php else: ?>
    <p style="padding: var(--space-4); color: rgba(255,255,255,0.6);">Join this group to post.</p>
  <?php endif; ?>
</div>

<div class="messenger-info" id="messenger-info">
  <div class="messenger-info-header">
    <button type="button" class="back-btn" id="close-contact-info" aria-label="Back to conversation">←</button>
    <span>Group Info</span>
  </div>
  <div class="messenger-info-body">
    <img class="messenger-info-avatar" src="/uploads/<?= e($group['avatar'] ?? 'default_group.png') ?>" alt="">
    <div class="messenger-info-name"><?= e($group['name']) ?></div>
    <div class="messenger-info-status"><?= (int) $memberCount ?> member<?= $memberCount === 1 ? '' : 's' ?></div>

    <div class="messenger-info-section">
      <div class="messenger-info-label">Group Info</div>
      <div class="messenger-info-row"><span>Privacy</span><span><?= e(ucfirst($group['privacy'])) ?></span></div>
      <div class="messenger-info-row"><span>Members</span><span><?= (int) $memberCount ?></span></div>
    </div>
  </div>
</div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
  window.WS_TOKEN = <?= json_encode(ensure_ws_session($db, $userId)) ?>;
  window.GROUP_ID = <?= json_encode($groupPublicId) ?>;
  window.LAST_SEQ = <?= json_encode($lastSeq) ?>;
</script>
<script src="<?= asset_url('/assets/js/time_format.js') ?>"></script>
<script src="<?= asset_url('/assets/js/group.js') ?>"></script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
