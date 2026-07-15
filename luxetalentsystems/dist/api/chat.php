<?php
session_start();
// ═══════════════════════════════════════════════════════════════════════════
// Live Chat API — Clean rebuild
// /api/chat.php
// ═══════════════════════════════════════════════════════════════════════════
//
// Endpoints (single switch on $_GET['action']):
//
//   VISITOR (no auth required):
//     start              POST  {name,email}             → {session_id}
//     send               POST  {session_id,message}     → {ok}
//     poll               GET   ?session_id&last_ts      → {messages[],typing,status}
//     visitor_typing     POST  {session_id,typing}      → {ok}
//     check_online       GET                            → {online,count}
//     leave_message      POST  {name,email,phone,body}  → {ok}  (stored in `messages`)
//
//   AGENT (admin/recruiter — requires session login):
//     set_status         POST  {status}                 → {ok}     // online|away|offline
//     get_status         GET                            → {status} // for slide-switch restore
//     sessions_list      GET   ?filter=active|all       → [sessions]
//     messages_get       GET   ?session_id              → {messages[],session}
//     reply              POST  {session_id,message}     → {ok}
//     close_session      POST  {session_id}             → {ok}
//     agent_typing       POST  {session_id,typing}      → {ok}
//     agents_list        GET                            → [agents]  // who's working panel
//     offline_inbox      GET   ?limit                   → [messages] // leave-a-message inbox
//     mark_read          POST  {id}                     → {ok}       // mark offline message read
//
// All responses are JSON. Successful = {ok:true,...}. Failed = HTTP 4xx/5xx + {error:'...'}.
// ═══════════════════════════════════════════════════════════════════════════

require __DIR__ . '/../cors.php';
require __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

// ─── helpers ───
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
    if (!$raw) return [];
    $j = json_decode($raw, true);
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

// ─── agent auth ───
// Reads agent identity from the PHP session that login.php establishes.
// Returns [email, name, role] or throws 401.
function agent_or_401(): array {
    $email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';
    if (!$email) bad('Not signed in', 401);

    $st = pdo()->prepare('SELECT email, name, role FROM chat_agents WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch();

    if (!$row) {
        // First time this agent appears — auto-register as admin with name = email local-part
        $id   = uuid();
        $name = ucfirst(explode('@', $email)[0]);
        pdo()->prepare(
            'INSERT INTO chat_agents (id, email, name, status, role) VALUES (?, ?, ?, ?, ?)'
        )->execute([$id, $email, $name, 'offline', 'admin']);
        $row = ['email' => $email, 'name' => $name, 'role' => 'admin'];
    }

    // Stamp last_seen
    pdo()->prepare('UPDATE chat_agents SET last_seen = NOW() WHERE email = ?')->execute([$email]);

    return $row;
}

// ─── action dispatch ───
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        // ── VISITOR ────────────────────────────────────────────────────────
        case 'start':           visitor_start();          break;
        case 'send':            visitor_send();           break;
        case 'poll':            visitor_poll();           break;
        case 'visitor_typing':  visitor_typing();         break;
        case 'check_online':    visitor_check_online();   break;
        case 'leave_message':   visitor_leave_message();  break;
        case 'stats':           chat_stats();             break;

        // ── AGENT ──────────────────────────────────────────────────────────
        case 'set_status':      agent_set_status();       break;
        case 'get_status':      agent_get_status();       break;
        case 'sessions_list':   agent_sessions_list();    break;
        case 'messages_get':    agent_messages_get();     break;
        case 'reply':           agent_reply();            break;
        case 'close_session':   agent_close_session();    break;
        case 'agent_typing':    agent_typing();           break;
        case 'agents_list':     agent_agents_list();      break;
        case 'offline_inbox':   agent_offline_inbox();    break;
        case 'mark_read':       agent_mark_read();        break;

        default:
            bad('Unknown action: ' . $action, 400);
    }
} catch (Throwable $e) {
    error_log('[chat.php] ' . $e->getMessage());
    bad('Server error: ' . $e->getMessage(), 500);
}

// ═══════════════════════════════════════════════════════════════════════════
// VISITOR HANDLERS
// ═══════════════════════════════════════════════════════════════════════════

