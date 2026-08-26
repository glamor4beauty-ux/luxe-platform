<?php
/* ═══════════════════════════════════════════════════════════════════════════
   auth.php — one-time OAuth, for the app built in the dev dashboard.

   Not needed if config.php has an shpat_ token. Visit this page once, approve
   the app, and the token it returns is written to .token and used from then
   on.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/lib.php';

session_start();

function page(string $title, string $body, string $tone = 'info'): void {
    $colour = ['info' => '#d8a94b', 'ok' => '#4ec97a', 'bad' => '#f2685e'][$tone] ?? '#d8a94b';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow">'
       . '<title>' . e($title) . ' · ' . e(APP_NAME) . '</title><style>'
       . 'body{margin:0;background:#0f1115;color:#e9eaee;font-family:-apple-system,'
       . 'BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:15px;line-height:1.6;'
       . 'display:flex;align-items:center;justify-content:center;min-height:100vh;padding:22px}'
       . '.box{background:#171a21;border:1px solid #242832;border-radius:14px;padding:26px;'
       . 'max-width:520px;width:100%}'
       . 'h1{margin:0 0 12px;font-size:19px;color:' . $colour . '}'
       . 'p{margin:0 0 12px;color:#b9bcc6}'
       . 'code{background:#12151b;padding:2px 6px;border-radius:4px;font-size:13px;'
       . 'word-break:break-all}'
       . 'ol{padding-left:20px;color:#b9bcc6}li{margin-bottom:7px}'
       . 'a.btn{display:inline-block;background:#d8a94b;color:#14161b;text-decoration:none;'
       . 'padding:12px 22px;border-radius:10px;font-weight:700;margin-top:8px}'
       . '</style></head><body><div class="box">'
       . '<h1>' . e($title) . '</h1>' . $body . '</div></body></html>';
    exit;
}

/* ── already sorted ─────────────────────────────────────────────────────── */
if (SHOP_TOKEN !== '') {
    page('Already connected',
        '<p>A token is set directly in <code>config.php</code>, so this page is not needed.</p>'
      . '<a class="btn" href="index.php">Go to customers</a>', 'ok');
}

/* ── the return leg ─────────────────────────────────────────────────────── */
if (isset($_GET['code'])) {

    /* state proves the callback belongs to the request we started, rather
       than someone else's link */
    $sent = $_SESSION['oauth_state'] ?? '';
    $back = (string)($_GET['state'] ?? '');
    if ($sent === '' || !hash_equals($sent, $back)) {
        page('That did not come from here',
            '<p>The security value did not match, so the response was ignored. '
          . 'Start again from this page.</p>'
          . '<a class="btn" href="auth.php">Start over</a>', 'bad');
    }
    unset($_SESSION['oauth_state']);

    /* Shopify signs its callbacks; verifying it means the parameters were not
       edited on the way here */
    $params = $_GET;
    $hmac = $params['hmac'] ?? '';
    unset($params['hmac'], $params['signature']);
    ksort($params);
    $calc = hash_hmac('sha256', http_build_query($params), SHOP_CLIENT_SECRET);
    if (!hash_equals($calc, (string)$hmac)) {
        page('The signature did not check out',
            '<p>Shopify\'s signature on this callback could not be verified, so no token was '
          . 'stored. Usually the Client secret in <code>config.php</code> does not match '
          . 'the app.</p>', 'bad');
    }

    $ch = curl_init('https://' . SHOP_DOMAIN . '/admin/oauth/access_token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'client_id'     => SHOP_CLIENT_ID,
            'client_secret' => SHOP_CLIENT_SECRET,
            'code'          => $_GET['code'],
        ]),
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);

    $d = json_decode((string)$raw, true);
    if (empty($d['access_token'])) {
        page('Shopify would not issue a token',
            '<p>' . e($d['error_description'] ?? $d['error'] ?? 'No reason given.') . '</p>'
          . '<p>The Client ID and secret both have to belong to this app, and the Redirect URL '
          . 'declared on the app must match <code>' . e(SHOP_REDIRECT) . '</code> exactly.</p>', 'bad');
    }

    if (@file_put_contents(TOKEN_FILE, $d['access_token']) === false) {
        page('Could not save the token',
            '<p>The token came back but could not be written to <code>' . e(TOKEN_FILE) . '</code>. '
          . 'The web user needs write access to that directory.</p>', 'bad');
    }
    @chmod(TOKEN_FILE, 0600);

    page('Connected',
        '<p>The token is stored. This page is not needed again unless the app is '
      . 'uninstalled or its scopes change.</p>'
      . '<p style="font-size:13px;color:#7b8194">Granted: <code>'
      . e($d['scope'] ?? '') . '</code></p>'
      . '<a class="btn" href="index.php">Go to customers</a>', 'ok');
}

/* ── the outward leg ────────────────────────────────────────────────────── */
if (SHOP_CLIENT_ID === '' || SHOP_CLIENT_SECRET === '') {
    page('Nothing to connect with',
        '<p>Put either an <code>shpat_</code> token, or the app\'s Client ID and secret, '
      . 'into <code>config.php</code>.</p>'
      . '<ol><li>Shopify dev dashboard &rarr; your app &rarr; Settings</li>'
      . '<li>Copy the Client ID and Client secret</li>'
      . '<li>Paste them into <code>config.php</code></li>'
      . '<li>Reload this page</li></ol>', 'bad');
}

$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

$url = 'https://' . SHOP_DOMAIN . '/admin/oauth/authorize?' . http_build_query([
    'client_id'    => SHOP_CLIENT_ID,
    'scope'        => SHOP_SCOPES,
    'redirect_uri' => SHOP_REDIRECT,
    'state'        => $state,
]);

page('Connect to Shopify',
    '<p>This asks the store to grant read access to orders, customers and fulfilment. '
  . 'It is a one-time step.</p>'
  . '<a class="btn" href="' . e($url) . '">Approve on Shopify</a>'
  . '<p style="font-size:12.5px;color:#7b8194;margin-top:16px">Redirect URL in use: <code>'
  . e(SHOP_REDIRECT) . '</code><br>This has to match the app exactly, or Shopify will refuse.</p>');
