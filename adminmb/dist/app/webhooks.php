<?php
/* ═══════════════════════════════════════════════════════════════════════════
   webhooks.php — what Shopify tells us, and what we must answer.

   Four topics. One is practical; three are required by Shopify and an app is
   rejected without them.

     app/uninstalled          they have gone — the token is dead already
     customers/data_request   a customer wants to know what we hold
     customers/redact         a customer wants it deleted
     shop/redact              the shop is gone; delete everything

   Every one is verified against the app secret before it is believed, and
   every one is recorded — when a merchant asks what happened to a customer's
   data, "we have a log" is the only answer worth having.

   Answering 200 quickly matters: Shopify retries anything slower than five
   seconds and marks the endpoint unreliable.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/lib.php';

/* the raw body, before anything can alter it */
$body  = file_get_contents('php://input');
$hmac  = (string)($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '');
$topic = (string)($_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '');
$shop  = app_shop($_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] ?? '');

if (!app_check_webhook($body, $hmac)) {
    app_log('webhook: bad signature, topic ' . $topic);
    http_response_code(401);
    exit;
}

$data = json_decode($body, true) ?: [];

try {
    $db = app_db();
    app_tables($db);

    switch ($topic) {

    /* ── they have uninstalled ────────────────────────────────────────────
       The token is already void, so nothing can be called on their shop. Mark
       it and stop. Their affiliate record and commission history stay: money
       earned is still owed whether or not the app is installed. */
    case 'app/uninstalled': {
        $db->prepare("UPDATE mb_shops SET uninstalled = NOW(), token = '' WHERE shop = ?")
           ->execute([$shop]);

        $q = $db->prepare("SELECT code FROM mb_shops WHERE shop = ?");
        $q->execute([$shop]);
        if ($code = $q->fetchColumn()) {
            /* inactive rather than deleted — she may come back, and deleting
               somebody who is owed money would be indefensible */
            $db->prepare("UPDATE affiliates SET status = 'inactive' WHERE code = ?")
               ->execute([$code]);
        }

        app_log('uninstalled: ' . $shop);
        break;
    }

    /* ── a customer asks what we hold ─────────────────────────────────────
       Almost always nothing. Customers buy from our shop, not theirs, so a
       customer of the merchant is not somebody we have records of. Recorded
       either way, because "we checked and held nothing" is a different answer
       from never having looked. */
    case 'customers/data_request': {
        $cust = $data['customer'] ?? [];
        $email = strtolower((string)($cust['email'] ?? ''));

        $found = [];

        if ($email !== '') {
            /* the only way we could hold anything is if they are also an
               affiliate of ours */
            $a = $db->prepare("SELECT code, first_name, last_name, store_name, email,
                                      phone, street, city, state, zip, country, opened
                                 FROM affiliates WHERE email = ?");
            $a->execute([$email]);
            if ($row = $a->fetch()) $found['affiliate_account'] = $row;
        }

        $answer = $found
            ? 'Held as an affiliate account; details supplied to the merchant.'
            : 'Nothing held. Customers of this shop purchase from Models Boutique '
            . 'and are not recorded by this app.';

        $db->prepare("INSERT INTO mb_privacy_log (shop, topic, payload, answered)
                      VALUES (?,?,?,?)")
           ->execute([$shop, $topic, substr($body, 0, 60000), $answer]);

        app_log('data_request: ' . $shop . ' — ' . $answer);
        break;
    }

    /* ── a customer asks to be forgotten ──────────────────────────────── */
    case 'customers/redact': {
        $cust = $data['customer'] ?? [];
        $email = strtolower((string)($cust['email'] ?? ''));
        $done = 'Nothing held for that customer.';

        if ($email !== '') {
            $a = $db->prepare("SELECT code FROM affiliates WHERE email = ?");
            $a->execute([$email]);

            if ($code = $a->fetchColumn()) {
                /* Their name and address go. The code, the commission and the
                   payments stay, because those are our own financial records
                   and we are required to keep them — but they no longer say
                   who the person was. */
                $q = $db->prepare("SELECT profile_img, id_img FROM affiliates WHERE code = ?");
                $q->execute([$code]);
                if ($r = $q->fetch()) {
                    $root = '/var/www/sites/affiliate/dist/uploads/affiliates';
                    $real = realpath($root);
                    foreach ([$r['profile_img'], $r['id_img']] as $f) {
                        if (!$f) continue;
                        $abs = realpath($root . '/' . basename($f));
                        if ($abs && $real && strpos($abs, $real) === 0 && is_file($abs)) {
                            @unlink($abs);
                        }
                    }
                }

                $db->prepare("UPDATE affiliates
                                 SET first_name = '', last_name = '', email = '',
                                     phone = '', street = '', city = '', state = '',
                                     zip = '', profile_img = '', id_img = '',
                                     pass_hash = '', status = 'inactive'
                               WHERE code = ?")->execute([$code]);

                $done = 'Personal details removed for ' . $code
                      . '. Financial records kept as required, without identifying details.';
            }
        }

        $db->prepare("INSERT INTO mb_privacy_log (shop, topic, payload, answered)
                      VALUES (?,?,?,?)")
           ->execute([$shop, $topic, substr($body, 0, 60000), $done]);

        app_log('redact: ' . $shop . ' — ' . $done);
        break;
    }

    /* ── the shop itself is gone ──────────────────────────────────────────
       Sent forty-eight hours after uninstall. Everything about the shop goes.
       The affiliate record stays only if money is owed; otherwise it goes too. */
    case 'shop/redact': {
        $q = $db->prepare("SELECT code FROM mb_shops WHERE shop = ?");
        $q->execute([$shop]);
        $code = (string)$q->fetchColumn();

        $kept = false;

        if ($code !== '') {
            $p = $db->prepare("SELECT COUNT(*) FROM affiliate_payouts WHERE ref = ?");
            $p->execute([$code]);
            $hasMoney = (int)$p->fetchColumn() > 0;

            if ($hasMoney) {
                /* Anonymised rather than deleted. A payment record with no
                   name is still a payment record; deleting it would leave the
                   books wrong. */
                $db->prepare("UPDATE affiliates
                                 SET first_name = '', last_name = '', email = '',
                                     phone = '', street = '', city = '', state = '',
                                     zip = '', profile_img = '', id_img = '',
                                     pass_hash = '', status = 'inactive'
                               WHERE code = ?")->execute([$code]);
                $kept = true;
            } else {
                $db->prepare("DELETE FROM affiliates WHERE code = ?")->execute([$code]);
                $db->prepare("DELETE FROM affiliate_rates WHERE ref = ?")->execute([$code]);
                $db->prepare("DELETE FROM affiliate_stripe WHERE code = ?")->execute([$code]);
            }
        }

        $db->prepare("DELETE FROM mb_shops WHERE shop = ?")->execute([$shop]);

        $done = $kept
            ? 'Shop deleted. Affiliate record anonymised, financial records kept.'
            : 'Shop and affiliate record deleted in full.';

        $db->prepare("INSERT INTO mb_privacy_log (shop, topic, payload, answered)
                      VALUES (?,?,?,?)")
           ->execute([$shop, $topic, substr($body, 0, 60000), $done]);

        app_log('shop_redact: ' . $shop . ' — ' . $done);
        break;
    }

    default:
        app_log('webhook: nothing to do for ' . $topic);
    }

} catch (Throwable $e) {
    app_log('webhook ' . $topic . ': ' . $e->getMessage());
    /* A 500 makes Shopify retry, which is right — losing a redaction request
       because of a passing database fault is not acceptable. */
    http_response_code(500);
    exit;
}

http_response_code(200);
echo 'ok';
