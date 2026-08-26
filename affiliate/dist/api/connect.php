<?php
/* ═══════════════════════════════════════════════════════════════════════════
   connect.php — paying affiliates through their own Stripe account.

   Stripe Connect, Express accounts. She goes to Stripe, proves who she is,
   adds a bank account, and Stripe decides. We never see her bank details or
   her identity documents, which is the whole reason for using it.

   What happens when Stripe says no is the part worth getting right. It is not
   an error and it is not her fault — some people cannot be verified for
   reasons that have nothing to do with honesty. So: her dashboard says plainly
   that payouts cannot go through Stripe and that we will arrange another way,
   and an email tells the studio so somebody can pick up the phone.

     GET  ?action=start      → her onboarding link
          ?action=status     → where she has got to
          ?action=refresh    → a fresh link, when the old one expired
     POST (no action)        → Stripe's callback
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/../config.php';

const STRIPE_SECRET = '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';
const STRIPE_HOOK   = 'whsec_nD48l8HvIQSOSBEJz9F8ibClhXWWoYZw';

const SITE       = 'https://affiliate.modelsboutique.com';
const STUDIO_TO  = 'support@modelsboutique.com';
const STUDIO_FROM = 'noreply@modelsboutique.com';

function out(array $d, int $c = 200): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($c);
    echo json_encode($d);
    exit;
}

function stripe(string $path, array $data = [], string $method = 'POST'): array {
    $ch = curl_init('https://api.stripe.com/v1/' . ltrim($path, '/'));
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => STRIPE_SECRET . ':',
        CURLOPT_TIMEOUT        => 25,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
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
        return ['ok' => false, 'error' => $d['error']['message'] ?? 'Stripe refused that',
                'code' => $d['error']['code'] ?? ''];
    }
    return ['ok' => true, 'data' => $d];
}

function tables(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS affiliate_stripe (
        code          VARCHAR(20) PRIMARY KEY,
        acct          VARCHAR(80) NOT NULL DEFAULT '',
        /* pending  — she has not finished, or Stripe is still deciding
           enabled  — approved, payouts can go
           blocked  — Stripe will not verify her; pay another way
           disabled — was enabled, has stopped being so */
        state         ENUM('pending','enabled','blocked','disabled') NOT NULL DEFAULT 'pending',
        needs         TEXT,
        reason        VARCHAR(255) NOT NULL DEFAULT '',
        started       TIMESTAMP NULL,
        settled       TIMESTAMP NULL,
        studio_told   TINYINT(1) NOT NULL DEFAULT 0,
        updated_at    TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/* ── what Stripe's answer means for her ───────────────────────────────────
   Three questions matter: can she be paid, is anything outstanding, and has
   Stripe given up. Everything else in the account object is detail. */
function read_account(array $a): array {
    $req = $a['requirements'] ?? [];

    $payouts = !empty($a['payouts_enabled']);
    $due     = array_merge($req['currently_due'] ?? [], $req['past_due'] ?? []);
    $blocked = !empty($req['disabled_reason']);

    /* "rejected" or a listed requirement Stripe says can never be satisfied
       means this is not going to happen, whatever she does next */
    $final = $blocked && (
        strpos((string)$req['disabled_reason'], 'rejected') !== false
        || strpos((string)$req['disabled_reason'], 'listed') !== false
    );

    if ($final)   return ['state' => 'blocked',  'needs' => $due,
                          'reason' => (string)$req['disabled_reason']];
    if ($payouts) return ['state' => 'enabled',  'needs' => [], 'reason' => ''];
    if ($blocked) return ['state' => 'pending',  'needs' => $due,
                          'reason' => (string)$req['disabled_reason']];
    return ['state' => 'pending', 'needs' => $due, 'reason' => ''];
}

/* Plain words for what Stripe asks for in its own vocabulary, because
   "individual.verification.document" tells nobody anything. */
function plain_needs(array $needs): array {
    $said = [];
    foreach ($needs as $n) {
        $t = (string)$n;
        if (strpos($t, 'verification.document') !== false) $said['id'] = 'A photo of your ID';
        elseif (strpos($t, 'external_account') !== false)  $said['bank'] = 'Your bank details';
        elseif (strpos($t, 'dob') !== false)               $said['dob'] = 'Your date of birth';
        elseif (strpos($t, 'address') !== false)           $said['addr'] = 'Your address';
        elseif (strpos($t, 'ssn') !== false || strpos($t, 'id_number') !== false)
                                                           $said['tax'] = 'Your tax number';
        elseif (strpos($t, 'phone') !== false)             $said['phone'] = 'A phone number';
        elseif (strpos($t, 'email') !== false)             $said['email'] = 'An email address';
        elseif (strpos($t, 'tos_acceptance') !== false)    $said['tos'] = 'Accepting Stripe\'s terms';
        else                                               $said[$t] = 'Something else Stripe asks for';
    }
    return array_values($said);
}

