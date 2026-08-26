<?php
/* ═══════════════════════════════════════════════════════════════════════════
   callback.php — Shopify sending the merchant back, permission granted.

   Swap the code for a token, learn who they are, make them an affiliate, and
   register the webhooks. Then into the app.

   The affiliate record is the point. A merchant who installs this is not a
   separate kind of user — they are an affiliate whose storefront happens to be
   a Shopify shop, so everything already built for affiliates works for them
   from the moment they arrive.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/lib.php';

session_start();

$shop  = app_shop($_GET['shop'] ?? '');
$code  = (string)($_GET['code'] ?? '');
$state = (string)($_GET['state'] ?? '');

if ($shop === '' || $code === '') {
    http_response_code(400);
    exit('Something is missing from that request.');
}

if (!app_check_query($_GET)) {
    app_log('callback: bad signature for ' . $shop);
    http_response_code(400);
    exit('That request could not be verified.');
}

/* The nonce we set on the way out. If it does not match, this callback was
   not started by us. */
if ($state === '' || !hash_equals((string)($_SESSION['mb_state'] ?? ''), $state)) {
    app_log('callback: state mismatch for ' . $shop);
    http_response_code(400);
    exit('That request could not be verified.');
}
unset($_SESSION['mb_state']);

/* ── the token ────────────────────────────────────────────────────────── */
$ch = curl_init('https://' . $shop . '/admin/oauth/access_token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode([
        'client_id'     => APP_KEY,
        'client_secret' => APP_SECRET,
        'code'          => $code,
    ]),
    CURLOPT_TIMEOUT        => 25,
]);
$raw = curl_exec($ch);
curl_close($ch);

$tok = json_decode((string)$raw, true);
if (!is_array($tok) || empty($tok['access_token'])) {
    app_log('callback: no token for ' . $shop . ' — ' . substr((string)$raw, 0, 200));
    http_response_code(502);
    exit('Shopify did not give us access. Try installing again.');
}

$db = app_db();
app_tables($db);

$db->prepare("INSERT INTO mb_shops (shop, token, scopes, installed_at, uninstalled)
              VALUES (?,?,?, NOW(), NULL)
              ON DUPLICATE KEY UPDATE token = VALUES(token), scopes = VALUES(scopes),
                                      uninstalled = NULL")
   ->execute([$shop, (string)$tok['access_token'], (string)($tok['scope'] ?? APP_SCOPES)]);

/* ── who they are ─────────────────────────────────────────────────────── */
$info = app_call($shop, 'shop.json');
$s = $info['ok'] ? ($info['data']['shop'] ?? []) : [];

$name  = (string)($s['name'] ?? explode('.', $shop)[0]);
$email = (string)($s['email'] ?? '');
$owner = (string)($s['shop_owner'] ?? '');

$db->prepare("UPDATE mb_shops SET shop_name = ?, shop_email = ?, owner_name = ?,
                                  country = ?, plan = ?, last_seen = NOW()
               WHERE shop = ?")
   ->execute([$name, $email, $owner,
              (string)($s['country_code'] ?? ''),
              (string)($s['plan_name'] ?? ''), $shop]);

/* ── making them an affiliate ─────────────────────────────────────────── */
$q = $db->prepare("SELECT code FROM mb_shops WHERE shop = ?");
$q->execute([$shop]);
$aff = (string)$q->fetchColumn();

if ($aff === '') {
    /* An existing affiliate reinstalling under the same email keeps her code
       and her history, rather than starting again as somebody new. */
    if ($email !== '') {
        $e = $db->prepare("SELECT code FROM affiliates WHERE email = ?");
        $e->execute([strtolower($email)]);
        $aff = (string)$e->fetchColumn();
    }

    if ($aff === '') {
        $aff = app_make_code($db, $name, $shop);

        $parts = preg_split('/\s+/', trim($owner), 2);
        $db->prepare("INSERT INTO affiliates
                (code, first_name, last_name, store_name, email, hosting, template,
                 status, site_url, opened)
              VALUES (?,?,?,?,?, 'diy', 'template1', 'active', ?, CURDATE())")
           ->execute([
               $aff,
               $parts[0] ?? '',
               $parts[1] ?? '',
               $name,
               strtolower($email),
               'https://' . $shop,
           ]);

        /* Merchants start on the standard rate like anybody else. */
        $db->prepare("INSERT IGNORE INTO affiliate_rates (ref, percent, updated_by)
                      VALUES (?, 20.00, 'shopify install')")
           ->execute([$aff]);
    }

    $db->prepare("UPDATE mb_shops SET code = ? WHERE shop = ?")->execute([$aff, $shop]);
}

/* ── the webhooks Shopify insists on ──────────────────────────────────── */
$hooks = [
    'app/uninstalled'        => '/webhooks.php',
    'customers/data_request' => '/webhooks.php',
    'customers/redact'       => '/webhooks.php',
    'shop/redact'            => '/webhooks.php',
];

foreach ($hooks as $topic => $path) {
    app_call($shop, 'webhooks.json', [
        'webhook' => [
            'topic'   => $topic,
            'address' => APP_URL . $path,
            'format'  => 'json',
        ],
    ], 'POST');
    /* A duplicate is refused and that is fine — registering again after a
       reinstall should not be an error. */
}

header('Location: ' . APP_URL . '/index.php?shop=' . urlencode($shop)
     . '&host=' . urlencode((string)($_GET['host'] ?? '')));
exit;
