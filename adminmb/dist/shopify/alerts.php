<?php
/* ═══════════════════════════════════════════════════════════════════════════
   alerts.php — the nightly look around, and an email if anything needs doing.

       sudo -u www-data php alerts.php            # print, send nothing
       sudo -u www-data php alerts.php --send
       sudo -u www-data php alerts.php --send --force   (ignore the quiet period)

   Two things shape this.

   A monitor that only speaks when something is wrong is indistinguishable
   from one that has stopped working. So it sends a short all-clear once a
   week even when there is nothing to report.

   And the first thing it checks is whether it can talk to Shopify at all. A
   dead token returns empty lists, every check passes, and the silence reads
   as good news. That failure gets its own loud alert.

   Repeats are suppressed: an alert already sent is not sent again until it
   clears and comes back, otherwise the same low-stock line arrives nightly
   until it becomes wallpaper.
   ═══════════════════════════════════════════════════════════════════════════ */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line.\n");
}

require __DIR__ . '/lib.php';

const STATE_FILE = __DIR__ . '/cache/alert-state.json';

/* ── settings, overridable in config.php ─────────────────────────────────── */
$LOW_STOCK      = defined('ALERT_LOW_STOCK')      ? ALERT_LOW_STOCK      : 3;
$UNFULFILLED_D  = defined('ALERT_UNFULFILLED_DAYS') ? ALERT_UNFULFILLED_DAYS : 2;
$UNPAID_D       = defined('ALERT_UNPAID_DAYS')    ? ALERT_UNPAID_DAYS    : 3;
$TO             = defined('ALERT_EMAIL')          ? ALERT_EMAIL          : '';
$FROM           = defined('ALERT_FROM')           ? ALERT_FROM           : 'alerts@' . (gethostname() ?: 'localhost');
$HEARTBEAT_DAYS = defined('ALERT_HEARTBEAT_DAYS') ? ALERT_HEARTBEAT_DAYS : 7;
$APP_URL        = defined('ALERT_APP_URL')        ? ALERT_APP_URL        : '';

$send  = in_array('--send', $argv, true);
$force = in_array('--force', $argv, true);

function state(): array {
    if (!is_file(STATE_FILE)) return [];
    $d = json_decode((string)file_get_contents(STATE_FILE), true);
    return is_array($d) ? $d : [];
}
function state_save(array $s): void {
    @mkdir(dirname(STATE_FILE), 0775, true);
    @file_put_contents(STATE_FILE, json_encode($s, JSON_PRETTY_PRINT));
}

$state = state();
$now   = time();

$alerts = [];   /* things needing attention */
$notes  = [];   /* worth knowing, not urgent */
$fatal  = null;

/* ═══ 0. can we talk to Shopify at all ═════════════════════════════════════
   Everything below depends on this. If it fails, every other check would
   quietly pass on empty data. */