function visitor_start(): void {
    $j = input_json();
    $name  = trim($j['name']  ?? '');
    $email = trim($j['email'] ?? '');
    if ($name === '') bad('Name is required', 400);

    $sessionId = uuid();
    pdo()->prepare(
        'INSERT INTO chat_sessions (id, visitor_name, visitor_email, status, created_at, updated_at)
         VALUES (?, ?, ?, "active", NOW(), NOW())'
    )->execute([$sessionId, $name, $email]);

    pdo()->prepare(
        'INSERT INTO chat_messages (id, session_id, sender_type, sender_name, message, created_at)
         VALUES (?, ?, "system", "System", ?, NOW())'
    )->execute([uuid(), $sessionId, "Welcome, $name. An agent will be with you shortly."]);

    respond(['ok' => true, 'session_id' => $sessionId]);
}

function visitor_send(): void {
    $j = input_json();
    $sessionId = trim($j['session_id'] ?? '');
    $message   = trim($j['message']    ?? '');
    if ($sessionId === '' || $message === '') bad('Missing session_id or message', 400);

    // Verify the session is real & active
    $st = pdo()->prepare('SELECT visitor_name, status FROM chat_sessions WHERE id = ?');
    $st->execute([$sessionId]);
    $sess = $st->fetch();
    if (!$sess)                       bad('Session not found', 404);
    if ($sess['status'] !== 'active') bad('Session is closed', 409);

    pdo()->prepare(
        'INSERT INTO chat_messages (id, session_id, sender_type, sender_name, message, created_at)
         VALUES (?, ?, "visitor", ?, ?, NOW())'
    )->execute([uuid(), $sessionId, $sess['visitor_name'], $message]);

    pdo()->prepare('UPDATE chat_sessions SET updated_at = NOW(), typing = "none" WHERE id = ?')
        ->execute([$sessionId]);

    respond(['ok' => true]);
}

function visitor_poll(): void {
    $sessionId = trim($_GET['session_id'] ?? '');
    $lastTs    = trim($_GET['last_ts']    ?? '');
    if ($sessionId === '') bad('Missing session_id', 400);

    // Session info
    $st = pdo()->prepare('SELECT typing, status FROM chat_sessions WHERE id = ?');
    $st->execute([$sessionId]);
    $sess = $st->fetch();
    if (!$sess) bad('Session not found', 404);

    // Messages — cursor is created_at (UUID ids aren't sortable)
    if ($lastTs !== '') {
        $st = pdo()->prepare(
            'SELECT id, sender_type, sender_role, sender_name, message, created_at
               FROM chat_messages
              WHERE session_id = ? AND created_at > ?
              ORDER BY created_at ASC, id ASC'
        );
        $st->execute([$sessionId, $lastTs]);
    } else {
        $st = pdo()->prepare(
            'SELECT id, sender_type, sender_role, sender_name, message, created_at
               FROM chat_messages
              WHERE session_id = ?
              ORDER BY created_at ASC, id ASC'
        );
        $st->execute([$sessionId]);
    }

    respond([
        'ok'           => true,
        'messages'     => $st->fetchAll(),
        'agent_typing' => $sess['typing'] === 'agent',
        'status'       => $sess['status'],
    ]);
}

function visitor_typing(): void {
    $j = input_json();
    $sessionId = trim($j['session_id'] ?? '');
    $typing    = !empty($j['typing']);
    if ($sessionId === '') respond(['ok' => true]);  // silent no-op

    pdo()->prepare('UPDATE chat_sessions SET typing = ? WHERE id = ?')
        ->execute([$typing ? 'visitor' : 'none', $sessionId]);

    respond(['ok' => true]);
}

function visitor_check_online(): void {
    $st = pdo()->query(
        "SELECT COUNT(*) FROM chat_agents
          WHERE status = 'online'
            AND last_seen > DATE_SUB(NOW(), INTERVAL 2 MINUTE)"
    );
    $count = (int)$st->fetchColumn();
    respond(['ok' => true, 'online' => $count > 0, 'count' => $count]);
}

