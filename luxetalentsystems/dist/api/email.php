<?php
require __DIR__.'/../cors.php';
require __DIR__.'/../config.php';
// LUXE_HMAC_SECRET is defined in api/antibot.php (Task 1). Loaded here so
// per-performer SMTP/IMAP passwords can be encrypted at rest with a key
// derived from it. If antibot.php is missing (older deploys), the settings
// endpoints still validate inputs but fail closed on encrypt/decrypt.
if (is_file(__DIR__.'/antibot.php')) { require_once __DIR__.'/antibot.php'; }

// ═══════════════════════════════════════════════════
// Email API — luxetalentsystems.com/api/email.php
// ═══════════════════════════════════════════════════


/**
 * AES-256-GCM helpers for storing mailbox passwords at rest.
 * Key is derived from LUXE_HMAC_SECRET via SHA-256. Output format:
 *   [12-byte IV][16-byte auth tag][ciphertext]
 * stored as a binary string in a VARBINARY column.
 *
 * If LUXE_HMAC_SECRET is not defined (antibot.php missing), these throw —
 * encrypted values are useless without it anyway.
 */
function luxe_mailpw_key(): string {
    if (!defined('LUXE_HMAC_SECRET')) {
        throw new RuntimeException('LUXE_HMAC_SECRET is not defined (antibot.php missing).');
    }
    // Derive a 256-bit AES key from the HMAC secret. Different domain
    // separator so this key never overlaps with HMAC signing material.
    return hash('sha256', 'luxe-mailpw-v1|' . LUXE_HMAC_SECRET, true);
}
function luxe_encrypt(string $plain): string {
    $key = luxe_mailpw_key();
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) throw new RuntimeException('Encrypt failed');
    return $iv . $tag . $ct;
}
function luxe_decrypt(string $blob): string {
    if (strlen($blob) < 28) throw new RuntimeException('Encrypted blob too short');
    $key = luxe_mailpw_key();
    $iv  = substr($blob, 0, 12);
    $tag = substr($blob, 12, 16);
    $ct  = substr($blob, 28);
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($pt === false) throw new RuntimeException('Decrypt failed (key changed or data corrupted)');
    return $pt;
}

const SMTP_HOST = 'talentmail.luxetalentsystems.com';
const SMTP_PORT = 587;
const SMTP_USER = 'support@luxetalentsystems.com';
const SMTP_PASS = 'Sonia@7700';
const SMTP_FROM = 'support@luxetalentsystems.com';
const SMTP_FROM_NAME = 'Luxe Talent';
const IMAP_HOST = 'talentmail.luxetalentsystems.com';
const IMAP_PORT = 993;
const IMAP_USER = 'support@luxetalentsystems.com';
const IMAP_PASS = 'Sonia@7700';
const IMAP_USER2 = 'support@luxemodelcollective.com';
const IMAP_PASS2 = 'Sonia@7700';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch($action) {
        case 'inbox':          handleImapInbox(); break;
        case 'read':           handleImapRead(); break;
        case 'set_read':       handleImapSetRead(); break;
        case 'reply_email':    handleImapReply(); break;
        case 'delete_email':   handleImapDelete(); break;
        case 'search_email':   handleImapSearch(); break;
        case 'folders':
            $account = $_GET['account'] ?? '';
            $creds   = perf_creds_from_request();
            $mbox    = getImapConnection('INBOX', $account, $creds);
            $ref     = imap_ref_for($creds);
            $folders = imap_list($mbox, $ref, '*');
            $result = [];
            if($folders) {
                foreach($folders as $f) {
                    $name = str_replace($ref, '', $f);
                    $status = @imap_status($mbox, $f, SA_MESSAGES|SA_UNSEEN);
                    $result[] = ['name'=>$name, 'total'=>$status?$status->messages:0, 'unread'=>$status?$status->unseen:0];
                }
            }
            imap_close($mbox);
            json_response($result);
            break;
        case 'create_folder':
            $json = json_decode(file_get_contents('php://input'), true);
            $name = trim($json['name'] ?? '');
            $account = $json['account'] ?? '';
            if(!$name) throw new RuntimeException('Folder name required');
            $creds = perf_creds_from_request($json);
            $mbox  = getImapConnection('INBOX', $account, $creds);
            $ref   = imap_ref_for($creds);
            $ok = @imap_createmailbox($mbox, imap_utf7_encode($ref.$name));
            if(!$ok) { imap_close($mbox); throw new RuntimeException('Failed: '.imap_last_error()); }
            @imap_subscribe($mbox, imap_utf7_encode($ref.$name));
            imap_close($mbox);
            json_response(['success'=>true,'folder'=>$name]);
            break;
        case 'delete_folder':
            $json = json_decode(file_get_contents('php://input'), true);
            $name = trim($json['name'] ?? '');
            $account = $json['account'] ?? '';
            if(!$name || in_array($name, ['INBOX','Sent','Drafts','Trash','Junk'])) throw new RuntimeException('Cannot delete this folder');
            $creds = perf_creds_from_request($json);
            $mbox  = getImapConnection('INBOX', $account, $creds);
            $ref   = imap_ref_for($creds);
            @imap_unsubscribe($mbox, imap_utf7_encode($ref.$name));
            $ok = @imap_deletemailbox($mbox, imap_utf7_encode($ref.$name));
            imap_close($mbox);
            if(!$ok) throw new RuntimeException('Failed: '.imap_last_error());
            json_response(['success'=>true]);
            break;
        case 'move_email':
            $json = json_decode(file_get_contents('php://input'), true);
            $uid = (int)($json['uid'] ?? 0);
            $folder = trim($json['folder'] ?? '');
            $account = $json['account'] ?? '';
            if(!$uid || !$folder) throw new RuntimeException('Missing uid or folder');
            $creds = perf_creds_from_request($json);
            $mbox  = getImapConnection('INBOX', $account, $creds);
            imap_mail_move($mbox, (string)$uid, $folder, CP_UID);
            imap_expunge($mbox);
            imap_close($mbox);
            json_response(['success'=>true]);
            break;
        case 'send':           handleSend(); break;
        case 'send_template':  handleSendTemplate(); break;
        case 'send_bulk':      handleSendBulk(); break;
        case 'templates':      handleTemplates(); break;
        case 'save_template':  handleSaveTemplate(); break;
        case 'delete_template': handleDeleteTemplate(); break;
        case 'history':        handleHistory(); break;
        case 'stats':          handleEmailStats(); break;
        case 'test':           handleTest(); break;
        // ── Per-performer email-account settings (Settings tab) ──
        case 'settings_get':   handleSettingsGet();  break;
        case 'settings_save':  handleSettingsSave(); break;
        case 'settings_test':  handleSettingsTest(); break;
        default: json_response(['success'=>false,'error'=>'Invalid action'],400);
    }
} catch(Throwable $e) {
    error_log('[email] '.$e->getMessage());
    json_response(['success'=>false,'error'=>$e->getMessage()],400);
}

