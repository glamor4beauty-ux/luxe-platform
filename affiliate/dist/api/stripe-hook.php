<?php
/* ═══════════════════════════════════════════════════════════════════════════
   stripe-hook.php — subscriptions, and what happens when one is paid.

   Two entry points in one file, because they are two halves of the same
   conversation: we send somebody to Stripe, and Stripe tells us how it went.

     POST ?action=checkout   {code, plan}    → a Stripe checkout address
     POST (no action)                        → Stripe's callback

   Provisioning happens on the webhook, never on the browser redirect. A
   customer who pays and then closes the tab still gets her site: the redirect
   is a courtesy, the webhook is the record.

   Every callback is verified against the signing secret. Without that check,
   anyone who knows this address could post "payment succeeded" and be given a
   site for nothing.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/../config.php';

const STRIPE_SECRET = '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';
const STRIPE_HOOK   = 'whsec_nD48l8HvIQSOSBEJz9F8ibClhXWWoYZw';

const PRICE_MONTHLY   = 'price_1U6k9iFIT6ITVFPTvYZJms4o';   /* $29.99 a month */
const PRICE_QUARTERLY = 'price_1U6kGcFIT6ITVFPTyRq0EgV7';   /* $89.95 every three */
/* A real subscription at a nominal price, for walking the whole path without
   refunding thirty dollars each time. Remove when testing is done. */
const PRICE_TEST      = 'price_1U6fjuFIT6ITVFPTkq66sQGy';   /* $2.00 a year */

const SITE = 'https://affiliate.modelsboutique.com';

function out(array $d, int $c = 200): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($c);
    echo json_encode($d);
    exit;
}

/* Stripe's API, spoken directly. A library would be four hundred files to do
   what three requests need, and one less thing to keep updated. */
function stripe(string $path, array $data = [], string $method = 'POST'): array {
    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');

    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => STRIPE_SECRET . ':',
        CURLOPT_TIMEOUT        => 25,
    ];

    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        /* Stripe wants nested data as bracketed form fields rather than JSON */
        $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
    }

    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) return ['ok' => false, 'error' => 'Could not reach Stripe: ' . $err];

    $d = json_decode((string)$raw, true);
    if (!is_array($d)) return ['ok' => false, 'error' => 'Stripe sent something unreadable'];

    if (isset($d['error'])) {
        return ['ok' => false, 'error' => $d['error']['message'] ?? 'Stripe declined that',
                'code' => $d['error']['code'] ?? '', 'raw' => $d['error']];
    }
    return ['ok' => true, 'data' => $d];
}

