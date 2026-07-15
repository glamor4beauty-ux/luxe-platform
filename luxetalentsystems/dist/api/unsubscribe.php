<?php
/* Unsubscribe — /api/unsubscribe.php?e=EMAIL&t=TOKEN
   Token-verified. Flips registration.marketing_opt_out=1. Public (no session). */
require __DIR__ . '/../config.php';
require __DIR__ . '/_settings.php';

function unsub_token(string $email): string {
    $secret = app_setting('marketing_unsub_secret');
    if ($secret === '') $secret = 'luxe-fallback-secret';
    return substr(hash_hmac('sha256', strtolower($email), $secret), 0, 24);
}

$email = trim($_GET['e'] ?? '');
$tok   = trim($_GET['t'] ?? '');
$ok = false;
if ($email !== '' && $tok !== '' && hash_equals(unsub_token($email), $tok)) {
    try {
        db()->prepare("UPDATE registration SET marketing_opt_out=1 WHERE email=?")->execute([$email]);
        $ok = true;
    } catch (Throwable $e) { error_log('[unsub] '.$e->getMessage()); }
}
header('Content-Type: text/html; charset=utf-8');
$msg = $ok
  ? "You have been unsubscribed. You will no longer receive marketing emails from us."
  : "We could not process this unsubscribe link. It may be invalid or expired.";
echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">'
   . '<title>Unsubscribe</title></head><body style="font-family:system-ui,Arial,sans-serif;background:#0e1117;color:#e8eaf0;display:flex;align-items:center;justify-content:center;min-height:90vh;margin:0">'
   . '<div style="max-width:480px;text-align:center;padding:30px">'
   . '<h2 style="color:#d4a830">Luxe Model Collective</h2>'
   . '<p style="font-size:15px;line-height:1.5">'.htmlspecialchars($msg).'</p>'
   . '</div></body></html>';