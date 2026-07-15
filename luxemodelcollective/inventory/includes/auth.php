<?php
/**
 * Authentication and session management.
 */

require_once __DIR__ . '/db.php';

const LOCKOUT_THRESHOLD = 5;        // failed attempts before lockout
const LOCKOUT_MINUTES   = 15;       // how long the lock lasts
const SESSION_NAME      = 'fcdash'; // cookie name

function start_session_secure(): void
{
    if (session_status() !== PHP_SESSION_NONE) return;
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    start_session_secure();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool
{
    start_session_secure();
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token ?? '');
}

function require_csrf(): void
{
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('CSRF token validation failed. Refresh the page and try again.');
    }
}

function login_user(int $user_id, string $username, string $role): void
{
    start_session_secure();
    session_regenerate_id(true);
    $_SESSION['user_id']  = $user_id;
    $_SESSION['username'] = $username;
    $_SESSION['role']     = $role;
    $_SESSION['login_at'] = time();

    db()->prepare('UPDATE fc_users SET last_login = NOW(), failed_attempts = 0, locked_until = NULL WHERE id = ?')
        ->execute([$user_id]);
}

function logout_user(): void
{
    start_session_secure();
    $_SESSION = [];
    session_destroy();
}

function current_user(): ?array
{
    start_session_secure();
    if (empty($_SESSION['user_id'])) return null;
    return [
        'id'       => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'role'     => $_SESSION['role'],
    ];
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

function require_super_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'super_admin') {
        http_response_code(403);
        die('Access denied. Super Admin role required.');
    }
    return $user;
}

/**
 * Attempt to log in with username and password.
 * Returns user array on success, error string on failure.
 */
function attempt_login(string $username, string $password)
{
    $stmt = db()->prepare('SELECT * FROM fc_users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        // Constant-time dummy hash to prevent username enumeration timing attacks
        password_verify($password, '$2y$12$' . str_repeat('a', 53));
        return 'Invalid username or password.';
    }

    if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        $minutes = ceil((strtotime($user['locked_until']) - time()) / 60);
        return "Account locked due to failed login attempts. Try again in {$minutes} minute(s).";
    }

    if (!password_verify($password, $user['password_hash'])) {
        $attempts = $user['failed_attempts'] + 1;
        if ($attempts >= LOCKOUT_THRESHOLD) {
            $lock_until = date('Y-m-d H:i:s', time() + LOCKOUT_MINUTES * 60);
            db()->prepare('UPDATE fc_users SET failed_attempts = ?, locked_until = ? WHERE id = ?')
                ->execute([$attempts, $lock_until, $user['id']]);
            return "Too many failed attempts. Account locked for " . LOCKOUT_MINUTES . " minutes.";
        }
        db()->prepare('UPDATE fc_users SET failed_attempts = ? WHERE id = ?')
            ->execute([$attempts, $user['id']]);
        return 'Invalid username or password.';
    }

    login_user((int) $user['id'], $user['username'], $user['role']);
    return [
        'id'       => (int) $user['id'],
        'username' => $user['username'],
        'role'     => $user['role'],
    ];
}

function esc(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
