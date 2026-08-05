<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
start_secure_session();
$userId = require_login();
$db = get_db();

$pageTitle = 'Search';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header"><h1>Search</h1></div>

<div class="input-pill" style="margin-bottom: var(--space-2);">
  <span class="icon">🔍</span>
  <input type="text" id="search-input" placeholder="Search goods, friends, houses, groups…">
</div>

<div class="search-tabs">
  <button class="search-tab is-active" data-cat="all">All</button>
  <button class="search-tab" data-cat="goods">Goods</button>
  <button class="search-tab" data-cat="jobs">Jobs</button>
  <button class="search-tab" data-cat="lost_found">Lost&nbsp;&amp;&nbsp;Found</button>
  <button class="search-tab" data-cat="houses">Houses</button>
  <button class="search-tab" data-cat="friends">Friends</button>
  <button class="search-tab" data-cat="groups">Groups</button>
</div>

<div class="card" style="padding: var(--space-2);">
  <div id="search-results">
    <p style="padding: var(--space-4); color: var(--color-ink-soft); font-size:14px;">Start typing to search.</p>
  </div>
</div>

<script src="<?= asset_url('/assets/js/search.js') ?>"></script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
