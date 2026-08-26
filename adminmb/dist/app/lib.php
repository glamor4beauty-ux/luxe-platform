<?php
/* ═══════════════════════════════════════════════════════════════════════════
   lib.php — the shared parts of the Shopify app.

   A merchant who installs this becomes an affiliate. Their shop shows our
   products, their customers buy from our store, and they earn commission —
   so the shop domain is simply another way of identifying an affiliate, and
   everything already built for affiliates applies to them.

   Every request that claims to come from Shopify is verified before it is
   believed. There are three different ways Shopify signs things and each
   needs its own check:

     the install redirect   an hmac query parameter
     webhooks               a base64 header over the raw body
     embedded page calls    a session token, which is a JWT

   Getting any of them wrong means trusting a request that anyone could have
   made.
   ═══════════════════════════════════════════════════════════════════════════ */

/* ── the app itself ───────────────────────────────────────────────────── */
const APP_KEY    = '47fc6b64c3c7853a057047c3b71660b1';   /* from the Shopify dev dashboard */
const APP_SECRET = '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';
const APP_URL    = 'https://admin.modelsboutique.com/app';
const APP_SCOPES = 'read_products,write_script_tags';

/* our own shop, whose products every affiliate sells */
const HOME_SHOP  = 'ykxnu0-cd.myshopify.com';
const HOME_API   = 'https://admin.modelsboutique.com/api';

const API_VERSION = '2025-01';

