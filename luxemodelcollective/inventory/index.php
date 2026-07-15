<?php
/**
 * Entry point. Routes the user based on their state.
 */
require_once __DIR__ . '/includes/auth.php';

if (needs_first_run_setup()) {
    header('Location: setup.php');
    exit;
}

if (!current_user()) {
    header('Location: login.php');
    exit;
}

header('Location: dashboard.php');
exit;