$ping = shop_query('{ shop { name currencyCode } }');
if (!$ping['ok']) {
    $fatal = $ping['message'] ?? 'unknown';
} else {
    $shopName = $ping['data']['shop']['name'] ?? 'the store';

    /* ═══ 1. orders sitting unfulfilled ════════════════════════════════════ */
    $cut = date('Y-m-d', strtotime("-{$UNFULFILLED_D} days"));
    $r = shop_query('
        query($q: String!) {
          orders(first: 50, query: $q, sortKey: CREATED_AT) {
            edges { node {
              id name createdAt
              displayFulfillmentStatus
              customer { displayName }
              totalPriceSet { shopMoney { amount currencyCode } }
            } }
          }
        }', ['q' => "fulfillment_status:unshipped AND created_at:<{$cut} AND status:open"]);

    if ($r['ok']) {
        foreach ($r['data']['orders']['edges'] ?? [] as $e) {
            $o = $e['node'];
            $days = (int)floor(($now - strtotime($o['createdAt'])) / 86400);
            $alerts[] = [
                'key'  => 'unfulfilled:' . gid_num($o['id']),
                'kind' => 'Unfulfilled',
                'line' => $o['name'] . ' — ' . $days . ' day' . ($days === 1 ? '' : 's') . ' old'
                        . (!empty($o['customer']['displayName']) ? ', ' . $o['customer']['displayName'] : '')
                        . ', ' . money($o['totalPriceSet']['shopMoney']['amount'] ?? null,
                                       $o['totalPriceSet']['shopMoney']['currencyCode'] ?? 'USD'),
                'weight' => $days,
            ];
        }
    } else {
        $notes[] = 'Could not check unfulfilled orders: ' . $r['message'];
    }

    /* ═══ 2. orders taken but not paid ═════════════════════════════════════ */
    $cut2 = date('Y-m-d', strtotime("-{$UNPAID_D} days"));
    $r = shop_query('
        query($q: String!) {
          orders(first: 30, query: $q, sortKey: CREATED_AT) {
            edges { node {
              id name createdAt displayFinancialStatus
              totalPriceSet { shopMoney { amount currencyCode } }
            } }
          }
        }', ['q' => "financial_status:pending AND created_at:<{$cut2} AND status:open"]);

    if ($r['ok']) {
        foreach ($r['data']['orders']['edges'] ?? [] as $e) {
            $o = $e['node'];
            $days = (int)floor(($now - strtotime($o['createdAt'])) / 86400);
            $alerts[] = [
                'key'  => 'unpaid:' . gid_num($o['id']),
                'kind' => 'Payment pending',
                'line' => $o['name'] . ' — ' . $days . ' days, '
                        . money($o['totalPriceSet']['shopMoney']['amount'] ?? null,
                                $o['totalPriceSet']['shopMoney']['currencyCode'] ?? 'USD'),
                'weight' => $days,
            ];
        }
    }

    /* ═══ 3. stock running out, and money sitting in it ════════════════════ */
    $r = shop_query('
        {
          products(first: 100, query: "status:active") {
            edges { node {
              id title status totalInventory
              variants(first: 25) { edges { node {
                sku price inventoryQuantity
                inventoryItem { unitCost { amount currencyCode } }
              } } }
            } }
          }
        }');

    if ($r['ok']) {
        $out = [];
        $low = [];
        $below = [];
        $noCost = 0;
        $stockValue = 0.0;
        $potential  = 0.0;

        foreach ($r['data']['products']['edges'] ?? [] as $e) {
            $p = $e['node'];
            $tot = (int)($p['totalInventory'] ?? 0);

            foreach ($p['variants']['edges'] ?? [] as $ve) {
                $v = $ve['node'];
                $c = $v['inventoryItem']['unitCost']['amount'] ?? null;
                $pr = $v['price'] ?? null;
                $q = (int)($v['inventoryQuantity'] ?? 0);
                if ($c === null) { $noCost++; continue; }
                if ($q > 0) {
                    $stockValue += (float)$c * $q;
                    if ($pr !== null) $potential += ((float)$pr - (float)$c) * $q;
                }
                if ($pr !== null && (float)$pr < (float)$c) {
                    $below[] = $p['title'] . ($v['sku'] ? ' (' . $v['sku'] . ')' : '')
                             . ' — sells at ' . money($pr) . ', costs ' . money($c);
                }
            }

            if ($tot <= 0)                $out[] = $p['title'];
            elseif ($tot <= $LOW_STOCK)   $low[] = $p['title'] . ' — ' . $tot . ' left';
        }

        foreach ($low as $l) {
            $alerts[] = ['key' => 'low:' . md5($l), 'kind' => 'Running low', 'line' => $l, 'weight' => 5];
        }
        foreach ($out as $o) {
            $alerts[] = ['key' => 'out:' . md5($o), 'kind' => 'Out of stock', 'line' => $o, 'weight' => 4];
        }
        foreach ($below as $b) {
            $alerts[] = ['key' => 'below:' . md5($b), 'kind' => 'Priced under cost',
                         'line' => $b, 'weight' => 9];
        }

        $notes[] = 'Stock at cost ' . money($stockValue)
                 . ', worth ' . money($stockValue + $potential) . ' at current prices.';
        if ($noCost) {
            $notes[] = $noCost . ' variant' . ($noCost === 1 ? ' has' : 's have')
                     . ' no cost recorded, so they count as nothing in that figure.';
        }
    } else {
        $notes[] = 'Could not check stock: ' . $r['message'];
    }

    /* ═══ 4. what came in yesterday ════════════════════════════════════════ */
    $since = date('Y-m-d', strtotime('-1 day'));
    $r = shop_query('
        query($q: String!) {
          orders(first: 100, query: $q) {
            edges { node { totalPriceSet { shopMoney { amount currencyCode } } } }
          }
        }', ['q' => "created_at:>={$since}"]);

    if ($r['ok']) {
        $n = 0; $sum = 0.0;
        foreach ($r['data']['orders']['edges'] ?? [] as $e) {
            $n++;
            $sum += (float)($e['node']['totalPriceSet']['shopMoney']['amount'] ?? 0);
        }
        $notes[] = $n
            ? $n . ' order' . ($n === 1 ? '' : 's') . ' in the last day, ' . money($sum) . '.'
            : 'No orders in the last day.';
    }
}

/* ═══ suppress what has already been said ══════════════════════════════════ */
$seen    = $state['open'] ?? [];
$fresh   = [];
$repeats = 0;
foreach ($alerts as $a) {
    $fresh[$a['key']] = true;
    if (isset($seen[$a['key']])) { $a['old'] = true; $repeats++; }
}
$new = array_values(array_filter($alerts, fn($a) => !isset($seen[$a['key']])));
$cleared = array_diff(array_keys($seen), array_keys($fresh));

/* worst first */
usort($new, fn($a, $b) => $b['weight'] <=> $a['weight']);

/* ═══ is there anything to send ════════════════════════════════════════════ */
$lastBeat = (int)($state['last_heartbeat'] ?? 0);
$dueBeat  = ($now - $lastBeat) > ($HEARTBEAT_DAYS * 86400);

$why = $fatal ? 'fatal' : ($new ? 'new' : ($dueBeat ? 'heartbeat' : ''));
if ($force && !$why) $why = 'forced';

/* ═══ compose ══════════════════════════════════════════════════════════════ */
$subject = '';
$body    = '';

if ($fatal) {
    $subject = APP_NAME . ' — cannot reach Shopify';
    $body  = "The nightly check could not talk to Shopify, so nothing was checked.\n\n"
           . "  " . $fatal . "\n\n"
           . "Until this is fixed the absence of alerts means nothing — no news is not\n"
           . "good news here.\n\n"
           . "Usually the access token has been revoked, which happens when the app is\n"
           . "uninstalled or its scopes change. Visiting auth.php once issues a new one.\n";
} elseif ($new || $repeats) {
    $subject = APP_NAME . ' — ' . count($new) . ' new';
    if ($repeats) $subject .= ', ' . $repeats . ' still open';

    $body = "Nightly check for " . ($shopName ?? 'the store') . ".\n\n";

    if ($new) {
        $body .= "NEEDS ATTENTION\n" . str_repeat('─', 52) . "\n";
        $byKind = [];
        foreach ($new as $a) $byKind[$a['kind']][] = $a['line'];
        foreach ($byKind as $kind => $lines) {
            $body .= "\n" . strtoupper($kind) . "\n";
            foreach ($lines as $l) $body .= "  · " . $l . "\n";
        }
        $body .= "\n";
    }

    if ($repeats) {
        $body .= str_repeat('─', 52) . "\n"
               . $repeats . " thing" . ($repeats === 1 ? " was" : "s were")
               . " already reported and remain open. They are not\n"
               . "listed again to keep this readable.\n\n";
    }
    if ($cleared) {
        $body .= count($cleared) . " earlier alert" . (count($cleared) === 1 ? " has" : "s have")
               . " cleared.\n\n";
    }
} else {
    $subject = APP_NAME . ' — all clear';
    $body = "Nothing needs attention at " . ($shopName ?? 'the store') . ".\n\n"
          . "This note goes out about every " . $HEARTBEAT_DAYS . " days whether or not\n"
          . "there is news, so that silence can be trusted to mean silence rather than\n"
          . "a broken job.\n\n";
}

if (!$fatal && $notes) {
    $body .= str_repeat('─', 52) . "\nWORTH KNOWING\n";
    foreach ($notes as $n) $body .= "  · " . $n . "\n";
    $body .= "\n";
}

if ($APP_URL !== '') $body .= $APP_URL . "\n";
$body .= "\n" . date('D j M Y, H:i') . "\n";

/* ═══ out ══════════════════════════════════════════════════════════════════ */
echo "── " . date('Y-m-d H:i') . " ──────────────────────────────\n";
echo "checked:  " . ($fatal ? 'nothing, Shopify unreachable'
                            : (count($alerts) . ' issues, ' . count($new) . ' new')) . "\n";
echo "sending:  " . ($why ?: 'nothing new, heartbeat not due') . "\n\n";

if (!$why) {
    /* still record state, so something that clears is noticed next time */
    $state['open'] = $fresh;
    $state['last_run'] = $now;
    state_save($state);
    exit(0);
}

echo "Subject: $subject\n\n$body\n";

if ($send) {
    if ($TO === '') {
        echo "NOT SENT — no ALERT_EMAIL in config.php\n";
        exit(1);
    }
    $headers = "From: " . $FROM . "\r\n"
             . "Content-Type: text/plain; charset=utf-8\r\n"
             . "X-Mailer: Models Boutique\r\n";
    $ok = @mail($TO, $subject, $body, $headers);
    echo $ok ? "sent to $TO\n" : "SEND FAILED — check the mail log\n";
    if (!$ok) exit(1);
}

$state['open'] = $fresh;
$state['last_run'] = $now;
/* Any email proves the job is alive, which is the whole point of the
   heartbeat — so every send resets its clock, not only the all-clear. */
$state['last_heartbeat'] = $now;
state_save($state);
