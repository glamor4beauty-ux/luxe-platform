<?php
/**
 * First-run setup page. Creates the initial Super Admin account.
 * Only accessible if no users exist yet.
 */
require_once __DIR__ . '/includes/auth.php';

if (!needs_first_run_setup()) {
    header('Location: login.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm']  ?? '';

    if (strlen($username) < 3 || strlen($username) > 50) {
        $error = 'Username must be between 3 and 50 characters.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]+$/', $username)) {
        $error = 'Username can only contain letters, numbers, dots, dashes, and underscores.';
    } elseif (strlen($password) < 10) {
        $error = 'Password must be at least 10 characters long.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = db()->prepare('INSERT INTO fc_users (username, password_hash, role) VALUES (?, ?, ?)');
        try {
            $stmt->execute([$username, $hash, 'super_admin']);
            $uid = (int) db()->lastInsertId();
            login_user($uid, $username, 'super_admin');
            header('Location: dashboard.php');
            exit;
        } catch (PDOException $e) {
            $error = 'Could not create user. The database may not be set up. Run schema.sql first.';
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Setup &mdash; My Furry Companion</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-body">
  <div class="auth-card">
    <h1 class="auth-title">First-time Setup</h1>
    <p class="auth-sub">Create your Super Admin account. You can add additional users (Admins) later.</p>
    <?php if ($error): ?>
      <div class="alert alert-error"><?= esc($error) ?></div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>">
      <label>Username
        <input type="text" name="username" required minlength="3" maxlength="50" autofocus
               value="<?= esc($_POST['username'] ?? '') ?>"
               pattern="[A-Za-z0-9_.\-]+" autocomplete="off">
      </label>
      <label>Password (minimum 10 characters)
        <input type="password" name="password" required minlength="10" autocomplete="new-password">
      </label>
      <label>Confirm Password
        <input type="password" name="confirm" required minlength="10" autocomplete="new-password">
      </label>
      <button type="submit" class="btn btn-primary btn-block">Create Account</button>
    </form>
    <p class="auth-note">
      Store this password securely. If you forget it, you'll need to reset it by editing the users table directly via phpMyAdmin.
    </p>
  </div>
</body>
</html>
