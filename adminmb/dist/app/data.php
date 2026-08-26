<?php
/* ═══════════════════════════════════════════════════════════════════════════
   data.php — what the embedded page asks for.

   Every request carries a session token from App Bridge. It is verified, and
   the shop is taken from inside it — never from the query string, which
   anybody could edit. That is the whole security model of an embedded app.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

function reply(array $d, int $c = 200): void {
    http_response_code($c);
    echo json_encode($d);
    exit;
}

/* ── who is asking ────────────────────────────────────────────────────── */
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (stripos($auth, 'Bearer ') !== 0) {
    reply(['ok' => false, 'error' => 'Not authorised'], 401);
}

$claims = app_check_session(trim(substr($auth, 7)));
if (!$claims) reply(['ok' => false, 'error' => 'That session is not valid'], 401);

$shop = app_shop($claims['shop'] ?? '');
if ($shop === '') reply(['ok' => false, 'error' => 'Unknown shop'], 401);

try {
    $db = app_db();

    $q = $db->prepare("SELECT code FROM mb_shops WHERE shop = ? AND uninstalled IS NULL");
    $q->execute([$shop]);
    $code = (string)$q->fetchColumn();
    if ($code === '') reply(['ok' => false, 'error' => 'That shop is not connected'], 403);

    /* their rate */
    $r = $db->prepare("SELECT percent FROM affiliate_rates WHERE ref = ?");
    $r->execute([$code]);
    $rate = ($v = $r->fetchColumn()) !== false ? (float)$v : 20.0;

    /* ── their sales, from our own shop ───────────────────────────────────
       Orders live in our store, tagged with their code when the cart was
       made. Read through our own connection, so a merchant's token can never
       reach anything but their own figures. */
    $token = trim((string)@file_get_contents('/var/www/sites/adminmb/dist/shopify/.token'));
    if ($token === '') reply(['ok' => false, 'error' => 'The shop is not connected'], 502);

    $from = date('Y-m-d', strtotime('-365 days'));

    $ch = curl_init('https://' . HOME_SHOP . '/admin/api/' . API_VERSION . '/graphql.json');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json',
                                   'X-Shopify-Access-Token: ' . $token],
        CURLOPT_POSTFIELDS     => json_encode([
            'query' => 'query($q: String!) {
                orders(first: 100, query: $q, sortKey: CREATED_AT, reverse: true) {
                  edges { node {
                    name createdAt displayFinancialStatus
                    customAttributes { key value }
                    customer { displayName }
                    currentSubtotalPriceSet { shopMoney { amount } }
                    totalRefundedSet { shopMoney { amount } }
                  } }
                } }',
            'variables' => ['q' => 'created_at:>=' . $from],
        ]),
        CURLOPT_TIMEOUT => 25,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);

    $d = json_decode((string)$raw, true);
    $edges = $d['data']['orders']['edges'] ?? [];

    $sales = [];
    $sold = 0.0; $earned = 0.0; $n = 0;

    foreach ($edges as $e) {
        $o = $e['node'];

        $ref = '';
        foreach ($o['customAttributes'] ?? [] as $a) {
            if (($a['key'] ?? '') === 'referred_by') $ref = strtoupper(trim((string)$a['value']));
        }
        if ($ref !== strtoupper($code)) continue;

        $sub = (float)($o['currentSubtotalPriceSet']['shopMoney']['amount'] ?? 0);
        $refunded = (float)($o['totalRefundedSet']['shopMoney']['amount'] ?? 0);
        $fin = strtoupper((string)$o['displayFinancialStatus']);
        $counts = ($fin === 'PAID' && $refunded <= 0.001);

        $n++;
        if ($counts) { $sold += $sub; $earned += $sub * $rate / 100; }

        $sales[] = [
            'order'      => $o['name'],
            'date'       => substr((string)$o['createdAt'], 0, 10),
            'customer'   => $o['customer']['displayName'] ?? 'Guest',
            'amount'     => round($sub, 2),
            'commission' => $counts ? round($sub * $rate / 100, 2) : 0.0,
            'counts'     => $counts,
            'status'     => ucfirst(strtolower(str_replace('_', ' ', $fin))),
        ];
    }

    $totals = ['orders' => $n, 'sold' => round($sold, 2),
               'commission' => round($earned, 2), 'rate' => $rate];

    switch ($_GET['action'] ?? '') {
        case 'summary': reply(['ok' => true, 'totals' => $totals, 'code' => $code]);
        case 'sales':   reply(['ok' => true, 'sales' => $sales, 'totals' => $totals]);
        default:        reply(['ok' => false, 'error' => 'Unknown action'], 400);
    }

} catch (Throwable $e) {
    app_log('data: ' . $e->getMessage());
    reply(['ok' => false, 'error' => 'Something went wrong at our end.'], 500);
}