function tables(PDO $db): void {
    /* What Stripe has told us, kept so the dashboard does not have to ask
       Stripe every time somebody looks at a list. */
    $db->exec("CREATE TABLE IF NOT EXISTS affiliate_subs (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        code          VARCHAR(20) NOT NULL,
        plan          ENUM('monthly','quarterly','test') NOT NULL DEFAULT 'monthly',
        stripe_cust   VARCHAR(80) NOT NULL DEFAULT '',
        stripe_sub    VARCHAR(80) NOT NULL DEFAULT '',
        stripe_status VARCHAR(40) NOT NULL DEFAULT '',
        amount        DECIMAL(8,2) NOT NULL DEFAULT 0,
        started       TIMESTAMP NULL,
        renews        DATE NULL,
        last_paid     TIMESTAMP NULL,
        last_failure  VARCHAR(255) NOT NULL DEFAULT '',
        provisioned   TINYINT(1) NOT NULL DEFAULT 0,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY u_code (code),
        KEY k_sub (stripe_sub)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    /* Every callback, kept whether or not it was understood. When a payment
       is disputed months later, "what did Stripe actually tell us" is the
       only question worth asking, and memory is not an answer. */
    $db->exec("CREATE TABLE IF NOT EXISTS stripe_events (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        event_id   VARCHAR(80) NOT NULL,
        type       VARCHAR(80) NOT NULL DEFAULT '',
        code       VARCHAR(20) NOT NULL DEFAULT '',
        handled    TINYINT(1) NOT NULL DEFAULT 0,
        note       VARCHAR(255) NOT NULL DEFAULT '',
        payload    MEDIUMTEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY u_event (event_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/* ═══ starting a subscription ══════════════════════════════════════════════ */
function start_checkout(PDO $db): void {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];

    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($in['code'] ?? '')));
    $want = (string)($in['plan'] ?? 'monthly');
    $plan = in_array($want, ['quarterly', 'test'], true) ? $want : 'monthly';

    if ($code === '') out(['ok' => false, 'error' => 'No account was given.'], 400);

    $q = $db->prepare("SELECT * FROM affiliates WHERE code = ?");
    $q->execute([$code]);
    $a = $q->fetch(PDO::FETCH_ASSOC);
    if (!$a) out(['ok' => false, 'error' => 'That account could not be found.'], 404);

    /* Already paying: send her to manage it rather than starting a second
       subscription, which is a refund conversation nobody wants. */
    $s = $db->prepare("SELECT stripe_status FROM affiliate_subs WHERE code = ?");
    $s->execute([$code]);
    $existing = $s->fetchColumn();
    if (in_array($existing, ['active', 'trialing'], true)) {
        out(['ok' => false, 'already' => true,
             'error' => 'This account already has a subscription running.'], 400);
    }

    $r = stripe('checkout/sessions', [
        'mode'                 => 'subscription',
        'line_items[0][price]'    => $plan === 'quarterly' ? PRICE_QUARTERLY
                                   : ($plan === 'test' ? PRICE_TEST : PRICE_MONTHLY),
        'line_items[0][quantity]' => 1,
        'customer_email'       => $a['email'],
        'success_url'          => SITE . '/welcome.html?code=' . $code . '&session={CHECKOUT_SESSION_ID}',
        'cancel_url'           => SITE . '/subscribe.html?code=' . $code . '&cancelled=1',
        /* the affiliate code travels with the payment, so the callback knows
           whose site to build without matching on an email address */
        'client_reference_id'  => $code,
        'metadata[code]'       => $code,
        'metadata[plan]'       => $plan,
        'subscription_data[metadata][code]' => $code,
        'allow_promotion_codes' => 'true',
        'billing_address_collection' => 'required',
    ]);

    if (!$r['ok']) {
        error_log('[stripe] checkout: ' . $r['error']);
        out(['ok' => false, 'error' => 'The payment page could not be opened. '
            . 'Try again in a moment.'], 502);
    }

    $db->prepare("INSERT INTO affiliate_subs (code, plan, amount)
                  VALUES (?,?,?)
                  ON DUPLICATE KEY UPDATE plan = VALUES(plan), amount = VALUES(amount)")
       ->execute([$code, $plan,
                  $plan === 'quarterly' ? 89.95 : ($plan === 'test' ? 2.00 : 29.99)]);

    out(['ok' => true, 'url' => $r['data']['url']]);
}

/* ═══ Stripe calling back ══════════════════════════════════════════════════
   Verified before anything is believed. The signature covers a timestamp and
   the exact body, so a replayed or edited callback fails. */
function verify(string $payload, string $header): bool {
    if ($header === '') return false;

    $t = null; $sigs = [];
    foreach (explode(',', $header) as $part) {
        $bits = explode('=', trim($part), 2);
        if (count($bits) !== 2) continue;
        if ($bits[0] === 't') $t = $bits[1];
        if ($bits[0] === 'v1') $sigs[] = $bits[1];
    }
    if ($t === null || !$sigs) return false;

    /* five minutes, so a captured callback cannot be replayed tomorrow */
    if (abs(time() - (int)$t) > 300) return false;

    $expected = hash_hmac('sha256', $t . '.' . $payload, STRIPE_HOOK);
    foreach ($sigs as $s) {
        if (hash_equals($expected, $s)) return true;
    }
    return false;
}

function handle_hook(PDO $db): void {
    $payload = file_get_contents('php://input');
    $sig = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

    if (!verify($payload, $sig)) {
        error_log('[stripe] a callback failed verification');
        http_response_code(400);
        exit('signature');
    }

    $ev = json_decode($payload, true);
    if (!is_array($ev)) { http_response_code(400); exit('unreadable'); }

    $id   = (string)($ev['id'] ?? '');
    $type = (string)($ev['type'] ?? '');
    $obj  = $ev['data']['object'] ?? [];

    /* Stripe retries on any non-200, so the same event arrives more than once.
       Recording the id first means the second delivery does nothing. */
    try {
        $db->prepare("INSERT INTO stripe_events (event_id, type, payload) VALUES (?,?,?)")
           ->execute([$id, $type, substr($payload, 0, 60000)]);
    } catch (Throwable $e) {
        http_response_code(200);
        exit('seen');
    }

    $code = strtoupper((string)(
        $obj['client_reference_id']
        ?? $obj['metadata']['code']
        ?? $obj['subscription_details']['metadata']['code']
        ?? ''
    ));

    $note = '';

    switch ($type) {

    case 'checkout.session.completed': {
        if ($code === '') { $note = 'no affiliate code on the session'; break; }

        $db->prepare("UPDATE affiliate_subs
                         SET stripe_cust = ?, stripe_sub = ?, stripe_status = 'active',
                             started = NOW(), last_paid = NOW(), last_failure = ''
                       WHERE code = ?")
           ->execute([
               (string)($obj['customer'] ?? ''),
               (string)($obj['subscription'] ?? ''),
               $code,
           ]);

        $db->prepare("UPDATE affiliates SET status = 'active', stripe_id = ? WHERE code = ?")
           ->execute([(string)($obj['customer'] ?? ''), $code]);

        /* the site is built here, on the payment, not on the redirect */
        $note = provision($db, $code);
        break;
    }

    case 'invoice.paid': {
        $sub = (string)($obj['subscription'] ?? '');
        $db->prepare("UPDATE affiliate_subs
                         SET stripe_status = 'active', last_paid = NOW(),
                             last_failure = '',
                             renews = FROM_UNIXTIME(?, '%Y-%m-%d')
                       WHERE stripe_sub = ?")
           ->execute([(int)($obj['period_end'] ?? time()), $sub]);

        /* a renewal on a suspended account brings it back */
        $db->prepare("UPDATE affiliates a
                        JOIN affiliate_subs s ON s.code = a.code
                         SET a.status = 'active'
                       WHERE s.stripe_sub = ? AND a.status = 'inactive'")
           ->execute([$sub]);

        $note = 'renewed';
        break;
    }

    case 'invoice.payment_failed': {
        $sub = (string)($obj['subscription'] ?? '');

        /* Plain words, because this is read by whoever has to ring her up.
           "card_declined" tells them nothing they can say out loud. */
        $why = $obj['last_payment_error']['message']
            ?? $obj['last_finalization_error']['message']
            ?? 'The card was declined.';

        $db->prepare("UPDATE affiliate_subs
                         SET stripe_status = 'past_due', last_failure = ?
                       WHERE stripe_sub = ?")
           ->execute([substr((string)$why, 0, 255), $sub]);

        /* Nothing is switched off here. Stripe retries a failed payment over
           several days, and taking somebody's shop down on the first decline
           would cost her sales over a card that expired. */
        $note = 'payment failed: ' . substr((string)$why, 0, 120);
        break;
    }

    case 'customer.subscription.deleted': {
        $sub = (string)($obj['id'] ?? '');
        $db->prepare("UPDATE affiliate_subs SET stripe_status = 'cancelled' WHERE stripe_sub = ?")
           ->execute([$sub]);
        $db->prepare("UPDATE affiliates a JOIN affiliate_subs s ON s.code = a.code
                         SET a.status = 'inactive'
                       WHERE s.stripe_sub = ?")
           ->execute([$sub]);
        $note = 'cancelled';
        break;
    }

    default:
        $note = 'not acted on';
    }

    $db->prepare("UPDATE stripe_events SET handled = 1, code = ?, note = ? WHERE event_id = ?")
       ->execute([$code, substr($note, 0, 255), $id]);

    http_response_code(200);
    echo 'ok';
    exit;
}

/* ═══ building her site ════════════════════════════════════════════════════
   Called once, when the first payment clears. Deliberately forgiving: if the
   install fails, the payment still stands and somebody can finish it by hand.
   Refusing the money because a folder could not be made would be worse. */
function provision(PDO $db, string $code): string {
    $q = $db->prepare("SELECT * FROM affiliates WHERE code = ?");
    $q->execute([$code]);
    $a = $q->fetch(PDO::FETCH_ASSOC);
    if (!$a) return 'no such affiliate';

    $done = $db->prepare("SELECT provisioned FROM affiliate_subs WHERE code = ?");
    $done->execute([$code]);
    if ((int)$done->fetchColumn() === 1) return 'already built';

    $script = __DIR__ . '/../bin/provision.sh';
    if (!is_file($script)) return 'the install script is not on the server';

    /* Run detached: Stripe waits for this response, and a callback that takes
       thirty seconds is a callback Stripe gives up on and retries. */
    $cmd = 'nohup ' . escapeshellcmd($script) . ' ' . escapeshellarg($code)
         . ' >> /var/log/mb-provision.log 2>&1 &';
    @exec($cmd);

    $db->prepare("UPDATE affiliate_subs SET provisioned = 1 WHERE code = ?")->execute([$code]);
    return 'install started';
}

/* ═══════════════════════════════════════════════════════════════════════════ */
try {
    $db = db();
    tables($db);

    if (($_GET['action'] ?? '') === 'checkout') { start_checkout($db); }
    handle_hook($db);

} catch (Throwable $e) {
    error_log('[stripe] ' . $e->getMessage() . ' @ ' . $e->getLine());
    /* A 500 makes Stripe retry, which is right for a passing fault and wrong
       for a permanent one — but a retry costs nothing and a lost payment
       record costs a great deal. */
    http_response_code(500);
    echo 'error';
}
