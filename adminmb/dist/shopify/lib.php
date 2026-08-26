<?php
/* ═══════════════════════════════════════════════════════════════════════════
   lib.php — talking to Shopify, and the bits every page needs.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/config.php';

const TOKEN_FILE = __DIR__ . '/.token';

function shop_token(): string {
    if (SHOP_TOKEN !== '') return SHOP_TOKEN;
    if (is_file(TOKEN_FILE)) {
        $t = trim((string)file_get_contents(TOKEN_FILE));
        if ($t !== '') return $t;
    }
    return '';
}

function shop_ready(): bool { return shop_token() !== ''; }

/* One GraphQL call. Returns ['ok'=>bool, ...] rather than throwing, so a page
   can render the reason instead of a blank screen. */
function shop_query(string $query, array $vars = [], ?string $cacheKey = null): array {
    /* A null variable is the same problem — Shopify would rather the key were
       absent than present and empty. */
    $vars = array_filter($vars, fn($v) => $v !== null);
    $token = shop_token();
    if ($token === '') {
        return ['ok' => false, 'reason' => 'no_token',
                'message' => 'No access token yet.'];
    }

    if ($cacheKey !== null) {
        $f = __DIR__ . '/cache/' . preg_replace('/[^a-z0-9_-]/i', '', $cacheKey) . '.json';
        if (is_file($f) && (time() - filemtime($f)) < SHOP_CACHE_SECONDS) {
            $c = json_decode((string)file_get_contents($f), true);
            if (is_array($c)) { $c['cached'] = true; return $c; }
        }
    }

    $ch = curl_init('https://' . SHOP_DOMAIN . '/admin/api/' . SHOP_API_VERSION . '/graphql.json');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Shopify-Access-Token: ' . $token,
        ],
        /* Shopify rejects an empty variables object outright — "Invalid
           variables parameter" — so send the key only when there is something
           in it. */
        CURLOPT_POSTFIELDS => json_encode(
            $vars ? ['query' => $query, 'variables' => $vars] : ['query' => $query]
        ),
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'reason' => 'unreachable', 'message' => 'Could not reach Shopify: ' . $cerr];
    }
    if ($code === 401 || $code === 403) {
        return ['ok' => false, 'reason' => 'unauthorised',
                'message' => 'Shopify refused the token. It may have been revoked, or belong to a different store.'];
    }

    $d = json_decode((string)$raw, true);
    if (!is_array($d)) {
        return ['ok' => false, 'reason' => 'bad_reply',
                'message' => 'Shopify sent something unreadable (HTTP ' . $code . ').'];
    }
    if (!empty($d['errors'])) {
        $m = $d['errors'][0]['message'] ?? 'unknown error';
        /* the most common one by far, and the message alone is cryptic */
        if (stripos($m, 'access denied') !== false || stripos($m, 'scope') !== false) {
            $m .= ' — the app is missing a scope. Add it to the app, then reinstall so the '
                . 'permission is actually granted.';
        }
        return ['ok' => false, 'reason' => 'graphql', 'message' => $m];
    }

    $out = ['ok' => true, 'data' => $d['data'] ?? [], 'cached' => false];
    if ($cacheKey !== null) {
        @mkdir(__DIR__ . '/cache', 0775, true);
        @file_put_contents(__DIR__ . '/cache/' . preg_replace('/[^a-z0-9_-]/i', '', $cacheKey) . '.json',
                           json_encode($out));
    }
    return $out;
}

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function money($amount, string $cur = 'USD'): string {
    if ($amount === null || $amount === '') return '—';
    $sym = ['USD' => '$', 'CAD' => 'CA$', 'GBP' => '£', 'EUR' => '€'][$cur] ?? ($cur . ' ');
    return $sym . number_format((float)$amount, 2);
}

function when($iso): string {
    if (!$iso) return '—';
    $t = strtotime((string)$iso);
    return $t ? date('j M Y', $t) : '—';
}

function when_full($iso): string {
    if (!$iso) return '—';
    $t = strtotime((string)$iso);
    return $t ? date('j M Y, g:ia', $t) : '—';
}

/* Shopify ids look like gid://shopify/Customer/12345 — the tail is what the
   admin URL wants. */
function gid_num(?string $gid): string {
    if (!$gid) return '';
    $p = explode('/', $gid);
    return end($p) ?: '';
}

/* ── where a product comes from ──────────────────────────────────────────
   Trendsi fulfils as a service rather than holding a Shopify location, so
   there is no location to filter on. The vendor field is what actually
   separates the two catalogues — Trendsi stamps its own name on everything it
   supplies — and it stays put when apps are reconnected, which locations do
   not. */
const VENDOR_TRENDSI = 'Trendsi';

function shop_vendors(): array {
    $cache = __DIR__ . '/cache/vendors.json';
    if (is_file($cache) && (time() - filemtime($cache)) < 3600) {
        $c = json_decode((string)file_get_contents($cache), true);
        if (is_array($c) && $c) return $c;
    }

    /* shop.productVendors is the cheap way to ask; it returns the distinct
       list without paging through every product */
    $r = shop_query('{ shop { productVendors(first: 50) { edges { node } } } }');
    $out = [];
    if ($r['ok']) {
        foreach ($r['data']['shop']['productVendors']['edges'] ?? [] as $ed) {
            $v = is_array($ed['node'] ?? null) ? '' : (string)($ed['node'] ?? '');
            if ($v !== '') $out[] = $v;
        }
    }

    if (!$out) {
        /* fall back to reading them off the products themselves */
        $r2 = shop_query('{ products(first: 100) { edges { node { vendor } } } }');
        if ($r2['ok']) {
            $seen = [];
            foreach ($r2['data']['products']['edges'] ?? [] as $ed) {
                $v = trim((string)($ed['node']['vendor'] ?? ''));
                if ($v !== '') $seen[$v] = true;
            }
            $out = array_keys($seen);
        }
    }

    sort($out);
    if ($out) {
        @mkdir(__DIR__ . '/cache', 0775, true);
        @file_put_contents($cache, json_encode($out));
    }
    return $out;
}

/* Trendsi is the supplier and gets its own mark; everything else is ours. */
function vendor_kind(string $vendor): string {
    return stripos($vendor, 'trendsi') !== false ? 'trendsi' : 'shop';
}

/* The two tabs, resolved to real vendor names where they exist. */
function shop_tabs(): array {
    $vendors = shop_vendors();
    $trendsi = null;
    $own     = null;
    foreach ($vendors as $v) {
        if (vendor_kind($v) === 'trendsi') { $trendsi = $trendsi ?? $v; }
        else                               { $own     = $own     ?? $v; }
    }
    return [
        ['kind' => 'trendsi', 'vendor' => $trendsi, 'label' => $trendsi ?? 'Trendsi'],
        ['kind' => 'shop',    'vendor' => $own,     'label' => $own     ?? 'Shop'],
    ];
}
