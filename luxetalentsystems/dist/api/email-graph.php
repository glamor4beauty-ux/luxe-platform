<?php
require __DIR__.'/../cors.php';
require __DIR__.'/../config.php';

// ═══════════════════════════════════════════════════
// Microsoft Graph Email API
// luxetalentsystems.com/api/email-graph.php
// ═══════════════════════════════════════════════════

// ── CONFIGURE THESE ──
const GRAPH_TENANT_ID     = 'YOUR_TENANT_ID';
const GRAPH_CLIENT_ID     = 'YOUR_CLIENT_ID';
const GRAPH_CLIENT_SECRET = 'YOUR_CLIENT_SECRET';
const GRAPH_MAILBOX       = 'support@luxemodelcollective.com';
const GRAPH_TOKEN_FILE    = '/tmp/graph_token.json';

const GRAPH_API = 'https://graph.microsoft.com/v1.0';
const GRAPH_AUTH = 'https://login.microsoftonline.com/'.GRAPH_TENANT_ID.'/oauth2/v2.0/token';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch($action) {
        case 'inbox':          handleInbox(); break;
        case 'read':           handleRead(); break;
        case 'send':           handleSend(); break;
        case 'reply':          handleReply(); break;
        case 'delete':         handleDelete(); break;
        case 'search':         handleSearch(); break;
        case 'folders':        handleFolders(); break;
        case 'mark_read':      handleMarkRead(); break;
        case 'mark_unread':    handleMarkUnread(); break;
        case 'send_template':  handleSendTemplate(); break;
        case 'templates':      handleTemplates(); break;
        case 'save_template':  handleSaveTemplate(); break;
        case 'delete_template': handleDeleteTemplate(); break;
        case 'stats':          handleStats(); break;
        case 'test':           handleTest(); break;
        default: json_response(['success'=>false,'error'=>'Invalid action'],400);
    }
} catch(Throwable $e) {
    error_log('[graph-email] '.$e->getMessage());
    json_response(['success'=>false,'error'=>$e->getMessage()],400);
}

// ═══════════════════════════════════════════════════
// AUTH — Client Credentials Flow (no user interaction)
// ═══════════════════════════════════════════════════
function getGraphToken(): string {
    // Check cache
    if(file_exists(GRAPH_TOKEN_FILE)) {
        $cached = json_decode(file_get_contents(GRAPH_TOKEN_FILE), true);
        if($cached && isset($cached['access_token']) && $cached['expires_at'] > time() + 60) {
            return $cached['access_token'];
        }
    }

    $ch = curl_init(GRAPH_AUTH);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id'     => GRAPH_CLIENT_ID,
            'client_secret' => GRAPH_CLIENT_SECRET,
            'scope'         => 'https://graph.microsoft.com/.default',
            'grant_type'    => 'client_credentials',
        ]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if(!isset($data['access_token'])) {
        $err = $data['error_description'] ?? $data['error'] ?? 'Unknown auth error';
        throw new RuntimeException('Graph auth failed: '.$err);
    }

    // Cache
    $data['expires_at'] = time() + ($data['expires_in'] ?? 3600);
    file_put_contents(GRAPH_TOKEN_FILE, json_encode($data));
    return $data['access_token'];
}

