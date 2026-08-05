<?php
require_once __DIR__ . '/nav.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle ?? 'App') ?></title>
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<div class="app-shell">
  <?php require __DIR__ . '/sidebar.php'; ?>

  <div class="app-main">
    <header class="topbar glass-panel">
      <button class="menu-btn" id="menu-toggle" aria-label="Open menu">☰</button>
      <span class="topbar-title"><?= e($pageTitle ?? '') ?></span>
    </header>

    <main class="page-main">
      <script nonce="<?= e(csp_nonce()) ?>">
        window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
        window.WS_TOKEN = window.WS_TOKEN || <?= json_encode((isset($db, $userId)) ? ensure_ws_session($db, $userId) : '') ?>;
      </script>
