<?php
// Expects $page_title and $user (from require_login)
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= esc($page_title ?? 'Dashboard') ?> &mdash; My Furry Companion</title>
  <link rel="stylesheet" href="assets/style.css">
  <meta name="csrf-token" content="<?= esc(csrf_token()) ?>">
</head>
<body>
<header class="topbar">
  <div class="brand">
    <span class="brand-name">My Furry Companion</span>
    <span class="brand-tag">Inventory Dashboard</span>
  </div>
  <div class="topbar-center">
    <div class="clock" id="estClock">&nbsp;</div>
  </div>
  <div class="topbar-actions">
    <?php if (!empty($user) && $user['role'] === 'super_admin'): ?>
      <a href="upload.php" class="btn btn-accent">Upload CSV</a>
    <?php endif; ?>
    <?php if (!empty($user)): ?>
      <div class="user-menu">
        <span class="username"><?= esc($user['username']) ?></span>
        <span class="role-badge role-<?= esc($user['role']) ?>"><?= $user['role'] === 'super_admin' ? 'Super Admin' : 'Admin' ?></span>
        <a href="logout.php" class="btn btn-ghost">Sign out</a>
      </div>
    <?php endif; ?>
  </div>
</header>
<main class="container">
