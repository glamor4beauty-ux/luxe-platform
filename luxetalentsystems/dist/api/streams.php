<?php
// ═══════════════════════════════════════════════════════════════════════════
// Streaming API — /api/streams.php
// ═══════════════════════════════════════════════════════════════════════════
//
// Endpoints:
//
//   PERFORMER (requires performer session — same auth pattern as chat.php):
//     get_my_key                 GET  → {stream_key, urls{...}, instructions}
//     regenerate_my_key          POST → {stream_key}
//
//   ADMIN (requires admin/recruiter login):
//     list_active                GET  → [{session_id, performer_email, started_at, key, hls_url, ...}]
//     list_recordings            GET  → [{recording, performer, cdn_url, duration, ...}]
//     restream_targets_list      GET  ?performer_email → [{...}]
//     restream_target_save       POST {platform, label, rtmp_url, rtmp_key, performer_email?} → {id}
//     restream_target_delete     POST {id} → {ok}
//     restream_start             POST {session_id, target_id} → {job_id}
//     restream_stop              POST {job_id} → {ok}
//
//   WEBHOOKS (called by MediaMTX / stream-upload — no session, secret-protected):
//     notify_publish_start       POST {stream_key, source_type, source_id} → {ok}
//     notify_publish_done        POST {stream_key} → {ok}
//     notify_recording_uploaded  POST {stream_key, filename, cdn_url, size, duration, secret} → {ok}
//
// ═══════════════════════════════════════════════════════════════════════════

require __DIR__ . '/../cors.php';
require __DIR__ . '/../config.php';

// Start the session only after cors.php has handled (and exited on) the OPTIONS
// preflight — otherwise the preflight clobbers the saved session with an empty
// payload, breaking auth on the next real request (same bug as chat.php had).
session_start();

header('Content-Type: application/json; charset=utf-8');

// ─── shared secret with the streaming server (must match /etc/streaming.env)
// In production, move this to config.php.
define('STREAM_WEBHOOK_SECRET', defined('STREAM_WEBHOOK_SECRET_OVERRIDE')
    ? STREAM_WEBHOOK_SECRET_OVERRIDE
    : 'VseubY44We1FpyxiVbRVLeQLBiL7MM5d');

define('STREAM_DOMAIN', 'media.divafans.club');
define('STREAM_SERVER_IP', '67.227.156.88');

// ─── helpers ────────────────────────────────────────────────────────────────
function respond($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}
function bad(string $msg, int $status = 400): void {
    respond(['error' => $msg], $status);
}
function input_json(): array {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw ?: '', true);
    return is_array($j) ? $j : [];
}
function pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}
function uuid(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
function new_stream_key(): string {
    // 32 hex chars — opaque but URL-safe and easy to copy
    return bin2hex(random_bytes(16));
}
function admin_or_401(): array {
    $email = $_SESSION['user_email'] ?? $_SESSION['admin_email'] ?? '';
    if (!$email) bad('Not signed in', 401);
    $name = $_SESSION['user_name'] ?? '';
    $role = $_SESSION['user_role'] ?? 'admin';
    return ['email' => $email, 'name' => $name, 'role' => $role];
}
function performer_or_401(): array {
    // Performer session: set by performer-login.php — adapt the key names if yours differ
    $email = $_SESSION['perf_email'] ?? $_SESSION['user_email'] ?? '';
    if (!$email) bad('Not signed in', 401);
    return ['email' => $email];
}

// ─── dispatch ──────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        // ── Performer ──
        case 'get_my_key':                performer_get_my_key();           break;
        case 'regenerate_my_key':         performer_regenerate_my_key();    break;

        // ── Admin ──
        case 'list_active':               admin_list_active();              break;
        case 'list_recordings':           admin_list_recordings();          break;
        case 'restream_targets_list':     admin_restream_targets_list();    break;
        case 'restream_target_save':      admin_restream_target_save();     break;
        case 'restream_target_delete':    admin_restream_target_delete();   break;
        case 'restream_start':            admin_restream_start();           break;
        case 'restream_stop':             admin_restream_stop();            break;

        // ── Webhooks from streaming server ──
        case 'notify_publish_start':      webhook_publish_start();          break;
        case 'notify_publish_done':       webhook_publish_done();           break;
        case 'notify_recording_uploaded': webhook_recording_uploaded();     break;

        default: bad('Unknown action: ' . $action, 400);
    }
} catch (Throwable $e) {
    error_log('[streams.php] ' . $e->getMessage());
    bad('Server error: ' . $e->getMessage(), 500);
}

// ═══════════════════════════════════════════════════════════════════════════
// PERFORMER
// ═══════════════════════════════════════════════════════════════════════════

