<?php
/* ═══════════════════════════════════════════════════════════════════════════
   Messaging API — /api/messaging.php
   Unified SMS + WhatsApp send / history endpoint for the softphone drawer's
   messaging accordion, plus DB-backed message templates.

   SMS and WhatsApp both go through the same Twilio REST Messages endpoint.
   WhatsApp uses whatsapp:+E.164 From/To and — outside the 24-hour customer
   service window — must use a pre-approved template (ContentSid + variables)
   instead of a free-form Body.

   Credentials come from the DB-backed Settings store (api/settings.php /
   _settings.php). Nothing is hardcoded.

     GET  ?action=history&channel=sms|whatsapp[&limit=200]
            → [ {peer, body, direction, status, created_at, content_sid}, … ]
     GET  ?action=conversation&channel=…&peer=+1…
            → { ok, messages:[ {body, direction, status, created_at}, … ] }
     GET  ?action=templates[&channel=sms|whatsapp]
            → { ok, templates:[ {id, channel, name, content_sid, body, variables[]}, … ] }
     POST ?action=send
            SMS / in-window WhatsApp:  { channel, to, body }
            WhatsApp template:         { channel:"whatsapp", to, content_sid, variables:{…} | [..] }
            → { ok, success, sid, status }  (success kept for parity with sms.php callers)
     POST ?action=save_template  { id?, channel, name, content_sid, body, variables:[…] }
            → { ok, id }
     POST ?action=delete_template { id }
            → { ok }

   ── To SHARE SMS history with the legacy /api/sms.php comms tab instead of
      this endpoint's own log, point the SMS reads at that table: see the
      $SMS_LEGACY_TABLE note in conversation()/history() below. Left self-
      contained by default so nothing here depends on an unseen schema.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/../cors.php';
require __DIR__ . '/../config.php';
require __DIR__ . '/_settings.php';

session_start();
/* ── Inbound SMS webhook (Twilio Messaging Service → here). No session. ── */
if (($_GET["action"] ?? "") === "webhook") {
    $from = $_POST["From"] ?? "";
    $body = $_POST["Body"] ?? "";
    $sid  = $_POST["MessageSid"] ?? ($_POST["SmsSid"] ?? "");
    if ($from !== "") {
        $peer = function_exists("msg_peer") ? msg_peer($from) : $from;
        log_message("sms", "inbound", $peer, $body, $sid, "received", "", "");
    }
    header("Content-Type: text/xml; charset=utf-8");
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response/>";
    exit;
}
                       // after cors.php so the OPTIONS preflight can't clobber it
header('Content-Type: application/json; charset=utf-8');

/* Require a signed-in admin (login.php sets these). */
$email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';
if ($email === '') json_response(['ok' => false, 'error' => 'Not signed in'], 401);

$identity = function_exists('identity_for_email') ? identity_for_email($email) : $email;

