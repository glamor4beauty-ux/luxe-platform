<?php
/* ═══════════════════════════════════════════════════════════════════════════
   login.php — signing in, and creating the first account.

   With no accounts on file the page becomes a setup form instead, and whoever
   completes it becomes the superadmin. That is safer than shipping a default
   password, which tends to survive far longer than intended.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/config.php';
require __DIR__ . '/authz.php';

auth_start();

$setup = users_none();
$error = '';
$next  = $_GET['next'] ?? 'index.php';
/* only ever return somewhere inside this app */
if (!preg_match('#^/shopify/#', (string)$next)) $next = 'index.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass  = (string)($_POST['password'] ?? '');

    if ($setup) {
        $again = (string)($_POST['password2'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'That does not look like an email address.';
        } elseif ($pass !== $again) {
            $error = 'The two passwords do not match.';
        } elseif ($p = password_problem($pass)) {
            $error = $p;
        } else {
            $ok = users_save([[
                'email'   => $email,
                'hash'    => password_hash($pass, PASSWORD_DEFAULT),
                'role'    => ROLE_SUPER,
                'created' => date('c'),
            ]]);
            if (!$ok) {
                $error = 'Could not write the account file. The web user needs write access '
                       . 'to ' . htmlspecialchars(dirname(USERS_FILE)) . '.';
            } else {
                auth_login($email, $pass);
                header('Location: index.php');
                exit;
            }
        }
    } else {
        if (auth_login($email, $pass)) {
            header('Location: ' . $next);
            exit;
        }
        $error = 'Email or password not recognised.';
    }
}

function e2($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"/>
<meta name="robots" content="noindex,nofollow"/>
<meta name="theme-color" content="#0f1115"/>
<title><?= $setup ? 'Set up' : 'Sign in' ?> · <?= e2(APP_NAME) ?></title>
<style>
:root{--bg:#0f1115;--panel:#171a21;--panel2:#12151b;--line:#242832;--line2:#333846;
  --txt:#e9eaee;--txt2:#b9bcc6;--mute:#7b8194;--gold:#d8a94b;--ok:#4ec97a;--bad:#f2685e}
*{box-sizing:border-box}
html,body{margin:0;padding:0;min-height:100%}
body{background:var(--bg);color:var(--txt);
  font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
  font-size:15px;line-height:1.55;-webkit-font-smoothing:antialiased;
  display:flex;align-items:center;justify-content:center;min-height:100vh;padding:22px}
.card{width:100%;max-width:400px}
.brand{text-align:center;margin-bottom:26px}
.mark{width:52px;height:52px;margin:0 auto 12px;display:block}
.brand h1{margin:0;font-size:19px;font-weight:700}
.brand p{margin:4px 0 0;font-size:12.5px;color:var(--mute)}
form{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:22px}
label{display:block;font-size:10.5px;color:var(--mute);text-transform:uppercase;
  letter-spacing:.07em;font-weight:600;margin-bottom:16px}
input{display:block;width:100%;margin-top:6px;background:var(--panel2);
  border:1px solid var(--line2);border-radius:10px;padding:12px 14px;color:var(--txt);
  font-size:16px;outline:none;font-family:inherit;font-weight:400;
  text-transform:none;letter-spacing:0}
input:focus{border-color:var(--gold)}
button{width:100%;background:var(--gold);border:none;border-radius:10px;color:#14161b;
  padding:14px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit}
button:hover{background:#e6bb63}
.err{background:rgba(242,104,94,.1);border:1px solid rgba(242,104,94,.4);color:#ffb3ac;
  border-radius:9px;padding:11px 13px;font-size:13.5px;margin-bottom:16px;line-height:1.5}
.hint{font-size:12px;color:var(--mute);margin:14px 0 0;line-height:1.6}
.hint b{color:var(--gold)}
.setup-note{background:rgba(216,169,75,.07);border:1px solid rgba(216,169,75,.3);
  border-radius:9px;padding:12px 14px;font-size:13px;color:var(--txt2);
  margin-bottom:18px;line-height:1.6}
</style>
</head>
<body>
<div class="card">

  <div class="brand">
    <svg class="mark" viewBox="0 0 24 24">
      <rect x="1.5" y="1.5" width="21" height="21" rx="4.5" fill="#d8a94b"/>
      <path d="M6.4 7.1h11.2v3.1h-1.05c-.15-1.2-.5-1.55-1.5-1.55h-1.9v6.9c0 .95.2 1.15 1.25 1.2v1.15H9.6v-1.15c1.05-.05 1.25-.25 1.25-1.2v-6.9h-1.9c-1 0-1.35.35-1.5 1.55H6.4z" fill="#0f1115"/>
    </svg>
    <h1><?= e2(APP_NAME) ?></h1>
    <p><?= $setup ? 'First run' : 'Sign in to continue' ?></p>
  </div>

  <form method="post" action="">
    <?php if ($error): ?><div class="err"><?= e2($error) ?></div><?php endif; ?>

    <?php if ($setup): ?>
      <div class="setup-note">
        No accounts yet. Whoever fills this in becomes the <b>superadmin</b> — the
        only role that can change anything or add other people.
      </div>
    <?php endif; ?>

    <label>Email
      <input type="email" name="email" value="<?= e2($_POST['email'] ?? '') ?>"
             autocomplete="username" required autofocus>
    </label>

    <label>Password
      <input type="password" name="password"
             autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>" required>
    </label>

    <?php if ($setup): ?>
      <label>Password again
        <input type="password" name="password2" autocomplete="new-password" required>
      </label>
    <?php endif; ?>

    <button type="submit"><?= $setup ? 'Create the account' : 'Sign in' ?></button>

    <?php if ($setup): ?>
      <p class="hint">At least 10 characters, with a number or symbol among them.</p>
    <?php endif; ?>
  </form>

</div>
</body>
</html>