function visitor_leave_message(): void {
    $j     = input_json();
    $name  = trim($j['name']  ?? '');
    $email = trim($j['email'] ?? '');
    $phone = trim($j['phone'] ?? '');
    $body  = trim($j['body']  ?? '');
    if ($name === '' || $email === '' || $body === '') bad('Name, email, and message are required', 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))    bad('Invalid email', 400);

    $subject  = 'Offline chat — ' . $name;
    $fullBody = "From: $name <$email>\n";
    if ($phone !== '') $fullBody .= "Phone: $phone\n";
    $fullBody .= "\n" . $body;

    pdo()->prepare(
        'INSERT INTO messages (from_email, to_email, subject, body, is_read, created_at)
         VALUES (?, ?, ?, ?, 0, NOW())'
    )->execute([$email, 'support@luxemodelcollective.com', $subject, $fullBody]);

    respond(['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════════
// AGENT HANDLERS  (require login session)
// ═══════════════════════════════════════════════════════════════════════════

function agent_set_status(): void {
    $me = agent_or_401();
    $j  = input_json();
    $status = $j['status'] ?? '';
    if (!in_array($status, ['online', 'away', 'offline'], true)) bad('Invalid status', 400);

    pdo()->prepare(
        'UPDATE chat_agents SET status = ?, is_online = ?, last_seen = NOW() WHERE email = ?'
    )->execute([$status, $status === 'online' ? 1 : 0, $me['email']]);

    respond(['ok' => true, 'status' => $status]);
}

function agent_get_status(): void {
    $me = agent_or_401();
    $st = pdo()->prepare('SELECT status FROM chat_agents WHERE email = ?');
    $st->execute([$me['email']]);
    respond(['ok' => true, 'status' => $st->fetchColumn() ?: 'offline', 'name' => $me['name'], 'role' => $me['role']]);
}

function agent_sessions_list(): void {
    agent_or_401();
    $filter = $_GET['filter'] ?? 'active';

    if ($filter === 'all') {
        $st = pdo()->query(
            "SELECT s.id, s.visitor_name, s.visitor_email, s.status, s.created_at, s.updated_at, s.agent_name,
                    (SELECT COUNT(*) FROM chat_messages
                       WHERE session_id = s.id AND sender_type = 'visitor' AND is_read = 0) AS unread,
                    (SELECT message FROM chat_messages
                       WHERE session_id = s.id ORDER BY created_at DESC LIMIT 1) AS last_message,
                    (SELECT created_at FROM chat_messages
                       WHERE session_id = s.id ORDER BY created_at DESC LIMIT 1) AS last_message_at
               FROM chat_sessions s
              ORDER BY s.updated_at DESC LIMIT 100"
        );
    } else {
        $st = pdo()->prepare(
            "SELECT s.id, s.visitor_name, s.visitor_email, s.status, s.created_at, s.updated_at, s.agent_name,
                    (SELECT COUNT(*) FROM chat_messages
                       WHERE session_id = s.id AND sender_type = 'visitor' AND is_read = 0) AS unread,
                    (SELECT message FROM chat_messages
                       WHERE session_id = s.id ORDER BY created_at DESC LIMIT 1) AS last_message,
                    (SELECT created_at FROM chat_messages
                       WHERE session_id = s.id ORDER BY created_at DESC LIMIT 1) AS last_message_at
               FROM chat_sessions s
              WHERE s.status = ?
              ORDER BY s.updated_at DESC LIMIT 100"
        );
        $st->execute([$filter]);
    }
    respond(['ok' => true, 'sessions' => $st->fetchAll()]);
}

function agent_messages_get(): void {
    agent_or_401();
    $sessionId = trim($_GET['session_id'] ?? '');
    if ($sessionId === '') bad('Missing session_id', 400);

    $si = pdo()->prepare('SELECT * FROM chat_sessions WHERE id = ?');
    $si->execute([$sessionId]);
    $session = $si->fetch();
    if (!$session) bad('Session not found', 404);

    $mi = pdo()->prepare(
        'SELECT id, sender_type, sender_role, sender_name, message, created_at
           FROM chat_messages
          WHERE session_id = ?
          ORDER BY created_at ASC, id ASC'
    );
    $mi->execute([$sessionId]);
    $messages = $mi->fetchAll();

    // Mark visitor messages as read now that the agent is viewing them
    pdo()->prepare(
        "UPDATE chat_messages SET is_read = 1
          WHERE session_id = ? AND sender_type = 'visitor' AND is_read = 0"
    )->execute([$sessionId]);

    respond(['ok' => true, 'session' => $session, 'messages' => $messages]);
}

function agent_reply(): void {
    $me = agent_or_401();
    $j  = input_json();
    $sessionId = trim($j['session_id'] ?? '');
    $message   = trim($j['message']    ?? '');
    if ($sessionId === '' || $message === '') bad('Missing session_id or message', 400);

    $st = pdo()->prepare('SELECT status FROM chat_sessions WHERE id = ?');
    $st->execute([$sessionId]);
    $sess = $st->fetch();
    if (!$sess)                       bad('Session not found', 404);
    if ($sess['status'] !== 'active') bad('Session is closed', 409);

    pdo()->prepare(
        'INSERT INTO chat_messages (id, session_id, sender_type, sender_role, sender_name, message, created_at)
         VALUES (?, ?, "agent", ?, ?, ?, NOW())'
    )->execute([uuid(), $sessionId, $me['role'], $me['name'], $message]);

    pdo()->prepare(
        'UPDATE chat_sessions SET updated_at = NOW(), agent_name = ?, typing = "none" WHERE id = ?'
    )->execute([$me['name'], $sessionId]);

    respond(['ok' => true]);
}

function agent_close_session(): void {
    $me = agent_or_401();
    $j  = input_json();
    $sessionId = trim($j['session_id'] ?? '');
    if ($sessionId === '') bad('Missing session_id', 400);

    pdo()->prepare(
        'INSERT INTO chat_messages (id, session_id, sender_type, sender_name, message, created_at)
         VALUES (?, ?, "system", "System", "This chat has been closed. Thank you.", NOW())'
    )->execute([uuid(), $sessionId]);

    pdo()->prepare(
        'UPDATE chat_sessions SET status = "closed", closed_at = NOW(), updated_at = NOW() WHERE id = ?'
    )->execute([$sessionId]);

    respond(['ok' => true]);
}

function agent_typing(): void {
    agent_or_401();
    $j = input_json();
    $sessionId = trim($j['session_id'] ?? '');
    $typing    = !empty($j['typing']);
    if ($sessionId === '') respond(['ok' => true]);

    pdo()->prepare('UPDATE chat_sessions SET typing = ? WHERE id = ?')
        ->execute([$typing ? 'agent' : 'none', $sessionId]);

    respond(['ok' => true]);
}

function agent_agents_list(): void {
    agent_or_401();
    $st = pdo()->query(
        "SELECT email, name, role, status,
                TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS seconds_ago
           FROM chat_agents
          ORDER BY FIELD(status, 'online', 'away', 'offline'), name ASC"
    );
    respond(['ok' => true, 'agents' => $st->fetchAll()]);
}

function agent_offline_inbox(): void {
    agent_or_401();
    $limit = min((int)($_GET['limit'] ?? 25), 100);
    $st = pdo()->prepare(
        "SELECT id, from_email, subject, body, is_read, created_at
           FROM messages
          WHERE to_email = 'support@luxemodelcollective.com' AND subject LIKE 'Offline chat —%'
          ORDER BY created_at DESC LIMIT ?"
    );
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    respond(['ok' => true, 'messages' => $st->fetchAll()]);
}

function agent_mark_read(): void {
    agent_or_401();
    $j  = input_json();
    $id = (int)($j['id'] ?? 0);
    if ($id <= 0) bad('Missing id', 400);

    pdo()->prepare('UPDATE messages SET is_read = 1 WHERE id = ?')->execute([$id]);
    respond(['ok' => true]);
}

// Lightweight stats for dashboard notification widget
function chat_stats(): void {
    try {
        $st = pdo()->query("SELECT COUNT(*) AS n FROM chat_sessions WHERE status IN ('waiting','active')");
        $waiting = (int)($st->fetch()['n'] ?? 0);
        respond(['ok'=>true, 'waiting'=>$waiting]);
    } catch (Throwable $e) {
        respond(['ok'=>true, 'waiting'=>0]);
    }
}

