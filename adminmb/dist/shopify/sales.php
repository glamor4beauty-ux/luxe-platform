<?php
/* ═══════════════════════════════════════════════════════════════════════════
   sales.php — what has been sold through affiliates, and what is owed for it.

   Two views of the same orders:

     Sales    every referred order, as it happened
     Billing  the same money as a ledger — commission earned as credits,
              payments made as debits, and what is left owed

   Orders come from Shopify, which knows who was credited because the cart
   carried the affiliate's code when it was created. Payments come from our
   own records, because only we know when money actually left.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/authz.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/shell.php';

auth_start();
if (!auth_user()) { header('Location: login.php'); exit; }

$me = auth_user();
$my_email = is_array($me) ? ($me['email'] ?? '') : (string)$me;

function mb_db(): PDO {
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

$view = ($_GET['view'] ?? 'sales') === 'billing' ? 'billing' : 'sales';
$only = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['ref'] ?? '')));
$from = trim((string)($_GET['from'] ?? date('Y-m-d', strtotime('-180 days'))));
$to   = trim((string)($_GET['to'] ?? date('Y-m-d')));

$msg = '';
$err = '';

/* ── recording a payment ──────────────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $db = mb_db();
        $do = (string)($_POST['do'] ?? '');

        if ($do === 'pay') {
            $ref = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['ref'] ?? '')));
            $amt = round((float)preg_replace('/[^0-9.\-]/', '', (string)($_POST['amount'] ?? 0)), 2);
            if ($ref === '') throw new RuntimeException('No affiliate was given.');
            if ($amt <= 0)   throw new RuntimeException('An amount is needed.');

            $db->prepare("INSERT INTO affiliate_payouts (ref, amount, dated, note, created_by)
                          VALUES (?,?,?,?,?)")
               ->execute([$ref, $amt,
                          trim((string)($_POST['dated'] ?? '')) ?: date('Y-m-d'),
                          trim((string)($_POST['note'] ?? '')), $my_email]);
            $msg = 'Recorded ' . money($amt) . ' paid to ' . $ref . '.';
        }

        if ($do === 'unpay') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $db->prepare("DELETE FROM affiliate_payouts WHERE id = ?")->execute([$id]);
                $msg = 'Payment removed.';
            }
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

/* ── rates and names ──────────────────────────────────────────────────── */
$rates = [];
$names = [];
try {
    $db = mb_db();
    foreach ($db->query("SELECT ref, percent FROM affiliate_rates")->fetchAll() as $r) {
        $rates[strtoupper($r['ref'])] = (float)$r['percent'];
    }
    foreach ($db->query("SELECT code, first_name, last_name, store_name, email
                           FROM affiliates")->fetchAll() as $r) {
        $names[strtoupper($r['code'])] = $r;
    }
} catch (Throwable $e) {
    $err = $err ?: $e->getMessage();
}

/* ── the orders ───────────────────────────────────────────────────────────
   Attribution was recorded when the cart was made, so this reads rather than
   guesses which affiliate earned what. */
$sales = [];
$shopErr = '';

$r = shop_query('
    query($q: String!) {
      orders(first: 100, query: $q, sortKey: CREATED_AT, reverse: true) {
        edges { node {
          id name createdAt
          displayFinancialStatus displayFulfillmentStatus
          customAttributes { key value }
          customer { displayName email }
          currentSubtotalPriceSet { shopMoney { amount currencyCode } }
          subtotalPriceSet { shopMoney { amount } }
          totalPriceSet { shopMoney { amount } }
          totalRefundedSet { shopMoney { amount } }
        } }
      }
    }', ['q' => 'created_at:>=' . $from . ' created_at:<=' . $to]);

if (empty($r['ok'])) {
    $shopErr = $r['error'] ?? 'The shop could not be read.';
} else {
    foreach ($r['data']['orders']['edges'] ?? [] as $e) {
        $o = $e['node'];

        $ref = '';
        foreach ($o['customAttributes'] ?? [] as $a) {
            if (($a['key'] ?? '') === 'referred_by') $ref = strtoupper(trim((string)$a['value']));
        }
        if ($ref === '') continue;
        if ($only !== '' && $ref !== $only) continue;

        $sub = (float)($o['currentSubtotalPriceSet']['shopMoney']['amount']
                    ?? $o['subtotalPriceSet']['shopMoney']['amount'] ?? 0);
        $refunded = (float)($o['totalRefundedSet']['shopMoney']['amount'] ?? 0);
        $fin = strtoupper((string)$o['displayFinancialStatus']);
        $ful = strtoupper((string)$o['displayFulfillmentStatus']);

        /* Earned once paid and not refunded. Anything else is shown but does
           not count, because a commission on a refunded order is money paid
           out on a sale that did not happen. */
        $counts = ($fin === 'PAID' && $refunded <= 0.001);
        $rate = $rates[$ref] ?? 20.0;

        $sales[] = [
            'order'    => $o['name'],
            'date'     => substr((string)$o['createdAt'], 0, 10),
            'customer' => $o['customer']['displayName'] ?? 'Guest',
            'email'    => $o['customer']['email'] ?? '',
            'amount'   => round($sub, 2),
            'total'    => round((float)($o['totalPriceSet']['shopMoney']['amount'] ?? 0), 2),
            'ref'      => $ref,
            'fin'      => ucfirst(strtolower(str_replace('_', ' ', $fin))),
            'ful'      => ucfirst(strtolower(str_replace('_', ' ', $ful))),
            'counts'   => $counts,
            'rate'     => $rate,
            'commission' => $counts ? round($sub * $rate / 100, 2) : 0.0,
            'cur'      => $o['currentSubtotalPriceSet']['shopMoney']['currencyCode'] ?? 'USD',
        ];
    }
}

/* ── payments made ────────────────────────────────────────────────────── */
$payouts = [];
try {
    $db = mb_db();
    if ($only !== '') {
        $q = $db->prepare("SELECT * FROM affiliate_payouts WHERE ref = ?
                            AND dated BETWEEN ? AND ? ORDER BY dated, id");
        $q->execute([$only, $from, $to]);
    } else {
        $q = $db->prepare("SELECT * FROM affiliate_payouts
                            WHERE dated BETWEEN ? AND ? ORDER BY dated, id");
        $q->execute([$from, $to]);
    }
    $payouts = $q->fetchAll();
} catch (Throwable $e) { /* the ledger simply shows no debits */ }

/* ── the ledger ───────────────────────────────────────────────────────────
   Commission earned is a credit; money paid out is a debit; the running
   balance is what is still owed. Ordered by date so the two interleave the
   way they actually happened. */
$ledger = [];

foreach ($sales as $s) {
    if (!$s['counts']) continue;
    $ledger[] = [
        'date'     => $s['date'],
        'customer' => $s['customer'],
        'order'    => $s['order'],
        'amount'   => $s['amount'],
        'ref'      => $s['ref'],
        'status'   => $s['fin'] . ' · ' . $s['ful'],
        'credit'   => $s['commission'],
        'debit'    => 0.0,
        'note'     => '',
        'id'       => null,
        'cur'      => $s['cur'],
    ];
}

foreach ($payouts as $p) {
    $ledger[] = [
        'date'     => $p['dated'],
        'customer' => '',
        'order'    => '',
        'amount'   => 0.0,
        'ref'      => strtoupper($p['ref']),
        'status'   => 'Paid',
        'credit'   => 0.0,
        'debit'    => (float)$p['amount'],
        'note'     => $p['note'],
        'id'       => (int)$p['id'],
        'cur'      => 'USD',
    ];
}

usort($ledger, fn($a, $b) => [$a['date'], $a['order']] <=> [$b['date'], $b['order']]);

$totCredit = array_sum(array_column($ledger, 'credit'));
$totDebit  = array_sum(array_column($ledger, 'debit'));
$balance   = $totCredit - $totDebit;

$totSales  = array_sum(array_column($sales, 'amount'));
$counted   = count(array_filter($sales, fn($s) => $s['counts']));

page_open($view === 'billing' ? 'Billing' : 'Sales', 'affiliates');
?>

<style>
.sl-head{display:flex;justify-content:space-between;align-items:center;gap:14px;
  margin-bottom:14px;flex-wrap:wrap}
.sl-head h1{font-size:19px;font-weight:700;color:var(--txt)}
.sl-head .sub{font-size:12px;color:var(--mute);margin-top:2px}

.sl-tabs{display:flex;gap:7px;margin-bottom:14px;flex-wrap:wrap;align-items:center}
.sl-tab{border:1px solid var(--line2);background:transparent;color:var(--mute);
  border-radius:9px;padding:9px 17px;font-size:12.5px;font-weight:700;
  text-decoration:none;display:inline-block;white-space:nowrap}
.sl-tab:hover{border-color:var(--gold);color:var(--gold)}
.sl-tab.on{background:rgba(216,169,75,.12);border-color:var(--gold);color:var(--gold)}
.sl-dates{display:flex;gap:6px;align-items:center;margin-left:auto;font-size:12px;
  color:var(--mute);flex-wrap:wrap}
.sl-dates input{background:var(--panel2);border:1px solid var(--line2);border-radius:8px;
  padding:7px 10px;color:var(--txt);font-size:12.5px;outline:none;font-family:inherit}
.sl-dates input:focus{border-color:var(--gold)}
.sl-dates select{background:var(--panel2);border:1px solid var(--line2);border-radius:8px;
  padding:7px 10px;color:var(--txt);font-size:12.5px;outline:none;font-family:inherit}

.sl-note{background:rgba(78,201,122,.09);border:1px solid rgba(78,201,122,.35);
  color:#a8e6bd;border-radius:9px;padding:11px 14px;font-size:13px;margin-bottom:14px}
.sl-err{background:rgba(242,104,94,.08);border:1px solid rgba(242,104,94,.35);
  color:#ffb3ac;border-radius:9px;padding:11px 14px;font-size:13px;margin-bottom:14px}

.sl-sums{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));
  gap:12px;margin-bottom:18px}
.sl-sum{background:var(--panel);border:1px solid var(--line);border-radius:12px;
  padding:15px;text-align:center}
.sl-sum b{display:block;font-family:ui-monospace,Menlo,monospace;font-size:21px;
  color:var(--txt);line-height:1.2}
.sl-sum b.gold{color:var(--gold)}
.sl-sum b.ok{color:var(--ok)}
.sl-sum b.owed{color:var(--warn)}
.sl-sum span{display:block;font-size:9.5px;color:var(--mute);text-transform:uppercase;
  letter-spacing:.06em;font-weight:700;margin-top:5px}
.sl-sum.big{border-color:var(--gold);background:rgba(216,169,75,.06)}

.sl-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:11px}
.sl-tbl{width:100%;border-collapse:collapse;font-size:13.5px;min-width:900px}
.sl-tbl th{text-align:left;padding:12px 15px;font-size:9.5px;color:var(--mute);
  text-transform:uppercase;letter-spacing:.06em;font-weight:700;background:var(--panel2);
  border-bottom:1px solid var(--line2);white-space:nowrap}
.sl-tbl th.r,.sl-tbl td.r{text-align:right}
.sl-tbl td{padding:12px 15px;border-bottom:1px solid rgba(36,40,50,.7);color:var(--txt2);
  vertical-align:middle}
.sl-tbl tbody tr:last-child td{border-bottom:none}
.sl-tbl tbody tr:hover{background:rgba(255,255,255,.02)}
.sl-tbl tr.skip td{opacity:.5}
.sl-tbl tr.pay td{background:rgba(216,169,75,.04)}

.sl-cust{color:var(--txt);font-weight:600}
.sl-mail{font-size:11.5px;color:var(--mute);margin-top:2px}
.sl-ord{font-family:ui-monospace,Menlo,monospace;font-size:12.5px;color:var(--gold);
  white-space:nowrap}
.sl-date{font-family:ui-monospace,Menlo,monospace;font-size:12.5px;color:var(--mute);
  white-space:nowrap}
.sl-num{font-family:ui-monospace,Menlo,monospace;white-space:nowrap}
.sl-num.credit{color:var(--ok)}
.sl-num.debit{color:var(--warn)}
.sl-num.dim{color:var(--faint)}
.sl-ref{font-family:ui-monospace,Menlo,monospace;font-size:12px;color:var(--gold);
  white-space:nowrap;text-decoration:none}
.sl-ref:hover{text-decoration:underline}
.sl-who{font-size:11.5px;color:var(--mute);margin-top:2px}
.sl-pill{display:inline-block;border-radius:5px;padding:3px 9px;font-size:10.5px;
  font-weight:700;white-space:nowrap}
.sl-pill.paid{background:rgba(78,201,122,.14);color:var(--ok)}
.sl-pill.pending{background:rgba(224,163,64,.14);color:var(--warn)}
.sl-pill.refunded{background:rgba(242,104,94,.13);color:var(--bad)}
.sl-pill.ship{background:rgba(106,169,240,.13);color:var(--info)}
.sl-pill.none{background:rgba(123,129,148,.16);color:var(--mute)}
.sl-note-txt{font-size:11.5px;color:var(--mute);font-style:italic}

.sl-tbl tfoot td{border-top:2px solid var(--gold);background:rgba(216,169,75,.05);
  padding:15px;font-weight:700}
.sl-tbl tfoot .lbl{font-size:9.5px;color:var(--mute);text-transform:uppercase;
  letter-spacing:.06em;display:block;margin-bottom:3px}
.sl-tbl tfoot .amt{font-family:ui-monospace,Menlo,monospace;font-size:16px;color:var(--txt)}
.sl-tbl tfoot .amt.credit{color:var(--ok)}
.sl-tbl tfoot .amt.debit{color:var(--warn)}
.sl-tbl tfoot .amt.bal{color:var(--gold);font-size:19px}

.sl-payform{background:var(--panel);border:1px solid var(--line2);border-radius:12px;
  padding:16px;margin-bottom:18px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}
.sl-payform label{display:block;font-size:9.5px;color:var(--mute);text-transform:uppercase;
  letter-spacing:.07em;font-weight:700;margin-bottom:5px}
.sl-payform input,.sl-payform select{background:var(--panel2);border:1px solid var(--line2);
  border-radius:8px;padding:9px 11px;color:var(--txt);font-size:14px;outline:none;
  font-family:inherit}
.sl-payform input:focus,.sl-payform select:focus{border-color:var(--gold)}
.sl-payform .amt-in{width:110px;text-align:right;font-family:ui-monospace,monospace}
.sl-payform .note-in{flex:1;min-width:160px}
.sl-btn{background:var(--gold);border:1px solid var(--gold);border-radius:9px;
  color:#0f1115;padding:10px 20px;font-size:13px;font-weight:700;cursor:pointer;
  font-family:inherit}
.sl-btn:hover{background:#e8bf44}
.sl-x{background:none;border:none;color:var(--faint);font-size:15px;cursor:pointer;
  padding:2px 6px;font-family:inherit}
.sl-x:hover{color:var(--bad)}

.sl-empty{text-align:center;padding:44px;color:var(--faint);font-size:13.5px}
.sl-foot{font-size:11.5px;color:var(--faint);line-height:1.7;margin-top:12px}
.sl-foot b{color:var(--gold)}
</style>

<div class="sl-head">
  <div>
    <h1><?= $view === 'billing' ? 'Billing' : 'Sales' ?></h1>
    <div class="sub">
      <?= count($sales) ?> referred order<?= count($sales) === 1 ? '' : 's' ?>
      <?php if ($only && isset($names[$only])): ?>
        · <?= e(trim($names[$only]['first_name'] . ' ' . $names[$only]['last_name'])) ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="sl-tabs">
  <a class="sl-tab" href="affiliates.php">Affiliates</a>
  <a class="sl-tab <?= $view === 'sales' ? 'on' : '' ?>"
     href="sales.php?view=sales<?= $only ? '&ref=' . e($only) : '' ?>">Sales</a>
  <a class="sl-tab <?= $view === 'billing' ? 'on' : '' ?>"
     href="sales.php?view=billing<?= $only ? '&ref=' . e($only) : '' ?>">Billing</a>

  <form class="sl-dates" method="get">
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <select name="ref" onchange="this.form.submit()">
      <option value="">Everyone</option>
      <?php foreach ($names as $code => $n): ?>
        <option value="<?= e($code) ?>"<?= $only === $code ? ' selected' : '' ?>>
          <?= e(trim($n['first_name'] . ' ' . $n['last_name']) ?: $code) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <input type="date" name="from" value="<?= e($from) ?>" onchange="this.form.submit()">
    <span>to</span>
    <input type="date" name="to" value="<?= e($to) ?>" onchange="this.form.submit()">
  </form>
</div>

<?php if ($msg): ?><div class="sl-note"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="sl-err"><?= e($err) ?></div><?php endif; ?>
<?php if ($shopErr): ?><div class="sl-err"><?= e($shopErr) ?></div><?php endif; ?>

<?php if ($view === 'sales'): ?>

  <div class="sl-sums">
    <div class="sl-sum"><b><?= count($sales) ?></b><span>Orders</span></div>
    <div class="sl-sum"><b class="gold"><?= e(money($totSales)) ?></b><span>Sold</span></div>
    <div class="sl-sum"><b><?= $counted ?></b><span>Earning</span></div>
    <div class="sl-sum big"><b class="ok"><?= e(money($totCredit)) ?></b><span>Commission</span></div>
  </div>

  <?php if (!$sales): ?>
    <div class="sl-empty">No referred sales in this period.</div>
  <?php else: ?>

  <div class="sl-wrap">
    <table class="sl-tbl">
      <thead>
        <tr>
          <th>Customer</th>
          <th>Order</th>
          <th>Date of sale</th>
          <th class="r">Amount</th>
          <th>Affiliate</th>
          <th>Shopify status</th>
          <th class="r">Commission</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($sales as $s):
        $fk = strtolower($s['fin']);
        $fc = $fk === 'paid' ? 'paid'
            : (strpos($fk, 'refund') !== false ? 'refunded' : 'pending');
        $uk = strtolower($s['ful']);
        $uc = $uk === 'fulfilled' ? 'ship' : 'none';
      ?>
        <tr class="<?= $s['counts'] ? '' : 'skip' ?>">
          <td>
            <div class="sl-cust"><?= e($s['customer']) ?></div>
            <?php if ($s['email']): ?>
              <div class="sl-mail"><?= e($s['email']) ?></div>
            <?php endif; ?>
          </td>
          <td class="sl-ord"><?= e($s['order']) ?></td>
          <td class="sl-date"><?= e(date('j M Y', strtotime($s['date']))) ?></td>
          <td class="r sl-num"><?= e(money($s['amount'], $s['cur'])) ?></td>
          <td>
            <a class="sl-ref" href="sales.php?view=sales&ref=<?= e($s['ref']) ?>"><?= e($s['ref']) ?></a>
            <?php if (isset($names[$s['ref']])): ?>
              <div class="sl-who"><?= e(trim($names[$s['ref']]['first_name'] . ' '
                                            . $names[$s['ref']]['last_name'])) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <span class="sl-pill <?= $fc ?>"><?= e($s['fin']) ?></span>
            <span class="sl-pill <?= $uc ?>"><?= e($s['ful']) ?></span>
          </td>
          <td class="r sl-num <?= $s['counts'] ? 'credit' : 'dim' ?>">
            <?= $s['counts'] ? e(money($s['commission'], $s['cur'])) : '—' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3"></td>
          <td class="r"><span class="lbl">Sold</span>
            <span class="amt"><?= e(money($totSales)) ?></span></td>
          <td colspan="2"></td>
          <td class="r"><span class="lbl">Commission</span>
            <span class="amt credit"><?= e(money($totCredit)) ?></span></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="sl-foot">
    Faded rows are not earning — the order is unpaid or has been refunded, and a
    commission on a sale that did not happen is money out for nothing.
    <b>Amount</b> is the products, before shipping and tax.
  </div>

  <?php endif; ?>

<?php else: /* ══ billing ══ */ ?>

  <div class="sl-sums">
    <div class="sl-sum"><b class="ok"><?= e(money($totCredit)) ?></b><span>Credits</span></div>
    <div class="sl-sum"><b class="owed"><?= e(money($totDebit)) ?></b><span>Debits</span></div>
    <div class="sl-sum big"><b class="gold"><?= e(money($balance)) ?></b><span>Balance owed</span></div>
  </div>

  <form class="sl-payform" method="post">
    <input type="hidden" name="do" value="pay">
    <div>
      <label>Affiliate</label>
      <select name="ref" required>
        <option value="">Choose…</option>
        <?php foreach ($names as $code => $n): ?>
          <option value="<?= e($code) ?>"<?= $only === $code ? ' selected' : '' ?>>
            <?= e($code) ?> — <?= e(trim($n['first_name'] . ' ' . $n['last_name'])) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Amount paid</label>
      <input class="amt-in" name="amount" placeholder="0.00" inputmode="decimal" required>
    </div>
    <div>
      <label>Date</label>
      <input type="date" name="dated" value="<?= e(date('Y-m-d')) ?>">
    </div>
    <div class="note-in">
      <label>Note</label>
      <input name="note" placeholder="How it was sent" style="width:100%">
    </div>
    <button class="sl-btn" type="submit">Record payment</button>
  </form>

  <?php if (!$ledger): ?>
    <div class="sl-empty">Nothing to bill in this period.</div>
  <?php else: ?>

  <div class="sl-wrap">
    <table class="sl-tbl">
      <thead>
        <tr>
          <th>Customer</th>
          <th>Order</th>
          <th>Date of sale</th>
          <th class="r">Amount</th>
          <th>Affiliate</th>
          <th>Shopify status</th>
          <th class="r">Credit</th>
          <th class="r">Debit</th>
          <th class="r">Balance</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php
        $run = 0.0;
        foreach ($ledger as $l):
          $run += $l['credit'] - $l['debit'];
          $isPay = $l['debit'] > 0;
      ?>
        <tr class="<?= $isPay ? 'pay' : '' ?>">
          <td>
            <?php if ($l['customer']): ?>
              <div class="sl-cust"><?= e($l['customer']) ?></div>
            <?php else: ?>
              <span class="sl-note-txt">payment<?= $l['note'] ? ' · ' . e($l['note']) : '' ?></span>
            <?php endif; ?>
          </td>
          <td class="sl-ord"><?= e($l['order'] ?: '—') ?></td>
          <td class="sl-date"><?= e(date('j M Y', strtotime($l['date']))) ?></td>
          <td class="r sl-num<?= $l['amount'] ? '' : ' dim' ?>">
            <?= $l['amount'] ? e(money($l['amount'], $l['cur'])) : '—' ?></td>
          <td>
            <a class="sl-ref" href="sales.php?view=billing&ref=<?= e($l['ref']) ?>"><?= e($l['ref']) ?></a>
          </td>
          <td>
            <span class="sl-pill <?= $isPay ? 'paid' : (stripos($l['status'], 'paid') !== false ? 'paid' : 'pending') ?>">
              <?= e($l['status']) ?></span>
          </td>
          <td class="r sl-num <?= $l['credit'] ? 'credit' : 'dim' ?>">
            <?= $l['credit'] ? e(money($l['credit'], $l['cur'])) : '—' ?></td>
          <td class="r sl-num <?= $l['debit'] ? 'debit' : 'dim' ?>">
            <?= $l['debit'] ? e(money($l['debit'], $l['cur'])) : '—' ?></td>
          <td class="r sl-num"><?= e(money($run)) ?></td>
          <td class="r">
            <?php if ($l['id']): ?>
              <form method="post" style="display:inline"
                    onsubmit="return confirm('Remove this payment of <?= e(money($l['debit'])) ?>?')">
                <input type="hidden" name="do" value="unpay">
                <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                <button class="sl-x" type="submit" title="Remove">&times;</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="6"></td>
          <td class="r"><span class="lbl">Credits</span>
            <span class="amt credit"><?= e(money($totCredit)) ?></span></td>
          <td class="r"><span class="lbl">Debits</span>
            <span class="amt debit"><?= e(money($totDebit)) ?></span></td>
          <td class="r"><span class="lbl">Balance</span>
            <span class="amt bal"><?= e(money($balance)) ?></span></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="sl-foot">
    <b>Credits</b> are commission earned on settled orders. <b>Debits</b> are payments
    you have recorded as made. <b>Balance</b> is what is still owed — and it carries
    down the page in the order things happened, so the last line is the current
    position.
  </div>

  <?php endif; ?>

<?php endif; ?>

<?php page_close(); ?>