/* ── Storage bootstrap (idempotent) ───────────────────────────────────────── */
db()->exec(
    "CREATE TABLE IF NOT EXISTS messaging_log (
        id             BIGINT AUTO_INCREMENT PRIMARY KEY,
        channel        VARCHAR(16)  NOT NULL,           /* 'sms' | 'whatsapp' */
        direction      VARCHAR(12)  NOT NULL,           /* 'outbound' | 'inbound' */
        peer           VARCHAR(40)  NOT NULL,           /* other party, E.164 (no whatsapp: prefix) */
        body           TEXT,
        twilio_sid     VARCHAR(64),
        status         VARCHAR(32),
        content_sid    VARCHAR(64),
        agent_identity VARCHAR(128),
        created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_chan_peer    (channel, peer),
        KEY idx_chan_created (channel, created_at)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
db()->exec(
    "CREATE TABLE IF NOT EXISTS message_templates (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        channel     VARCHAR(16)  NOT NULL,              /* 'sms' | 'whatsapp' */
        name        VARCHAR(120) NOT NULL,
        content_sid VARCHAR(64),                        /* HX… for WhatsApp templates */
        body        TEXT,                               /* SMS text / human-readable preview */
        variables   TEXT,                               /* JSON array of placeholder labels */
        created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

/* ── Helpers ──────────────────────────────────────────────────────────────── */

/* Strip a whatsapp: prefix and surrounding whitespace; keep the E.164 number. */
function msg_peer(string $raw): string {
    $raw = trim($raw);
    if (stripos($raw, 'whatsapp:') === 0) $raw = substr($raw, strlen('whatsapp:'));
    return trim($raw);
}

/* Twilio REST credentials. Prefer the API Key SID/Secret already used by the
   Voice softphone (api/voice-token.php); fall back to Account SID + Auth Token.
   The Account SID is always required for the request URL. */
function twilio_auth(): array {
    $accountSid = app_setting('twilio_account_sid');
    $apiKey     = app_setting('twilio_api_key');
    $apiSecret  = app_setting('twilio_api_secret');
    $authToken  = app_setting('twilio_auth_token');
    if ($accountSid === '') return ['', '', '', 'Account SID not set in Settings'];
    if ($apiKey !== '' && $apiSecret !== '') return [$accountSid, $apiKey, $apiSecret, ''];
    if ($authToken !== '')                    return [$accountSid, $accountSid, $authToken, ''];
    return ['', '', '', 'Twilio auth not set in Settings (need API Key + Secret, or Auth Token)'];
}

/* POST application/x-www-form-urlencoded to the Twilio Messages resource. */
function twilio_send_message(array $form): array {
    [$accountSid, $user, $pass, $err] = twilio_auth();
    if ($err !== '') return ['ok' => false, 'error' => $err];

    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($accountSid) . '/Messages.json';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($form),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $user . ':' . $pass,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $e = curl_error($ch); curl_close($ch);
        return ['ok' => false, 'error' => 'Network error: ' . $e];
    }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($resp, true);
    if ($code >= 200 && $code < 300 && is_array($data)) {
        return ['ok' => true, 'sid' => $data['sid'] ?? '', 'status' => $data['status'] ?? 'queued'];
    }
    $apiMsg = is_array($data) ? ($data['message'] ?? '') : '';
    return ['ok' => false, 'error' => $apiMsg !== '' ? $apiMsg : ('Twilio HTTP ' . $code), 'code' => $data['code'] ?? null];
}

function log_message(string $channel, string $direction, string $peer, string $body, string $sid, string $status, string $contentSid, string $identity): void {
    try {
        db()->prepare(
            "INSERT INTO messaging_log (channel, direction, peer, body, twilio_sid, status, content_sid, agent_identity)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([$channel, $direction, $peer, $body, $sid, $status, $contentSid, $identity]);
    } catch (Throwable $e) {
        error_log('[messaging] log: ' . $e->getMessage());
    }
}

/* ── Routing ──────────────────────────────────────────────────────────────── */

$action  = $_GET['action'] ?? '';
$channel = strtolower(trim((string)($_GET['channel'] ?? '')));
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {

    /* ---- history (recent rows for a channel; client groups into threads) ---- */
    if ($action === 'history' && $method === 'GET') {
        if (!in_array($channel, ['sms', 'whatsapp'], true)) json_response(['ok' => false, 'error' => 'Bad channel'], 400);
        $limit = max(1, min(500, (int)($_GET['limit'] ?? 200)));
        // To share the legacy SMS table instead, replace this query for
        // $channel==='sms' with a SELECT against $SMS_LEGACY_TABLE.
        $st = db()->prepare(
            "SELECT peer, body, direction, status, content_sid, created_at
               FROM messaging_log WHERE channel = ? ORDER BY created_at DESC LIMIT $limit"
        );
        $st->execute([$channel]);
        json_response($st->fetchAll());        // bare array, matching sms.php?action=history
    }

    /* ---- conversation (one peer within a channel) ---- */
    if ($action === 'conversation' && $method === 'GET') {
        if (!in_array($channel, ['sms', 'whatsapp'], true)) json_response(['ok' => false, 'error' => 'Bad channel'], 400);
        $peer = msg_peer((string)($_GET['peer'] ?? ''));
        if ($peer === '') json_response(['ok' => false, 'error' => 'Missing peer'], 400);
        $st = db()->prepare(
            "SELECT body, direction, status, content_sid, created_at
               FROM messaging_log WHERE channel = ? AND peer = ? ORDER BY created_at ASC LIMIT 500"
        );
        $st->execute([$channel, $peer]);
        json_response(['ok' => true, 'peer' => $peer, 'messages' => $st->fetchAll()]);
    }

    /* ---- templates list ---- */
    if ($action === 'templates' && $method === 'GET') {
        if ($channel !== '' && in_array($channel, ['sms', 'whatsapp'], true)) {
            $st = db()->prepare("SELECT id, channel, name, content_sid, body, variables FROM message_templates WHERE channel = ? ORDER BY name");
            $st->execute([$channel]);
            $rows = $st->fetchAll();
        } else {
            $rows = db()->query("SELECT id, channel, name, content_sid, body, variables FROM message_templates ORDER BY channel, name")->fetchAll();
        }
        foreach ($rows as &$r) {
            $v = json_decode((string)$r['variables'], true);
            $r['variables'] = is_array($v) ? array_values($v) : [];
        }
        unset($r);
        json_response(['ok' => true, 'templates' => $rows]);
    }

    /* ---- save / upsert a template ---- */
    if ($action === 'save_template' && $method === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $ch = strtolower(trim((string)($in['channel'] ?? '')));
        if (!in_array($ch, ['sms', 'whatsapp'], true)) json_response(['ok' => false, 'error' => 'Bad channel'], 400);
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') json_response(['ok' => false, 'error' => 'Template name required'], 400);
        $contentSid = trim((string)($in['content_sid'] ?? ''));
        $body       = (string)($in['body'] ?? '');
        $vars       = $in['variables'] ?? [];
        if (!is_array($vars)) $vars = [];
        $vars = array_values(array_filter(array_map(fn($v) => trim((string)$v), $vars), fn($v) => $v !== ''));
        $varsJson = json_encode($vars, JSON_UNESCAPED_UNICODE);

        $id = (int)($in['id'] ?? 0);
        if ($id > 0) {
            db()->prepare("UPDATE message_templates SET channel=?, name=?, content_sid=?, body=?, variables=? WHERE id=?")
                ->execute([$ch, $name, $contentSid, $body, $varsJson, $id]);
        } else {
            db()->prepare("INSERT INTO message_templates (channel, name, content_sid, body, variables) VALUES (?,?,?,?,?)")
                ->execute([$ch, $name, $contentSid, $body, $varsJson]);
            $id = (int)db()->lastInsertId();
        }
        json_response(['ok' => true, 'id' => $id]);
    }

    /* ---- delete a template ---- */
    if ($action === 'delete_template' && $method === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($in['id'] ?? 0);
        if ($id <= 0) json_response(['ok' => false, 'error' => 'Missing id'], 400);
        db()->prepare("DELETE FROM message_templates WHERE id = ?")->execute([$id]);
        json_response(['ok' => true]);
    }

    /* ---- send (SMS, or WhatsApp free-form / template) ---- */
    if ($action === 'send' && $method === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $ch = strtolower(trim((string)($in['channel'] ?? 'sms')));
        if (!in_array($ch, ['sms', 'whatsapp'], true)) json_response(['ok' => false, 'success' => false, 'error' => 'Bad channel'], 400);

        $peer = msg_peer((string)($in['to'] ?? ''));
        if ($peer === '') json_response(['ok' => false, 'success' => false, 'error' => 'Missing recipient'], 400);

        $body       = (string)($in['body'] ?? '');
        $contentSid = trim((string)($in['content_sid'] ?? ''));

        // Build the From + To and the body/template fields per channel.
        $form = [];
        if ($ch === 'whatsapp') {
            $from = app_setting('twilio_whatsapp_from');
            if ($from === '') json_response(['ok' => false, 'success' => false, 'error' => 'WhatsApp From not set in Settings (e.g. whatsapp:+14155238886)'], 400);
            if (stripos($from, 'whatsapp:') !== 0) $from = 'whatsapp:' . $from;
            $form['From'] = $from;
            $form['To']   = 'whatsapp:' . $peer;
        } else {
            $svc  = app_setting('twilio_messaging_service_sid');
            $from = app_setting('twilio_sms_from');
            if ($from === '') $from = app_setting('twilio_from_number');     // fall back to the global From
            if ($svc !== '') { $form['MessagingServiceSid'] = $svc; }
            elseif ($from !== '') { $form['From'] = $from; }
            else json_response(['ok' => false, 'success' => false, 'error' => 'SMS From not set in Settings'], 400);
            $form['To'] = $peer;
        }

        $logBody = $body;
        if ($contentSid !== '') {
            // Template send (required for WhatsApp outside the 24h window).
            $form['ContentSid'] = $contentSid;
            $vars = $in['variables'] ?? [];
            $cv   = [];
            if (is_array($vars)) {
                // Accept either {"1":"x","2":"y"} or ["x","y"] (mapped to 1-based keys).
                $isList = array_keys($vars) === range(0, count($vars) - 1);
                if ($isList) { foreach ($vars as $i => $v) $cv[(string)($i + 1)] = (string)$v; }
                else         { foreach ($vars as $k => $v) $cv[(string)$k]       = (string)$v; }
            }
            if ($cv) $form['ContentVariables'] = json_encode($cv, JSON_UNESCAPED_UNICODE);
            if ($logBody === '') $logBody = '[template ' . $contentSid . ']' . ($cv ? ' ' . json_encode($cv, JSON_UNESCAPED_UNICODE) : '');
        } else {
            if ($body === '') json_response(['ok' => false, 'success' => false, 'error' => 'Empty message'], 400);
            $form['Body'] = $body;
        }

        $res = twilio_send_message($form);
        log_message($ch, 'outbound', $peer, $logBody, $res['sid'] ?? '', $res['ok'] ? ($res['status'] ?? 'queued') : 'failed', $contentSid, $identity);

        if ($res['ok']) {
            json_response(['ok' => true, 'success' => true, 'sid' => $res['sid'] ?? '', 'status' => $res['status'] ?? 'queued']);
        }
        json_response(['ok' => false, 'success' => false, 'error' => $res['error'] ?? 'Send failed', 'code' => $res['code'] ?? null], 502);
    }

    json_response(['ok' => false, 'error' => 'Unknown action'], 400);

} catch (Throwable $e) {
    error_log('[messaging] ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Server error'], 500);
}
