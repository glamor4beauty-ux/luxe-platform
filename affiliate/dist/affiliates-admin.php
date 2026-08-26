<?php
/* ═══════════════════════════════════════════════════════════════════════════
   affiliates.php — who has signed up, and what they are on.

   One table and nothing else, as asked. Status is set here; everything else
   comes from what they gave at registration.

   Free and subscribed are not a field they chose — they are what the hosting
   choice and Stripe say. A page that let you type "subscribed" against
   somebody who is not paying would be a page that lies.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/config.php';

$admin = mb_require_admin();
$me = $admin['email'];

$db = db();

/* ── changes ──────────────────────────────────────────────────────────── */
$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $do = (string)($_POST['do'] ?? '');
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['code'] ?? '')));

    if ($do === 'status' && $code !== '') {
        $s = (string)($_POST['status'] ?? '');
        if (in_array($s, ['trial', 'active', 'inactive', 'banned'], true)) {
            $db->prepare("UPDATE affiliates SET status = ? WHERE code = ?")->execute([$s, $code]);
            $msg = $code . ' set to ' . $s . '.';
        }
    }

    if ($do === 'delete' && $code !== '') {
        /* the ID photographs go with the record; government ID left on disk
           after its account has gone is worse than useless */
        $q = $db->prepare("SELECT profile_img, id_img FROM affiliates WHERE code = ?");
        $q->execute([$code]);
        if ($r = $q->fetch(PDO::FETCH_ASSOC)) {
            foreach ([$r['profile_img'], $r['id_img']] as $f) {
                if (!$f) continue;
                $abs = realpath(__DIR__ . '/uploads/affiliates/' . basename($f));
                $root = realpath(__DIR__ . '/uploads/affiliates');
                if ($abs && $root && strpos($abs, $root) === 0 && is_file($abs)) @unlink($abs);
            }
        }
        $db->prepare("DELETE FROM affiliates WHERE code = ?")->execute([$code]);
        $msg = $code . ' deleted.';
    }
}

