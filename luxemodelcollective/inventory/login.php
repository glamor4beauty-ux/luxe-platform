<?php
require_once __DIR__ . '/includes/auth.php';

if (needs_first_run_setup()) {
    header('Location: setup.php');
    exit;
}

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (empty($username) || empty($password)) {
        $error = 'Username and password are required.';
    } else {
        $result = attempt_login($username, $password);
        if (is_array($result)) {
            header('Location: dashboard.php');
            exit;
        }
        $error = $result;
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Sign In &mdash; My Furry Companion</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-body">
  <div class="auth-card">
    <h1 class="auth-title">My Furry Companion</h1>
    <p class="auth-sub">Inventory Dashboard</p>
    <?php if ($error): ?>
      <div class="alert alert-error"><?= esc($error) ?></div>
    <?php endif; ?>
    <form method="post" autocomplete="on">
      <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
      <label>Username
        <input type="text" name="username" required autofocus
               value="<?= esc($_POST['username'] ?? '') ?>" autocomplete="username">
      </label>
      <label>Password
        <input type="password" name="password" required autocomplete="current-password">
      </label>
      <button type="submit" class="btn btn-primary btn-block">Sign In</button>
    </form>
  </div>
</body>
</html>
