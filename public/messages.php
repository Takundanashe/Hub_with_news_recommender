<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';

start_secure_session();
$userId = require_login();
$db = get_db();

$pageTitle = 'Messages';
$activeNav = 'messages';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="messenger-shell">
  <div class="messenger-list">
    <?php require __DIR__ . '/../includes/dm_sidebar.php'; ?>
  </div>
  <div class="messenger-conversation messenger-conversation--empty">
    <div class="messenger-empty-state">
      <span class="icon">💬</span>
      <p>Select a conversation to start chatting.</p>
    </div>
  </div>
</div>

<script src="<?= asset_url('/assets/js/time_format.js') ?>"></script>
<script src="<?= asset_url('/assets/js/messages.js') ?>"></script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
