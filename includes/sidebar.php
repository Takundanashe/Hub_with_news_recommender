<div class="sidebar-backdrop" id="sidebar-backdrop"></div>
<aside class="sidebar glass-panel" id="sidebar">
  <div class="sidebar-brand">
    <span class="brand">Hub</span>
    <button class="sidebar-close" id="sidebar-close" aria-label="Close menu">✕</button>
  </div>
  <nav class="sidebar-links">
    <?php
    $unreadDm = get_unread_dm_count($db ?? null, $userId ?? null);
    $unreadGroups = get_unread_group_count($db ?? null, $userId ?? null);
    ?>
    <?php foreach (nav_items() as $item): ?>
      <?php
      $badgeCount = 0;
      if ($item['href'] === '/messages.php') $badgeCount = $unreadDm;
      if ($item['href'] === '/groups.php') $badgeCount = $unreadGroups;
      $badgeKey = $item['href'] === '/messages.php' ? 'messages' : ($item['href'] === '/groups.php' ? 'groups' : null);
      ?>
      <a href="<?= e($item['href']) ?>" class="sidebar-link <?= nav_is_active($item['match']) ? 'is-active' : '' ?>">
        <span class="icon"><?= $item['icon'] ?></span> <?= e($item['label']) ?>
        <?php if ($badgeKey): ?>
          <span class="nav-badge <?= $badgeCount > 0 ? 'is-visible' : '' ?>" data-badge="<?= e($badgeKey) ?>"><?= $badgeCount > 99 ? '99+' : $badgeCount ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <form method="post" action="/actions/logout.php" class="sidebar-logout">
    <button type="submit" class="btn-secondary btn-inline" style="width:100%;">Log out</button>
  </form>
</aside>