// ── Send single email ──
function handleSend(): void {
    $json = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $to = trim($json['to'] ?? '');
    $subject = trim($json['subject'] ?? '');
    $body = trim($json['body'] ?? '');
    $html = isset($json['html']) ? (bool)$json['html'] : true;
    if(!$to || !$subject || !$body) throw new RuntimeException('Missing: to, subject, body');

    // Optional per-performer override + optional CC list
    $creds = perf_creds_from_request($json);
    $cc = [];
    if (!empty($json['cc'])) {
        $raw = is_array($json['cc']) ? $json['cc'] : preg_split('/[,;\s]+/', (string)$json['cc']);
        foreach ($raw as $c) {
            $c = trim($c);
            if ($c !== '' && filter_var($c, FILTER_VALIDATE_EMAIL)) $cc[] = $c;
        }
    }

    $result = smtpSend($to, $subject, $body, $html, $creds, $cc);

    // Log
    $pdo = getPDO();
    $pdo->prepare('INSERT INTO email_log (to_email,subject,body,status,error_msg) VALUES (?,?,?,?,?)')->execute([
        $to, $subject, $body, $result['success']?'sent':'failed', $result['error']??''
    ]);

    json_response($result);
}

// ── Send using template ──
function handleSendTemplate(): void {
    $json = json_decode(file_get_contents('php://input'), true);
    $to = trim($json['to'] ?? '');
    $templateId = (int)($json['template_id'] ?? 0);
    $vars = $json['variables'] ?? [];
    if(!$to || !$templateId) throw new RuntimeException('Missing: to, template_id');

    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT * FROM email_templates WHERE id=?');
    $stmt->execute([$templateId]);
    $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$tpl) throw new RuntimeException('Template not found');

    // Replace variables
    $subject = $tpl['subject'];
    $body = $tpl['body'];
    foreach($vars as $k=>$v) {
        $subject = str_replace('{{'.$k.'}}', $v, $subject);
        $body = str_replace('{{'.$k.'}}', $v, $body);
    }

    // Also replace performer data if email matches
    $perf = $pdo->prepare('SELECT * FROM registration WHERE email=?');
    $perf->execute([strtolower($to)]);
    $p = $perf->fetch(PDO::FETCH_ASSOC);
    if($p) {
        $replacements = [
            '{{first_name}}'=>$p['first_name']??'',
            '{{last_name}}'=>$p['last_name']??'',
            '{{stage_name}}'=>$p['stage_name']??'',
            '{{email}}'=>$p['email']??'',
            '{{phone}}'=>$p['phone']??'',
            '{{status}}'=>$p['status']??'',
        ];
        foreach($replacements as $k=>$v) {
            $subject = str_replace($k, $v, $subject);
            $body = str_replace($k, $v, $body);
        }
    }

    $result = smtpSend($to, $subject, $body, true);

    $pdo->prepare('INSERT INTO email_log (to_email,subject,body,status,template_id,error_msg) VALUES (?,?,?,?,?,?)')->execute([
        $to, $subject, $body, $result['success']?'sent':'failed', $templateId, $result['error']??''
    ]);

    json_response($result);
}

