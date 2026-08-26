<?php
/* ═══════════════════════════════════════════════════════════════════════════
   login.php — one door, two kinds of person.

   The same page serves admin and affiliate, and which you are is decided by
   which table your email is in. Two separate sign-in pages would mean people
   arriving at the wrong one and being told their password is wrong when it is
   perfectly correct.

   The two sessions are kept apart, so an affiliate cannot become an admin by
   accident or otherwise.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/config.php';
mb_session();

$next = (string)($_GET['next'] ?? '');
if ($next === '' || $next[0] !== '/') $next = '';

if (mb_admin())     { header('Location: ' . ($next ?: '/affiliates.php')); exit; }
if (mb_affiliate()) { header('Location: ' . ($next ?: '/dashboard.php')); exit; }

$err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass  = (string)($_POST['password'] ?? '');

    /* A pause on every attempt, right or wrong. It costs a person half a
       second and costs somebody trying thousands of passwords rather more. */
    usleep(400000);

    if ($email === '' || $pass === '') {
        $err = 'Enter your email and password.';
    } else {
        $db = db();
        $known = false;

        try {
            $q = $db->prepare("SELECT id, email, name, pass_hash FROM mb_admins WHERE email = ?");
            $q->execute([$email]);
            if ($a = $q->fetch()) {
                $known = true;
                if (password_verify($pass, $a['pass_hash'])) {
                    session_regenerate_id(true);
                    $_SESSION['mb_admin'] = ['id' => $a['id'], 'email' => $a['email'],
                                             'name' => $a['name']];
                    $db->prepare("UPDATE mb_admins SET last_seen = NOW() WHERE id = ?")
                       ->execute([$a['id']]);
                    header('Location: ' . ($next ?: '/affiliates.php'));
                    exit;
                }
            }
        } catch (Throwable $e) {
            error_log('[login] ' . $e->getMessage());
        }

        if (!$known) {
            try {
                $q = $db->prepare("SELECT id, code, email, first_name, status, pass_hash
                                     FROM affiliates WHERE email = ?");
                $q->execute([$email]);
                if ($a = $q->fetch()) {
                    if ($a['pass_hash'] !== '' && password_verify($pass, $a['pass_hash'])) {

                        /* A closed account is told plainly, rather than left to
                           wonder whether it typed the password wrong. */
                        if ($a['status'] === 'banned') {
                            $err = 'This account has been closed. Get in touch if you think '
                                 . 'that is a mistake.';
                        } else {
                            session_regenerate_id(true);
                            $_SESSION['mb_affiliate'] = ['id' => $a['id'], 'code' => $a['code'],
                                                         'email' => $a['email'],
                                                         'name' => $a['first_name']];
                            $db->prepare("UPDATE affiliates SET last_seen = NOW() WHERE id = ?")
                               ->execute([$a['id']]);
                            header('Location: ' . ($next ?: '/dashboard.php'));
                            exit;
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('[login] ' . $e->getMessage());
            }
        }

        /* One message for a wrong password and for an email nobody has, so this
           page cannot be used to find out who holds an account. */
        if ($err === '') $err = 'That email and password do not match.';
    }
}

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<meta name="robots" content="noindex,nofollow"/>
<title>Sign in · <?= e(APP_NAME) ?></title>
<style>
:root{--bg:#0a0d14;--panel:#111622;--panel2:#0e1219;--line:#1e2330;--line2:#2a3040;
  --txt:#e8eaf0;--mute:#8a8fa8;--gold:#d4a830;--gold2:#e8bf44}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--txt);
  font-family:'DM Sans',system-ui,-apple-system,'Segoe UI',sans-serif;
  min-height:100vh;display:flex;align-items:center;justify-content:center;
  padding:24px 18px;font-size:15px;line-height:1.55}
a{color:var(--gold);text-decoration:none}
a:hover{color:var(--gold2)}
.box{width:100%;max-width:390px}
.brand{text-align:center;margin-bottom:24px}
.brand h1{font-size:23px;font-weight:700;letter-spacing:-.4px}
.brand h1 span{color:var(--gold)}
.brand p{font-size:13px;color:var(--mute);margin-top:4px}
form{background:var(--panel);border:1px solid var(--line);border-radius:15px;padding:24px}
label{display:block;font-size:10px;color:var(--mute);text-transform:uppercase;
  letter-spacing:.08em;font-weight:700;margin-bottom:6px}
input{width:100%;background:var(--panel2);border:1px solid var(--line2);border-radius:10px;
  padding:13px 14px;color:var(--txt);font-size:16px;outline:none;font-family:inherit;
  margin-bottom:15px}
input:focus{border-color:var(--gold)}
button{width:100%;background:var(--gold);border:none;border-radius:11px;color:#0a0d14;
  padding:14px;font-size:15.5px;font-weight:700;cursor:pointer;font-family:inherit}
button:hover{background:var(--gold2)}
.err{background:rgba(242,104,94,.09);border:1px solid rgba(242,104,94,.4);color:#ffb3ac;
  border-radius:9px;padding:11px 14px;font-size:13.5px;margin-bottom:16px;line-height:1.55}
.foot{text-align:center;font-size:13px;color:var(--mute);margin-top:18px;line-height:1.7}
</style>
</head>
<body>

<div class="box">

  <div class="brand">
    <h1>Models <span>Boutique</span></h1>
    <p>Sign in to your account</p>
  </div>

  <form method="post" action="">
    <?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>

    <label for="email">Email</label>
    <input type="email" id="email" name="email" autocomplete="email" required autofocus
           value="<?= e($_POST['email'] ?? '') ?>">

    <label for="password">Password</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>

    <button type="submit">Sign in</button>
  </form>

  <div class="foot">
    Not signed up yet? <a href="<?= e(AFFILIATE_URL) ?>/">Start selling</a>
  </div>

</div>
</body>
</html>
