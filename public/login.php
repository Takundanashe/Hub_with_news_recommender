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
<title>Log in</title>
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>
<main class="auth-v2">
  <div class="auth-v2-bg"></div>
  <form class="auth-v2-card" id="login-form" method="post" action="/actions/login.php" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

    <h1 class="auth-v2-heading">Login</h1>
    <p class="auth-v2-subtitle">Welcome back, please login to your account</p>

    <div id="auth-error" class="auth-v2-error"></div>

    <div class="auth-v2-field">
      <input id="email" name="email" type="email" placeholder="Email" required autofocus autocomplete="username">
    </div>

    <div class="auth-v2-field">
      <input id="password" name="password" type="password" placeholder="Password" required autocomplete="current-password">
      <button type="button" class="field-icon" id="toggle-password" aria-label="Show password">👁</button>
    </div>

    <div class="auth-v2-row">
      <label class="auth-v2-remember">
        <input type="checkbox" name="remember" checked> Remember me
      </label>
      <a class="auth-v2-forgot" href="#">Forgot Password?</a>
    </div>

    <button type="submit" class="auth-v2-submit" id="submit-btn">
      <span id="submit-label">Login</span>
    </button>

    <p class="auth-v2-footer">Don't have an account? <a href="/signup.php">Signup</a></p>
  </form>
</main>
<script src="<?= asset_url('/assets/js/login.js') ?>"></script>
</body>
</html>