function graphRequest(string $method, string $endpoint, ?array $body = null, bool $fullUrl = false): array {
    $token = getGraphToken();
    $url = $fullUrl ? $endpoint : GRAPH_API.'/users/'.GRAPH_MAILBOX.$endpoint;

    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer '.$token,
        'Content-Type: application/json',
        'Prefer: outlook.body-content-type="html"',
    ];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    if($body && in_array($method, ['POST','PATCH'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if($httpCode === 401) {
        // Token expired, retry once
        @unlink(GRAPH_TOKEN_FILE);
        $token = getGraphToken();
        $ch2 = curl_init($url);
        $headers[0] = 'Authorization: Bearer '.$token;
        curl_setopt_array($ch2, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);
        if($body && in_array($method, ['POST','PATCH'])) {
            curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $response = curl_exec($ch2);
        $httpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
    }

    if($httpCode === 204) return ['success'=>true]; // No content (delete, mark read)
    $data = json_decode($response, true);
    if($httpCode >= 400) {
        $err = $data['error']['message'] ?? 'HTTP '.$httpCode;
        throw new RuntimeException($err);
    }
    return $data ?: [];
}

// ═══════════════════════════════════════════════════
// INBOX — List messages
// ═══════════════════════════════════════════════════
function handleInbox(): void {
    $folder = $_GET['folder'] ?? 'inbox';
    $top = min((int)($_GET['limit'] ?? 25), 50);
    $skip = (int)($_GET['skip'] ?? 0);
    $filter = $_GET['filter'] ?? ''; // unread, read, all

    $endpoint = '/mailFolders/'.$folder.'/messages?$top='.$top.'&$skip='.$skip;
    $endpoint .= '&$orderby=receivedDateTime desc';
    $endpoint .= '&$select=id,subject,from,toRecipients,receivedDateTime,isRead,bodyPreview,hasAttachments,importance';

    if($filter === 'unread') $endpoint .= '&$filter=isRead eq false';
    elseif($filter === 'read') $endpoint .= '&$filter=isRead eq true';

    $data = graphRequest('GET', $endpoint);

    $messages = [];
    foreach(($data['value'] ?? []) as $msg) {
        $messages[] = [
            'id'          => $msg['id'],
            'subject'     => $msg['subject'] ?? '(no subject)',
            'from'        => $msg['from']['emailAddress']['address'] ?? '',
            'from_name'   => $msg['from']['emailAddress']['name'] ?? '',
            'to'          => array_map(fn($r) => $r['emailAddress']['address'] ?? '', $msg['toRecipients'] ?? []),
            'date'        => $msg['receivedDateTime'] ?? '',
            'is_read'     => $msg['isRead'] ?? false,
            'preview'     => $msg['bodyPreview'] ?? '',
            'has_attachments' => $msg['hasAttachments'] ?? false,
            'importance'  => $msg['importance'] ?? 'normal',
        ];
    }

    $total = $data['@odata.count'] ?? count($messages);
    $nextLink = $data['@odata.nextLink'] ?? null;

    json_response([
        'messages' => $messages,
        'total'    => $total,
        'has_more' => $nextLink !== null,
        'skip'     => $skip,
        'limit'    => $top,
    ]);
}

// ═══ Read single message ═══
function handleRead(): void {
    $id = $_GET['id'] ?? '';
    if(!$id) throw new RuntimeException('Missing: id');

    $data = graphRequest('GET', '/messages/'.$id.'?$select=id,subject,from,toRecipients,ccRecipients,receivedDateTime,isRead,body,hasAttachments,importance,conversationId');

    // Auto mark as read
    graphRequest('PATCH', '/messages/'.$id, ['isRead'=>true]);

    // Get attachments if any
    $attachments = [];
    if($data['hasAttachments'] ?? false) {
        $att = graphRequest('GET', '/messages/'.$id.'/attachments?$select=id,name,contentType,size');
        $attachments = $att['value'] ?? [];
    }

    json_response([
        'id'          => $data['id'],
        'subject'     => $data['subject'] ?? '',
        'from'        => $data['from']['emailAddress']['address'] ?? '',
        'from_name'   => $data['from']['emailAddress']['name'] ?? '',
        'to'          => array_map(fn($r) => $r['emailAddress']['address'] ?? '', $data['toRecipients'] ?? []),
        'cc'          => array_map(fn($r) => $r['emailAddress']['address'] ?? '', $data['ccRecipients'] ?? []),
        'date'        => $data['receivedDateTime'] ?? '',
        'is_read'     => true,
        'body'        => $data['body']['content'] ?? '',
        'body_type'   => $data['body']['contentType'] ?? 'html',
        'attachments' => $attachments,
        'conversation_id' => $data['conversationId'] ?? '',
    ]);
}

// ═══ Send email ═══
function handleSend(): void {
    $json = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $to = trim($json['to'] ?? '');
    $subject = trim($json['subject'] ?? '');
    $body = trim($json['body'] ?? '');
    $cc = trim($json['cc'] ?? '');
    if(!$to || !$subject || !$body) throw new RuntimeException('Missing: to, subject, body');

    // Wrap plain text in branded HTML
    if(strpos($body, '<') === false) {
        $body = '<html><body style="font-family:Arial,sans-serif;font-size:14px;color:#333;line-height:1.6">'
            . '<div style="max-width:600px;margin:0 auto;padding:20px">'
            . '<div style="text-align:center;padding:15px 0;border-bottom:2px solid #d4a830;margin-bottom:20px">'
            . '<h2 style="color:#d4a830;margin:0">Luxe Model Collective</h2></div>'
            . nl2br(htmlspecialchars($body))
            . '<div style="margin-top:30px;padding-top:15px;border-top:1px solid #eee;font-size:12px;color:#999;text-align:center">'
            . 'Luxe Model Collective | support@luxemodelcollective.com</div>'
            . '</div></body></html>';
    }

    $toRecipients = array_map(fn($e) => ['emailAddress'=>['address'=>trim($e)]], explode(',', $to));
    $message = [
        'message' => [
            'subject' => $subject,
            'body'    => ['contentType'=>'HTML','content'=>$body],
            'toRecipients' => $toRecipients,
        ],
        'saveToSentItems' => true,
    ];

    if($cc) {
        $message['message']['ccRecipients'] = array_map(fn($e) => ['emailAddress'=>['address'=>trim($e)]], explode(',', $cc));
    }

    graphRequest('POST', '/sendMail', $message);

    // Log to DB
    $pdo = getPDO();
    $pdo->prepare('INSERT INTO email_log (to_email,subject,body,status) VALUES (?,?,?,?)')->execute([$to, $subject, $body, 'sent']);

    json_response(['success'=>true,'message'=>'Email sent to '.$to]);
}

// ═══ Reply to email ═══
function handleReply(): void {
    $json = json_decode(file_get_contents('php://input'), true);
    $id = $json['id'] ?? '';
    $body = trim($json['body'] ?? '');
    $replyAll = $json['reply_all'] ?? false;
    if(!$id || !$body) throw new RuntimeException('Missing: id, body');

    $action = $replyAll ? 'replyAll' : 'reply';
    graphRequest('POST', '/messages/'.$id.'/'.$action, [
        'comment' => $body,
    ]);

    json_response(['success'=>true]);
}

// ═══ Delete email ═══
function handleDelete(): void {
    $json = json_decode(file_get_contents('php://input'), true) ?: $_GET;
    $id = $json['id'] ?? $_GET['id'] ?? '';
    if(!$id) throw new RuntimeException('Missing: id');

    graphRequest('DELETE', '/messages/'.$id);
    json_response(['success'=>true]);
}

// ═══ Search emails ═══
function handleSearch(): void {
    $q = $_GET['q'] ?? '';
    $top = min((int)($_GET['limit'] ?? 20), 50);
    if(!$q) throw new RuntimeException('Missing: q');

    $endpoint = '/messages?$search="'.$q.'"&$top='.$top;
    $endpoint .= '&$select=id,subject,from,receivedDateTime,isRead,bodyPreview,hasAttachments';

    $data = graphRequest('GET', $endpoint);

    $messages = [];
    foreach(($data['value'] ?? []) as $msg) {
        $messages[] = [
            'id'       => $msg['id'],
            'subject'  => $msg['subject'] ?? '',
            'from'     => $msg['from']['emailAddress']['address'] ?? '',
            'from_name'=> $msg['from']['emailAddress']['name'] ?? '',
            'date'     => $msg['receivedDateTime'] ?? '',
            'is_read'  => $msg['isRead'] ?? false,
            'preview'  => $msg['bodyPreview'] ?? '',
        ];
    }

    json_response(['messages'=>$messages, 'query'=>$q]);
}

// ═══ List folders ═══
function handleFolders(): void {
    $data = graphRequest('GET', '/mailFolders?$top=50');
    $folders = [];
    foreach(($data['value'] ?? []) as $f) {
        $folders[] = [
            'id'    => $f['id'],
            'name'  => $f['displayName'] ?? '',
            'total' => $f['totalItemCount'] ?? 0,
            'unread'=> $f['unreadItemCount'] ?? 0,
        ];
    }
    json_response($folders);
}

// ═══ Mark read/unread ═══
function handleMarkRead(): void {
    $json = json_decode(file_get_contents('php://input'), true);
    $id = $json['id'] ?? '';
    if(!$id) throw new RuntimeException('Missing: id');
    graphRequest('PATCH', '/messages/'.$id, ['isRead'=>true]);
    json_response(['success'=>true]);
}

function handleMarkUnread(): void {
    $json = json_decode(file_get_contents('php://input'), true);
    $id = $json['id'] ?? '';
    if(!$id) throw new RuntimeException('Missing: id');
    graphRequest('PATCH', '/messages/'.$id, ['isRead'=>false]);
    json_response(['success'=>true]);
}

// ═══ Send with template ═══
function handleSendTemplate(): void {
    $json = json_decode(file_get_contents('php://input'), true);
    $to = trim($json['to'] ?? '');
    $templateId = (int)($json['template_id'] ?? 0);
    if(!$to || !$templateId) throw new RuntimeException('Missing: to, template_id');

    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT * FROM email_templates WHERE id=?');
    $stmt->execute([$templateId]);
    $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$tpl) throw new RuntimeException('Template not found');

    $subject = $tpl['subject'];
    $body = $tpl['body'];

    // Replace performer variables
    $perf = $pdo->prepare('SELECT * FROM registration WHERE email=?');
    $perf->execute([strtolower($to)]);
    $p = $perf->fetch(PDO::FETCH_ASSOC);
    if($p) {
        $reps = ['{{first_name}}'=>$p['first_name']??'','{{last_name}}'=>$p['last_name']??'','{{stage_name}}'=>$p['stage_name']??'','{{email}}'=>$p['email']??'','{{phone}}'=>$p['phone']??'','{{status}}'=>$p['status']??''];
        foreach($reps as $k=>$v) { $subject=str_replace($k,$v,$subject); $body=str_replace($k,$v,$body); }
    }

    // Send via Graph
    $_POST = ['to'=>$to,'subject'=>$subject,'body'=>$body];
    // Reuse send logic
    $bodyHtml = '<html><body style="font-family:Arial,sans-serif;font-size:14px;color:#333;line-height:1.6">'
        . '<div style="max-width:600px;margin:0 auto;padding:20px">'
        . '<div style="text-align:center;padding:15px 0;border-bottom:2px solid #d4a830;margin-bottom:20px">'
        . '<h2 style="color:#d4a830;margin:0">Luxe Model Collective</h2></div>'
        . nl2br(htmlspecialchars($body))
        . '<div style="margin-top:30px;padding-top:15px;border-top:1px solid #eee;font-size:12px;color:#999;text-align:center">'
        . 'Luxe Model Collective | support@luxemodelcollective.com</div>'
        . '</div></body></html>';

    graphRequest('POST', '/sendMail', [
        'message' => [
            'subject' => $subject,
            'body'    => ['contentType'=>'HTML','content'=>$bodyHtml],
            'toRecipients' => [['emailAddress'=>['address'=>$to]]],
        ],
        'saveToSentItems' => true,
    ]);

    $pdo->prepare('INSERT INTO email_log (to_email,subject,body,status,template_id) VALUES (?,?,?,?,?)')->execute([$to, $subject, $body, 'sent', $templateId]);

    json_response(['success'=>true,'message'=>'Email sent to '.$to]);
}

// ═══ Template CRUD (reuse existing tables) ═══
function handleTemplates(): void {
    $pdo = getPDO();
    json_response($pdo->query('SELECT * FROM email_templates ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC));
}
function handleSaveTemplate(): void {
    $json = json_decode(file_get_contents('php://input'), true);
    $id = (int)($json['id']??0); $name = trim($json['name']??''); $subject = trim($json['subject']??''); $body = trim($json['body']??'');
    if(!$name||!$subject||!$body) throw new RuntimeException('Missing fields');
    $pdo = getPDO();
    if($id) { $pdo->prepare('UPDATE email_templates SET name=?,subject=?,body=?,updated_at=NOW() WHERE id=?')->execute([$name,$subject,$body,$id]); }
    else { $pdo->prepare('INSERT INTO email_templates (name,subject,body) VALUES (?,?,?)')->execute([$name,$subject,$body]); $id=$pdo->lastInsertId(); }
    json_response(['success'=>true,'id'=>$id]);
}
function handleDeleteTemplate(): void {
    $json = json_decode(file_get_contents('php://input'), true);
    $id = (int)($json['id']??0); if(!$id) throw new RuntimeException('Missing: id');
    getPDO()->prepare('DELETE FROM email_templates WHERE id=?')->execute([$id]);
    json_response(['success'=>true]);
}

// ═══ Stats ═══
function handleStats(): void {
    // Get folder counts from Graph
    try {
        $inbox = graphRequest('GET', '/mailFolders/inbox?$select=totalItemCount,unreadItemCount');
        $sent = graphRequest('GET', '/mailFolders/sentitems?$select=totalItemCount');
        $drafts = graphRequest('GET', '/mailFolders/drafts?$select=totalItemCount');
        json_response([
            'inbox_total'  => $inbox['totalItemCount'] ?? 0,
            'inbox_unread' => $inbox['unreadItemCount'] ?? 0,
            'sent_total'   => $sent['totalItemCount'] ?? 0,
            'drafts'       => $drafts['totalItemCount'] ?? 0,
            'templates'    => (int)getPDO()->query('SELECT COUNT(*) FROM email_templates')->fetchColumn(),
        ]);
    } catch(Throwable $e) {
        json_response(['success'=>false,'error'=>$e->getMessage()]);
    }
}

// ═══ Test connection ═══
function handleTest(): void {
    try {
        $token = getGraphToken();
        $inbox = graphRequest('GET', '/mailFolders/inbox?$select=totalItemCount,unreadItemCount');
        json_response([
            'success' => true,
            'auth'    => 'OK',
            'mailbox' => GRAPH_MAILBOX,
            'inbox'   => $inbox['totalItemCount'] ?? 0,
            'unread'  => $inbox['unreadItemCount'] ?? 0,
        ]);
    } catch(Throwable $e) {
        json_response(['success'=>false,'error'=>$e->getMessage()]);
    }
}

// ═══ Helpers ═══
function getPDO(): PDO {
    static $pdo = null;
    if(!$pdo) $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    return $pdo;
}
