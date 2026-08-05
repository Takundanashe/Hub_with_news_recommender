<?php
/**
 * Renders the .messenger-list contents for DMs: search bar, pinned self
 * profile row, "Recent chats" label, contact rows, Add Contact button.
 *
 * Expects, from the including page:
 *   $userId          - current user's id
 *   $db              - PDO handle
 *   $selectedPublicId - (optional) public_id of the open conversation, for
 *                        highlighting the selected row (chat.php only)
 */
$stmt = $db->prepare('SELECT fname, lname, avatar, status FROM users WHERE id = :id');
$stmt->execute([':id' => $userId]);
$me = $stmt->fetch();

$stmt = $db->prepare(
    "SELECT u.id, u.public_id, u.fname, u.lname, u.avatar, u.status,
            MAX(dm.created_at) AS last_time,
            (SELECT body FROM direct_messages
              WHERE (sender_id = :me1 AND recipient_id = u.id) OR (sender_id = u.id AND recipient_id = :me2)
              ORDER BY created_at DESC LIMIT 1) AS last_body,
            (SELECT sender_id FROM direct_messages
              WHERE (sender_id = :me3 AND recipient_id = u.id) OR (sender_id = u.id AND recipient_id = :me4)
              ORDER BY created_at DESC LIMIT 1) AS last_sender_id,
            (SELECT COUNT(*) FROM direct_messages
              WHERE sender_id = u.id AND recipient_id = :me5 AND read_at IS NULL) AS unread_count
     FROM direct_messages dm
     JOIN users u ON u.id = CASE WHEN dm.sender_id = :me6 THEN dm.recipient_id ELSE dm.sender_id END
     WHERE dm.sender_id = :me7 OR dm.recipient_id = :me8
     GROUP BY u.id
     ORDER BY last_time DESC"
);
$stmt->execute([
    ':me1' => $userId, ':me2' => $userId, ':me3' => $userId, ':me4' => $userId,
    ':me5' => $userId, ':me6' => $userId, ':me7' => $userId, ':me8' => $userId,
]);
$conversations = $stmt->fetchAll();
$selectedPublicId = $selectedPublicId ?? null;
?>
<div class="messenger-search">
  <span class="icon">🔍</span>
  <input type="text" id="contact-filter" placeholder="Search...">
</div>

<div class="chat-profile-row">
  <img src="/uploads/<?= e($me['avatar']) ?>" alt="">
  <div>
    <div class="name"><?= e($me['fname'] . ' ' . $me['lname']) ?></div>
    <div class="status-row"><span class="status-dot online"></span>Online</div>
  </div>
</div>

<div class="recent-chats-label">Recent chats</div>

<div class="messenger-list-scroll">
<?php if (!$conversations): ?>
  <p style="padding: var(--space-3) 4px; color: rgba(255,255,255,0.55); font-size: 14px;">
    No conversations yet - use Add Contact below to start one.
  </p>
<?php else: foreach ($conversations as $c):
    $isMine = (int) $c['last_sender_id'] === $userId;
    $preview = ($isMine ? 'You: ' : '') . (string) $c['last_body'];
    $isOnline = $c['status'] === 'Active now';
?>
  <a class="contact-row <?= $c['public_id'] === $selectedPublicId ? 'is-selected' : '' ?>" href="/chat.php?with=<?= e($c['public_id']) ?>">
    <div class="contact-avatar-wrap">
      <img src="/uploads/<?= e($c['avatar']) ?>" alt="">
      <?php if ($isOnline): ?><span class="status-dot online"></span><?php endif; ?>
    </div>
    <div class="contact-info">
      <div class="contact-name"><?= e($c['fname'] . ' ' . $c['lname']) ?></div>
      <div class="contact-preview" data-preview="<?= e($preview) ?>"><?= e(mb_strimwidth($preview, 0, 40, '...')) ?></div>
    </div>
    <div class="contact-meta">
      <span class="contact-time" data-created="<?= e($c['last_time']) ?>"></span>
      <?php if ((int) $c['unread_count'] > 0): ?>
        <span class="unread-badge"><?= (int) $c['unread_count'] > 99 ? '99+' : (int) $c['unread_count'] ?></span>
      <?php endif; ?>
    </div>
  </a>
<?php endforeach; endif; ?>
</div>

<a href="/search.php" class="add-contact-btn"><span class="icon">➕👤</span>Add Contact</a>