// ── Send bulk ──
function handleSendBulk(): void {
    $json = json_decode(file_get_contents('php://input'), true);
    $emails = $json['emails'] ?? [];
    $subject = trim($json['subject'] ?? '');
    $body = trim($json['body'] ?? '');
    $templateId = (int)($json['template_id'] ?? 0);
    if(empty($emails)) throw new RuntimeException('Missing: emails');
    if(!$templateId && (!$subject || !$body)) throw new RuntimeException('Missing: subject+body or template_id');

    $pdo = getPDO();
    $tpl = null;
    if($templateId) {
        $stmt = $pdo->prepare('SELECT * FROM email_templates WHERE id=?');
        $stmt->execute([$templateId]);
        $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    $sent = 0; $failed = 0; $results = [];
    foreach($emails as $to) {
        $to = trim($to);
        if(!$to) continue;

        $subj = $subject;
        $bod = $body;
        if($tpl) { $subj = $tpl['subject']; $bod = $tpl['body']; }

        // Replace performer vars
        $perf = $pdo->prepare('SELECT * FROM registration WHERE email=?');
        $perf->execute([strtolower($to)]);
        $p = $perf->fetch(PDO::FETCH_ASSOC);
        if($p) {
            $reps = ['{{first_name}}'=>$p['first_name']??'','{{last_name}}'=>$p['last_name']??'','{{stage_name}}'=>$p['stage_name']??'','{{email}}'=>$p['email']??'','{{phone}}'=>$p['phone']??'','{{status}}'=>$p['status']??''];
            foreach($reps as $k=>$v) { $subj=str_replace($k,$v,$subj); $bod=str_replace($k,$v,$bod); }
        }

        $r = smtpSend($to, $subj, $bod, true);
        $pdo->prepare('INSERT INTO email_log (to_email,subject,body,status,template_id,error_msg) VALUES (?,?,?,?,?,?)')->execute([
            $to, $subj, $bod, $r['success']?'sent':'failed', $templateId, $r['error']??''
        ]);
        if($r['success']) $sent++; else $failed++;
        $results[] = ['email'=>$to,'status'=>$r['success']?'sent':'failed'];

        usleep(500000); // 0.5s delay between sends
    }

    json_response(['success'=>true,'sent'=>$sent,'failed'=>$failed,'results'=>$results]);
}

// ── Template CRUD ──
function handleTemplates(): void {
    $pdo = getPDO();
    $stmt = $pdo->query('SELECT * FROM email_templates ORDER BY name ASC');
    json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function handleSaveTemplate(): void {
    $json = json_decode(file_get_contents('php://input'), true);
    $id = (int)($json['id'] ?? 0);
    $name = trim($json['name'] ?? '');
    $subject = trim($json['subject'] ?? '');
    $body = trim($json['body'] ?? '');
    if(!$name || !$subject || !$body) throw new RuntimeException('Missing: name, subject, body');

    $pdo = getPDO();
    if($id) {
        $pdo->prepare('UPDATE email_templates SET name=?,subject=?,body=?,updated_at=NOW() WHERE id=?')->execute([$name,$subject,$body,$id]);
    } else {
        $pdo->prepare('INSERT INTO email_templates (name,subject,body) VALUES (?,?,?)')->execute([$name,$subject,$body]);
        $id = $pdo->lastInsertId();
    }
    json_response(['success'=>true,'id'=>$id]);
}

function handleDeleteTemplate(): void {
    $json = json_decode(file_get_contents('php://input'), true);
    $id = (int)($json['id'] ?? 0);
    if(!$id) throw new RuntimeException('Missing: id');
    $pdo = getPDO();
    $pdo->prepare('DELETE FROM email_templates WHERE id=?')->execute([$id]);
    json_response(['success'=>true]);
}

// ── History ──
function handleHistory(): void {
    $pdo = getPDO();
    $limit = min((int)($_GET['limit']??50), 200);
    $to = $_GET['to'] ?? '';
    if($to) {
        $stmt = $pdo->prepare('SELECT * FROM email_log WHERE to_email=? ORDER BY created_at DESC LIMIT ?');
        $stmt->execute([$to, $limit]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM email_log ORDER BY created_at DESC LIMIT ?');
        $stmt->execute([$limit]);
    }
    json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// ── Stats ──
function handleEmailStats(): void {
    $pdo = getPDO();
    json_response([
        'total'=>(int)$pdo->query('SELECT COUNT(*) FROM email_log')->fetchColumn(),
        'sent'=>(int)$pdo->query("SELECT COUNT(*) FROM email_log WHERE status='sent'")->fetchColumn(),
        'failed'=>(int)$pdo->query("SELECT COUNT(*) FROM email_log WHERE status='failed'")->fetchColumn(),
        'today'=>(int)$pdo->query("SELECT COUNT(*) FROM email_log WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
        'templates'=>(int)$pdo->query('SELECT COUNT(*) FROM email_templates')->fetchColumn(),
    ]);
}

// ── Test connection ──
function handleTest(): void {
    $result = smtpSend(SMTP_USER, 'SMTP Test', 'This is a test email from Luxe Talent System.', false);
    json_response($result);
}

// ═══════════════════════════════════════════════════
// SMTP SEND (raw socket — no external library needed)
// ═══════════════════════════════════════════════════
function smtpSend(string $to, string $subject, string $body, bool $html = true, ?array $creds = null, array $cc = []): array {
    // Resolve which mail server to use. Admin path uses the SMTP_* constants
    // exactly as before; performer path uses the values they entered on the
    // Settings tab. Default headers also differ — the performer "From:" must
    // match their own SMTP_FROM (most relays will reject otherwise).
    if ($creds !== null) {
        $host  = $creds['smtp_host'];  $port  = (int)$creds['smtp_port'];
        $enc   = $creds['smtp_encryption']; // 'starttls' | 'ssl' | 'none'
        $user  = $creds['smtp_user'];  $pass  = $creds['smtp_pass'];
        $from  = $creds['email_address'] ?: $user;
        $fromN = $creds['display_name']  ?: $from;
    } else {
        $host = SMTP_HOST; $port = SMTP_PORT; $enc = 'starttls';
        $user = SMTP_USER; $pass = SMTP_PASS;
        $from = SMTP_FROM; $fromN = SMTP_FROM_NAME;
    }

    $headers = "From: " . $fromN . " <" . $from . ">\r\n";
    $headers .= "Reply-To: " . $from . "\r\n";
    if ($cc) $headers .= "Cc: " . implode(', ', $cc) . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    if($html) {
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        // Wrap plain text body in HTML if it doesn't have tags. For the
        // performer-side path, drop the Luxe Model Collective letterhead —
        // performers are sending personal mail, not branded outreach.
        if(strpos($body, '<') === false) {
            if ($creds === null) {
                $body = '<html><body style="font-family:Arial,sans-serif;font-size:14px;color:#333;line-height:1.6">'
                    . '<div style="max-width:600px;margin:0 auto;padding:20px">'
                    . '<div style="text-align:center;padding:15px 0;border-bottom:2px solid #d4a830;margin-bottom:20px">'
                    . '<h2 style="color:#d4a830;margin:0">Luxe Model Collective</h2></div>'
                    . nl2br(htmlspecialchars($body))
                    . '<div style="margin-top:30px;padding-top:15px;border-top:1px solid #eee;font-size:12px;color:#999;text-align:center">'
                    . 'Luxe Model Collective | 100 M Street SE #600, Washington, DC 20003</div>'
                    . '</div></body></html>';
            } else {
                $body = '<html><body style="font-family:Arial,sans-serif;font-size:14px;color:#333;line-height:1.6">'
                    . '<div style="max-width:600px;margin:0 auto;padding:20px">'
                    . nl2br(htmlspecialchars($body))
                    . '</div></body></html>';
            }
        }
    } else {
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    }

    try {
        $errno = 0; $errstr = '';
        // SSL: open with ssl:// (port 465 typically).
        // STARTTLS: open with tcp://, then upgrade after EHLO.
        // None: tcp:// only, no upgrade.
        $proto = ($enc === 'ssl') ? 'ssl' : 'tcp';
        $socket = @stream_socket_client($proto.'://'.$host.':'.$port, $errno, $errstr, 15);
        if(!$socket) throw new RuntimeException("Connect failed: $errstr ($errno)");

        stream_set_timeout($socket, 15);
        smtpRead($socket); // greeting

        smtpCmd($socket, "EHLO luxetalentsystems.com");

        if ($enc === 'starttls') {
            smtpCmd($socket, "STARTTLS");
            if(!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                throw new RuntimeException('TLS failed');
            }
            smtpCmd($socket, "EHLO luxetalentsystems.com");
        }

        smtpCmd($socket, "AUTH LOGIN");
        smtpCmd($socket, base64_encode($user));
        $authResp = smtpCmd($socket, base64_encode($pass));

        if(strpos($authResp, '235') === false && strpos($authResp, '334') !== false) {
            throw new RuntimeException('Auth failed — check credentials or enable SMTP AUTH on the mail server');
        }

        smtpCmd($socket, "MAIL FROM:<".$from.">");
        smtpCmd($socket, "RCPT TO:<$to>");
        foreach ($cc as $ccAddr) {
            smtpCmd($socket, "RCPT TO:<".$ccAddr.">");
        }
        smtpCmd($socket, "DATA");

        $msg = "Subject: $subject\r\n$headers\r\n$body\r\n.";
        smtpCmd($socket, $msg);
        smtpCmd($socket, "QUIT");

        fclose($socket);
        return ['success'=>true,'message'=>'Email sent to '.$to];
    } catch(Throwable $e) {
        if(isset($socket) && is_resource($socket)) fclose($socket);
        return ['success'=>false,'error'=>$e->getMessage()];
    }
}

function smtpCmd($socket, string $cmd): string {
    fwrite($socket, $cmd."\r\n");
    return smtpRead($socket);
}

function smtpRead($socket): string {
    $response = '';
    while($line = fgets($socket, 515)) {
        $response .= $line;
        if(isset($line[3]) && ($line[3]===' '||$line[3]==="\r")) break;
    }
    return $response;
}

// ═══ Helpers ═══

function getImapConnection($folder = 'INBOX', $account = '', ?array $creds = null) {
    if ($creds !== null) {
        // Per-performer override path. The performer entered host/port/etc
        // in the dashboard Settings tab; use those instead of the admin
        // SMTP_HOST / IMAP_HOST constants. Keeps the admin path identical
        // when $creds is null (the default).
        $host = $creds['imap_host']; $port = (int)$creds['imap_port'];
        $flag = ($creds['imap_encryption'] === 'starttls') ? '/imap/tls/novalidate-cert'
              : (($creds['imap_encryption'] === 'none')    ? '/imap/notls'
              :                                              '/imap/ssl/novalidate-cert');
        $mailbox = '{' . $host . ':' . $port . $flag . '}' . $folder;
        $mbox = @imap_open($mailbox, $creds['imap_user'], $creds['imap_pass']);
        if (!$mbox) throw new RuntimeException('IMAP failed: ' . imap_last_error());
        return $mbox;
    }
    $mailbox = '{'.IMAP_HOST.':'.IMAP_PORT.'/imap/ssl/novalidate-cert}'.$folder;
    // Default mailbox is support@luxemodelcollective.com.
    // Pass account='luxetalentsystems' to read the talentsystems mailbox instead.
    $useTalent = ($account === 'luxetalentsystems');
    $user = $useTalent ? IMAP_USER : IMAP_USER2;
    $pass = $useTalent ? IMAP_PASS : IMAP_PASS2;
    $mbox = @imap_open($mailbox, $user, $pass);
    if(!$mbox) throw new RuntimeException('IMAP failed: '.imap_last_error());
    return $mbox;
}

function handleImapInbox(): void {
    $folder = $_GET['folder'] ?? 'INBOX';
    $limit = min((int)($_GET['limit'] ?? 25), 50);
    $filter = $_GET['filter'] ?? '';
    $creds = perf_creds_from_request();
    $mbox = getImapConnection($folder, '', $creds);
    $info = imap_check($mbox);
    $total = $info->Nmsgs;
    if($total == 0) { imap_close($mbox); json_response(['messages'=>[],'total'=>0,'unread'=>0]); return; }
    $start = max(1, $total - $limit + 1);
    $overview = imap_fetch_overview($mbox, "$start:$total", 0);
    usort($overview, function($a,$b){ return strtotime($b->date) - strtotime($a->date); });
    if($filter === 'unread') $overview = array_filter($overview, fn($m) => !$m->seen);
    elseif($filter === 'read') $overview = array_filter($overview, fn($m) => $m->seen);
    $messages = [];
    foreach($overview as $msg) {
        $messages[] = [
            'uid' => $msg->uid, 'msgno' => $msg->msgno,
            'subject' => isset($msg->subject) ? imap_utf8($msg->subject) : '(no subject)',
            'from' => isset($msg->from) ? imap_utf8($msg->from) : '',
            'date' => $msg->date ?? '', 'is_read' => (bool)($msg->seen ?? false),
            'preview' => '', 'has_attachments' => false,
        ];
    }
    $unread = 0;
    $status = imap_status($mbox, imap_ref_for($creds).$folder, SA_UNSEEN);
    if($status) $unread = $status->unseen;
    imap_close($mbox);
    json_response(['messages'=>array_values($messages),'total'=>$total,'unread'=>$unread]);
}

function handleImapRead(): void {
    $uid = (int)($_GET['uid'] ?? 0);
    if(!$uid) throw new RuntimeException('Missing: uid');
    $folder = $_GET['folder'] ?? 'INBOX';
    $creds  = perf_creds_from_request();
    $mbox = getImapConnection($folder, '', $creds);
    $msgno = imap_msgno($mbox, $uid);
    if(!$msgno) { imap_close($mbox); throw new RuntimeException('Message not found'); }
    $header = imap_headerinfo($mbox, $msgno);
    $structure = imap_fetchstructure($mbox, $msgno);
    $body = '';
    if(isset($structure->parts)) {
        foreach($structure->parts as $i => $part) {
            if($part->subtype === 'HTML') {
                $body = imap_fetchbody($mbox, $msgno, $i+1);
                if($part->encoding == 3) $body = base64_decode($body);
                elseif($part->encoding == 4) $body = quoted_printable_decode($body);
                break;
            }
        }
        if(!$body) {
            $body = imap_fetchbody($mbox, $msgno, 1);
            if(isset($structure->parts[0]) && $structure->parts[0]->encoding == 3) $body = base64_decode($body);
            elseif(isset($structure->parts[0]) && $structure->parts[0]->encoding == 4) $body = quoted_printable_decode($body);
            $body = nl2br(htmlspecialchars($body));
        }
    } else {
        $body = imap_body($mbox, $msgno);
        if($structure->encoding == 3) $body = base64_decode($body);
        elseif($structure->encoding == 4) $body = quoted_printable_decode($body);
        if($structure->subtype !== 'HTML') $body = nl2br(htmlspecialchars($body));
    }
    imap_setflag_full($mbox, (string)$msgno, "\\Seen");
    $from = $header->from[0] ?? null;
    $fromStr = $from ? (isset($from->personal) ? imap_utf8($from->personal).' <'.$from->mailbox.'@'.$from->host.'>' : $from->mailbox.'@'.$from->host) : '';
    $to = $header->to[0] ?? null;
    $toStr = $to ? $to->mailbox.'@'.$to->host : '';
    imap_close($mbox);
    json_response(['uid'=>$uid,'subject'=>imap_utf8($header->subject??''),'from'=>$fromStr,'to'=>$toStr,'date'=>$header->date??'','body'=>$body]);
}

function handleImapSetRead(): void {
    $uid  = (int)($_GET['uid'] ?? $_POST['uid'] ?? 0);
    $read = (int)($_GET['read'] ?? $_POST['read'] ?? 1);
    if(!$uid) throw new RuntimeException('Missing: uid');
    $creds = perf_creds_from_request();
    $mbox = getImapConnection('INBOX', '', $creds);
    $msgno = imap_msgno($mbox, $uid);
    if(!$msgno) { imap_close($mbox); throw new RuntimeException('Message not found'); }
    if($read) imap_setflag_full($mbox, (string)$msgno, "\\Seen");
    else      imap_clearflag_full($mbox, (string)$msgno, "\\Seen");
    imap_close($mbox);
    json_response(['success'=>true,'uid'=>$uid,'read'=>(bool)$read]);
}

function handleImapReply(): void {
    $json = json_decode(file_get_contents('php://input'), true);
    $uid = (int)($json['uid'] ?? 0);
    $body = trim($json['body'] ?? '');
    $replyAll = !empty($json['reply_all']);
    if(!$uid || !$body) throw new RuntimeException('Missing: uid, body');
    $creds = perf_creds_from_request($json);
    $mbox = getImapConnection('INBOX', '', $creds);
    $msgno = imap_msgno($mbox, $uid);
    $header = imap_headerinfo($mbox, $msgno);
    $from = $header->from[0] ?? null;
    $to = $from ? $from->mailbox.'@'.$from->host : '';
    $subject = 'Re: '.imap_utf8($header->subject ?? '');

    // Reply-All: collect every address on the original To and Cc lines,
    // strip the original sender (already in $to) and our own outgoing
    // address (so we don't email ourselves a copy).
    $ccList = [];
    if ($replyAll) {
        $selfAddr = strtolower($creds ? (string)$creds['email_address'] : SMTP_FROM);
        $fromAddr = strtolower($to);
        $collect = function($arr) use (&$ccList, $selfAddr, $fromAddr) {
            if (!$arr) return;
            foreach ($arr as $a) {
                if (empty($a->mailbox) || empty($a->host)) continue;
                $addr = strtolower($a->mailbox.'@'.$a->host);
                if ($addr === $selfAddr || $addr === $fromAddr) continue;
                if (!in_array($addr, $ccList, true)) $ccList[] = $addr;
            }
        };
        $collect($header->to ?? []);
        $collect($header->cc ?? []);
    }
    imap_close($mbox);
    if(!$to) throw new RuntimeException('Cannot determine reply address');

    $result = smtpSend($to, $subject, $body, true, $creds, $ccList);
    $pdo = getPDO();
    $pdo->prepare('INSERT INTO email_log (to_email,subject,body,status) VALUES (?,?,?,?)')
        ->execute([$to.($ccList?' (+ '.count($ccList).' CC)':''), $subject, $body, $result['success']?'sent':'failed']);
    json_response($result);
}

function handleImapDelete(): void {
    $json = json_decode(file_get_contents('php://input'), true) ?: $_GET;
    $uid = (int)($json['uid'] ?? $_GET['uid'] ?? 0);
    $folder = $json['folder'] ?? $_GET['folder'] ?? 'INBOX';
    if(!$uid) throw new RuntimeException('Missing: uid');
    $creds = perf_creds_from_request($json);
    $mbox = getImapConnection($folder, '', $creds);
    imap_delete($mbox, (string)$uid, FT_UID);
    imap_expunge($mbox);
    imap_close($mbox);
    json_response(['success'=>true]);
}

function handleImapSearch(): void {
    $q = $_GET['q'] ?? '';
    if(!$q) throw new RuntimeException('Missing: q');
    $creds = perf_creds_from_request();
    $mbox = getImapConnection('INBOX', '', $creds);
    $results = imap_search($mbox, 'TEXT "'.addslashes($q).'"');
    if(!$results) { imap_close($mbox); json_response(['messages'=>[]]); return; }
    $results = array_reverse($results);
    $results = array_slice($results, 0, 20);
    $messages = [];
    foreach($results as $msgno) {
        $header = imap_headerinfo($mbox, $msgno);
        $ov = imap_fetch_overview($mbox, (string)$msgno, 0);
        $from = $header->from[0] ?? null;
        $fromStr = $from ? (isset($from->personal) ? imap_utf8($from->personal) : $from->mailbox.'@'.$from->host) : '';
        $messages[] = ['uid'=>$ov[0]->uid??0,'subject'=>imap_utf8($header->subject??''),'from'=>$fromStr,'date'=>$header->date??'','is_read'=>(bool)($ov[0]->seen??false)];
    }
    imap_close($mbox);
    json_response(['messages'=>$messages,'query'=>$q]);
}


function getPDO(): PDO {
    static $pdo = null;
    if(!$pdo) $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    return $pdo;
}

/* ═══════════════════════════════════════════════════════════════
   Per-performer email account — helpers + Settings tab endpoints
   ═══════════════════════════════════════════════════════════════
   Each performer enters their own SMTP/IMAP credentials on the
   dashboard Settings tab (performer_email_settings table). When
   the performer Email module calls email.php with ?performer=<email>,
   their saved credentials are loaded here and threaded through
   getImapConnection() and smtpSend(). When ?performer= is absent the
   admin path runs exactly as before.
   Passwords are encrypted at rest with AES-256-GCM keyed from
   LUXE_HMAC_SECRET (the same secret used by antibot.php).
*/

/** Resolve a per-performer credential bundle from the current request.
 *  Returns null if no performer= was supplied (admin path).
 *  $bodyJson is the already-decoded request body (for POST JSON handlers).
 *
 *  The performer only enters their display name + email address. Mail server
 *  details (host/port/encryption) are hard-pinned to the company mail server
 *  (SMTP_HOST/IMAP_HOST in this file), and the mailbox password is the
 *  performer's registration password from the `registration.plain_password`
 *  column. Everything else is invisible to them. */
function perf_creds_from_request($bodyJson = null): ?array {
    $email = '';
    if (isset($_GET['performer']))  $email = $_GET['performer'];
    elseif (isset($_POST['performer'])) $email = $_POST['performer'];
    elseif (is_array($bodyJson) && isset($bodyJson['performer'])) $email = $bodyJson['performer'];
    $email = strtolower(trim((string)$email));
    if ($email === '') return null;

    $pdo = getPDO();
    $st = $pdo->prepare('SELECT display_name, email_address, mailbox_password FROM performer_email_settings WHERE email=?');
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['email_address'])) {
        throw new RuntimeException('Email is not configured. Open Settings and save your name and email address.');
    }
    $addr = (string)$row['email_address'];

    // Prefer the encrypted mailbox password from performer_email_settings.
    // Falls back to registration.plain_password ONLY if the encrypted field
    // is empty (migration window).
    $plain = '';
    if (!empty($row['mailbox_password'])) {
        try { $plain = luxe_decrypt($row['mailbox_password']); }
        catch (Throwable $e) { error_log('luxe_decrypt failed for ' . $email . ': ' . $e->getMessage()); $plain = ''; }
    }
    if ($plain === '') {
        $pw = $pdo->prepare('SELECT plain_password FROM registration WHERE email=?');
        $pw->execute([$email]);
        $plain = (string)($pw->fetchColumn() ?: '');
    }
    if ($plain === '') {
        throw new RuntimeException('Mailbox password is not on file. Open Settings and enter your mailbox password (same as Roundcube).');
    }

    return [
        'display_name'    => (string)$row['display_name'],
        'email_address'   => $addr,
        // Hard-pinned to the company mail server. Same host serves both
        // @luxetalentsystems.com and @luxemodelcollective.com mailboxes.
        'smtp_host'       => SMTP_HOST,
        'smtp_port'       => SMTP_PORT,
        'smtp_encryption' => 'starttls',
        'smtp_user'       => $addr,
        'smtp_pass'       => $plain,
        'imap_host'       => IMAP_HOST,
        'imap_port'       => IMAP_PORT,
        'imap_encryption' => 'ssl',
        'imap_user'       => $addr,
        'imap_pass'       => $plain,
    ];
}

/** Build the IMAP mailbox reference string for either creds bundle or admin defaults. */
function imap_ref_for(?array $creds): string {
    if ($creds === null) {
        return '{'.IMAP_HOST.':'.IMAP_PORT.'/imap/ssl/novalidate-cert}';
    }
    $flag = ($creds['imap_encryption'] === 'starttls') ? '/imap/tls/novalidate-cert'
          : (($creds['imap_encryption'] === 'none')    ? '/imap/notls'
          :                                              '/imap/ssl/novalidate-cert');
    return '{' . $creds['imap_host'] . ':' . $creds['imap_port'] . $flag . '}';
}

/* ── Password encryption (AES-256-GCM keyed from LUXE_HMAC_SECRET) ── */
function pw_key(): string {
    if (!defined('LUXE_HMAC_SECRET')) {
        throw new RuntimeException('LUXE_HMAC_SECRET is not defined (api/antibot.php missing).');
    }
    return hash('sha256', 'pes:' . LUXE_HMAC_SECRET, true); // 32 raw bytes
}

function encrypt_pw(string $plain): string {
    if ($plain === '') return '';
    $key   = pw_key();
    $nonce = random_bytes(12);
    $tag   = '';
    $ct    = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ct === false) throw new RuntimeException('encrypt failed');
    return $nonce . $ct . $tag;
}

function decrypt_pw($blob): ?string {
    if ($blob === null || $blob === '') return '';     // empty is legal — "not set yet"
    if (strlen($blob) < 12 + 16 + 1) return null;
    $key   = pw_key();
    $nonce = substr($blob, 0, 12);
    $tag   = substr($blob, -16);
    $ct    = substr($blob, 12, -16);
    $pt    = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    return ($pt === false) ? null : $pt;
}

/** Validate that an entered performer email exists in the registration
 *  table. Same trust model as the rest of the project (no server-side
 *  session yet — see PERFORMER-EMAIL-SETUP.md "Security note"). */
function perf_lookup_email(): string {
    $bodyJson = json_decode(file_get_contents('php://input'), true);
    $e = strtolower(trim((string)(
        $_GET['performer']      ??
        $_POST['performer']     ??
        ($bodyJson['performer'] ?? '')
    )));
    if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Missing or invalid performer email');
    }
    $pdo = getPDO();
    $st = $pdo->prepare('SELECT 1 FROM registration WHERE email=?');
    $st->execute([$e]);
    if (!$st->fetchColumn()) throw new RuntimeException('Performer not found');
    return $e;
}

/** GET /api/email.php?action=settings_get&performer=<email>
 *  Returns the performer's display name and email address. Mail server
 *  details are hardcoded on the server (see perf_creds_from_request) and
 *  are not exposed to the client. */
function handleSettingsGet(): void {
    $email = perf_lookup_email();
    $pdo = getPDO();
    $st = $pdo->prepare('SELECT display_name, email_address, mailbox_password FROM performer_email_settings WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        json_response([
            'configured'           => false,
            'display_name'         => '',
            'email_address'        => '',
            'mailbox_password_set' => false,
        ]);
        return;
    }
    json_response([
        'configured'           => !empty($row['email_address']),
        'display_name'         => (string)$row['display_name'],
        'email_address'        => (string)$row['email_address'],
        'mailbox_password_set' => !empty($row['mailbox_password']),
    ]);
}

