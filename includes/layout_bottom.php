    </main>

    <nav class="bottom-nav glass-panel" aria-label="Primary">
      <?php foreach (nav_items() as $item): if (!in_array($item['href'], bottom_nav_items(), true)) continue; ?>
        <a href="<?= e($item['href']) ?>" class="<?= nav_is_active($item['match']) ? 'is-active' : '' ?>">
          <span class="icon"><?= $item['icon'] ?></span><?= e($item['label']) ?>
          <?php if ($item['href'] === '/messages.php'): $unreadDm = get_unread_dm_count($db ?? null, $userId ?? null); ?>
            <span class="nav-badge nav-badge--bottom <?= $unreadDm > 0 ? 'is-visible' : '' ?>" data-badge="messages"><?= $unreadDm > 99 ? '99+' : $unreadDm ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>
</div>
<script src="<?= asset_url('/assets/js/nav.js') ?>"></script>
<script src="<?= asset_url('/assets/js/nav_badges.js') ?>"></script>
<script src="<?= asset_url('/assets/js/action_sheet.js') ?>"></script>
</body>
</html>
