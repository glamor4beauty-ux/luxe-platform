<?php
/* ═══════════════════════════════════════════════════════════════════════════
   IVR config — /api/ivr-config.php
   Admin-gated read/write for the `ivr_config` settings row that drives
   api/ivr.php. Used by the Settings → IVR — Phone Menu card.

     GET  ?action=load            → { ok, config:{…} }
     POST ?action=save  {config}  → { ok }

   Stored as one JSON value under settings key 'ivr_config', same store as the
   rest of Settings (settings.php / _settings.php).
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/../cors.php';
require __DIR__ . '/../config.php';
require __DIR__ . '/_settings.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

/* Same admin gate as settings.php (login.php sets user_email). */
$email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';
if ($email === '') json_response(['ok' => false, 'error' => 'Not signed in'], 401);

/* Make sure the settings store exists (matches settings.php schema). */
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
        $st = db()->prepare("SELECT setting_value FROM settings WHERE setting_key = 'ivr_config' LIMIT 1");
        $st->execute();
        $raw = $st->fetchColumn();
        $cfg = ($raw !== false && $raw !== null && $raw !== '') ? json_decode($raw, true) : null;
        if (!is_array($cfg)) {
            // Sensible empty default so the card renders.
            $cfg = [
                'voice' => 'Polly.Joanna',
                'language' => 'en-US',
                'timeout' => 5,
                'greeting' => ['tts' => '', 'audio_url' => ''],
                'invalid_tts' => "Sorry, I didn't get that. Let's try again.",
                'voicemail_email' => 'support@luxemodelcollective.com',
                'menus' => ['main' => ['options' => []]],
            ];
        }
        json_response(['ok' => true, 'config' => $cfg]);
    }

    if ($action === 'save') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $cfg = $in['config'] ?? null;
        if (!is_array($cfg)) json_response(['ok' => false, 'error' => 'Missing config'], 400);

        // Normalize / guard a few fields.
        $cfg['voice']    = (string)($cfg['voice'] ?? 'Polly.Joanna');
        $cfg['language'] = (string)($cfg['language'] ?? 'en-US');
        $cfg['timeout']  = max(2, min(30, (int)($cfg['timeout'] ?? 5)));
        if (!isset($cfg['greeting']) || !is_array($cfg['greeting'])) $cfg['greeting'] = ['tts' => '', 'audio_url' => ''];
        if (!isset($cfg['menus']['main']['options']) || !is_array($cfg['menus']['main']['options'])) {
            $cfg['menus'] = ['main' => ['options' => []]];
        }
        // Normalize extensions (ext number → target).
        if (!isset($cfg['extensions']) || !is_array($cfg['extensions'])) $cfg['extensions'] = [];
        $extTargets = ['client', 'sip', 'phone', 'ringall'];
        $cleanExt = [];
        foreach ($cfg['extensions'] as $e) {
            if (!is_array($e)) continue;
            $num = trim((string)($e['ext'] ?? ''));
            $tg  = (string)($e['target'] ?? '');
            if ($num === '' || !in_array($tg, $extTargets, true)) continue;
            $cleanExt[] = $e;
        }
        $cfg['extensions'] = $cleanExt;
        // Keep only known digits/actions; drop empties.
        $valid = ['dial', 'voicemail', 'queue', 'submenu', 'repeat', 'hangup'];
        $clean = [];
        foreach ($cfg['menus']['main']['options'] as $o) {
            if (!is_array($o)) continue;
            $d = trim((string)($o['digit'] ?? ''));
            $a = (string)($o['action'] ?? '');
            if ($d === '' || !in_array($a, $valid, true)) continue;
            $clean[] = $o;
        }
        $cfg['menus']['main']['options'] = $clean;

        $json = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        db()->prepare(
            "INSERT INTO settings (setting_key, setting_value, is_secret) VALUES ('ivr_config', ?, 0)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([$json]);

        json_response(['ok' => true]);
    }

    json_response(['ok' => false, 'error' => 'Unknown action'], 400);

} catch (Throwable $e) {
    error_log('[ivr-config] ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Server error'], 500);
}
