<?php
/* ═══════════════════════════════════════════════════════════════════════════
   install.php — where a merchant arrives.

   Shopify sends them here when they click Install, and here again every time
   they open the app. Two jobs: send a new shop to Shopify's permission screen,
   and send an installed one straight through to the dashboard.

   The state parameter is a nonce. Without it, somebody could hand a merchant
   a crafted install link and have the callback fire against a shop they chose
   rather than one the merchant owns.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/lib.php';

session_start();

$shop = app_shop($_GET['shop'] ?? '');

if ($shop === '') {
    /* Somebody has arrived without a shop — usually a person typing the URL
       rather than an attack, so it is answered as such. */
    header('Content-Type: text/html; charset=utf-8');
    exit('<!DOCTYPE html><html><head><meta charset="utf-8">'
       . '<title>Models Boutique</title></head><body style="font-family:system-ui;'
       . 'background:#0a0d14;color:#e8eaf0;display:flex;align-items:center;'
       . 'justify-content:center;height:100vh;margin:0;text-align:center">'
       . '<div><h1 style="color:#d4a830">Models Boutique</h1>'
       . '<p>Install this from the Shopify App Store, or from your own admin.</p>'
       . '</div></body></html>');
}

/* Shopify signs the parameters it sends. Anything unsigned is refused. */
if (isset($_GET['hmac']) && !app_check_query($_GET)) {
    app_log('install: bad signature for ' . $shop);
    http_response_code(400);
    exit('That request could not be verified.');
}

$db = app_db();
app_tables($db);

$q = $db->prepare("SELECT token, scopes FROM mb_shops WHERE shop = ? AND uninstalled IS NULL");
$q->execute([$shop]);
$known = $q->fetch();

/* Already installed, and the permissions have not changed since — straight in.
   Asking a merchant to approve again on every visit is how an app feels
   broken. */
if ($known && $known['token'] !== '' && $known['scopes'] === APP_SCOPES) {
    header('Location: ' . APP_URL . '/index.php?shop=' . urlencode($shop)
         . '&host=' . urlencode((string)($_GET['host'] ?? '')));
    exit;
}

/* A one-time value, checked when Shopify sends them back. */
$state = bin2hex(random_bytes(16));
$_SESSION['mb_state'] = $state;
$_SESSION['mb_shop']  = $shop;

$to = 'https://' . $shop . '/admin/oauth/authorize?' . http_build_query([
    'client_id'    => APP_KEY,
    'scope'        => APP_SCOPES,
    'redirect_uri' => APP_URL . '/callback.php',
    'state'        => $state,
]);

/* The permission screen cannot be shown inside the frame the app runs in, so
   the top window has to be moved. A plain redirect would load Shopify's page
   inside our own iframe and show nothing. */
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Connecting…</title></head>
<body style="font-family:system-ui;background:#0a0d14;color:#8a8fa8;display:flex;
  align-items:center;justify-content:center;height:100vh;margin:0">
  <p>Taking you to Shopify…</p>
  <script>
    var to = <?= json_encode($to) ?>;
    if (window.top === window.self) window.location.href = to;
    else window.top.location.href = to;
  </script>
  <noscript><a href="<?= htmlspecialchars($to, ENT_QUOTES) ?>" style="color:#d4a830">Continue</a></noscript>
</body>
</html>
