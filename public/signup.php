<?php
require_once __DIR__ . '/../includes/security.php';
start_secure_session();
if (!empty($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}
$token = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Create account</title>
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<main class="auth-v2">
  <div class="auth-v2-bg"></div>
  <form class="auth-v2-card" id="signup-form" method="post" action="/actions/signup.php" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

    <h1 class="auth-v2-heading">Create Account</h1>
    <p class="auth-v2-subtitle">Let's get you started</p>

    <div id="auth-error" class="auth-v2-error"></div>

    <div class="auth-v2-field">
      <input id="fname" name="fname" type="text" placeholder="First name" required autofocus autocomplete="given-name">
    </div>
    <div class="auth-v2-field">
      <input id="lname" name="lname" type="text" placeholder="Last name" required autocomplete="family-name">
    </div>
    <div class="auth-v2-field">
      <input id="username" name="username" type="text" placeholder="Username" required maxlength="30" pattern="[a-zA-Z0-9_]{3,30}" autocomplete="username">
    </div>
    <div class="auth-v2-field">
      <input id="email" name="email" type="email" placeholder="Email" required autocomplete="email">
    </div>
    <div class="auth-v2-field">
      <input id="password" name="password" type="password" placeholder="Password" required minlength="8" autocomplete="new-password">
      <button type="button" class="field-icon toggle-password" aria-label="Show password">👁</button>
    </div>
    <div class="auth-v2-field">
      <input id="password_confirm" name="password_confirm" type="password" placeholder="Confirm password" required minlength="8" autocomplete="new-password">
      <button type="button" class="field-icon toggle-password" aria-label="Show password">👁</button>
    </div>

    <button type="submit" class="auth-v2-submit" id="submit-btn" style="margin-top: 8px;">
      <span id="submit-label">Create account</span>
    </button>

    <p class="auth-v2-footer">Already have an account? <a href="/login.php">Log in</a></p>
  </form>
</main>
<script src="<?= asset_url('/assets/js/signup.js') ?>"></script>
</body>
</html>
