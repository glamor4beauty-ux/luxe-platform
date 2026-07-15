<?php
/**
 * PDO database connection.
 * Returns a singleton PDO instance.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $config = require __DIR__ . '/config.php';
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
        try {
            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Don't expose the actual error to the user
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            die('Database connection failed. Check your credentials in includes/config.php.');
        }
    }
    return $pdo;
}

/**
 * Check if any user exists. If not, we need to run setup.
 */
function needs_first_run_setup(): bool
{
    try {
        $stmt = db()->query('SELECT COUNT(*) FROM fc_users');
        return (int) $stmt->fetchColumn() === 0;
    } catch (PDOException $e) {
        // Table might not exist yet — that also means setup is needed
        return true;
    }
}
