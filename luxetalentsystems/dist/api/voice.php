<?php
require __DIR__ . '/../cors.php';
require __DIR__ . '/comm-config.php';

/* ═══════════════════════════════════════════════════════════════
   Twilio Programmable Voice — /api/voice.php
   Outbound calls, inbound voicemail, status/recording webhooks,
   history and stats. Credentials come from comm-config.php.
   ═══════════════════════════════════════════════════════════════ */

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'call':          handleCall();         break;
        case 'twiml_connect': handleTwimlConnect();  break;
        case 'callback':      handleInbound();       break;
        case 'status':        handleStatus();        break;
        case 'recording':     handleRecording();     break;
        case 'ivr':           handleIvr();           break;  // play menu (Gather)
        case 'ivr_handle':    handleIvrHandle();     break;  // branch on pressed digit
        case 'ivr_get':       handleIvrGet();        break;  // read menu config (Settings UI)
        case 'ivr_save':      handleIvrSave();       break;  // save menu config (Settings UI)
        case 'history':       handleHistory();       break;
        case 'stats':         handleStats();         break;
        // ── Browser Voice SDK (dashboard dial pad) ────────────────────
        case 'token':         handleVoiceToken();    break;  // mint Twilio JWT for the browser SDK
        case 'voice_app':     handleVoiceApp();      break;  // TwiML webhook for outbound from the browser
        default: json_response(['success' => false, 'error' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    error_log('[voice] ' . $e->getMessage());
    json_response(['success' => false, 'error' => $e->getMessage()], 400);
}

/* ── Make outbound call ───────────────────────────────────────── */
function handleCall(): void {
    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $to = comm_e164($in['to'] ?? '');
    if (!$to) throw new RuntimeException('Missing: to');

    $adminPhone = comm_e164($in['admin_phone'] ?? '');
    $host = comm_host();
    $statusCb = 'https://' . $host . '/api/voice.php?action=status';
    $twiml = 'https://' . $host . '/api/voice.php?action=twiml_connect&to=' . urlencode($to);

    if ($adminPhone) {
        $params = [
            'From' => TW_FROM, 'To' => $adminPhone, 'Url' => $twiml,
            'StatusCallback' => $statusCb,
        ];
    } else {
        $params = [
            'From' => TW_FROM, 'To' => $to, 'Url' => $twiml, 'Record' => 'true',
            'RecordingStatusCallback' => 'https://' . $host . '/api/voice.php?action=recording',
            'StatusCallback' => $statusCb,
        ];
    }

    [$code, $data] = tw_post(TW_API . '/Calls.json', $params);
    if ($code >= 400) throw new RuntimeException($data['message'] ?? 'Twilio error (HTTP ' . $code . ')');

    $pdo = db();
    $email = $in['performer_email'] ?? comm_email_by_phone($pdo, $to) ?? '';
    $st = $pdo->prepare('INSERT INTO call_log (direction,phone,performer_email,twilio_sid,status,duration,admin_phone) VALUES (?,?,?,?,?,?,?)');
    $st->execute(['outbound', $to, $email, $data['sid'] ?? '', $data['status'] ?? 'initiated', 0, $adminPhone]);

    json_response(['success' => true, 'sid' => $data['sid'] ?? '', 'status' => $data['status'] ?? '']);
}

/* ── TwiML: bridge answered call to target ── */
function handleTwimlConnect(): void {
    $to = $_GET['to'] ?? '';
    header('Content-Type: text/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Say voice="alice">Connecting you now. Please wait.</Say>';
    echo '<Dial callerId="' . TW_FROM . '" record="record-from-answer" timeout="30">';
    echo '<Number>' . htmlspecialchars($to, ENT_QUOTES) . '</Number>';
    echo '</Dial>';
    echo '<Say voice="alice">The call could not be completed. Goodbye.</Say>';
    echo '</Response>';
    exit;
}

/* ── Inbound call webhook ── */
function handleInbound(): void {
    $from = $_POST['From'] ?? '';
    $sid  = $_POST['CallSid'] ?? '';
    $status = $_POST['CallStatus'] ?? '';

    $pdo = db();
    $email = comm_email_by_phone($pdo, $from) ?? '';
    $st = $pdo->prepare('INSERT INTO call_log (direction,phone,performer_email,twilio_sid,status,duration) VALUES (?,?,?,?,?,?)');
    $st->execute(['inbound', $from, $email, $sid, $status, 0]);

    // Hand the caller to the configurable IVR menu.
    header('Content-Type: text/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response><Redirect>/api/voice.php?action=ivr</Redirect></Response>';
    exit;
}

/* ── Load IVR menu config from DB into [digit => row] ── */
function ivr_load(PDO $pdo): array {
    $rows = $pdo->query('SELECT digit,action_type,say_text,dial_number FROM ivr_menu')->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) $map[$r['digit']] = $r;
    return $map;
}

/* ── Play the IVR menu (Gather). action follows Twilio PDF pattern. ── */
function handleIvr(): void {
    $pdo = db();
    $menu = ivr_load($pdo);
    $greeting = $menu['greeting']['say_text'] ?? 'For an agent, press 1. To leave a message, press 2.';

    header('Content-Type: text/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Gather numDigits="1" action="/api/voice.php?action=ivr_handle" timeout="6">';
    echo '<Say voice="alice">' . htmlspecialchars($greeting, ENT_QUOTES) . '</Say>';
    echo '</Gather>';
    // No input -> loop back to the menu
    echo '<Redirect>/api/voice.php?action=ivr</Redirect>';
    echo '</Response>';
    exit;
}

/* ── Branch on the pressed digit (Twilio posts Digits). ── */
function handleIvrHandle(): void {
    $digit = $_POST['Digits'] ?? '';
    $pdo = db();
    $menu = ivr_load($pdo);

    header('Content-Type: text/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';

    if ($digit !== '' && isset($menu[$digit])) {
        $row = $menu[$digit];
        switch ($row['action_type']) {
            case 'dial':
                $num = comm_e164($row['dial_number'] ?? '');
                if ($num) {
                    echo '<Say voice="alice">Connecting you now.</Say>';
                    echo '<Dial callerId="' . TW_FROM . '" record="record-from-answer" timeout="30"><Number>' . htmlspecialchars($num, ENT_QUOTES) . '</Number></Dial>';
                } else {
                    echo '<Say voice="alice">That option is not available right now.</Say>';
                    echo '<Redirect>/api/voice.php?action=ivr</Redirect>';
                }
                break;
            case 'voicemail':
                $msg = $row['say_text'] ?: 'Please leave a message after the beep.';
                echo '<Say voice="alice">' . htmlspecialchars($msg, ENT_QUOTES) . '</Say>';
                echo '<Record maxLength="120" action="/api/voice.php?action=recording" transcribe="true" playBeep="true"/>';
                echo '<Say voice="alice">We did not receive your message. Goodbye.</Say>';
                break;
            default: // say
                $msg = $row['say_text'] ?: 'Thank you.';
                echo '<Say voice="alice">' . htmlspecialchars($msg, ENT_QUOTES) . '</Say>';
                echo '<Redirect>/api/voice.php?action=ivr</Redirect>';
        }
    } else {
        // Invalid / no selection -> repeat the menu (PDF pattern)
        echo '<Say voice="alice">Sorry, I did not understand that choice.</Say>';
        echo '<Redirect>/api/voice.php?action=ivr</Redirect>';
    }

    echo '</Response>';
    exit;
}

/* ── Read menu config for the Settings UI ── */
function handleIvrGet(): void {
    $pdo = db();
    json_response(['menu' => ivr_load($pdo)]);
}

/* ── Save menu config from the Settings UI ──
   Expects JSON: { rows: [ {digit, action_type, say_text, dial_number}, ... ] } */
function handleIvrSave(): void {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $rows = $in['rows'] ?? [];
    if (!is_array($rows)) throw new RuntimeException('rows must be an array');

    $pdo = db();
    $st = $pdo->prepare(
        'INSERT INTO ivr_menu (digit,action_type,say_text,dial_number) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE action_type=VALUES(action_type), say_text=VALUES(say_text), dial_number=VALUES(dial_number)'
    );
    foreach ($rows as $r) {
        $digit = (string)($r['digit'] ?? '');
        if ($digit === '') continue;
        $st->execute([
            $digit,
            $r['action_type'] ?? 'say',
            $r['say_text'] ?? '',
            preg_replace('/[^+0-9]/', '', $r['dial_number'] ?? ''),
        ]);
    }
    json_response(['success' => true]);
}

/* ── Call status callback ── */
function handleStatus(): void {
    $sid = $_POST['CallSid'] ?? '';
    $status = $_POST['CallStatus'] ?? '';
    $duration = (int)($_POST['CallDuration'] ?? 0);
    if (!$sid) { http_response_code(200); echo 'OK'; exit; }
    $pdo = db();
    $st = $pdo->prepare('UPDATE call_log SET status=?, duration=? WHERE twilio_sid=?');
    $st->execute([$status, $duration, $sid]);
    http_response_code(200); echo 'OK'; exit;
}

/* ── Recording callback ── */
function handleRecording(): void {
    $sid = $_POST['CallSid'] ?? '';
    $url = $_POST['RecordingUrl'] ?? '';
    $rsid = $_POST['RecordingSid'] ?? '';
    $duration = (int)($_POST['RecordingDuration'] ?? 0);
    if ($sid && $url) {
        $pdo = db();
        $st = $pdo->prepare('UPDATE call_log SET recording_url=?, recording_sid=?, duration=? WHERE twilio_sid=?');
        $st->execute([$url . '.mp3', $rsid, $duration, $sid]);
    }
    http_response_code(200); echo 'OK'; exit;
}

/* ── Call history ── */
function handleHistory(): void {
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $phone = preg_replace('/[^+0-9]/', '', $_GET['phone'] ?? '');
    if (function_exists('tw_get')) {
        $url = TW_API . '/Calls.json?PageSize=' . $limit;
        [$code, $data] = tw_get($url);
        if ($code === 200 && isset($data['calls']) && is_array($data['calls'])) {
            $out = [];
            foreach ($data['calls'] as $c) {
                $isIn = stripos($c['direction'] ?? '', 'inbound') !== false;
                $peer = $isIn ? ($c['from'] ?? '') : ($c['to'] ?? '');
                if ($phone !== '' && strpos(preg_replace('/[^+0-9]/','',$peer), $phone) === false) continue;
                $out[] = [
                    'direction'  => $isIn ? 'inbound' : 'outbound',
                    'phone'      => $peer,
                    'twilio_sid' => $c['sid'] ?? '',
                    'status'     => $c['status'] ?? '',
                    'duration'   => (int)($c['duration'] ?? 0),
                    'created_at' => isset($c['date_created']) ? date('Y-m-d H:i:s', strtotime($c['date_created'])) : '',
                ];
            }
            json_response($out);
        }
    }
    $pdo = db();
    $phone = $_GET['phone'] ?? '';
    $email = $_GET['email'] ?? '';
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    if ($phone) {
        $st = $pdo->prepare('SELECT * FROM call_log WHERE phone=? ORDER BY created_at DESC LIMIT ' . (int)$limit);
        $st->execute([preg_replace('/[^+0-9]/', '', $phone)]);
    } elseif ($email) {
        $st = $pdo->prepare('SELECT * FROM call_log WHERE performer_email=? ORDER BY created_at DESC LIMIT ' . (int)$limit);
        $st->execute([strtolower($email)]);
    } else {
        $st = $pdo->query('SELECT * FROM call_log ORDER BY created_at DESC LIMIT ' . (int)$limit);
    }
    json_response($st->fetchAll(PDO::FETCH_ASSOC));
}

/* ── Stats ── */
function handleStats(): void {
    $pdo = db();
    $total    = (int)$pdo->query('SELECT COUNT(*) FROM call_log')->fetchColumn();
    $inbound  = (int)$pdo->query("SELECT COUNT(*) FROM call_log WHERE direction='inbound'")->fetchColumn();
    $outbound = (int)$pdo->query("SELECT COUNT(*) FROM call_log WHERE direction='outbound'")->fetchColumn();
    $today    = (int)$pdo->query("SELECT COUNT(*) FROM call_log WHERE DATE(created_at)=CURDATE()")->fetchColumn();
    $totalSec = (int)$pdo->query('SELECT COALESCE(SUM(duration),0) FROM call_log')->fetchColumn();
    json_response(['total'=>$total,'inbound'=>$inbound,'outbound'=>$outbound,'today'=>$today,'total_minutes'=>round($totalSec/60,1)]);
}

/* ═══════════════════════════════════════════════════════════════
   Browser Voice SDK — token endpoint + TwiML application
   ═══════════════════════════════════════════════════════════════
   The dashboard dial pad (luxe-voice.js) instantiates
       new Twilio.Device(token)
   after fetching that `token` from voice.php?action=token here.
   When the user presses Call, the SDK runs
       device.connect({ params: { To: '+1...' } })
   which causes Twilio to POST to the Voice URL configured on the
   TwiML App (TW_VOICE_APP_SID). We point that URL at
   voice.php?action=voice_app, and the handler below returns TwiML
   bridging the browser leg to the dialed PSTN number.

   One-time Twilio Console setup is required (API Key, Secret,
   TwiML App SID). See DIALPAD-SETUP.md.
*/

/* ── Mint a Twilio AccessToken JWT (HS256, manual — no Composer) ── */
function handleVoiceToken(): void {
    if (TW_API_KEY === '' || TW_API_SECRET === '' || TW_VOICE_APP_SID === '') {
        throw new RuntimeException(
            'Browser Voice SDK is not configured yet. '.
            'Fill TW_API_KEY, TW_API_SECRET and TW_VOICE_APP_SID in api/comm-config.php — see DIALPAD-SETUP.md.'
        );
    }

    // Identity is the SDK "user" the browser registers as. Default 'admin'
    // for the dashboard; can be overridden so a performer dashboard could
    // use its own identity later without code changes.
    $identity = preg_replace('/[^a-zA-Z0-9_.\-@+]/', '', (string)($_GET['identity'] ?? 'admin'));
    if ($identity === '') $identity = 'admin';

    $now   = time();
    $exp   = $now + 3600;                       // 1-hour token; SDK auto-renews via tokenWillExpire
    $jti   = TW_API_KEY . '-' . bin2hex(random_bytes(8));

    $header = [
        'typ' => 'JWT',
        'alg' => 'HS256',
        'cty' => 'twilio-fpa;v=1',              // Twilio first-party-auth content type
    ];
    $payload = [
        'jti'    => $jti,
        'iss'    => TW_API_KEY,                 // API Key SID, NOT the Account SID
        'sub'    => TW_SID,                     // Account SID
        'nbf'    => $now,
        'exp'    => $exp,
        'grants' => [
            'identity' => $identity,
            'voice'    => [
                'incoming' => ['allow' => true],
                'outgoing' => ['application_sid' => TW_VOICE_APP_SID],
            ],
        ],
    ];

    $jwt = jwt_hs256_encode($header, $payload, TW_API_SECRET);
    json_response(['token' => $jwt, 'identity' => $identity, 'ttl' => 3600]);
}

/* ── TwiML returned to Twilio when the browser places a call ──
   The browser SDK call arrives here as a POST with `To` and `From`.
   `To` is whatever the SDK passed in params; `From` is the SDK identity. */
function handleVoiceApp(): void {
    $to = comm_e164((string)($_REQUEST['To'] ?? ''));
    header('Content-Type: text/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    if ($to !== '') {
        // Bridge the browser leg to the dialed PSTN number, with recording.
        // callerId must be a number you own in Twilio (your Twilio "From").
        echo '<Dial callerId="' . htmlspecialchars(TW_FROM, ENT_QUOTES) . '"'
           . ' record="record-from-answer" timeout="30">';
        echo '<Number>' . htmlspecialchars($to, ENT_QUOTES) . '</Number>';
        echo '</Dial>';

        // Log the outbound attempt so the call shows up in history.
        try {
            $pdo = db();
            $sid = (string)($_REQUEST['CallSid'] ?? '');
            $email = comm_email_by_phone($pdo, $to) ?? '';
            $st = $pdo->prepare(
                'INSERT INTO call_log (direction,phone,performer_email,twilio_sid,status,duration) VALUES (?,?,?,?,?,?)'
            );
            $st->execute(['outbound', $to, $email, $sid, 'initiated', 0]);
        } catch (Throwable $logErr) {
            error_log('[voice_app log] ' . $logErr->getMessage());
        }
    } else {
        echo '<Say voice="alice">No destination was provided. Goodbye.</Say>';
    }
    echo '</Response>';
    exit;
}

/* ── Tiny JWT (HS256) — enough for Twilio AccessTokens, no library ── */
function jwt_hs256_encode(array $header, array $payload, string $secret): string {
    $segments = [
        b64u_encode(json_encode($header,  JSON_UNESCAPED_SLASHES)),
        b64u_encode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
    ];
    $signing = implode('.', $segments);
    $sig     = hash_hmac('sha256', $signing, $secret, true);
    return $signing . '.' . b64u_encode($sig);
}

function b64u_encode(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
