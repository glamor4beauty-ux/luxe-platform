<?php
/* ═══════════════════════════════════════════════════════════════════════════
   IVR — /api/ivr.php
   Config-driven Twilio phone tree. The whole menu lives in the DB `settings`
   row `ivr_config` (one JSON blob), so the tree is edited from data, not code.

   Twilio calls these endpoints server-to-server (no admin session). They are
   guarded by Twilio request-signature validation when an Auth Token is present;
   otherwise they run open (Twilio still only reaches them via your number).

   Actions (?action=…):
     (none)            Voice webhook entry → greeting + main menu  <Gather>
     handle            Caller pressed a key → route to the matching option
     hold              Hold-music TwiML for queues  (<Play>/<Say> loop)
     vm_complete       Twilio posts the finished recording → store + email
     vm_transcription  Twilio posts the transcription text → update + email

   Point your Twilio number's Voice webhook (A CALL COMES IN) at:
     https://luxetalentsystems.com/api/ivr.php   (HTTP POST)

   Config shape (settings.ivr_config):
   {
     "voice":"Polly.Joanna", "language":"en-US", "timeout":5,
     "greeting":{ "tts":"Thank you for calling…", "audio_url":"" },
     "invalid_tts":"Sorry, I didn't get that.",
     "voicemail_email":"support@luxemodelcollective.com",
     "menus":{
       "main":{
         "greeting":{ "tts":"", "audio_url":"" },     // optional per-menu override
         "options":[
           {"digit":"1","action":"dial","number":"+1…","label":"Sales"},
           {"digit":"2","action":"voicemail","prompt_tts":"Leave a message after the tone.","transcribe":true,"label":"Support"},
           {"digit":"3","action":"queue","queue":"billing","hold_music_url":"","label":"Billing"},
           {"digit":"4","action":"submenu","menu":"after_hours","label":"More"},
           {"digit":"0","action":"repeat","label":"Repeat"},
           {"digit":"9","action":"hangup","tts":"Goodbye.","label":"Hang up"}
         ]
       },
       "after_hours":{ "options":[ … ] }
     }
   }
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/../config.php';
require __DIR__ . '/_settings.php';

/* ── TwiML output helper ──────────────────────────────────────────────────── */
function twiml(string $xml): void {
    header('Content-Type: text/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<Response>' . $xml . '</Response>';
    exit;
}
function x(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

/* ── Optional: validate the request really came from Twilio ───────────────── */
function ivr_validate_twilio(): void {
    $token = app_setting('twilio_auth_token');
    if ($token === '') return;                       // not configured → skip (Twilio-only reachable anyway)
    $sig = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';
    if ($sig === '') return;                          // be lenient; don't hard-fail callbacks
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
    $data = $url;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $post = $_POST; ksort($post);
        foreach ($post as $k => $v) $data .= $k . $v;
    }
    $expected = base64_encode(hash_hmac('sha1', $data, $token, true));
    if (!hash_equals($expected, $sig)) {
        // Signature present but wrong → reject.
        http_response_code(403);
        twiml('<Say>Request could not be verified.</Say><Hangup/>');
    }
}

/* ── Storage bootstrap (CREATE grant is in place for the app user) ─────────── */
db()->exec(
    "CREATE TABLE IF NOT EXISTS ivr_voicemails (
        id            BIGINT AUTO_INCREMENT PRIMARY KEY,
        call_sid      VARCHAR(64),
        from_number   VARCHAR(40),
        to_number     VARCHAR(40),
        menu          VARCHAR(64),
        recording_sid VARCHAR(64),
        recording_url VARCHAR(255),
        duration      INT,
        transcription TEXT,
        is_read       TINYINT(1) NOT NULL DEFAULT 0,
        created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_created (created_at)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

/* ── Load IVR config ──────────────────────────────────────────────────────── */
function ivr_config(): array {
    $raw = app_setting('ivr_config');
    $cfg = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($cfg)) $cfg = [];
    // Defaults
    $cfg += [
        'voice' => 'Polly.Joanna',
        'language' => 'en-US',
        'timeout' => 5,
        'greeting' => ['tts' => 'Thank you for calling. Please listen to the following options.', 'audio_url' => ''],
        'invalid_tts' => "Sorry, I didn't get that. Let's try again.",
        'voicemail_email' => 'support@luxemodelcollective.com',
        'menus' => [],
    ];
    if (!isset($cfg['menus']['main'])) {
        $cfg['menus']['main'] = ['options' => []];
    }
    return $cfg;
}

$cfg     = ivr_config();
$voice   = (string)$cfg['voice'];
$lang    = (string)$cfg['language'];
$timeout = max(2, min(30, (int)$cfg['timeout']));
$base    = '/api/ivr.php';

/* Emit a prompt: <Play> the audio URL if present, else <Say> the TTS text. */
function prompt_xml(array $p, string $voice, string $lang): string {
    $audio = trim((string)($p['audio_url'] ?? ''));
    if ($audio !== '') return '<Play>' . x($audio) . '</Play>';
    $tts = (string)($p['tts'] ?? ($p['prompt_tts'] ?? ''));
    if ($tts === '') return '';
    return '<Say voice="' . x($voice) . '" language="' . x($lang) . '">' . x($tts) . '</Say>';
}

/* Build the <Dial> body for an extension/option target.
   Supports: phone number, browser softphone (Client), SIP device, or ring-all. */
function dial_target_xml(array $t, string $voice, string $lang): string {
    $sipDomain = app_setting('sip_domain_name');          // e.g. clarencemcsween.sip.twilio.com
    $callerId  = app_setting('twilio_from_number');
    $cid = $callerId !== '' ? ' callerId="' . x($callerId) . '"' : '';

    $type   = (string)($t['target'] ?? $t['action'] ?? '');
    $inner  = '';

    // Collect the dial children based on target type.
    $addNumber = function (string $n) use (&$inner) { if (trim($n) !== '') $inner .= '<Number>' . x(trim($n)) . '</Number>'; };
    $addClient = function (string $c) use (&$inner) { if (trim($c) !== '') $inner .= '<Client>' . x(trim($c)) . '</Client>'; };
    $addSip    = function (string $u) use (&$inner, $sipDomain) {
        $u = trim($u);
        if ($u === '') return;
        // Accept a bare username (→ user@domain) or a full sip: URI.
        if (stripos($u, 'sip:') === 0)            $inner .= '<Sip>' . x($u) . '</Sip>';
        elseif (strpos($u, '@') !== false)        $inner .= '<Sip>' . x('sip:' . $u) . '</Sip>';
        elseif ($sipDomain !== '')                $inner .= '<Sip>' . x('sip:' . $u . '@' . $sipDomain) . '</Sip>';
    };

    if ($type === 'phone' || $type === 'dial') {
        $addNumber((string)($t['number'] ?? ''));
    } elseif ($type === 'client') {
        $addClient((string)($t['client'] ?? $t['identity'] ?? ''));
    } elseif ($type === 'sip') {
        $addSip((string)($t['sip'] ?? $t['sip_user'] ?? ''));
    } elseif ($type === 'ringall' || $type === 'ring_all') {
        // Ring every device this person has, simultaneously.
        $addClient((string)($t['client'] ?? $t['identity'] ?? ''));
        $addSip((string)($t['sip'] ?? $t['sip_user'] ?? ''));
        $addNumber((string)($t['number'] ?? ''));
    }
    if ($inner === '') return '';
    return '<Dial' . $cid . ' timeout="25" answerOnBridge="true">' . $inner . '</Dial>';
}

/* Look up an extension number in the config's extensions map. Returns the
   extension's target array, or null. */
function find_extension(array $cfg, string $digits): ?array {
    $exts = $cfg['extensions'] ?? [];
    foreach ($exts as $e) {
        if ((string)($e['ext'] ?? '') === $digits) return $e;
    }
    return null;
}

/* Render a menu: its greeting + a <Gather> that collects the digits. */
function render_menu(string $menuId, array $cfg, string $voice, string $lang, int $timeout, string $base): string {
    $menu = $cfg['menus'][$menuId] ?? ['options' => []];
    // Per-menu greeting overrides the global greeting; fall back to global for "main".
    $greet = $menu['greeting'] ?? ($menuId === 'main' ? $cfg['greeting'] : ['tts' => '', 'audio_url' => '']);
    $g = prompt_xml($greet, $voice, $lang);

    // On the main menu, allow inline extension dialing: gather up to 4 digits so a
    // caller can type an extension (e.g. 101) OR a single menu digit. finishOnKey
    // "#" lets them end early; the short timeout disambiguates a lone digit.
    $extEnabled = ($menuId === 'main') && !empty($cfg['extensions']);
    $gatherAttrs = $extEnabled
        ? 'numDigits="4" finishOnKey="#" timeout="' . max(3, $timeout) . '"'
        : 'numDigits="1" timeout="' . $timeout . '"';

    $gather = '<Gather ' . $gatherAttrs . ' action="' . x($base . '?action=handle&menu=' . $menuId) . '" method="POST">'
            . $g
            . '</Gather>';
    // If the caller presses nothing, repeat the menu once.
    $repeat = '<Redirect method="POST">' . x($base . '?menu=' . $menuId) . '</Redirect>';
    return $gather . $repeat;
}

/* ── Routing ──────────────────────────────────────────────────────────────── */
$action = $_GET['action'] ?? '';

try {
    ivr_validate_twilio();

    /* ---- voicemail recording finished → store + email ---- */
    if ($action === 'vm_complete') {
        $callSid = $_POST['CallSid'] ?? '';
        $from    = $_POST['From'] ?? '';
        $to      = $_POST['To'] ?? '';
        $menu    = $_GET['menu'] ?? '';
        $recSid  = $_POST['RecordingSid'] ?? '';
        $recUrl  = $_POST['RecordingUrl'] ?? '';
        $dur     = (int)($_POST['RecordingDuration'] ?? 0);

        $st = db()->prepare(
            "INSERT INTO ivr_voicemails (call_sid, from_number, to_number, menu, recording_sid, recording_url, duration)
             VALUES (?,?,?,?,?,?,?)"
        );
        $st->execute([$callSid, $from, $to, $menu, $recSid, $recUrl ? $recUrl . '.mp3' : '', $dur]);
        $vmId = (int)db()->lastInsertId();

        ivr_send_voicemail_email($cfg['voicemail_email'], $from, ($recUrl ? $recUrl . '.mp3' : ''), $dur, '', $vmId);

        // Thank the caller and hang up.
        twiml('<Say voice="' . x($voice) . '" language="' . x($lang) . '">Thank you. Your message has been received. Goodbye.</Say><Hangup/>');
    }

    /* ---- transcription ready → update row + send follow-up ---- */
    if ($action === 'vm_transcription') {
        $recSid = $_POST['RecordingSid'] ?? '';
        $text   = $_POST['TranscriptionText'] ?? '';
        if ($recSid !== '') {
            db()->prepare("UPDATE ivr_voicemails SET transcription = ? WHERE recording_sid = ?")->execute([$text, $recSid]);
            // Look up the row to email the transcription.
            $row = db()->prepare("SELECT id, from_number, recording_url, duration FROM ivr_voicemails WHERE recording_sid = ? LIMIT 1");
            $row->execute([$recSid]);
            $r = $row->fetch();
            if ($r) ivr_send_voicemail_email($cfg['voicemail_email'], $r['from_number'], $r['recording_url'], (int)$r['duration'], $text, (int)$r['id']);
        }
        header('Content-Type: text/plain'); echo 'OK'; exit;
    }

    /* ---- hold music for queues ---- */
    if ($action === 'hold') {
        $music = trim((string)($_GET['music'] ?? ''));
        if ($music !== '') {
            twiml('<Play loop="0">' . x($music) . '</Play>');
        }
        // Default: a short message looped while waiting.
        twiml('<Say voice="' . x($voice) . '" language="' . x($lang) . '">Please hold. Your call is important to us.</Say>'
            . '<Play loop="0">https://demo.twilio.com/docs/classic.mp3</Play>');
    }

    /* ---- caller pressed a key → route ---- */
    if ($action === 'handle') {
        $menuId = $_GET['menu'] ?? 'main';
        $digits = (string)($_POST['Digits'] ?? '');
        $menu   = $cfg['menus'][$menuId] ?? ['options' => []];
        $opts   = $menu['options'] ?? [];

        // 1) Full-extension match first (e.g. "101"). Works on the main menu where
        //    inline extension dialing is enabled, and on the extension prompt.
        if (strlen($digits) >= 2) {
            $ext = find_extension($cfg, $digits);
            if ($ext !== null) {
                $pre = prompt_xml(['tts' => $ext['tts'] ?? ('Connecting you to extension ' . $digits . '.')], $voice, $lang);
                $dial = dial_target_xml($ext, $voice, $lang);
                if ($dial === '') {
                    twiml('<Say voice="' . x($voice) . '" language="' . x($lang) . '">That extension is not configured.</Say><Redirect method="POST">' . x($base . '?menu=main') . '</Redirect>');
                }
                twiml($pre . $dial
                    . '<Say voice="' . x($voice) . '" language="' . x($lang) . '">The person you called is not available.</Say>'
                    . '<Redirect method="POST">' . x($base . '?menu=main') . '</Redirect>');
            }
            // 2+ digits but no extension match → fall through to invalid.
        }

        // 2) Single-digit menu option.
        $digit  = substr($digits, 0, 1);
        $chosen = null;
        foreach ($opts as $o) { if ((string)($o['digit'] ?? '') === $digit) { $chosen = $o; break; } }

        if ($chosen === null) {
            twiml('<Say voice="' . x($voice) . '" language="' . x($lang) . '">' . x($cfg['invalid_tts']) . '</Say>'
                . '<Redirect method="POST">' . x($base . '?menu=' . $menuId) . '</Redirect>');
        }

        $act = (string)($chosen['action'] ?? '');

        if ($act === 'dial') {
            $num = trim((string)($chosen['number'] ?? ''));
            if ($num === '') twiml('<Say voice="' . x($voice) . '" language="' . x($lang) . '">This option is not configured.</Say><Redirect method="POST">' . x($base . '?menu=' . $menuId) . '</Redirect>');
            $pre = prompt_xml(['tts' => $chosen['tts'] ?? ('Connecting you to ' . ($chosen['label'] ?? 'the line') . '.')], $voice, $lang);
            twiml($pre . dial_target_xml(['target' => 'phone', 'number' => $num], $voice, $lang)
                . '<Say voice="' . x($voice) . '" language="' . x($lang) . '">The call could not be completed.</Say><Hangup/>');
        }

        if ($act === 'client' || $act === 'sip' || $act === 'ringall') {
            $pre = prompt_xml(['tts' => $chosen['tts'] ?? ('Connecting you to ' . ($chosen['label'] ?? 'the line') . '.')], $voice, $lang);
            $tgt = $chosen; $tgt['target'] = $act;
            $dial = dial_target_xml($tgt, $voice, $lang);
            if ($dial === '') twiml('<Say voice="' . x($voice) . '" language="' . x($lang) . '">This option is not configured.</Say><Redirect method="POST">' . x($base . '?menu=' . $menuId) . '</Redirect>');
            twiml($pre . $dial
                . '<Say voice="' . x($voice) . '" language="' . x($lang) . '">The person you called is not available.</Say><Hangup/>');
        }

        if ($act === 'extension_prompt') {
            // "Press to dial an extension" → gather the extension digits, then handle.
            $p = prompt_xml(['tts' => $chosen['tts'] ?? 'Please enter the extension, followed by the pound key.'], $voice, $lang);
            twiml('<Gather numDigits="4" finishOnKey="#" timeout="6" action="' . x($base . '?action=handle&menu=main') . '" method="POST">'
                . $p . '</Gather>'
                . '<Redirect method="POST">' . x($base . '?menu=main') . '</Redirect>');
        }

        if ($act === 'voicemail') {
            $prompt = prompt_xml($chosen, $voice, $lang);
            if ($prompt === '') $prompt = '<Say voice="' . x($voice) . '" language="' . x($lang) . '">Please leave your message after the tone. Press pound when finished.</Say>';
            $transcribe = !empty($chosen['transcribe']);
            $tAttr = $transcribe
                ? ' transcribe="true" transcribeCallback="' . x($base . '?action=vm_transcription') . '"'
                : '';
            twiml($prompt
                . '<Record maxLength="180" playBeep="true" finishOnKey="#"'
                . ' action="' . x($base . '?action=vm_complete&menu=' . $menuId) . '"'
                . $tAttr . ' />'
                . '<Say voice="' . x($voice) . '" language="' . x($lang) . '">No message recorded. Goodbye.</Say><Hangup/>');
        }

        if ($act === 'queue') {
            $q = trim((string)($chosen['queue'] ?? 'support'));
            $music = trim((string)($chosen['hold_music_url'] ?? ''));
            $waitUrl = $base . '?action=hold' . ($music !== '' ? '&music=' . rawurlencode($music) : '');
            $pre = prompt_xml(['tts' => $chosen['tts'] ?? 'Please hold while we connect you.'], $voice, $lang);
            twiml($pre . '<Enqueue waitUrl="' . x($waitUrl) . '" waitUrlMethod="POST">' . x($q) . '</Enqueue>');
        }

        if ($act === 'submenu') {
            $sub = (string)($chosen['menu'] ?? '');
            if ($sub === '' || !isset($cfg['menus'][$sub])) {
                twiml('<Say voice="' . x($voice) . '" language="' . x($lang) . '">That submenu is not configured.</Say><Redirect method="POST">' . x($base . '?menu=' . $menuId) . '</Redirect>');
            }
            twiml(render_menu($sub, $cfg, $voice, $lang, $timeout, $base));
        }

        if ($act === 'repeat') {
            twiml('<Redirect method="POST">' . x($base . '?menu=' . $menuId) . '</Redirect>');
        }

        if ($act === 'hangup') {
            $bye = prompt_xml(['tts' => $chosen['tts'] ?? 'Goodbye.'], $voice, $lang);
            twiml($bye . '<Hangup/>');
        }

        // Unknown action → replay
        twiml('<Redirect method="POST">' . x($base . '?menu=' . $menuId) . '</Redirect>');
    }

    /* ---- default: entry point (Voice webhook) → render a menu ---- */
    $menuId = $_GET['menu'] ?? 'main';
    if (!isset($cfg['menus'][$menuId])) $menuId = 'main';
    twiml(render_menu($menuId, $cfg, $voice, $lang, $timeout, $base));

} catch (Throwable $e) {
    error_log('[ivr] ' . $e->getMessage());
    twiml('<Say>We are sorry, an error occurred. Please call again later.</Say><Hangup/>');
}

/* ── Voicemail email via the talentmail SMTP server (same path as register.php).
     Reads SMTP creds from the Settings store, falling back to known values so it
     works immediately; add smtp_user / smtp_password in Settings to override. ── */
function ivr_send_voicemail_email(string $to, string $from, string $recUrl, int $dur, string $transcription, int $vmId): bool {
    if (trim($to) === '') return false;

    $subject = 'New Voicemail' . ($from ? ' from ' . $from : '');
    $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222">'
          . '<h2 style="color:#b8860b;margin:0 0 12px">New Voicemail</h2>'
          . '<p><b>From:</b> ' . htmlspecialchars($from ?: 'Unknown') . '<br>'
          . '<b>Duration:</b> ' . (int)$dur . ' sec<br>'
          . '<b>Voicemail #:</b> ' . (int)$vmId . '</p>'
          . ($transcription !== '' ? '<p><b>Transcription:</b><br>' . nl2br(htmlspecialchars($transcription)) . '</p>' : '<p><i>Transcription pending…</i></p>')
          . ($recUrl !== '' ? '<p><a href="' . htmlspecialchars($recUrl) . '">Listen to recording</a></p>' : '')
          . '<p><a href="https://luxetalentsystems.com/dashboard.html">Open Dashboard</a></p></div>';

    $host = 'talentmail.luxetalentsystems.com'; $port = 587;
    $user = app_setting('smtp_user');     if ($user === '') $user = 'support@luxetalentsystems.com';
    $pass = app_setting('smtp_password'); if ($pass === '') $pass = 'Sonia@7700';   // TODO: move fully to Settings + rotate
    $fromEmail = $user; $fromName = 'Luxe Talent IVR';

    $fp = @stream_socket_client("tcp://$host:$port", $en, $es, 15);
    if (!$fp) { error_log("[ivr-mail] connect fail $es"); return false; }
    $read = function () use ($fp) { $d = ''; while ($line = fgets($fp, 515)) { $d .= $line; if (substr($line, 3, 1) == ' ') break; } return $d; };
    $cmd  = function ($c) use ($fp, $read) { if ($c !== null) fputs($fp, $c . "\r\n"); return $read(); };
    $cmd(null);
    $cmd("EHLO luxetalentsystems.com");
    $cmd("STARTTLS");
    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) { error_log('[ivr-mail] tls fail'); fclose($fp); return false; }
    $cmd("EHLO luxetalentsystems.com");
    $cmd("AUTH LOGIN");
    $cmd(base64_encode($user));
    $r = $cmd(base64_encode($pass));
    if (strpos($r, '235') === false) { error_log('[ivr-mail] auth fail: ' . trim($r)); fclose($fp); return false; }
    $cmd("MAIL FROM:<$fromEmail>");
    $cmd("RCPT TO:<$to>");
    $cmd("DATA");
    $headers  = "From: $fromName <$fromEmail>\r\n";
    $headers .= "To: $to\r\n";
    $headers .= "Subject: $subject\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Date: " . date('r') . "\r\n";
    $body = str_replace("\r\n.\r\n", "\r\n..\r\n", $html);
    $cmd($headers . "\r\n" . $body . "\r\n.");
    $cmd("QUIT");
    fclose($fp);
    return true;
}
