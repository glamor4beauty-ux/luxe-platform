<?php
/* ═══════════════════════════════════════════════════════════════════════════
   payouts.php — who can be paid, and who cannot.

   Stripe Connect decides whether an affiliate can receive money. This is where
   you see what it decided, and the only screen where "blocked" is actionable:
   somebody has to ring her up and arrange another way, and that does not
   happen unless it is visible.

   Nothing here can change a Stripe decision. It reports, and lets you note
   that you have dealt with it.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/authz.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/shell.php';

auth_start();
if (!auth_user()) { header('Location: login.php'); exit; }

$me = auth_user();
$my_email = is_array($me) ? ($me['email'] ?? '') : (string)$me;

const STRIPE_SECRET = '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';

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

function stripe_get(string $path): array {
    $ch = curl_init('https://api.stripe.com/v1/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => STRIPE_SECRET . ':',
        CURLOPT_TIMEOUT        => 20,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if ($raw === false) return ['ok' => false];
    $d = json_decode((string)$raw, true);
    if (!is_array($d) || isset($d['error'])) return ['ok' => false];
    return ['ok' => true, 'data' => $d];
}

$msg = '';
$err = '';

/* ── changes ──────────────────────────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $db = mb_db();
        $do = (string)($_POST['do'] ?? '');
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['code'] ?? '')));

        if ($do === 'recheck' && $code !== '') {
            /* Asked of Stripe directly, because a webhook can be missed and
               being wrong about whether somebody can be paid is the kind of
               wrong that costs a relationship. */
            $q = $db->prepare("SELECT acct FROM affiliate_stripe WHERE code = ?");
            $q->execute([$code]);
            $acct = (string)$q->fetchColumn();

            if ($acct === '') throw new RuntimeException('No Stripe account for ' . $code . '.');

            $r = stripe_get('accounts/' . $acct);
            if (!$r['ok']) throw new RuntimeException('Stripe did not answer.');

            $a = $r['data'];
            $req = $a['requirements'] ?? [];
            $due = array_merge($req['currently_due'] ?? [], $req['past_due'] ?? []);
            $dis = (string)($req['disabled_reason'] ?? '');

            $final = $dis !== '' && (strpos($dis, 'rejected') !== false
                                  || strpos($dis, 'listed') !== false);

            $state = $final ? 'blocked'
                   : (!empty($a['payouts_enabled']) ? 'enabled' : 'pending');

            $db->prepare("UPDATE affiliate_stripe
                             SET state = ?, needs = ?, reason = ?
                           WHERE code = ?")
               ->execute([$state, json_encode($due), $dis, $code]);

            $msg = $code . ' is ' . $state . '.';
        }

        if ($do === 'handled' && $code !== '') {
            /* Marking that somebody has spoken to her. It changes nothing at
               Stripe — it says a person has picked this up, so the next person
               looking does not ring her again. */
            $db->prepare("UPDATE affiliate_stripe SET studio_told = 2 WHERE code = ?")
               ->execute([$code]);
            $msg = 'Marked as dealt with.';
        }

    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