/** POST /api/email.php?action=settings_save
 *  Body JSON: { performer:<email>, display_name, email_address }
 *  Mail server settings are hardcoded server-side, so the performer only
 *  ever sends these two fields. */
function handleSettingsSave(): void {
    $json  = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $email = perf_lookup_email();

    $display = substr(trim((string)($json['display_name']  ?? '')), 0, 120);
    $addr    = substr(strtolower(trim((string)($json['email_address'] ?? ''))), 0, 190);
    $mailpw  = (string)($json['mailbox_password'] ?? '');

    if ($addr === '' || !filter_var($addr, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('A valid email address is required.');
    }

    $pdo = getPDO();

    if ($mailpw !== '') {
        // Mailbox password supplied — encrypt and update everything.
        $enc = luxe_encrypt($mailpw);
        $stmt = $pdo->prepare(
            'INSERT INTO performer_email_settings (email, display_name, email_address, mailbox_password)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE display_name     = VALUES(display_name),
                                     email_address    = VALUES(email_address),
                                     mailbox_password = VALUES(mailbox_password)'
        );
        $stmt->execute([$email, $display, $addr, $enc]);
    } else {
        // No mailbox password in this save — leave the existing one untouched.
        $stmt = $pdo->prepare(
            'INSERT INTO performer_email_settings (email, display_name, email_address)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE display_name  = VALUES(display_name),
                                     email_address = VALUES(email_address)'
        );
        $stmt->execute([$email, $display, $addr]);
    }

    json_response(['success' => true]);
}

/** GET /api/email.php?action=settings_test&performer=<email>
 *  Opens the IMAP mailbox + SMTP socket using the saved settings and
 *  returns a per-protocol pass/fail. Used by the Settings tab "Test" button. */
function handleSettingsTest(): void {
    $email = perf_lookup_email();
    $creds = perf_creds_from_request();
    if ($creds === null) throw new RuntimeException('No settings saved for '.$email);

    $imapOk = false; $imapErr = '';
    try {
        $mbox = getImapConnection('INBOX', '', $creds);
        $imapOk = (bool)$mbox;
        if ($mbox) imap_close($mbox);
    } catch (Throwable $e) { $imapErr = $e->getMessage(); }

    // SMTP test: connect + EHLO + (optional STARTTLS) + AUTH, then QUIT.
    // We do NOT send a probe email — connect + auth is enough.
    $smtpOk = false; $smtpErr = '';
    try {
        $proto = ($creds['smtp_encryption'] === 'ssl') ? 'ssl' : 'tcp';
        $errno = 0; $errstr = '';
        $sock = @stream_socket_client($proto.'://'.$creds['smtp_host'].':'.$creds['smtp_port'], $errno, $errstr, 10);
        if (!$sock) throw new RuntimeException("Connect failed: $errstr ($errno)");
        stream_set_timeout($sock, 10);
        smtpRead($sock);
        smtpCmd($sock, "EHLO luxetalentsystems.com");
        if ($creds['smtp_encryption'] === 'starttls') {
            smtpCmd($sock, "STARTTLS");
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                throw new RuntimeException('STARTTLS upgrade failed');
            }
            smtpCmd($sock, "EHLO luxetalentsystems.com");
        }
        smtpCmd($sock, "AUTH LOGIN");
        smtpCmd($sock, base64_encode($creds['smtp_user']));
        $resp = smtpCmd($sock, base64_encode($creds['smtp_pass']));
        if (strpos($resp, '235') === false) throw new RuntimeException('SMTP authentication rejected');
        smtpCmd($sock, "QUIT");
        fclose($sock);
        $smtpOk = true;
    } catch (Throwable $e) {
        if (isset($sock) && is_resource($sock)) fclose($sock);
        $smtpErr = $e->getMessage();
    }

    json_response([
        'imap' => ['ok' => $imapOk, 'error' => $imapErr],
        'smtp' => ['ok' => $smtpOk, 'error' => $smtpErr],
    ]);
}