/* ── telling the studio ───────────────────────────────────────────────────
   Only when Stripe has decided against her, and only once. A person has to
   ring her up and arrange another way to pay, and that does not happen unless
   somebody is told. */
function tell_studio(PDO $db, array $aff, string $reason): void {
    $q = $db->prepare("SELECT studio_told FROM affiliate_stripe WHERE code = ?");
    $q->execute([$aff['code']]);
    if ((int)$q->fetchColumn() === 1) return;

    $who  = trim($aff['first_name'] . ' ' . $aff['last_name']) ?: $aff['code'];
    $subj = 'Payout setup failed — ' . $who . ' (' . $aff['code'] . ')';

    $body = "Stripe will not verify this affiliate, so payouts cannot go through them.\n\n"
          . "  Name    " . $who . "\n"
          . "  Code    " . $aff['code'] . "\n"
          . "  Store   " . ($aff['store_name'] ?: '—') . "\n"
          . "  Email   " . $aff['email'] . "\n"
          . "  Phone   " . ($aff['phone'] ?: '—') . "\n\n"
          . "  Stripe's reason: " . ($reason ?: 'not given') . "\n\n"
          . "She has been told that payouts cannot go through Stripe and that we\n"
          . "will arrange another way. Somebody should get in touch.\n\n"
          . "Her commission is still earned and still owed — this is only about\n"
          . "how it reaches her.\n\n"
          . SITE . "\n";

    @mail(STUDIO_TO, $subj, $body,
          "From: " . STUDIO_FROM . "\r\n"
        . "Reply-To: " . $aff['email'] . "\r\n"
        . "X-Mailer: Models Boutique\r\n");

    $db->prepare("UPDATE affiliate_stripe SET studio_told = 1 WHERE code = ?")
       ->execute([$aff['code']]);
}

