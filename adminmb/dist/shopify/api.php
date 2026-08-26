<?php
/* ═══════════════════════════════════════════════════════════════════════════
   api.php — what the dialogs fetch when they open.

   Orders and payments are pulled per customer rather than with the list. A
   list of 50 customers each carrying their orders and line items is a large,
   slow query that Shopify will throttle; almost all of it is never looked at.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/lib.php';
require_once __DIR__ . '/authz.php';

auth_start();
if (!auth_user()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Not signed in']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

function out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d);
    exit;
}

$action = $_GET['action'] ?? '';
$id     = trim((string)($_GET['id'] ?? ''));

/* ids arrive as the numeric tail; Shopify wants the gid back */
function gid(string $type, string $n): string {
    return str_starts_with($n, 'gid://') ? $n : 'gid://shopify/' . $type . '/' . preg_replace('/\D/', '', $n);
}

switch ($action) {

/* ── a customer's orders, with the fulfilment location on each ────────── */
case 'orders': {
    if ($id === '') out(['ok' => false, 'error' => 'id required'], 400);

    $q = <<<'GQL'
    query CustomerOrders($id: ID!) {
      customer(id: $id) {
        id
        displayName
        firstName
        lastName
        phone
        email
        orders(first: 20, sortKey: CREATED_AT, reverse: true) {
          edges { node {
            id
            name
            createdAt
            displayFulfillmentStatus
            displayFinancialStatus
            totalPriceSet { shopMoney { amount currencyCode } }
            fulfillmentOrders(first: 5) {
              edges { node {
                assignedLocation { name location { id } }
              } }
            }
            fulfillments(first: 5) {
              status
              createdAt
              trackingInfo { company number url }
            }
            shippingAddress { name address1 address2 city province zip country phone }
            shippingLine { title }
            totalShippingPriceSet { shopMoney { amount currencyCode } }
            totalDiscountsSet { shopMoney { amount currencyCode } }
            discountApplications(first: 5) {
              edges { node {
                ... on DiscountCodeApplication { code }
                ... on ManualDiscountApplication { title }
              } }
            }
            lineItems(first: 25) {
              edges { node {
                title
                quantity
                sku
                variant {
                  id
                  price
                  image { url }
                }
                image { url }
                originalTotalSet { shopMoney { amount currencyCode } }
              } }
            }
          } }
        }
      }
    }
    GQL;

    $r = shop_query($q, ['id' => gid('Customer', $id)]);
    if (!$r['ok']) out(['ok' => false, 'error' => $r['message'] ?? 'failed'], 400);

    $c = $r['data']['customer'] ?? null;
    if (!$c) out(['ok' => false, 'error' => 'No such customer'], 404);

    $orders = [];
    foreach ($c['orders']['edges'] ?? [] as $oe) {
        $o = $oe['node'];

        /* one order can be split across locations; list each once, in order */
        $locs = [];
        foreach ($o['fulfillmentOrders']['edges'] ?? [] as $fe) {
            $al = $fe['node']['assignedLocation'] ?? null;
            if (!$al || empty($al['name'])) continue;
            $lid = $al['location']['id'] ?? '';
            $key = $lid ?: $al['name'];
            if (!isset($locs[$key])) $locs[$key] = ['name' => $al['name'], 'id' => $lid];
        }

        $items = [];
        foreach ($o['lineItems']['edges'] ?? [] as $le) {
            $li = $le['node'];
            $items[] = [
                'title' => $li['title'] ?? '',
                'qty'   => (int)($li['quantity'] ?? 0),
                'sku'   => $li['sku'] ?? '',
                'image' => $li['image']['url'] ?? ($li['variant']['image']['url'] ?? ''),
                'total' => $li['originalTotalSet']['shopMoney']['amount'] ?? null,
                'cur'   => $li['originalTotalSet']['shopMoney']['currencyCode'] ?? 'USD',
            ];
        }

        /* tracking, when there is any — the thing you actually want to read
           out to somebody asking where their parcel is */
        $tracking = [];
        foreach ($o['fulfillments'] ?? [] as $f) {
            foreach ($f['trackingInfo'] ?? [] as $t) {
                if (empty($t['number']) && empty($t['url'])) continue;
                $tracking[] = [
                    'company' => $t['company'] ?? '',
                    'number'  => $t['number'] ?? '',
                    'url'     => $t['url'] ?? '',
                    'status'  => $f['status'] ?? '',
                    'when'    => $f['createdAt'] ?? '',
                ];
            }
        }

        $codes = [];
        foreach ($o['discountApplications']['edges'] ?? [] as $de) {
            $n = $de['node'];
            $c = $n['code'] ?? ($n['title'] ?? '');
            if ($c !== '') $codes[] = $c;
        }

        $sa = $o['shippingAddress'] ?? null;
        $ship = null;
        if ($sa) {
            $ship = trim(implode(', ', array_filter([
                $sa['address1'] ?? '', $sa['address2'] ?? '',
                $sa['city'] ?? '', $sa['province'] ?? '', $sa['zip'] ?? '',
                $sa['country'] ?? '',
            ])));
        }

        $orders[] = [
            'id'        => gid_num($o['id'] ?? ''),
            'name'      => $o['name'] ?? '',
            'created'   => $o['createdAt'] ?? '',
            'fulfil'    => $o['displayFulfillmentStatus'] ?? '',
            'financial' => $o['displayFinancialStatus'] ?? '',
            'total'     => $o['totalPriceSet']['shopMoney']['amount'] ?? null,
            'cur'       => $o['totalPriceSet']['shopMoney']['currencyCode'] ?? 'USD',
            'locations' => array_values($locs),
            'items'     => $items,
            'tracking'  => $tracking,
            'ship_to'   => $ship,
            'ship_name' => $sa['name'] ?? '',
            'ship_phone'=> $sa['phone'] ?? '',
            'ship_method' => $o['shippingLine']['title'] ?? '',
            'ship_cost' => $o['totalShippingPriceSet']['shopMoney']['amount'] ?? null,
            'discount'  => $o['totalDiscountsSet']['shopMoney']['amount'] ?? null,
            'codes'     => $codes,
        ];
    }

    $name = trim((string)($c['displayName'] ?? ''));
    if ($name === '') $name = trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? ''));

    out(['ok' => true, 'customer' => [
        'name'  => $name !== '' ? $name : 'No name',
        'phone' => $c['phone'] ?? '',
        'email' => $c['email'] ?? '',
    ], 'orders' => $orders]);
}

