<?php
/* ═══════════════════════════════════════════════════════════════════════════
   shop.php — the boutique's data.

   Sits between the browser and Shopify's Storefront API. The token could
   technically live in the page — it is designed to be public — but routing
   through here means one place to change it, one place to cache, and the
   chance to decide what the front end is told.

   That last point matters for stock. Shopify will report exact quantities and
   this deliberately does not pass them on: a customer needs to know whether
   something is available, not that two remain. Only availableForSale crosses
   the line.

     GET  ?action=products[&after=&q=]   the grid
          ?action=product&handle=        one product with its variants
          ?action=collections            for filtering
     POST ?action=checkout               {lines:[{variantId, quantity}]}
   ═══════════════════════════════════════════════════════════════════════════ */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

/* A POST from another domain is preceded by an OPTIONS request asking whether
   it is allowed. Answering that with the headers above and nothing else is
   what lets the checkout through. */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

const SHOP_DOMAIN      = 'ykxnu0-cd.myshopify.com';
const STOREFRONT_TOKEN = '14b556af77da264a7673a0fc6d914b24';
const API_VERSION      = '2025-01';

/* Shopify counts requests, and a boutique's catalogue changes slowly. A short
   cache keeps a busy page from asking the same question repeatedly. */
const CACHE_DIR     = __DIR__ . '/cache/shop';
const CACHE_SECONDS = 180;

function out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d);
    exit;
}

function gql(string $query, array $vars = [], ?string $cacheKey = null): array {
    if ($cacheKey !== null && CACHE_SECONDS > 0) {
        $f = CACHE_DIR . '/' . md5($cacheKey) . '.json';
        if (is_file($f) && (time() - filemtime($f)) < CACHE_SECONDS) {
            $c = json_decode((string)file_get_contents($f), true);
            if (is_array($c)) return $c;
        }
    }

    $payload = ['query' => $query];
    if ($vars) $payload['variables'] = $vars;

    $ch = curl_init('https://' . SHOP_DOMAIN . '/api/' . API_VERSION . '/graphql.json');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Shopify-Storefront-Access-Token: ' . STOREFRONT_TOKEN,
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) return ['ok' => false, 'error' => 'Could not reach the shop: ' . $err];

    $d = json_decode((string)$raw, true);
    if (!is_array($d)) return ['ok' => false, 'error' => 'The shop sent something unreadable'];

    if (!empty($d['errors'])) {
        $msg = $d['errors'][0]['message'] ?? 'Unknown error';
        error_log('[shop] ' . $msg);
        return ['ok' => false, 'error' => $msg, 'http' => $code];
    }

    $res = ['ok' => true, 'data' => $d['data'] ?? []];

    if ($cacheKey !== null && CACHE_SECONDS > 0) {
        @mkdir(CACHE_DIR, 0775, true);
        @file_put_contents(CACHE_DIR . '/' . md5($cacheKey) . '.json', json_encode($res));
    }
    return $res;
}

function money($m): ?string {
    if (!$m || !isset($m['amount'])) return null;
    return number_format((float)$m['amount'], 2);
}


/* Both the whole-shop and by-collection queries return the same shape, so the
   conversion lives in one place. */
function shape_products(array $conn): array {
    $items = [];

    foreach ($conn['edges'] ?? [] as $e) {
        $n = $e['node'];
        $imgs = [];
        foreach ($n['images']['edges'] ?? [] as $ie) $imgs[] = $ie['node']['url'];

        $min = $n['priceRange']['minVariantPrice'] ?? null;
        $max = $n['priceRange']['maxVariantPrice'] ?? null;
        $was = $n['compareAtPriceRange']['minVariantPrice'] ?? null;

        $items[] = [
            'handle'    => $n['handle'],
            'title'     => $n['title'],
            /* the only stock fact the front end is given */
            'available' => (bool)$n['availableForSale'],
            'image'     => $n['featuredImage']['url'] ?? ($imgs[0] ?? null),
            'alt'       => $n['featuredImage']['altText'] ?? $n['title'],
            'hover'     => $imgs[1] ?? null,
            'price'     => money($min),
            'price_max' => money($max),
            'varies'    => ($min['amount'] ?? null) !== ($max['amount'] ?? null),
            'was'       => ($was && (float)($was['amount'] ?? 0) > (float)($min['amount'] ?? 0))
                           ? money($was) : null,
            'currency'  => $min['currencyCode'] ?? 'USD',
        ];
    }

    return [
        'ok' => true,
        'products' => $items,
        'more'   => !empty($conn['pageInfo']['hasNextPage']),
        'cursor' => $conn['pageInfo']['endCursor'] ?? null,
    ];
}

