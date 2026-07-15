<?php
/* SendGrid Event Webhook — /api/email-webhook.php
   Receives delivered/open/click/bounce/dropped/unsubscribe events as a JSON array.
   Updates email_recipients + logs to email_events. Public (no session). */
require __DIR__ . '/../config.php';
require __DIR__ . '/_settings.php';
header('Content-Type: text/plain; charset=utf-8');

$raw = file_get_contents('php://input');
$events = json_decode($raw, true);
if (!is_array($events)) { http_response_code(200); echo "no events"; exit; }

$pdo = db();
$logIns = $pdo->prepare("INSERT INTO email_events (sg_message_id,email,event,url,reason,raw) VALUES (?,?,?,?,?,?)");

foreach ($events as $ev) {
    $email = $ev['email'] ?? '';
    $event = $ev['event'] ?? '';
    $url   = $ev['url'] ?? '';
    $reason= $ev['reason'] ?? ($ev['response'] ?? '');
    // SendGrid sends sg_message_id like "abc123.recvd-..." — first segment matches our stored id
    $sgid  = $ev['sg_message_id'] ?? '';
    $sgidShort = $sgid !== '' ? explode('.', $sgid)[0] : '';

    $logIns->execute([$sgidShort, $email, $event, substr($url,0,500), substr($reason,0,500), substr(json_encode($ev),0,2000)]);

    // Map event → recipient update. Match on sg_message_id when possible, else email+campaign via custom_args.
    $campId = isset($ev['campaign_id']) ? (int)$ev['campaign_id'] : 0;

    $set = null;
    switch ($event) {
        case 'delivered': $set = "status='delivered'"; break;
        case 'open':      $set = "status='opened', opened_at=COALESCE(opened_at,NOW())"; break;
        case 'click':     $set = "status='clicked', clicked_at=COALESCE(clicked_at,NOW()), opened_at=COALESCE(opened_at,NOW())"; break;
        case 'bounce':
        case 'dropped':
        case 'blocked':   $set = "status='bounced', bounced_at=COALESCE(bounced_at,NOW())"; break;
        case 'unsubscribe':
        case 'group_unsubscribe':
        case 'spamreport':
            // honor opt-out
            if ($email !== '') {
                try { $pdo->prepare("UPDATE registration SET marketing_opt_out=1 WHERE email=?")->execute([$email]); } catch (Throwable $e) {}
            }
            $set = "status='unsubscribed'";
            break;
    }
    if ($set !== null) {
        try {
            if ($sgidShort !== '') {
                $pdo->prepare("UPDATE email_recipients SET $set WHERE sg_message_id=?")->execute([$sgidShort]);
            } elseif ($campId && $email !== '') {
                $pdo->prepare("UPDATE email_recipients SET $set WHERE campaign_id=? AND email=?")->execute([$campId,$email]);
            } elseif ($email !== '') {
                $pdo->prepare("UPDATE email_recipients SET $set WHERE email=? ORDER BY id DESC LIMIT 1")->execute([$email]);
            }
        } catch (Throwable $e) { error_log('[email-webhook] '.$e->getMessage()); }
    }
}
http_response_code(200);
echo "ok";