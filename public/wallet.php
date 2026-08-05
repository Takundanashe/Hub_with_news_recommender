<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';

start_secure_session();
$userId = require_login();
$db = get_db();

$stmt = $db->prepare('SELECT money_id, balance_cents FROM wallets WHERE user_id = :id');
$stmt->execute([':id' => $userId]);
$wallet = $stmt->fetch();

$stmt = $db->prepare(
    "SELECT wt.public_id, wt.amount_cents, wt.memo, wt.created_at,
            CASE WHEN wt.sender_id = :me THEN 'out' ELSE 'in' END AS direction,
            other.fname AS other_fname, other.lname AS other_lname
     FROM wallet_transactions wt
     LEFT JOIN users other ON other.id = (CASE WHEN wt.sender_id = :me THEN wt.recipient_id ELSE wt.sender_id END)
     WHERE wt.sender_id = :me OR wt.recipient_id = :me
     ORDER BY wt.created_at DESC LIMIT 50"
);
$stmt->execute([':me' => $userId]);
$transactions = $stmt->fetchAll();

// Simple last-6-transaction in/out bar comparison - no chart library needed.
$recentIn = array_sum(array_map(fn($t) => $t['direction'] === 'in' ? $t['amount_cents'] : 0, array_slice($transactions, 0, 10)));
$recentOut = array_sum(array_map(fn($t) => $t['direction'] === 'out' ? $t['amount_cents'] : 0, array_slice($transactions, 0, 10)));
$maxBar = max($recentIn, $recentOut, 1);

$pageTitle = 'Wallet';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="page-header"><h1>Wallet</h1></div>

<div class="wallet-hero">
  <div class="wallet-hero-label">Available balance</div>
  <div class="wallet-balance"><?= e(number_format($wallet['balance_cents'] / 100, 2)) ?> credits</div>
  <div class="money-id"><?= e($wallet['money_id']) ?></div>

  <div class="wallet-quick-actions">
    <button id="qr-toggle"><span class="icon">▦</span>QR</button>
    <button onclick="document.getElementById('send-form').scrollIntoView({behavior:'smooth'})"><span class="icon">↗</span>Send</button>
    <button onclick="document.getElementById('history-card').scrollIntoView({behavior:'smooth'})"><span class="icon">↺</span>History</button>
  </div>
  <div id="qr-code" style="display:none;"></div>
</div>

<div class="card" style="padding: var(--space-5); margin-top: var(--space-5);">
  <h2 style="margin-top:0; font-size:15px;">Recent activity</h2>
  <div class="stat-bars">
    <div class="stat-bar in" style="height: <?= max(8, round($recentIn / $maxBar * 100)) ?>%;"></div>
    <div class="stat-bar out" style="height: <?= max(8, round($recentOut / $maxBar * 100)) ?>%;"></div>
  </div>
  <div style="display:flex; justify-content:space-between; font-size:12px; color:var(--color-ink-soft);">
    <span>In: <?= e(number_format($recentIn / 100, 2)) ?></span>
    <span>Out: <?= e(number_format($recentOut / 100, 2)) ?></span>
  </div>
</div>

<div class="card" id="send-form-card" style="padding: var(--space-5); margin-top: var(--space-5); max-width: 480px;">
  <h2 style="margin-top:0; font-size:16px;">Send credits</h2>
  <form id="send-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <div id="form-message" class="form-message"></div>
    <div class="field">
      <div class="input-pill"><span class="icon">🆔</span><input id="recipient_money_id" name="recipient_money_id" type="text" placeholder="MID-XXXXXXXX" required></div>
    </div>
    <div class="field">
      <div class="input-pill"><span class="icon">💰</span><input id="amount" name="amount" type="number" step="0.01" min="0.01" placeholder="Amount" required></div>
    </div>
    <div class="field">
      <div class="input-pill"><span class="icon">📝</span><input id="memo" name="memo" type="text" placeholder="Memo (optional)" maxlength="140"></div>
    </div>
    <button type="submit" class="btn-primary">Send</button>
  </form>
</div>

<div class="card" id="history-card" style="padding: var(--space-5); margin-top: var(--space-5);">
  <h2 style="margin-top:0; font-size:16px;">Transaction history</h2>
  <?php if (!$transactions): ?>
    <p style="color: var(--color-ink-soft); font-size:14px;">No transactions yet.</p>
  <?php else: foreach ($transactions as $t): ?>
    <div class="tx-row">
      <span><?= $t['direction'] === 'out' ? 'To ' : 'From ' ?><?= e($t['other_fname'] . ' ' . $t['other_lname']) ?><?= $t['memo'] ? ' — ' . e($t['memo']) : '' ?></span>
      <span class="tx-amount <?= e($t['direction']) ?>"><?= $t['direction'] === 'out' ? '-' : '+' ?><?= e(number_format($t['amount_cents'] / 100, 2)) ?></span>
    </div>
  <?php endforeach; endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js" nonce="<?= e(csp_nonce()) ?>"></script>
<script nonce="<?= e(csp_nonce()) ?>">window.MONEY_ID = <?= json_encode($wallet['money_id']) ?>;</script>
<script src="<?= asset_url('/assets/js/wallet.js') ?>"></script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
