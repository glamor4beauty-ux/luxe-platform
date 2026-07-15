<?php
/* ═══════════════════════════════════════════════════════════════════════════
   Per-admin softphone settings — /api/user-settings.php
   Each admin user has their OWN softphone configuration (caller ID, edge,
   auto-register, etc.), scoped by their Twilio Access Token identity.

     GET  ?action=load                 → { ok, identity, settings:{ key:{value|set,secret} } }
     POST ?action=save  {settings:[…]} → { ok }
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/../cors.php';
require __DIR__ . '/../config.php';
require __DIR__ . '/_settings.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

$email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';
if ($email === '') json_response(['ok' => false, 'error' => 'Not signed in'], 401);

$identity = identity_for_email($email);
ensure_user_settings_table();

$action = $_GET['action'] ?? 'load';

try {
    if ($action === 'load') {
        $st = db()->prepare("SELECT setting_key, setting_value, is_secret FROM user_settings WHERE identity = ?");
        $st->execute([$identity]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            if ((int)$r['is_secret'] === 1) {
                $out[$r['setting_key']] = ['secret' => true, 'set' => ($r['setting_value'] !== null && $r['setting_value'] !== '')];
            } else {
                $out[$r['setting_key']] = ['secret' => false, 'value' => (string)$r['setting_value']];
            }
        }
        json_response(['ok' => true, 'identity' => $identity, 'settings' => $out]);
    }

    if ($action === 'save') {
        $in = json_decode(file_get_contents('php://input'), true);
        if (!is_array($in) || !isset($in['settings']) || !is_array($in['settings'])) {
            json_response(['ok' => false, 'error' => 'No settings supplied'], 400);
        }
        $upsert = db()->prepare(
            "INSERT INTO user_settings (identity, setting_key, setting_value, is_secret)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_secret = VALUES(is_secret)"
        );
        $saved = 0;
        foreach ($in['settings'] as $item) {
            $key = trim((string)($item['key'] ?? ''));
            if ($key === '') continue;
            $secret = !empty($item['secret']) ? 1 : 0;
            $val = (string)($item['value'] ?? '');
            if ($secret === 1 && $val === '') continue;   // keep existing secret
            $upsert->execute([$identity, $key, $val, $secret]);
            $saved++;
        }
        json_response(['ok' => true, 'saved' => $saved]);
    }

    json_response(['ok' => false, 'error' => 'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('[user-settings] ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Server error'], 500);
}