/* ── the list ─────────────────────────────────────────────────────────── */
$rows = [];
try {
    $db = mb_db();
    $db->exec("CREATE TABLE IF NOT EXISTS affiliate_stripe (
        code VARCHAR(20) PRIMARY KEY, acct VARCHAR(80) NOT NULL DEFAULT '',
        state ENUM('pending','enabled','blocked','disabled') NOT NULL DEFAULT 'pending',
        needs TEXT, reason VARCHAR(255) NOT NULL DEFAULT '',
        started TIMESTAMP NULL, settled TIMESTAMP NULL,
        studio_told TINYINT(1) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $rows = $db->query(
        "SELECT a.code, a.first_name, a.last_name, a.store_name, a.email, a.phone,
                a.hosting, a.status,
                s.acct, s.state, s.needs, s.reason, s.started, s.settled, s.studio_told
           FROM affiliates a
           LEFT JOIN affiliate_stripe s ON s.code = a.code
          ORDER BY
            CASE COALESCE(s.state,'none')
              WHEN 'blocked'  THEN 1
              WHEN 'disabled' THEN 2
              WHEN 'pending'  THEN 3
              WHEN 'enabled'  THEN 5
              ELSE 4 END,
            a.opened DESC")->fetchAll();
} catch (Throwable $e) {
    $err = $err ?: $e->getMessage();
}

/* what is owed, so a blocked affiliate with money waiting stands out */
$owed = [];
try {
    $db = mb_db();
    foreach ($db->query("SELECT ref, COALESCE(SUM(amount),0) p
                           FROM affiliate_payouts GROUP BY ref")->fetchAll() as $p) {
        $owed[strtoupper($p['ref'])] = (float)$p['p'];
    }
} catch (Throwable $e) { /* the column simply reads nothing */ }

function plain_needs($json): array {
    $needs = json_decode((string)$json, true);
    if (!is_array($needs)) return [];
    $said = [];
    foreach ($needs as $n) {
        $t = (string)$n;
        if (strpos($t, 'verification.document') !== false) $said['id'] = 'photo ID';
        elseif (strpos($t, 'external_account') !== false)  $said['bank'] = 'bank details';
        elseif (strpos($t, 'dob') !== false)               $said['dob'] = 'date of birth';
        elseif (strpos($t, 'address') !== false)           $said['addr'] = 'address';
        elseif (strpos($t, 'ssn') !== false || strpos($t, 'id_number') !== false)
                                                           $said['tax'] = 'tax number';
        elseif (strpos($t, 'tos_acceptance') !== false)    $said['tos'] = 'Stripe terms';
    }
    return array_values($said);
}

$counts = ['enabled' => 0, 'pending' => 0, 'blocked' => 0, 'none' => 0];
foreach ($rows as $r) {
    $s = $r['state'] ?: 'none';
    if ($s === 'disabled') $s = 'blocked';
    $counts[$s] = ($counts[$s] ?? 0) + 1;
}

page_open('Payouts', 'affiliates');
?>

<style>
.po-head{display:flex;justify-content:space-between;align-items:center;gap:14px;
  margin-bottom:14px;flex-wrap:wrap}
.po-head h1{font-size:19px;font-weight:700;color:var(--txt)}
.po-head .sub{font-size:12px;color:var(--mute);margin-top:2px}
.po-tabs{display:flex;gap:7px;margin-bottom:16px;flex-wrap:wrap}
.po-tab{border:1px solid var(--line2);background:transparent;color:var(--mute);
  border-radius:9px;padding:9px 17px;font-size:12.5px;font-weight:700;
  text-decoration:none;white-space:nowrap}
.po-tab:hover{border-color:var(--gold);color:var(--gold)}
.po-tab.on{background:rgba(216,169,75,.12);border-color:var(--gold);color:var(--gold)}

.po-note{background:rgba(78,201,122,.09);border:1px solid rgba(78,201,122,.35);
  color:#a8e6bd;border-radius:9px;padding:11px 14px;font-size:13px;margin-bottom:14px}
.po-err{background:rgba(242,104,94,.08);border:1px solid rgba(242,104,94,.35);
  color:#ffb3ac;border-radius:9px;padding:11px 14px;font-size:13px;margin-bottom:14px}

.po-sums{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));
  gap:12px;margin-bottom:18px}
.po-sum{background:var(--panel);border:1px solid var(--line);border-radius:12px;
  padding:15px;text-align:center}
.po-sum b{display:block;font-family:ui-monospace,Menlo,monospace;font-size:22px;
  color:var(--txt);line-height:1.2}
.po-sum b.ok{color:var(--ok)}
.po-sum b.warn{color:var(--warn)}
.po-sum b.bad{color:var(--bad)}
.po-sum span{display:block;font-size:9.5px;color:var(--mute);text-transform:uppercase;
  letter-spacing:.06em;font-weight:700;margin-top:5px}
.po-sum.alert{border-color:var(--bad);background:rgba(242,104,94,.05)}

.po-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:11px}
.po-tbl{width:100%;border-collapse:collapse;font-size:13.5px;min-width:900px}
.po-tbl th{text-align:left;padding:12px 15px;font-size:9.5px;color:var(--mute);
  text-transform:uppercase;letter-spacing:.06em;font-weight:700;background:var(--panel2);
  border-bottom:1px solid var(--line2);white-space:nowrap}
.po-tbl th.r,.po-tbl td.r{text-align:right}
.po-tbl th.c,.po-tbl td.c{text-align:center}
.po-tbl td{padding:13px 15px;border-bottom:1px solid rgba(36,40,50,.7);color:var(--txt2);
  vertical-align:middle}
.po-tbl tbody tr:last-child td{border-bottom:none}
.po-tbl tbody tr:hover{background:rgba(255,255,255,.02)}
.po-tbl tr.needs-you{background:rgba(242,104,94,.05)}
.po-tbl tr.needs-you:hover{background:rgba(242,104,94,.08)}

.po-code{font-family:ui-monospace,Menlo,monospace;color:var(--gold);font-size:13px;
  white-space:nowrap}
.po-name{font-weight:600;color:var(--txt);font-size:14.5px}
.po-mail{font-size:12px;color:var(--mute);margin-top:2px;word-break:break-all}
.po-dim{font-size:12.5px;color:var(--mute)}
.po-mono{font-family:ui-monospace,Menlo,monospace;white-space:nowrap;font-size:12px}

.po-pill{display:inline-block;border-radius:5px;padding:4px 11px;font-size:11px;
  font-weight:700;white-space:nowrap}
.po-pill.enabled{background:rgba(78,201,122,.14);color:var(--ok)}
.po-pill.pending{background:rgba(224,163,64,.14);color:var(--warn)}
.po-pill.blocked{background:rgba(242,104,94,.14);color:var(--bad)}
.po-pill.disabled{background:rgba(242,104,94,.1);color:var(--bad)}
.po-pill.none{background:rgba(123,129,148,.16);color:var(--mute)}
.po-why{font-size:11.5px;color:var(--mute);margin-top:4px;line-height:1.5}
.po-why.bad{color:var(--bad)}

.po-btn{background:none;border:1px solid var(--line2);border-radius:7px;
  color:var(--mute);padding:6px 12px;font-size:11.5px;font-weight:700;cursor:pointer;
  font-family:inherit}
.po-btn:hover{border-color:var(--gold);color:var(--gold)}
.po-btn.done{border-color:rgba(78,201,122,.4);color:var(--ok)}
.po-flag{display:inline-block;background:rgba(78,201,122,.14);color:var(--ok);
  font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:5px}

.po-empty{text-align:center;padding:44px;color:var(--faint);font-size:13.5px}
.po-foot{font-size:11.5px;color:var(--faint);line-height:1.75;margin-top:14px}
.po-foot b{color:var(--gold)}
</style>

<div class="po-head">
  <div>
    <h1>Payouts</h1>
    <div class="sub"><?= count($rows) ?> affiliate<?= count($rows) === 1 ? '' : 's' ?></div>
  </div>
</div>

<div class="po-tabs">
  <a class="po-tab" href="affiliates.php">Affiliates</a>
  <a class="po-tab" href="sales.php?view=sales">Sales</a>
  <a class="po-tab" href="sales.php?view=billing">Billing</a>
  <a class="po-tab" href="creatives.php">Creatives</a>
  <a class="po-tab on" href="payouts.php">Payouts</a>
</div>

<?php if ($msg): ?><div class="po-note"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="po-err"><?= e($err) ?></div><?php endif; ?>

<div class="po-sums">
  <div class="po-sum"><b class="ok"><?= (int)$counts['enabled'] ?></b><span>Ready</span></div>
  <div class="po-sum"><b class="warn"><?= (int)$counts['pending'] ?></b><span>Part done</span></div>
  <div class="po-sum<?= $counts['blocked'] ? ' alert' : '' ?>">
    <b class="bad"><?= (int)$counts['blocked'] ?></b><span>Blocked</span></div>
  <div class="po-sum"><b><?= (int)$counts['none'] ?></b><span>Not started</span></div>
</div>

<?php if (!$rows): ?>
  <div class="po-empty">Nobody has registered yet.</div>
<?php else: ?>

<div class="po-wrap">
  <table class="po-tbl">
    <thead>
      <tr>
        <th>Affiliate</th>
        <th>Phone</th>
        <th class="c">Payout status</th>
        <th>Stripe account</th>
        <th>Since</th>
        <th class="r">Paid so far</th>
        <th class="r"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
      $state = $r['state'] ?: 'none';
      $needsYou = ($state === 'blocked' || $state === 'disabled')
               && (int)$r['studio_told'] !== 2;
      $needs = plain_needs($r['needs']);

      $label = match ($state) {
        'enabled'  => 'Ready',
        'pending'  => 'Part done',
        'blocked'  => 'Blocked',
        'disabled' => 'Disconnected',
        default    => 'Not started',
      };
    ?>
      <tr class="<?= $needsYou ? 'needs-you' : '' ?>">
        <td>
          <div class="po-name"><?= e(trim($r['first_name'].' '.$r['last_name'])) ?: e($r['code']) ?></div>
          <div class="po-mail"><?= e($r['code']) ?> · <?= e($r['email']) ?></div>
        </td>

        <td class="po-dim po-mono"><?= e($r['phone'] ?: '—') ?></td>

        <td class="c">
          <span class="po-pill <?= e($state) ?>"><?= e($label) ?></span>
          <?php if ($state === 'pending' && $needs): ?>
            <div class="po-why">waiting on <?= e(implode(', ', $needs)) ?></div>
          <?php elseif ($r['reason']): ?>
            <div class="po-why bad"><?= e($r['reason']) ?></div>
          <?php endif; ?>
        </td>

        <td class="po-dim po-mono"><?= e($r['acct'] ?: '—') ?></td>

        <td class="po-dim po-mono">
          <?= $r['settled'] ? e(date('j M y', strtotime($r['settled'])))
             : ($r['started'] ? e(date('j M y', strtotime($r['started']))) : '—') ?>
        </td>

        <td class="r po-mono">
          <?= isset($owed[strtoupper($r['code'])])
              ? e(money($owed[strtoupper($r['code'])])) : '—' ?>
        </td>

        <td class="r">
          <?php if ($r['acct']): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="do" value="recheck">
              <input type="hidden" name="code" value="<?= e($r['code']) ?>">
              <button class="po-btn" type="submit">Re-check</button>
            </form>
          <?php endif; ?>

          <?php if ($needsYou): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="do" value="handled">
              <input type="hidden" name="code" value="<?= e($r['code']) ?>">
              <button class="po-btn done" type="submit">Dealt with</button>
            </form>
          <?php elseif (($state === 'blocked' || $state === 'disabled')
                        && (int)$r['studio_told'] === 2): ?>
            <span class="po-flag">Dealt with</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="po-foot">
  <b>Blocked</b> means Stripe will not verify them, and no amount of trying again will
  change it — somebody has to arrange another way to pay. Those rows are marked in red
  until somebody says they have dealt with it.<br>
  <b>Part done</b> means she has started and Stripe is still waiting on something; that
  usually resolves itself.<br>
  <b>Re-check</b> asks Stripe directly rather than trusting what we last heard.
</div>

<?php endif; ?>

<?php page_close(); ?>
