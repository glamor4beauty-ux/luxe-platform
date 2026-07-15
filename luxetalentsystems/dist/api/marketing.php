<?php
/* ═══════════════════════════════════════════════════════════════════════════
   Email Marketing — /api/marketing.php   (SendGrid Web API v3)
   Segments performers from `registration`, sends HTML campaigns with open/click
   tracking, auto-appends an unsubscribe link. API key from Settings store.
     GET  ?action=segments              → recipient counts per segment
     GET  ?action=list                  → recent campaigns + stats
     GET  ?action=campaign&id=N         → one campaign + per-recipient rows
     POST ?action=preview {segment}     → count + sample recipients
     POST ?action=send {name,subject,body_html,segment,from_email,from_name}
   ═══════════════════════════════════════════════════════════════════════════ */
require __DIR__ . '/../cors.php';
require __DIR__ . '/../config.php';
require __DIR__ . '/_settings.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

$email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';
if ($email === '') json_response(['ok'=>false,'error'=>'Not signed in'], 401);

/* Segment → WHERE clause. banned + opted-out ALWAYS excluded. */
function segment_where(string $seg): string {
    $base = "status NOT IN ('banned') AND COALESCE(marketing_opt_out,0)=0 AND email IS NOT NULL AND email<>''";
    switch ($seg) {
        case 'pending':  return "$base AND status='pending'";
        case 'active':   return "$base AND status='active'";
        case 'inactive': return "$base AND status='inactive'";
        case 'all':      return $base;
        default:         return "$base AND 1=0"; // unknown segment → empty
    }
}