/* ── the list ─────────────────────────────────────────────────────────── */
$rows = [];
try {
    $rows = $db->query(
        "SELECT a.code, a.first_name, a.last_name, a.store_name, a.email,
                a.status, a.hosting, a.opened,
                s.plan, s.stripe_status, s.amount
           FROM affiliates a
           LEFT JOIN affiliate_subs s ON s.code = a.code
          ORDER BY a.opened DESC, a.id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $msg = 'Could not read the list: ' . $e->getMessage();
}

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* What somebody is actually on. Taken from the hosting choice and from what
   Stripe reports, never typed in — the two together are the only honest
   answer to "is this person paying". */
function plan_of(array $r): array {
    if (($r['hosting'] ?? 'diy') !== 'managed') {
        return ['label' => 'Free', 'css' => 'free', 'amount' => null];
    }

    $st = (string)($r['stripe_status'] ?? '');
    $amt = $r['amount'] !== null ? (float)$r['amount'] : null;
    $per = ($r['plan'] ?? '') === 'quarterly' ? 'quarter' : 'month';

    if ($st === 'active')   return ['label' => 'Subscribed', 'css' => 'paid',
                                    'amount' => $amt, 'per' => $per];
    if ($st === 'past_due') return ['label' => 'Payment failed', 'css' => 'late',
                                    'amount' => $amt, 'per' => $per];
    if ($st === 'cancelled') return ['label' => 'Cancelled', 'css' => 'off',
                                     'amount' => null];

    /* chose hosting, never finished paying — the case worth seeing */
    return ['label' => 'Not started', 'css' => 'late', 'amount' => null];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<meta name="robots" content="noindex,nofollow"/>
<title>Affiliates · Models Boutique</title>
<style>
:root{--bg:#0a0d14;--panel:#111622;--panel2:#0e1219;--line:#1e2330;--line2:#2a3040;
  --txt:#e8eaf0;--txt2:#b9bcc6;--mute:#8a8fa8;--faint:#5a6070;
  --gold:#d4a830;--ok:#3fb950;--warn:#e0a340;--bad:#f85149}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--txt);
  font-family:'DM Sans',system-ui,-apple-system,'Segoe UI',sans-serif;
  font-size:14px;line-height:1.5;padding:24px 18px 60px}
.wrap{max-width:1180px;margin:0 auto}

h1{font-size:20px;font-weight:700;margin-bottom:3px}
.sub{font-size:12px;color:var(--mute)}
.top{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;
  margin-bottom:18px;flex-wrap:wrap}
.who{font-size:12px;color:var(--mute);text-align:right}
.who a{display:block;color:var(--gold);text-decoration:none;margin-top:3px}
.who a:hover{text-decoration:underline}
.note{background:rgba(63,185,80,.09);border:1px solid rgba(63,185,80,.35);
  color:#a8e6bd;border-radius:9px;padding:11px 14px;font-size:13px;margin-bottom:14px}

.tblwrap{overflow-x:auto;border:1px solid var(--line);border-radius:11px}
table{width:100%;border-collapse:collapse;font-size:13px;min-width:900px}
th{text-align:left;padding:11px 12px;font-size:9.5px;color:var(--mute);
  text-transform:uppercase;letter-spacing:.06em;font-weight:700;background:var(--panel2);
  border-bottom:1px solid var(--line2);white-space:nowrap}
th.r,td.r{text-align:right}
td{padding:11px 12px;border-bottom:1px solid rgba(30,35,48,.7);color:var(--txt2);
  vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:rgba(255,255,255,.02)}

.name{font-weight:600;color:var(--txt);font-size:14px}
.store{font-size:11.5px;color:var(--mute);margin-top:2px}
.code{font-family:ui-monospace,Menlo,monospace;color:var(--gold);font-size:12.5px;
  white-space:nowrap}
.email{font-size:12px;color:var(--mute);word-break:break-all}
.when{font-size:12px;color:var(--mute);white-space:nowrap}

.pill{display:inline-block;border-radius:5px;padding:3px 10px;font-size:10.5px;
  font-weight:700;white-space:nowrap}
.pill.free{background:rgba(138,143,168,.14);color:var(--mute)}
.pill.paid{background:rgba(63,185,80,.14);color:var(--ok)}
.pill.late{background:rgba(224,163,64,.14);color:var(--warn)}
.pill.off{background:rgba(248,81,73,.13);color:var(--bad)}
.amt{font-family:ui-monospace,Menlo,monospace;white-space:nowrap;color:var(--txt2)}
.amt small{color:var(--faint);font-family:inherit}
.dash{color:var(--faint)}

/* the four states, coloured as asked */
select.st{background:var(--panel2);border:1px solid var(--line2);border-radius:7px;
  padding:7px 9px;font-size:12px;outline:none;font-family:inherit;cursor:pointer;
  font-weight:700}
select.st.trial{color:var(--warn);border-color:rgba(224,163,64,.5)}
select.st.active{color:var(--ok);border-color:rgba(63,185,80,.5)}
select.st.inactive{color:#e8d44b;border-color:rgba(232,212,75,.5)}
select.st.banned{color:var(--bad);border-color:rgba(248,81,73,.5)}
select.st option{background:var(--panel2);color:var(--txt)}

.act{background:none;border:1px solid var(--line2);border-radius:7px;color:var(--mute);
  padding:6px 11px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit}
.act:hover{border-color:var(--gold);color:var(--gold)}
.act.del:hover{border-color:var(--bad);color:var(--bad)}

.empty{text-align:center;padding:44px;color:var(--faint);font-size:13.5px}
</style>
</head>
<body>
<div class="wrap">

  <div class="top">
    <div>
      <h1>Affiliates</h1>
      <div class="sub"><?= count($rows) ?> registered</div>
    </div>
    <div class="who">
      <?= e($admin['email']) ?>
      <a href="/logout.php">Sign out</a>
    </div>
  </div>

  <?php if ($msg): ?><div class="note"><?= e($msg) ?></div><?php endif; ?>

  <?php if (!$rows): ?>
    <div class="empty">Nobody has registered yet.</div>
  <?php else: ?>

  <div class="tblwrap">
    <table>
      <thead>
        <tr>
          <th>Name</th>
          <th>Affiliate ID</th>
          <th>Status</th>
          <th>Email</th>
          <th>Plan</th>
          <th class="r">Amount</th>
          <th>Opened</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        $p = plan_of($r);
      ?>
        <tr>
          <td>
            <div class="name"><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?></div>
            <?php if ($r['store_name']): ?>
              <div class="store"><?= e($r['store_name']) ?></div>
            <?php endif; ?>
          </td>

          <td class="code"><?= e($r['code']) ?></td>

          <td>
            <form method="post" style="display:inline">
              <input type="hidden" name="do" value="status">
              <input type="hidden" name="code" value="<?= e($r['code']) ?>">
              <select name="status" class="st <?= e($r['status']) ?>"
                      onchange="this.form.submit()">
                <option value="trial"    <?= $r['status'] === 'trial'    ? 'selected' : '' ?>>Trial</option>
                <option value="active"   <?= $r['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $r['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                <option value="banned"   <?= $r['status'] === 'banned'   ? 'selected' : '' ?>>Banned</option>
              </select>
            </form>
          </td>

          <td class="email"><?= e($r['email']) ?></td>

          <td><span class="pill <?= e($p['css']) ?>"><?= e($p['label']) ?></span></td>

          <td class="r amt">
            <?php if ($p['amount'] !== null): ?>
              $<?= number_format($p['amount'], 2) ?><small> / <?= e($p['per']) ?></small>
            <?php else: ?>
              <span class="dash">&mdash;</span>
            <?php endif; ?>
          </td>

          <td class="when"><?= $r['opened'] ? e(date('j M Y', strtotime($r['opened']))) : '&mdash;' ?></td>

          <td class="r">
            <form method="post" style="display:inline"
                  onsubmit="return confirm('Delete <?= e($r['code']) ?>?\n\nThe record and the ID photographs go with it. This cannot be undone.')">
              <input type="hidden" name="do" value="delete">
              <input type="hidden" name="code" value="<?= e($r['code']) ?>">
              <button class="act del" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php endif; ?>

</div>
</body>
</html>