function app_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=models_boutique;charset=utf8mb4',
                   'mb_app', 'Mb7#kRq2vLx9Twn4', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function app_tables(PDO $db): void {
    /* One row per shop that has installed. The affiliate code links it to
       everything already built — commission, sales, payouts — so a merchant
       is an affiliate who happens to have arrived through Shopify. */
    $db->exec("CREATE TABLE IF NOT EXISTS mb_shops (
        shop          VARCHAR(120) PRIMARY KEY,
        token         VARCHAR(255) NOT NULL DEFAULT '',
        scopes        VARCHAR(500) NOT NULL DEFAULT '',
        code          VARCHAR(20)  NOT NULL DEFAULT '',
        shop_name     VARCHAR(160) NOT NULL DEFAULT '',
        shop_email    VARCHAR(160) NOT NULL DEFAULT '',
        owner_name    VARCHAR(160) NOT NULL DEFAULT '',
        country       VARCHAR(8)   NOT NULL DEFAULT '',
        plan          VARCHAR(60)  NOT NULL DEFAULT '',
        installed_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        uninstalled   TIMESTAMP NULL,
        last_seen     TIMESTAMP NULL,
        KEY k_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    /* Shopify asks what we did with a customer's data when they request it,
       and expects a record. Keeping what was asked and when is the only
       honest answer. */
    $db->exec("CREATE TABLE IF NOT EXISTS mb_privacy_log (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        shop       VARCHAR(120) NOT NULL DEFAULT '',
        topic      VARCHAR(60)  NOT NULL DEFAULT '',
        payload    TEXT,
        answered   VARCHAR(255) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY k_shop (shop)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/* ── is this really Shopify ────────────────────────────────────────────── */

/* The install redirect signs its query string. Everything except hmac is
   sorted and joined, and the result compared — timing-safe, because a
   comparison that returns early leaks the answer one character at a time. */
function app_check_query(array $q): bool {
    $hmac = (string)($q['hmac'] ?? '');
    if ($hmac === '') return false;

    unset($q['hmac'], $q['signature']);
    ksort($q);

    $pairs = [];
    foreach ($q as $k => $v) {
        $k = str_replace(['&', '%', '='], ['%26', '%25', '%3D'], (string)$k);
        $v = str_replace(['&', '%'], ['%26', '%25'], (string)$v);
        $pairs[] = $k . '=' . $v;
    }

    return hash_equals(hash_hmac('sha256', implode('&', $pairs), APP_SECRET), $hmac);
}

/* Webhooks sign the raw body, and the signature arrives base64 rather than
   hex. The body must be read before anything else touches it. */
function app_check_webhook(string $body, string $header): bool {
    if ($header === '') return false;
    return hash_equals(base64_encode(hash_hmac('sha256', $body, APP_SECRET, true)), $header);
}

/* An embedded page's calls carry a session token — a JWT signed with the app
   secret. It proves the request came from a real admin session, which a
   cookie cannot in a third-party frame. */
function app_check_session(string $jwt): ?array {
    $bits = explode('.', $jwt);
    if (count($bits) !== 3) return null;

    [$h, $p, $s] = $bits;

    $expect = rtrim(strtr(base64_encode(
        hash_hmac('sha256', $h . '.' . $p, APP_SECRET, true)), '+/', '-_'), '=');
    if (!hash_equals($expect, $s)) return null;

    $claims = json_decode((string)base64_decode(strtr($p, '-_', '+/')), true);
    if (!is_array($claims)) return null;

    /* an expired token is not a token */
    if (($claims['exp'] ?? 0) < time()) return null;
    if (($claims['nbf'] ?? 0) > time() + 5) return null;
    if (($claims['aud'] ?? '') !== APP_KEY) return null;

    /* dest is the shop it came from, and it is the only trustworthy source
       of that — a shop parameter in a URL is one anybody could type */
    $shop = parse_url((string)($claims['dest'] ?? ''), PHP_URL_HOST);
    if (!$shop) return null;

    $claims['shop'] = $shop;
    return $claims;
}

/* A shop domain from a URL, made safe. Anything that is not a myshopify
   address is refused rather than tidied, because a near-miss is more likely
   an attempt than a typo. */
function app_shop(?string $raw): string {
    $s = strtolower(trim((string)$raw));
    $s = preg_replace('#^https?://#', '', $s);
    $s = rtrim(explode('/', $s)[0], '.');
    return preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/', $s) ? $s : '';
}

/* ── talking to a merchant's shop ─────────────────────────────────────── */
function app_call(string $shop, string $path, array $body = null, string $method = 'GET'): array {
    $q = app_db()->prepare("SELECT token FROM mb_shops WHERE shop = ? AND uninstalled IS NULL");
    $q->execute([$shop]);
    $token = (string)$q->fetchColumn();
    if ($token === '') return ['ok' => false, 'error' => 'That shop is not connected.'];

    $ch = curl_init('https://' . $shop . '/admin/api/' . API_VERSION . '/' . ltrim($path, '/'));
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json',
                                   'X-Shopify-Access-Token: ' . $token],
        CURLOPT_TIMEOUT        => 25,
    ];
    if ($body !== null) {
        $opts[CURLOPT_CUSTOMREQUEST] = $method === 'GET' ? 'POST' : $method;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) return ['ok' => false, 'error' => 'Could not reach the shop.'];
    $d = json_decode((string)$raw, true);
    if (!is_array($d)) return ['ok' => false, 'error' => 'Unreadable reply', 'http' => $code];
    if (!empty($d['errors'])) return ['ok' => false, 'error' => is_string($d['errors'])
        ? $d['errors'] : json_encode($d['errors'])];
    return ['ok' => true, 'data' => $d];
}

/* ── the affiliate behind a shop ──────────────────────────────────────── */

/* Made from the shop name, the same shape as every other code, so a merchant
   and a person are the same thing to everything downstream. */
function app_make_code(PDO $db, string $shopName, string $shop): string {
    $stem = strtoupper(preg_replace('/[^A-Za-z]/', '', $shopName));
    if (strlen($stem) < 3) {
        $stem = strtoupper(preg_replace('/[^A-Za-z]/', '', explode('.', $shop)[0]));
    }
    $stem = substr($stem ?: 'SHOP', 0, 4);

    for ($i = 0; $i < 60; $i++) {
        $try = 'AFF' . $stem . str_pad((string)random_int(0, 99), 2, '0', STR_PAD_LEFT);
        $c = $db->prepare("SELECT 1 FROM affiliates WHERE code = ?");
        $c->execute([$try]);
        if (!$c->fetch()) return $try;
    }
    return 'AFF' . $stem . substr(bin2hex(random_bytes(2)), 0, 3);
}

function app_log(string $what): void {
    error_log('[mb-app] ' . $what);
}
