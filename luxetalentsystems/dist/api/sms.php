<?php
require __DIR__ . '/../cors.php';
require __DIR__ . '/comm-config.php';

/* ═══════════════════════════════════════════════════════════════
   Twilio SMS — /api/sms.php   (credentials from comm-config.php)
   send | send_bulk | history | conversation | webhook | auto_confirm | stats
   Table: sms_messages (direction,phone,body,twilio_sid,status,performer_email,created_at)
   ═══════════════════════════════════════════════════════════════ */

const SMS_ENDPOINT = TW_API . '/Messages.json';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'send':         handleSend();        break;
        case 'send_bulk':    handleSendBulk();     break;
        case 'history':      handleHistory();      break;
        case 'conversation': handleConversation(); break;
        case 'webhook':      handleWebhook();      break;
        case 'auto_confirm': handleAutoConfirm();  break;
        case 'stats':        handleStats();        break;
        default: json_response(['success' => false, 'error' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    error_log('[sms] ' . $e->getMessage());
    json_response(['success' => false, 'error' => $e->getMessage()], 400);
}

/* ── Send one SMS ── */
function handleSend(): void {
    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $to = comm_e164($in['to'] ?? '');
    $body = trim($in['body'] ?? '');
    if (!$to || !$body) throw new RuntimeException('Missing: to, body');

    $r = sendSMS($to, $body);
    $pdo = db();
    $email = $in['performer_email'] ?? comm_email_by_phone($pdo, $to) ?? '';
    $st = $pdo->prepare('INSERT INTO sms_messages (direction,phone,body,twilio_sid,status,performer_email) VALUES (?,?,?,?,?,?)');
    $st->execute(['outbound', $to, $body, $r['sid'] ?? '', $r['status'] ?? 'sent', $email]);
    json_response(['success' => true, 'sid' => $r['sid'] ?? '', 'status' => $r['status'] ?? '']);
}

/* ── Bulk SMS to performers by email list ── */
function handleSendBulk(): void {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $body = trim($in['body'] ?? '');
    $emails = $in['emails'] ?? [];
    if (!$body) throw new RuntimeException('Missing: body');
    if (!$emails) throw new RuntimeException('Missing: emails');

    $pdo = db();
    $sent = 0; $failed = 0; $results = [];
    foreach ($emails as $email) {
        $st = $pdo->prepare('SELECT phone FROM registration WHERE email=? AND phone IS NOT NULL AND phone!=""');
        $st->execute([strtolower($email)]);
        $phone = $st->fetchColumn();
        if (!$phone) { $failed++; $results[] = ['email'=>$email,'status'=>'no phone']; continue; }
        $clean = comm_e164($phone);
        if (strlen(preg_replace('/[^0-9]/','',$clean)) < 10) { $failed++; $results[] = ['email'=>$email,'status'=>'invalid phone']; continue; }

        $r = sendSMS($clean, $body);
        $log = $pdo->prepare('INSERT INTO sms_messages (direction,phone,body,twilio_sid,status,performer_email) VALUES (?,?,?,?,?,?)');
        $log->execute(['outbound', $clean, $body, $r['sid'] ?? '', $r['status'] ?? '', $email]);
        $sent++;
        $results[] = ['email'=>$email,'phone'=>$clean,'status'=>$r['status'] ?? 'sent'];
    }
    json_response(['success'=>true,'sent'=>$sent,'failed'=>$failed,'results'=>$results]);
}

/* ── History (all / by phone / by email) ── */
function handleHistory(): void {
    $pdo = db();
    $phone = $_GET['phone'] ?? '';
    $email = $_GET['email'] ?? '';
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    if ($phone) {
        $st = $pdo->prepare('SELECT * FROM sms_messages WHERE phone=? ORDER BY created_at DESC LIMIT ' . (int)$limit);
        $st->execute([preg_replace('/[^+0-9]/','',$phone)]);
    } elseif ($email) {
        $st = $pdo->prepare('SELECT * FROM sms_messages WHERE performer_email=? ORDER BY created_at DESC LIMIT ' . (int)$limit);
        $st->execute([strtolower($email)]);
    } else {
        $st = $pdo->query('SELECT * FROM sms_messages ORDER BY created_at DESC LIMIT ' . (int)$limit);
    }
    json_response($st->fetchAll(PDO::FETCH_ASSOC));
}

/* ── Conversation thread with one number ── */
function handleConversation(): void {
    $pdo = db();
    $phone = preg_replace('/[^+0-9]/','',$_GET['phone'] ?? '');
    if (!$phone) throw new RuntimeException('Missing: phone');
    $limit = min((int)($_GET['limit'] ?? 100), 500);

    $st = $pdo->prepare('SELECT * FROM sms_messages WHERE phone=? ORDER BY created_at ASC LIMIT ' . (int)$limit);
    $st->execute([$phone]);
    $messages = $st->fetchAll(PDO::FETCH_ASSOC);

    $info = $pdo->prepare('SELECT email,first_name,last_name,stage_name,phone FROM registration WHERE phone LIKE ? LIMIT 1');
    $info->execute(['%'.substr($phone,-10).'%']);
    $performer = $info->fetch(PDO::FETCH_ASSOC) ?: null;

    json_response(['messages'=>$messages, 'performer'=>$performer]);
}

/* ── Inbound SMS webhook (set as the number's Messaging webhook) ── */
function handleWebhook(): void {
    $from = $_POST['From'] ?? '';
    $body = $_POST['Body'] ?? '';
    $sid  = $_POST['MessageSid'] ?? '';
    if (!$from || !$body) { header('Content-Type: text/xml'); echo '<Response></Response>'; return; }

    $pdo = db();
    $email = comm_email_by_phone($pdo, $from) ?? '';
    $st = $pdo->prepare('INSERT INTO sms_messages (direction,phone,body,twilio_sid,status,performer_email) VALUES (?,?,?,?,?,?)');
    $st->execute(['inbound', $from, $body, $sid, 'received', $email]);

    // No auto-reply by default (avoids surprise outbound charges). Just acknowledge.
    header('Content-Type: text/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
}

/* ── Registration confirmation SMS ── */
function handleAutoConfirm(): void {
    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $phone = comm_e164($in['phone'] ?? '');
    $name  = trim($in['name'] ?? '');
    $email = trim($in['email'] ?? '');
    if (!$phone) throw new RuntimeException('Missing: phone');

    $body = "Welcome to Luxe Model Collective" . ($name ? ", $name" : "") . "! "
          . "Your registration has been received. "
          . "Login at https://luxetalentsystems.com/login.html"
          . ($email ? " ($email)" : "") . ".";

    $r = sendSMS($phone, $body);
    $pdo = db();
    $st = $pdo->prepare('INSERT INTO sms_messages (direction,phone,body,twilio_sid,status,performer_email) VALUES (?,?,?,?,?,?)');
    $st->execute(['outbound', $phone, $body, $r['sid'] ?? '', $r['status'] ?? '', $email]);
    json_response(['success'=>true,'sid'=>$r['sid'] ?? '']);
}

/* ── Stats ── */
function handleStats(): void {
    $pdo = db();
    $total    = (int)$pdo->query('SELECT COUNT(*) FROM sms_messages')->fetchColumn();
    $inbound  = (int)$pdo->query("SELECT COUNT(*) FROM sms_messages WHERE direction='inbound'")->fetchColumn();
    $outbound = (int)$pdo->query("SELECT COUNT(*) FROM sms_messages WHERE direction='outbound'")->fetchColumn();
    $today    = (int)$pdo->query("SELECT COUNT(*) FROM sms_messages WHERE DATE(created_at)=CURDATE()")->fetchColumn();
    $unique   = (int)$pdo->query('SELECT COUNT(DISTINCT phone) FROM sms_messages')->fetchColumn();
    json_response(['total'=>$total,'inbound'=>$inbound,'outbound'=>$outbound,'today'=>$today,'unique_contacts'=>$unique]);
}

/* ── Helper: send via Twilio Messages API ── */
function sendSMS(string $to, string $body): array {
    [$code, $data] = tw_post(SMS_ENDPOINT, ['From'=>TW_FROM, 'To'=>$to, 'Body'=>$body]);
    if ($code >= 400) throw new RuntimeException($data['message'] ?? 'Twilio error (HTTP ' . $code . ')');
    return $data;
}