/* ═══ one product ══════════════════════════════════════════════════════════ */

$action = $_GET['action'] ?? 'products';

switch ($action) {

/* ═══ the grid ═════════════════════════════════════════════════════════════ */
case 'products': {
    $after = trim((string)($_GET['after'] ?? ''));
    $q     = trim((string)($_GET['q'] ?? ''));
    $coll  = trim((string)($_GET['collection'] ?? ''));

    /* A collection is a different query in Shopify's API rather than a filter
       on the same one, so it is handled separately rather than bent into the
       search string. */
    if ($coll !== '') {
        $r = gql('
            query($handle: String!, $n: Int!, $after: String) {
              collection(handle: $handle) {
                products(first: $n, after: $after) {
                  edges {
                    cursor
                    node {
                      id handle title
                      availableForSale
                      featuredImage { url altText }
                      images(first: 2) { edges { node { url altText } } }
                      priceRange {
                        minVariantPrice { amount currencyCode }
                        maxVariantPrice { amount currencyCode }
                      }
                      compareAtPriceRange { minVariantPrice { amount } }
                    }
                  }
                  pageInfo { hasNextPage endCursor }
                }
              }
            }',
            ['handle' => $coll, 'n' => 24, 'after' => $after ?: null],
            'coll:' . $coll . ':' . $after
        );

        if (!$r['ok']) out(['ok' => false, 'error' => $r['error']], 502);

        $conn = $r['data']['collection']['products'] ?? null;
        if ($conn === null) out(['ok' => false, 'error' => 'No such category'], 404);

        out(shape_products($conn));
    }

    $r = gql('
        query($n: Int!, $after: String, $q: String) {
          products(first: $n, after: $after, query: $q, sortKey: BEST_SELLING) {
            edges {
              cursor
              node {
                id handle title
                availableForSale
                featuredImage { url altText }
                images(first: 2) { edges { node { url altText } } }
                priceRange {
                  minVariantPrice { amount currencyCode }
                  maxVariantPrice { amount currencyCode }
                }
                compareAtPriceRange { minVariantPrice { amount } }
              }
            }
            pageInfo { hasNextPage endCursor }
          }
        }',
        ['n' => 24, 'after' => $after ?: null, 'q' => $q ?: null],
        'products:' . $after . ':' . $q
    );

    if (!$r['ok']) out(['ok' => false, 'error' => $r['error']], 502);

    out(shape_products($r['data']['products'] ?? []));
}
case 'product': {
    $handle = trim((string)($_GET['handle'] ?? ''));
    if ($handle === '') out(['ok' => false, 'error' => 'handle required'], 400);

    $r = gql('
        query($handle: String!) {
          product(handle: $handle) {
            id handle title descriptionHtml
            availableForSale
            options { name values }
            images(first: 8) { edges { node { url altText } } }
            variants(first: 100) {
              edges {
                node {
                  id title availableForSale
                  price { amount currencyCode }
                  compareAtPrice { amount }
                  image { url }
                  selectedOptions { name value }
                }
              }
            }
          }
        }', ['handle' => $handle], 'product:' . $handle);

    if (!$r['ok']) out(['ok' => false, 'error' => $r['error']], 502);

    $p = $r['data']['product'] ?? null;
    if (!$p) out(['ok' => false, 'error' => 'No such product'], 404);

    $images = [];
    foreach ($p['images']['edges'] ?? [] as $e) {
        $images[] = ['url' => $e['node']['url'], 'alt' => $e['node']['altText'] ?? $p['title']];
    }

    $variants = [];
    foreach ($p['variants']['edges'] ?? [] as $e) {
        $v = $e['node'];
        $opts = [];
        foreach ($v['selectedOptions'] ?? [] as $o) $opts[$o['name']] = $o['value'];

        $variants[] = [
            'id'        => $v['id'],
            'title'     => $v['title'],
            /* again: whether, never how many */
            'available' => (bool)$v['availableForSale'],
            'price'     => money($v['price']),
            'was'       => ($v['compareAtPrice'] &&
                            (float)$v['compareAtPrice']['amount'] > (float)$v['price']['amount'])
                           ? money($v['compareAtPrice']) : null,
            'image'     => $v['image']['url'] ?? null,
            'options'   => $opts,
        ];
    }

    out([
        'ok' => true,
        'product' => [
            'handle'      => $p['handle'],
            'title'       => $p['title'],
            'description' => $p['descriptionHtml'],
            'available'   => (bool)$p['availableForSale'],
            'options'     => $p['options'],
            'images'      => $images,
            'variants'    => $variants,
            'currency'    => $variants[0]['currency'] ?? 'USD',
        ],
    ]);
}

case 'collections': {
    $r = gql('
        query { collections(first: 20, sortKey: TITLE) {
          edges { node { handle title } }
        } }', [], 'collections');

    if (!$r['ok']) out(['ok' => false, 'error' => $r['error']], 502);

    $c = [];
    foreach ($r['data']['collections']['edges'] ?? [] as $e) {
        $c[] = ['handle' => $e['node']['handle'], 'title' => $e['node']['title']];
    }
    out(['ok' => true, 'collections' => $c]);
}

/* ═══ checkout ═════════════════════════════════════════════════════════════
   The cart is turned into a Shopify cart, which returns the URL that takes
   payment. Card details never touch this server, which is the single best
   reason to let Shopify carry the checkout. */
case 'checkout': {
    $in = json_decode(file_get_contents('php://input'), true);
    $lines = is_array($in['lines'] ?? null) ? $in['lines'] : [];

    if (!$lines) out(['ok' => false, 'error' => 'Your bag is empty'], 400);

    $clean = [];
    foreach ($lines as $l) {
        $id  = (string)($l['variantId'] ?? '');
        $qty = (int)($l['quantity'] ?? 0);
        if ($id === '' || $qty < 1) continue;
        if ($qty > 99) $qty = 99;
        $clean[] = ['merchandiseId' => $id, 'quantity' => $qty];
    }
    if (!$clean) out(['ok' => false, 'error' => 'Nothing in the bag could be ordered'], 400);

    /* Attribution rides on the cart, so the order arrives at Shopify already
       carrying who sent the customer. Recording it here instead would mean
       guessing later which order matched which cart — this way Shopify holds
       the answer and the report just reads it. */
    $ref = preg_replace('/[^A-Za-z0-9._-]/', '', (string)($in['ref'] ?? ''));
    $ref = substr($ref, 0, 60);

    $attrs = [];
    if ($ref !== '') {
        $attrs[] = ['key' => 'referred_by', 'value' => $ref];
        $attrs[] = ['key' => 'referred_at', 'value' => date('c')];
    }

    $r = gql('
        mutation($lines: [CartLineInput!]!, $attrs: [AttributeInput!]) {
          cartCreate(input: { lines: $lines, attributes: $attrs }) {
            cart {
              checkoutUrl
              cost { totalAmount { amount currencyCode } }
            }
            userErrors { field message }
          }
        }', ['lines' => $clean, 'attrs' => $attrs ?: null]);

    if (!$r['ok']) out(['ok' => false, 'error' => $r['error']], 502);

    $errs = $r['data']['cartCreate']['userErrors'] ?? [];
    if ($errs) {
        out(['ok' => false, 'error' => $errs[0]['message'] ?? 'The bag could not be prepared'], 400);
    }

    $cart = $r['data']['cartCreate']['cart'] ?? null;
    if (!$cart || empty($cart['checkoutUrl'])) {
        out(['ok' => false, 'error' => 'No checkout could be created'], 502);
    }

    out([
        'ok' => true,
        'url' => $cart['checkoutUrl'],
        'ref' => $ref ?: null,
        'total' => money($cart['cost']['totalAmount'] ?? null),
        'currency' => $cart['cost']['totalAmount']['currencyCode'] ?? 'USD',
    ]);
}

default:
    out(['ok' => false, 'error' => 'Unknown action'], 400);
}