/* ── a customer's payments ────────────────────────────────────────────── */
case 'payments': {
    if ($id === '') out(['ok' => false, 'error' => 'id required'], 400);

    $q = <<<'GQL'
    query CustomerPayments($id: ID!) {
      customer(id: $id) {
        displayName firstName lastName phone email
        amountSpent { amount currencyCode }
        orders(first: 20, sortKey: CREATED_AT, reverse: true) {
          edges { node {
            id
            name
            createdAt
            displayFinancialStatus
            totalPriceSet { shopMoney { amount currencyCode } }
            totalRefundedSet { shopMoney { amount currencyCode } }
            transactions(first: 10) {
              id
              kind
              status
              gateway
              processedAt
              amountSet { shopMoney { amount currencyCode } }
              paymentDetails {
                ... on CardPaymentDetails { company last4 expirationMonth expirationYear }
              }
            }
          } }
        }
      }
    }
    GQL;

    $r = shop_query($q, ['id' => gid('Customer', $id)]);
    if (!$r['ok']) out(['ok' => false, 'error' => $r['message'] ?? 'failed'], 400);

    $c = $r['data']['customer'] ?? null;
    if (!$c) out(['ok' => false, 'error' => 'No such customer'], 404);

    $orders = [];
    foreach ($c['orders']['edges'] ?? [] as $oe) {
        $o = $oe['node'];
        $tx = [];
        foreach ($o['transactions'] ?? [] as $t) {
            $pd = $t['paymentDetails'] ?? null;
            $card = '';
            if ($pd && !empty($pd['last4'])) {
                $card = trim(($pd['company'] ?? '') . ' ····' . $pd['last4']);
            }
            $tx[] = [
                'kind'    => $t['kind'] ?? '',
                'status'  => $t['status'] ?? '',
                'gateway' => $t['gateway'] ?? '',
                'when'    => $t['processedAt'] ?? '',
                'amount'  => $t['amountSet']['shopMoney']['amount'] ?? null,
                'cur'     => $t['amountSet']['shopMoney']['currencyCode'] ?? 'USD',
                'card'    => $card,
            ];
        }
        $refunded = (float)($o['totalRefundedSet']['shopMoney']['amount'] ?? 0);
        $orders[] = [
            'id'        => gid_num($o['id'] ?? ''),
            'name'      => $o['name'] ?? '',
            'created'   => $o['createdAt'] ?? '',
            'financial' => $o['displayFinancialStatus'] ?? '',
            'total'     => $o['totalPriceSet']['shopMoney']['amount'] ?? null,
            'cur'       => $o['totalPriceSet']['shopMoney']['currencyCode'] ?? 'USD',
            'refunded'  => $refunded > 0 ? $refunded : null,
            'tx'        => $tx,
        ];
    }

    $name = trim((string)($c['displayName'] ?? ''));
    if ($name === '') $name = trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? ''));

    out(['ok' => true, 'customer' => [
        'name'  => $name !== '' ? $name : 'No name',
        'phone' => $c['phone'] ?? '',
        'email' => $c['email'] ?? '',
        'spent' => $c['amountSpent']['amount'] ?? null,
        'cur'   => $c['amountSpent']['currencyCode'] ?? 'USD',
    ], 'orders' => $orders]);
}

default:
    out(['ok' => false, 'error' => 'Unknown action'], 400);
}