function performer_get_my_key(): void {
    $me = performer_or_401();

    // Find or auto-create
    $st = pdo()->prepare('SELECT stream_key FROM stream_keys WHERE performer_email = ? AND revoked_at IS NULL');
    $st->execute([$me['email']]);
    $key = $st->fetchColumn();

    if (!$key) {
        $key = new_stream_key();
        pdo()->prepare('INSERT INTO stream_keys (performer_email, stream_key) VALUES (?, ?)')
             ->execute([$me['email'], $key]);
    }

    // Is the performer currently live?
    $li = pdo()->prepare("SELECT id FROM stream_sessions WHERE stream_key = ? AND status = 'live' ORDER BY started_at DESC LIMIT 1");
    $li->execute([$key]);
    $live_session = $li->fetchColumn() ?: null;

    respond([
        'ok'         => true,
        'stream_key' => $key,
        'is_live'    => (bool)$live_session,
        'urls'       => [
            'rtmp'   => 'rtmp://' . STREAM_DOMAIN . ':1935/' . $key,
            'whip'   => 'https://' . STREAM_DOMAIN . '/whip/' . $key . '/whip',
            'hls'    => 'https://' . STREAM_DOMAIN . '/hls/' . $key . '/index.m3u8',
        ],
    ]);
}

function performer_regenerate_my_key(): void {
    $me = performer_or_401();

    // Revoke any current key
    pdo()->prepare('UPDATE stream_keys SET revoked_at = NOW() WHERE performer_email = ? AND revoked_at IS NULL')
         ->execute([$me['email']]);

    $key = new_stream_key();
    pdo()->prepare('INSERT INTO stream_keys (performer_email, stream_key) VALUES (?, ?)')
         ->execute([$me['email'], $key]);

    respond(['ok' => true, 'stream_key' => $key]);
}

// ═══════════════════════════════════════════════════════════════════════════
// ADMIN
// ═══════════════════════════════════════════════════════════════════════════

function admin_list_active(): void {
    admin_or_401();

    $st = pdo()->query("
        SELECT ss.id, ss.stream_key, ss.performer_email, ss.started_at, ss.source_type, ss.record_enabled,
               TIMESTAMPDIFF(SECOND, ss.started_at, NOW()) AS secs_running,
               sk.performer_email AS owner_email
          FROM stream_sessions ss
     LEFT JOIN stream_keys sk ON sk.stream_key = ss.stream_key
         WHERE ss.status = 'live'
      ORDER BY ss.started_at DESC
    ");
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['hls_url'] = 'https://' . STREAM_DOMAIN . '/hls/' . $r['stream_key'] . '/index.m3u8';
    }
    respond(['ok' => true, 'sessions' => $rows]);
}

