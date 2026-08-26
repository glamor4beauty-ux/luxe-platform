<?php
/* ═══════════════════════════════════════════════════════════════════════════
   logout.php — leaving.

   The session is emptied and its cookie expired, rather than only the one key
   being unset: a half-cleared session is how somebody signs out on a shared
   computer and stays signed in.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/config.php';
mb_session();

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
              $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_destroy();

header('Location: /login.php');
exit;
