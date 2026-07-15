<?php
/* ═══════════════════════════════════════════════════════════════════════════
   Settings API — /api/settings.php
   DB-backed key/value store for ALL credentials, passwords and API keys.
   Nothing is hardcoded in source; everything lives in the `settings` table and
   is edited from the dashboard Settings tab.

   Secrets are WRITE-ONLY from the UI: load() never returns a secret's value,
   only whether it is set. save() updates a secret only when a new value is
   supplied (a blank field keeps the existing value).

     GET  ?action=load                 → { ok, settings:{ key:{value|set, secret} } }
     POST ?action=save  {settings:[…]} → { ok }
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/../cors.php';
require __DIR__ . '/../config.php';

session_start();                       // after cors.php so the OPTIONS preflight can't clobber it
header('Content-Type: application/json; charset=utf-8');

/* Require a signed-in admin (login.php sets these). */
$email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';
if ($email === '') json_response(['ok' => false, 'error' => 'Not signed in'], 401);

/* Make sure the store exists. */
db()->exec(
    "CREATE TABLE IF NOT EXISTS settings (
        setting_key   VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        is_secret     TINYINT(1) NOT NULL DEFAULT 0,
        updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$action = $_GET['action'] ?? 'load';

try {
    if ($action === 'load') {
        $rows = db()->query("SELECT setting_key, setting_value, is_secret FROM settings")->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            if ((int)$r['is_secret'] === 1) {
                $out[$r['setting_key']] = [
                    'secret' => true,
                    'set'    => ($r['setting_value'] !== null && $r['setting_value'] !== ''),
                ];
            } else {
                $out[$r['setting_key']] = ['secret' => false, 'value' => (string)$r['setting_value']];
            }
        }
        json_response(['ok' => true, 'settings' => $out]);
    }

    if ($action === 'save') {
        $in = json_decode(file_get_contents('php://input'), true);
        if (!is_array($in) || !isset($in['settings']) || !is_array($in['settings'])) {
            json_response(['ok' => false, 'error' => 'No settings supplied'], 400);
        }

        $upsert = db()->prepare(
            "INSERT INTO settings (setting_key, setting_value, is_secret)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_secret = VALUES(is_secret)"
        );
        $delete = db()->prepare("DELETE FROM settings WHERE setting_key = ?");

        $saved = 0;
        foreach ($in['settings'] as $item) {
            $key = trim((string)($item['key'] ?? ''));
            if ($key === '') continue;

            // explicit removal
            if (!empty($item['delete'])) { $delete->execute([$key]); continue; }

            $secret = !empty($item['secret']) ? 1 : 0;
            $val    = (string)($item['value'] ?? '');

            // Secret left blank → keep whatever is already stored, just (re)mark secret flag.
            if ($secret === 1 && $val === '') {
                db()->prepare("UPDATE settings SET is_secret = 1 WHERE setting_key = ?")->execute([$key]);
                continue;
            }
            $upsert->execute([$key, $val, $secret]);
            $saved++;
        }
        json_response(['ok' => true, 'saved' => $saved]);
    }

    json_response(['ok' => false, 'error' => 'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('[settings] ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Server error'], 500);
}
