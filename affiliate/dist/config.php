<?php
/* ═══════════════════════════════════════════════════════════════════════════
   config.php — Models Boutique.

   Its own database and its own account, reaching nothing else on this server.
   Luxe Talent is a separate business on the same machine and the two share
   nothing but the hardware.

   Sits at the root of both admin.modelsboutique.com and
   affiliate.modelsboutique.com, identical on each.
   ═══════════════════════════════════════════════════════════════════════════ */

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'models_boutique');
define('DB_USER', 'mb_app');
define('DB_PASS', 'Mb7#kRq2vLx9Twn4');

define('APP_NAME',    'Models Boutique');
define('ADMIN_URL',   'https://admin.modelsboutique.com');
define('AFFILIATE_URL', 'https://affiliate.modelsboutique.com');

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

if (!function_exists('json_response')) {
    function json_response($d, $code = 200) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($d);
        exit;
    }
}

/* ── who is signed in ─────────────────────────────────────────────────────
   Two kinds of person use this system and they are kept apart deliberately.
   An admin runs the business; an affiliate sees her own sales and nothing
   else. Sharing one session would mean one mistake in one query exposing
   everybody's figures to everybody. */

function mb_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function mb_admin(): ?array {
    mb_session();
    return $_SESSION['mb_admin'] ?? null;
}

function mb_affiliate(): ?array {
    mb_session();
    return $_SESSION['mb_affiliate'] ?? null;
}

function mb_require_admin(): array {
    $a = mb_admin();
    if (!$a) {
        header('Location: /login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
        exit;
    }
    return $a;
}

function mb_require_affiliate(): array {
    $a = mb_affiliate();
    if (!$a) {
        header('Location: /login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
        exit;
    }
    return $a;
}
