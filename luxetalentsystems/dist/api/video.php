<?php
require __DIR__ . '/../cors.php';
require __DIR__ . '/comm-config.php';

/* ═══════════════════════════════════════════════════════════════
   Twilio Video — /api/video.php
   Mints Access Tokens (JWT) granting a user access to a Video room.
   Architecture: one room per performer; admin joins the performer's
   room -> two-way admin <-> performer only.
   Credentials: TW_API_KEY / TW_API_SECRET / TW_SID from comm-config.php
   No external libraries: JWT built natively with hash_hmac.
   ═══════════════════════════════════════════════════════════════ */

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'token':  handleToken();  break;
        case 'config': handleConfig(); break;  // tells the UI if Video is set up
        case 'request': handleRequest(); break;  // performer raises hand
        case 'pending': handlePending(); break;  // admin polls for incoming
        case 'answer':  handleAnswer();  break;  // admin accept/decline
        case 'check':   handleCheck();   break;  // performer polls for the answer
        case 'cancel':  handleCancel();  break;  // performer cancels their request
        default: json_response(['success' => false, 'error' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    error_log('[video] ' . $e->getMessage());
    json_response(['success' => false, 'error' => $e->getMessage()], 400);
}

/* Is Video configured? (API key present) */
function handleConfig(): void {
    json_response(['configured' => (TW_API_KEY !== '' && TW_API_SECRET !== '')]);
}

/* Issue an access token for {identity} to join {room}. */
function handleToken(): void {
    if (TW_API_KEY === '' || TW_API_SECRET === '') {
        throw new RuntimeException('Video not configured: set TW_API_KEY and TW_API_SECRET in comm-config.php');
    }

    $in = json_decode(file_get_contents('php://input'), true) ?: array_merge($_GET, $_POST);
    $identity = trim($in['identity'] ?? '');
    $room     = trim($in['room'] ?? '');
    if ($identity === '') throw new RuntimeException('Missing: identity');
    if ($room === '')     throw new RuntimeException('Missing: room');

    $token = makeVideoToken($identity, $room, 3600);
    json_response(['success' => true, 'token' => $token, 'identity' => $identity, 'room' => $room]);
}

/* ── Build a Twilio Access Token (JWT) with a Video grant ──
   Spec: header {alg:HS256, typ:JWT, cty:twilio-fpa;v=1}
         payload { jti, iss=API_KEY, sub=ACCOUNT_SID, iat, exp,
                   grants:{ identity, video:{ room } } }
   Signed HMAC-SHA256 with the API Key Secret.                     */
function makeVideoToken(string $identity, string $room, int $ttl): string {
    $now = time();
    $header = ['alg' => 'HS256', 'typ' => 'JWT', 'cty' => 'twilio-fpa;v=1'];
    $payload = [
        'jti' => TW_API_KEY . '-' . $now,
        'iss' => TW_API_KEY,
        'sub' => TW_SID,
        'iat' => $now,
        'exp' => $now + $ttl,
        'grants' => [
            'identity' => $identity,
            'video'    => ['room' => $room],
        ],
    ];
    $seg = [b64url(json_encode($header)), b64url(json_encode($payload))];
    $sig = hash_hmac('sha256', implode('.', $seg), TW_API_SECRET, true);
    $seg[] = b64url($sig);
    return implode('.', $seg);
}

function b64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/* room name for a performer (matches video-client.js roomNameFor) */
function roomFor(string $email): string {
    return 'perf_' . preg_replace('/[^a-z0-9]/', '_', strtolower($email));
}

/* Performer raises hand. Replaces any stale pending request from same performer. */
function handleRequest(): void {
    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $email = strtolower(trim($in['performer_email'] ?? ''));
    $stage = trim($in['stage_name'] ?? '');
    if ($email === '') throw new RuntimeException('Missing: performer_email');

    $pdo = db();
    // cancel previous pending from this performer
    $pdo->prepare("UPDATE video_requests SET status='cancelled' WHERE performer_email=? AND status='pending'")->execute([$email]);
    $room = roomFor($email);
    $st = $pdo->prepare('INSERT INTO video_requests (performer_email,stage_name,room,status) VALUES (?,?,?,?)');
    $st->execute([$email, $stage, $room, 'pending']);
    json_response(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'room' => $room]);
}

/* Admin polls: list pending requests (newest first), within last 2 minutes. */
function handlePending(): void {
    $pdo = db();
    $st = $pdo->query("SELECT id, performer_email, stage_name, room, created_at FROM video_requests WHERE status='pending' AND created_at >= (NOW() - INTERVAL 2 MINUTE) ORDER BY created_at ASC");
    json_response(['requests' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* Admin accepts or declines a request. */
function handleAnswer(): void {
    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = (int)($in['id'] ?? 0);
    $decision = ($in['decision'] ?? '') === 'accept' ? 'accepted' : 'declined';
    if (!$id) throw new RuntimeException('Missing: id');
    $pdo = db();
    $st = $pdo->prepare("UPDATE video_requests SET status=?, answered_at=NOW() WHERE id=? AND status='pending'");
    $st->execute([$decision, $id]);
    $row = $pdo->prepare('SELECT room, performer_email FROM video_requests WHERE id=?');
    $row->execute([$id]);
    $r = $row->fetch(PDO::FETCH_ASSOC) ?: [];
    json_response(['success' => true, 'status' => $decision, 'room' => $r['room'] ?? '']);
}

/* Performer polls for the latest answer to their request. */
function handleCheck(): void {
    $email = strtolower(trim($_GET['performer_email'] ?? ''));
    if ($email === '') throw new RuntimeException('Missing: performer_email');
    $pdo = db();
    $st = $pdo->prepare('SELECT id, status, room FROM video_requests WHERE performer_email=? ORDER BY created_at DESC LIMIT 1');
    $st->execute([$email]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['status' => 'none', 'room' => ''];
    json_response($r);
}

/* Performer cancels their pending request (hung up before answer). */
function handleCancel(): void {
    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $email = strtolower(trim($in['performer_email'] ?? ''));
    if ($email === '') throw new RuntimeException('Missing: performer_email');
    $pdo = db();
    $pdo->prepare("UPDATE video_requests SET status='cancelled' WHERE performer_email=? AND status='pending'")->execute([$email]);
    json_response(['success' => true]);
}
