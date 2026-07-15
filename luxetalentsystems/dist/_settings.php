<?php
/* Credential accessor — require this from any endpoint that needs a secret.
   Usage:  require __DIR__.'/_settings.php';  $sid = app_setting('twilio_account_sid');
   Reads from the DB `settings` table (managed by the Settings tab), so nothing
   has to be hardcoded in source. This file is require-only and is intentionally
   NOT in the nginx allow-list, so it can never be fetched over the web. */

if (!function_exists('app_setting')) {
    function app_setting(string $key, string $default = ''): string {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            try {
                foreach (db()->query("SELECT setting_key, setting_value FROM settings")->fetchAll() as $r) {
                    $cache[$r['setting_key']] = (string)$r['setting_value'];
                }
            } catch (Throwable $e) {
                $cache = [];
            }
        }
        return ($cache[$key] ?? '') !== '' ? $cache[$key] : $default;
    }
}

/* Per-admin settings, scoped by the admin's softphone identity (the sanitized
   email used in the Twilio Access Token). Each admin user gets their own
   softphone configuration. */
if (!function_exists('ensure_user_settings_table')) {
    function ensure_user_settings_table(): void {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS user_settings (
                identity      VARCHAR(121) NOT NULL,
                setting_key   VARCHAR(100) NOT NULL,
                setting_value TEXT,
                is_secret     TINYINT(1) NOT NULL DEFAULT 0,
                updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (identity, setting_key)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('identity_for_email')) {
    function identity_for_email(string $email): string {
        // Twilio Access Token identity: alphanumeric + underscore only, max 121 chars.
        $id = preg_replace('/[^A-Za-z0-9_]/', '_', $email);
        return substr($id, 0, 121);
    }
}

if (!function_exists('user_setting')) {
    function user_setting(string $identity, string $key, string $default = ''): string {
        try {
            ensure_user_settings_table();
            $st = db()->prepare("SELECT setting_value FROM user_settings WHERE identity = ? AND setting_key = ?");
            $st->execute([$identity, $key]);
            $v = $st->fetchColumn();
            return ($v !== false && $v !== null && $v !== '') ? (string)$v : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
}