/* ── keeping our copy in step ─────────────────────────────────────────── */
function sync(PDO $db, string $code, array $acct): array {
    $read = read_account($acct);

    $db->prepare("INSERT INTO affiliate_stripe (code, acct, state, needs, reason, settled)
                  VALUES (?,?,?,?,?, CASE WHEN ? IN ('enabled','blocked') THEN NOW() ELSE NULL END)
                  ON DUPLICATE KEY UPDATE
                    acct = VALUES(acct), state = VALUES(state),
                    needs = VALUES(needs), reason = VALUES(reason),
                    settled = COALESCE(affiliate_stripe.settled, VALUES(settled))")
       ->execute([$code, (string)($acct['id'] ?? ''), $read['state'],
                  json_encode($read['needs']), $read['reason'], $read['state']]);

    if ($read['state'] === 'blocked') {
        $q = $db->prepare("SELECT * FROM affiliates WHERE code = ?");
        $q->execute([$code]);
        if ($aff = $q->fetch()) tell_studio($db, $aff, $read['reason']);
    }

    return $read;
}

/* ═══════════════════════════════════════════════════════════════════════ */
try {
    $db = db();
    tables($db);
    $action = $_GET['action'] ?? '';

    /* ── Stripe calling back ─────────────────────────────────────────────
       account.updated arrives whenever her verification moves, which is how
       we learn she has been approved or refused without asking repeatedly. */
    if ($action === '') {
        $payload = file_get_contents('php://input');
        $sig = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

        $t = null; $sigs = [];
        foreach (explode(',', $sig) as $part) {
            $b = explode('=', trim($part), 2);
            if (count($b) !== 2) continue;
            if ($b[0] === 't')  $t = $b[1];
            if ($b[0] === 'v1') $sigs[] = $b[1];
        }
        $good = false;
        if ($t !== null && $sigs && abs(time() - (int)$t) <= 300) {
            $expect = hash_hmac('sha256', $t . '.' . $payload, STRIPE_HOOK);
            foreach ($sigs as $s) if (hash_equals($expect, $s)) $good = true;
        }
        if (!$good) { http_response_code(400); exit('signature'); }

        $ev = json_decode($payload, true);
        $type = (string)($ev['type'] ?? '');
        $obj  = $ev['data']['object'] ?? [];

        if ($type === 'account.updated' || $type === 'account.application.deauthorized') {
            $acctId = (string)($obj['id'] ?? '');
            $q = $db->prepare("SELECT code FROM affiliate_stripe WHERE acct = ?");
            $q->execute([$acctId]);
            if ($code = $q->fetchColumn()) {
                if ($type === 'account.application.deauthorized') {
                    $db->prepare("UPDATE affiliate_stripe SET state='disabled' WHERE code=?")
                       ->execute([$code]);
                } else {
                    sync($db, (string)$code, $obj);
                }
            }
        }

        http_response_code(200);
        exit('ok');
    }

    /* everything below is hers, and only hers */
    $me = mb_affiliate();
    if (!$me) out(['ok' => false, 'error' => 'Not signed in'], 401);
    $code = strtoupper($me['code']);

    $q = $db->prepare("SELECT * FROM affiliates WHERE code = ?");
    $q->execute([$code]);
    $aff = $q->fetch();
    if (!$aff) out(['ok' => false, 'error' => 'No record found'], 404);

    $q = $db->prepare("SELECT * FROM affiliate_stripe WHERE code = ?");
    $q->execute([$code]);
    $row = $q->fetch() ?: null;

    switch ($action) {

    /* ── where she has got to ─────────────────────────────────────────── */
    case 'status': {
        if (!$row || $row['acct'] === '') {
            out(['ok' => true, 'state' => 'none',
                 'message' => 'Set up payouts to get paid directly to your bank.']);
        }

        /* asked fresh, because a webhook can be missed and being wrong about
           whether somebody can be paid is worse than one extra request */
        $r = stripe('accounts/' . $row['acct'], [], 'GET');
        $read = $r['ok'] ? sync($db, $code, $r['data'])
                         : ['state' => $row['state'],
                            'needs' => json_decode((string)$row['needs'], true) ?: [],
                            'reason' => $row['reason']];

        $out = ['ok' => true, 'state' => $read['state']];

        if ($read['state'] === 'enabled') {
            $out['message'] = 'Payouts are set up. Your commission goes straight to your bank.';
        } elseif ($read['state'] === 'blocked') {
            /* Said plainly, and without implying she has done something wrong,
               because she has not. */
            $out['message'] = 'Stripe was not able to verify your details, so payouts '
                            . 'cannot go through them. This does not affect what you '
                            . 'have earned — we will be in touch to arrange another way '
                            . 'to pay you.';
            $out['final'] = true;
        } elseif ($read['state'] === 'disabled') {
            $out['message'] = 'Your payout account has been disconnected. Set it up again '
                            . 'to keep being paid directly.';
        } else {
            $out['message'] = $read['needs']
                ? 'Stripe still needs a few things from you.'
                : 'Stripe is checking your details. This usually takes a few minutes.';
            $out['needs'] = plain_needs($read['needs']);
        }

        out($out);
    }

    /* ── starting, or carrying on ─────────────────────────────────────── */
    case 'start':
    case 'refresh': {
        if ($row && $row['state'] === 'blocked') {
            out(['ok' => false, 'final' => true,
                 'error' => 'Stripe was not able to verify your details. We will be in '
                          . 'touch to arrange another way to pay you.'], 400);
        }

        $acct = $row['acct'] ?? '';

        if ($acct === '') {
            /* Express: Stripe hosts the onboarding and holds the identity
               documents, so her bank details and ID never touch this server. */
            $r = stripe('accounts', [
                'type'    => 'express',
                'country' => 'US',
                'email'   => $aff['email'],
                'capabilities[transfers][requested]' => 'true',
                'business_type' => 'individual',
                'business_profile[product_description]' => 'Affiliate sales commission',
                'business_profile[url]' => $aff['site_url'] ?: SITE,
                'individual[first_name]' => $aff['first_name'],
                'individual[last_name]'  => $aff['last_name'],
                'individual[email]'      => $aff['email'],
                'metadata[code]'         => $code,
                'settings[payouts][schedule][interval]' => 'manual',
            ]);

            if (!$r['ok']) {
                error_log('[connect] create: ' . $r['error']);
                out(['ok' => false, 'error' => 'Payout setup could not be started just now. '
                                             . 'Try again shortly.'], 502);
            }

            $acct = (string)$r['data']['id'];
            $db->prepare("INSERT INTO affiliate_stripe (code, acct, state, started)
                          VALUES (?,?, 'pending', NOW())
                          ON DUPLICATE KEY UPDATE acct = VALUES(acct)")
               ->execute([$code, $acct]);
        }

        /* These links expire in minutes, which is why refresh exists rather
           than one link stored and reused. */
        $l = stripe('account_links', [
            'account'     => $acct,
            'refresh_url' => SITE . '/payouts.php?again=1',
            'return_url'  => SITE . '/payouts.php?done=1',
            'type'        => 'account_onboarding',
        ]);

        if (!$l['ok']) {
            error_log('[connect] link: ' . $l['error']);
            out(['ok' => false, 'error' => 'The setup page could not be opened. '
                                         . 'Try again shortly.'], 502);
        }

        out(['ok' => true, 'url' => $l['data']['url']]);
    }

    /* ── her Stripe dashboard, once she is set up ─────────────────────── */
    case 'dashboard': {
        if (!$row || $row['state'] !== 'enabled') {
            out(['ok' => false, 'error' => 'Payouts are not set up yet.'], 400);
        }
        $r = stripe('accounts/' . $row['acct'] . '/login_links');
        if (!$r['ok']) out(['ok' => false, 'error' => 'Could not open that just now.'], 502);
        out(['ok' => true, 'url' => $r['data']['url']]);
    }

    default:
        out(['ok' => false, 'error' => 'Unknown action'], 400);
    }

} catch (Throwable $e) {
    error_log('[connect] ' . $e->getMessage() . ' @ ' . $e->getLine());
    out(['ok' => false, 'error' => 'Something went wrong at our end.'], 500);
}