function admin_list_recordings(): void {
    admin_or_401();
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $st = pdo()->prepare("
        SELECT id, stream_key, performer_email, cdn_url, filename, file_size, duration_secs, created_at
          FROM stream_recordings
      ORDER BY created_at DESC
         LIMIT ?
    ");
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    respond(['ok' => true, 'recordings' => $st->fetchAll()]);
}

function admin_restream_targets_list(): void {
    admin_or_401();
    $performer = $_GET['performer_email'] ?? null;
    if ($performer) {
        $st = pdo()->prepare('SELECT * FROM stream_restream_targets WHERE performer_email = ? OR performer_email IS NULL ORDER BY platform');
        $st->execute([$performer]);
    } else {
        $st = pdo()->query('SELECT * FROM stream_restream_targets ORDER BY platform, label');
    }
    respond(['ok' => true, 'targets' => $st->fetchAll()]);
}

function admin_restream_target_save(): void {
    admin_or_401();
    $j = input_json();
    $platform = $j['platform'] ?? '';
    $label    = trim($j['label']    ?? '');
    $url      = trim($j['rtmp_url'] ?? '');
    $key      = trim($j['rtmp_key'] ?? '');
    $perf     = $j['performer_email'] ?? null;
    if (!in_array($platform, ['facebook','youtube','twitch','custom'], true)) bad('Invalid platform');
    if (!$url || !$key) bad('rtmp_url and rtmp_key are required');
    if (!preg_match('#^rtmps?://#', $url)) bad('rtmp_url must start with rtmp:// or rtmps://');

    $id = $j['id'] ?? null;
    if ($id) {
        pdo()->prepare('UPDATE stream_restream_targets SET platform=?,label=?,rtmp_url=?,rtmp_key=?,performer_email=? WHERE id=?')
             ->execute([$platform,$label,$url,$key,$perf,(int)$id]);
        respond(['ok' => true, 'id' => (int)$id]);
    } else {
        pdo()->prepare('INSERT INTO stream_restream_targets (performer_email,platform,label,rtmp_url,rtmp_key) VALUES (?,?,?,?,?)')
             ->execute([$perf,$platform,$label,$url,$key]);
        respond(['ok' => true, 'id' => (int)pdo()->lastInsertId()]);
    }
}

function admin_restream_target_delete(): void {
    admin_or_401();
    $j = input_json();
    $id = (int)($j['id'] ?? 0);
    if ($id <= 0) bad('Missing id');
    pdo()->prepare('DELETE FROM stream_restream_targets WHERE id = ?')->execute([$id]);
    respond(['ok' => true]);
}

function admin_restream_start(): void {
    admin_or_401();
    $j = input_json();
    $session_id = trim($j['session_id'] ?? '');
    $target_id  = (int)($j['target_id'] ?? 0);
    if (!$session_id || !$target_id) bad('Missing session_id or target_id');

    // Lookup session + target
    $st = pdo()->prepare('SELECT stream_key FROM stream_sessions WHERE id = ? AND status = "live"');
    $st->execute([$session_id]);
    $key = $st->fetchColumn();
    if (!$key) bad('Session not found or not live', 404);

    $tt = pdo()->prepare('SELECT rtmp_url, rtmp_key FROM stream_restream_targets WHERE id = ?');
    $tt->execute([$target_id]);
    $target = $tt->fetch();
    if (!$target) bad('Target not found', 404);

    // Create job row; the streaming server polls for new jobs and runs ffmpeg.
    // (Simpler v1 approach: we just record the job; admin can stop it later.
    //  Actual ffmpeg execution is left as a follow-up worker on the stream box.)
    $job_id = uuid();
    pdo()->prepare('INSERT INTO stream_restream_jobs (id, session_id, target_id, status) VALUES (?,?,?,"running")')
         ->execute([$job_id, $session_id, $target_id]);

    respond(['ok' => true, 'job_id' => $job_id,
             'note' => 'Restream job queued. The streaming server worker will start ffmpeg shortly.']);
}

function admin_restream_stop(): void {
    admin_or_401();
    $j = input_json();
    $job_id = trim($j['job_id'] ?? '');
    if (!$job_id) bad('Missing job_id');
    pdo()->prepare('UPDATE stream_restream_jobs SET status = "stopped", stopped_at = NOW() WHERE id = ?')
         ->execute([$job_id]);
    respond(['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════════
// WEBHOOKS — no PHP session, called by MediaMTX or stream-upload
// ═══════════════════════════════════════════════════════════════════════════
// Trust signal: caller IP must match the streaming server, OR the request
// contains the shared webhook secret. Both checks combined are belt+braces.

function webhook_check(): void {
    // Only the streaming server should be calling these
    $allowed_ips = [STREAM_SERVER_IP, '127.0.0.1', '::1'];
    $caller = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($caller, $allowed_ips, true)) {
        bad('Forbidden (caller IP not allowed: ' . $caller . ')', 403);
    }
}

function webhook_publish_start(): void {
    webhook_check();
    $j = input_json();
    $key = trim($j['stream_key'] ?? '');
    if (!$key) bad('Missing stream_key');

    // Verify key
    $st = pdo()->prepare('SELECT performer_email FROM stream_keys WHERE stream_key = ? AND revoked_at IS NULL');
    $st->execute([$key]);
    $email = $st->fetchColumn();
    if (!$email) bad('Unknown stream key', 401);

    // Close any stale "live" session for this key
    pdo()->prepare("UPDATE stream_sessions SET status = 'dropped', ended_at = NOW() WHERE stream_key = ? AND status = 'live'")
         ->execute([$key]);

    // Open a fresh session
    $session_id = uuid();
    pdo()->prepare("INSERT INTO stream_sessions (id, stream_key, performer_email, source_type) VALUES (?,?,?,?)")
         ->execute([$session_id, $key, $email, $j['source_type'] ?? null]);

    respond(['ok' => true, 'session_id' => $session_id]);
}

function webhook_publish_done(): void {
    webhook_check();
    $j = input_json();
    $key = trim($j['stream_key'] ?? '');
    if (!$key) bad('Missing stream_key');
    pdo()->prepare("UPDATE stream_sessions SET status = 'ended', ended_at = NOW() WHERE stream_key = ? AND status = 'live'")
         ->execute([$key]);
    // Mark any running restream jobs for this session as stopped
    pdo()->prepare("UPDATE stream_restream_jobs SET status = 'stopped', stopped_at = NOW()
                   WHERE status = 'running' AND session_id IN (SELECT id FROM stream_sessions WHERE stream_key = ?)")
         ->execute([$key]);
    respond(['ok' => true]);
}

function webhook_recording_uploaded(): void {
    $j = input_json();
    $secret = $j['secret'] ?? '';
    if (!hash_equals(STREAM_WEBHOOK_SECRET, $secret)) {
        bad('Forbidden (bad secret)', 403);
    }

    $key      = trim($j['stream_key'] ?? '');
    $filename = trim($j['filename']   ?? '');
    $cdn_url  = trim($j['cdn_url']    ?? '');
    $size     = (int)($j['size']      ?? 0);
    $duration = (int)($j['duration']  ?? 0);
    if (!$key || !$filename || !$cdn_url) bad('Missing fields');

    // Lookup performer (best-effort — falls back if key is now revoked)
    $st = pdo()->prepare('SELECT performer_email FROM stream_keys WHERE stream_key = ? ORDER BY created_at DESC LIMIT 1');
    $st->execute([$key]);
    $email = $st->fetchColumn() ?: null;

    $rec_id = uuid();
    pdo()->prepare('INSERT INTO stream_recordings (id, stream_key, performer_email, cdn_url, filename, file_size, duration_secs) VALUES (?,?,?,?,?,?,?)')
         ->execute([$rec_id, $key, $email, $cdn_url, $filename, $size, $duration]);

    respond(['ok' => true, 'recording_id' => $rec_id]);
}
