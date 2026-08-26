<?php
/* ═══════════════════════════════════════════════════════════════════════════
   auth.php — who is signed in, and what they may do.

   Two roles:
     superadmin  reads and writes, and manages who else has an account
     admin       reads only

   Accounts live in .users.json beside this file. That file holds password
   hashes, so it must never be served — the nginx block denies it, and it is
   written 0600 as a second line of defence.

   Read-only is enforced in write.php, not just hidden in the interface: a
   hidden button stops nobody who can type a URL.
   ═══════════════════════════════════════════════════════════════════════════ */

const USERS_FILE = __DIR__ . '/.users.json';

const ROLE_SUPER = 'superadmin';
const ROLE_ADMIN = 'admin';

function auth_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/shopify/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);
        session_name('EWSESS');
        session_start();
    }
}

function users_load(): array {
    if (!is_file(USERS_FILE)) return [];
    $d = json_decode((string)file_get_contents(USERS_FILE), true);
    return is_array($d) ? $d : [];
}

function users_save(array $users): bool {
    $ok = @file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT)) !== false;
    if ($ok) @chmod(USERS_FILE, 0600);
    return $ok;
}

/* No accounts yet means the first person to arrive becomes superadmin. The
   alternative is a password baked into a file somewhere, which is worse. */
function users_none(): bool {
    return !users_load();
}

function auth_user(): ?array {
    auth_start();
    $e = $_SESSION['ew_user'] ?? '';
    if ($e === '') return null;
    $users = users_load();
    foreach ($users as $u) {
        if (strcasecmp($u['email'] ?? '', $e) === 0) return $u;
    }
    /* the account was deleted while they were signed in */
    unset($_SESSION['ew_user']);
    return null;
}

function auth_role(): string {
    $u = auth_user();
    return $u['role'] ?? '';
}

function is_super(): bool { return auth_role() === ROLE_SUPER; }
function can_write(): bool { return auth_role() === ROLE_SUPER; }

function auth_login(string $email, string $password): bool {
    auth_start();
    foreach (users_load() as $u) {
        if (strcasecmp($u['email'] ?? '', $email) !== 0) continue;
        if (!password_verify($password, $u['hash'] ?? '')) return false;

        session_regenerate_id(true);      /* a fresh id, so a stolen one is useless */
        $_SESSION['ew_user'] = $u['email'];
        $_SESSION['ew_at']   = time();
        return true;
    }
    /* burn about as long as a real check would, so a wrong email and a wrong
       password take the same time to answer */
    password_verify($password, '$2y$10$usesomesillystringfor.OyMyF0aXpQvVzMbTM8Uc3zqxUn3W');
    return false;
}

function auth_logout(): void {
    auth_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'],
                  $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* Every page calls this first. */
function auth_require(): array {
    auth_start();

    if (users_none()) {
        header('Location: login.php?setup=1');
        exit;
    }
    $u = auth_user();
    if (!$u) {
        $to = $_SERVER['REQUEST_URI'] ?? '/shopify/';
        header('Location: login.php?next=' . rawurlencode($to));
        exit;
    }
    return $u;
}

function auth_require_super(): array {
    $u = auth_require();
    if (($u['role'] ?? '') !== ROLE_SUPER) {
        http_response_code(403);
        echo '<!DOCTYPE html><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<body style="background:#0f1115;color:#e9eaee;font-family:-apple-system,sans-serif;'
           . 'padding:40px 22px;line-height:1.6">'
           . '<h1 style="font-size:18px;color:#f2685e;margin:0 0 8px">Not your page</h1>'
           . '<p style="color:#b9bcc6;font-size:14px;margin:0 0 18px">Settings are for the '
           . 'superadmin. Your account is read only.</p>'
           . '<a href="index.php" style="color:#d8a94b">Back to customers</a></body>';
        exit;
    }
    return $u;
}

function password_problem(string $p): ?string {
    if (strlen($p) < 10) return 'Use at least 10 characters.';
    if (preg_match('/^[a-zA-Z]+$/', $p)) return 'Add a number or a symbol.';
    return null;
}