function seg_recipients(PDO $pdo, string $seg): array {
    $w = segment_where($seg);
    $st = $pdo->query("SELECT email, first_name, last_name, stage_name FROM registration WHERE $w");
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function merge_vars(string $tpl, array $r): string {
    $map = [
        '{{first_name}}' => $r['first_name'] ?? '',
        '{{last_name}}'  => $r['last_name'] ?? '',
        '{{stage_name}}' => $r['stage_name'] ?? ($r['first_name'] ?? ''),
        '{{email}}'      => $r['email'] ?? '',
    ];
    return strtr($tpl, $map);
}

/* Token so unsubscribe links can't be forged. */
function unsub_token(string $email): string {
    $secret = app_setting('marketing_unsub_secret');
    if ($secret === '') $secret = 'luxe-fallback-secret';
    return substr(hash_hmac('sha256', strtolower($email), $secret), 0, 24);
}
function unsub_link(string $email): string {
    $base = app_setting('marketing_unsub_base');
    if ($base === '') $base = 'https://luxetalentsystems.com/api/unsubscribe.php';
    return $base.'?e='.rawurlencode($email).'&t='.unsub_token($email);
}

/* SendGrid send. Returns [httpCode, messageId|errorBody]. */
function sg_send(string $apiKey, array $msg): array {
    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer '.$apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($msg),
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($resp, 0, $hsize);
    $body = substr($resp, $hsize);
    curl_close($ch);
    $msgId = '';
    if (preg_match('/X-Message-Id:\s*([^\r\n]+)/i', $headers, $m)) $msgId = trim($m[1]);
    return [$code, $code>=200 && $code<300 ? $msgId : $body];
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = db();

try {
    if ($action === 'segments' && $method === 'GET') {
        $out = [];
        foreach (['pending','active','inactive','all'] as $s) {
            $w = segment_where($s);
            $out[$s] = (int)$pdo->query("SELECT COUNT(*) FROM registration WHERE $w")->fetchColumn();
        }
        json_response(['ok'=>true,'segments'=>$out]);
    }

    if ($action === 'list' && $method === 'GET') {
        $rows = $pdo->query(
            "SELECT c.*,
                (SELECT COUNT(*) FROM email_recipients r WHERE r.campaign_id=c.id AND r.status='delivered') AS delivered,
                (SELECT COUNT(*) FROM email_recipients r WHERE r.campaign_id=c.id AND r.opened_at IS NOT NULL) AS opened,
                (SELECT COUNT(*) FROM email_recipients r WHERE r.campaign_id=c.id AND r.clicked_at IS NOT NULL) AS clicked,
                (SELECT COUNT(*) FROM email_recipients r WHERE r.campaign_id=c.id AND r.bounced_at IS NOT NULL) AS bounced
             FROM email_campaigns c ORDER BY c.created_at DESC LIMIT 50"
        )->fetchAll(PDO::FETCH_ASSOC);
        json_response(['ok'=>true,'campaigns'=>$rows]);
    }

    if ($action === 'campaign' && $method === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        $c = $pdo->prepare("SELECT * FROM email_campaigns WHERE id=?"); $c->execute([$id]);
        $camp = $c->fetch(PDO::FETCH_ASSOC);
        if (!$camp) json_response(['ok'=>false,'error'=>'Not found'], 404);
        $r = $pdo->prepare("SELECT email,status,opened_at,clicked_at,bounced_at FROM email_recipients WHERE campaign_id=? ORDER BY id DESC LIMIT 500");
        $r->execute([$id]);
        json_response(['ok'=>true,'campaign'=>$camp,'recipients'=>$r->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'preview' && $method === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $seg = $in['segment'] ?? '';
        $recips = seg_recipients($pdo, $seg);
        $sample = array_slice(array_map(fn($r)=>$r['email'], $recips), 0, 10);
        json_response(['ok'=>true,'count'=>count($recips),'sample'=>$sample]);
    }

    if ($action === 'send' && $method === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $name    = trim($in['name'] ?? '');
        $subject = trim($in['subject'] ?? '');
        $body    = (string)($in['body_html'] ?? '');
        $seg     = $in['segment'] ?? '';
        $fromE   = trim($in['from_email'] ?? '') ?: app_setting('marketing_from_email');
        $fromN   = trim($in['from_name'] ?? '') ?: app_setting('marketing_from_name');
        if ($fromE === '') $fromE = 'support@luxemodelcollective.com';
        if ($fromN === '') $fromN = 'Luxe Model Collective';

        if ($name===''||$subject===''||$body===''||$seg==='')
            json_response(['ok'=>false,'error'=>'Missing name, subject, body, or segment'], 400);

        $apiKey = app_setting('sendgrid_api_key');
        if ($apiKey === '') json_response(['ok'=>false,'error'=>'SendGrid API key not set in Settings (sendgrid_api_key)'], 400);

        $recips = seg_recipients($pdo, $seg);
        if (!count($recips)) json_response(['ok'=>false,'error'=>'No recipients in that segment'], 400);

        // Create campaign row
        $ins = $pdo->prepare("INSERT INTO email_campaigns (name,subject,body_html,segment,from_email,from_name,status,total) VALUES (?,?,?,?,?,?,'sending',?)");
        $ins->execute([$name,$subject,$body,$seg,$fromE,$fromN,count($recips)]);
        $campId = (int)$pdo->lastInsertId();

        $recIns = $pdo->prepare("INSERT INTO email_recipients (campaign_id,email,sg_message_id,status) VALUES (?,?,?,?)");
        $sent = 0; $fail = 0;
        foreach ($recips as $r) {
            $to = $r['email'];
            $htmlBody = merge_vars($body, $r);
            // Append unsubscribe link if not already present
            if (stripos($htmlBody, 'unsubscribe.php') === false) {
                $htmlBody .= '<br><br><hr><p style="font-size:11px;color:#888">You are receiving this because you registered with Luxe Model Collective. <a href="'.unsub_link($to).'">Unsubscribe</a></p>';
            }
            $msg = [
                'personalizations' => [[ 'to' => [['email'=>$to]] ]],
                'from'    => ['email'=>$fromE, 'name'=>$fromN],
                'subject' => merge_vars($subject, $r),
                'content' => [['type'=>'text/html','value'=>$htmlBody]],
                'tracking_settings' => [
                    'click_tracking' => ['enable'=>true],
                    'open_tracking'  => ['enable'=>true],
                ],
                'custom_args' => ['campaign_id'=>(string)$campId],
            ];
            [$code, $res] = sg_send($apiKey, $msg);
            if ($code>=200 && $code<300) { $recIns->execute([$campId,$to,$res,'sent']); $sent++; }
            else { $recIns->execute([$campId,$to,'','failed']); $fail++; error_log("[marketing] send fail $to: HTTP $code $res"); }
            usleep(120000); // ~8/sec throttle
        }
        $pdo->prepare("UPDATE email_campaigns SET status='sent', sent_count=?, sent_at=NOW() WHERE id=?")->execute([$sent,$campId]);
        json_response(['ok'=>true,'campaign_id'=>$campId,'sent'=>$sent,'failed'=>$fail,'total'=>count($recips)]);
    }

    json_response(['ok'=>false,'error'=>'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('[marketing] '.$e->getMessage());
    json_response(['ok'=>false,'error'=>'Server error'], 500);
}