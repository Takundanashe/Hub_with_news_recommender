<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';

start_secure_session();
$userId = require_login();
$db = get_db();

$stmt = $db->prepare(
    "SELECT g.public_id, g.name, g.description, g.privacy, g.avatar,
            EXISTS(SELECT 1 FROM group_members gm WHERE gm.group_id = g.id AND gm.user_id = :me) AS joined,
            (SELECT body FROM group_messages WHERE group_id = g.id ORDER BY created_at DESC LIMIT 1) AS last_body,
            (SELECT created_at FROM group_messages WHERE group_id = g.id ORDER BY created_at DESC LIMIT 1) AS last_time
     FROM groups_table g
     WHERE g.privacy = 'public' OR EXISTS(SELECT 1 FROM group_members gm2 WHERE gm2.group_id = g.id AND gm2.user_id = :me)
     ORDER BY g.created_at DESC"
);
$stmt->execute([':me' => $userId]);
$groups = $stmt->fetchAll();
$myGroups = array_filter($groups, static fn ($g) => $g['joined']);
$discoverGroups = array_filter($groups, static fn ($g) => !$g['joined']);

$pageTitle = 'Groups';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header">
  <h1>Groups</h1>
  <button class="btn-primary btn-inline" id="new-group-btn">New group</button>
</div>

<dialog id="new-group-dialog" class="card" style="padding: var(--space-5); width: min(420px, 90vw); border: none;">
  <form id="new-group-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <div id="form-message" class="form-message"></div>
    <div class="field">
      <label for="name">Group name</label>
      <input id="name" name="name" type="text" required maxlength="80">
    </div>
    <div class="field">
      <label for="description">Description</label>
      <textarea id="description" name="description" rows="3" maxlength="500"></textarea>
    </div>
    <div class="field">
      <label for="privacy">Privacy</label>
      <select id="privacy" name="privacy">
        <option value="public">Public - anyone can find and join</option>
        <option value="private">Private - invite only</option>
      </select>
    </div>
    <div style="display:flex; gap:8px;">
      <button type="submit" class="btn-primary btn-inline">Create</button>
      <button type="button" class="btn-secondary" id="cancel-group-btn">Cancel</button>
    </div>
  </form>
</dialog>

<div class="card contact-list-card" style="margin-bottom: var(--space-5);">
  <?php if (!$myGroups): ?>
    <p style="padding: var(--space-4); color: var(--color-ink-soft);">You haven't joined any groups yet - browse below.</p>
  <?php else: foreach ($myGroups as $g): ?>
    <a class="contact-row" href="/group.php?id=<?= e($g['public_id']) ?>">
      <div class="contact-avatar-wrap"><img src="/uploads/<?= e($g['avatar']) ?>" alt=""></div>
      <div class="contact-info">
        <div class="contact-name"><?= e($g['name']) ?></div>
        <div class="contact-preview"><?= e(mb_strimwidth((string) $g['last_body'], 0, 40, '...')) ?: 'No messages yet' ?></div>
      </div>
      <div class="contact-meta">
        <span class="contact-time" data-created="<?= e($g['last_time']) ?>"></span>
      </div>
    </a>
  <?php endforeach; endif; ?>
</div>

<div class="page-header"><h2 style="font-size:16px; color:var(--color-ink-soft);">Discover groups</h2></div>
<div class="card-grid">
  <?php foreach ($discoverGroups as $g): ?>
    <div class="card listing-card">
      <div class="listing-card-body">
        <p class="listing-title"><?= e($g['name']) ?> <span class="pill"><?= e($g['privacy']) ?></span></p>
        <p style="font-size:13px; color:var(--color-ink-soft); min-height:36px;"><?= e($g['description']) ?></p>
        <button class="btn-primary btn-sm join-group-btn" data-group="<?= e($g['public_id']) ?>">Join</button>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<script src="<?= asset_url('/assets/js/time_format.js') ?>"></script>
<script src="<?= asset_url('/assets/js/groups.js') ?>"></script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
