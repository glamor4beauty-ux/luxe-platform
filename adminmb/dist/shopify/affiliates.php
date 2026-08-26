<?php
/* ═══════════════════════════════════════════════════════════════════════════
   affiliates.php — the affiliate side, inside the store dashboard.

   Same shell, same login, same look as the shop. One dashboard with three
   areas rather than two systems sharing a domain.

   The list carries what you need to recognise somebody and act: who they are,
   their code, their status and their rate. Everything else — phone, address,
   subscription, site address — is on the form behind Edit, because a table
   with fourteen columns is a table nobody reads.
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

$msg = '';
$err = '';
$editing = null;

/* ── changes ──────────────────────────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $db = mb_db();
        $do = (string)($_POST['do'] ?? '');
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['code'] ?? '')));

        if ($do === 'status' && $code !== '') {
            $s = (string)($_POST['status'] ?? '');
            if (in_array($s, ['trial','active','inactive','banned'], true)) {
                $db->prepare("UPDATE affiliates SET status = ? WHERE code = ?")->execute([$s, $code]);
                $msg = $code . ' set to ' . $s . '.';
            }
        }

        if ($do === 'rate' && $code !== '') {
            $pct = (float)($_POST['percent'] ?? 0);
            if ($pct >= 0 && $pct <= 100) {
                $db->prepare("INSERT INTO affiliate_rates (ref, percent, updated_by)
                              VALUES (?,?,?)
                              ON DUPLICATE KEY UPDATE percent = VALUES(percent),
                                                      updated_by = VALUES(updated_by)")
                   ->execute([$code, $pct, $my_email]);
                $msg = $code . ' now on ' . number_format($pct, 1) . '%.';
            }
        }

        if ($do === 'delete' && $code !== '') {
            /* the photographs go with the record; government ID left on disk
               after its account has gone is worse than useless */
            $q = $db->prepare("SELECT profile_img, id_img FROM affiliates WHERE code = ?");
            $q->execute([$code]);
            if ($r = $q->fetch()) {
                $root = '/var/www/sites/affiliate/dist/uploads/affiliates';
                $real = realpath($root);
                foreach ([$r['profile_img'], $r['id_img']] as $f) {
                    if (!$f) continue;
                    $abs = realpath($root . '/' . basename($f));
                    if ($abs && $real && strpos($abs, $real) === 0 && is_file($abs)) @unlink($abs);
                }
            }
            $db->prepare("DELETE FROM affiliates WHERE code = ?")->execute([$code]);
            $db->prepare("DELETE FROM affiliate_subs WHERE code = ?")->execute([$code]);
            $msg = $code . ' deleted.';
        }

        /* ── the form ─────────────────────────────────────────────────── */
        if ($do === 'save') {
            $first = trim((string)($_POST['first_name'] ?? ''));
            $last  = trim((string)($_POST['last_name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $pass  = (string)($_POST['password'] ?? '');

            if ($last === '') throw new RuntimeException('A last name is needed.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('That email address does not look right.');
            }
            if ($pass !== '' && strlen($pass) < 8) {
                throw new RuntimeException('A password needs at least 8 characters.');
            }

            $hosting  = in_array($_POST['hosting'] ?? '', ['managed','diy'], true)
                        ? $_POST['hosting'] : 'diy';
            $template = in_array($_POST['template'] ?? '', ['template1','template2'], true)
                        ? $_POST['template'] : 'template1';
            $status   = in_array($_POST['status'] ?? '', ['trial','active','inactive','banned'], true)
                        ? $_POST['status'] : 'trial';

            $vals = [
                $first, $last,
                trim((string)($_POST['store_name'] ?? '')), $email,
                trim((string)($_POST['phone'] ?? '')),
                trim((string)($_POST['street'] ?? '')),
                trim((string)($_POST['city'] ?? '')),
                trim((string)($_POST['state'] ?? '')),
                trim((string)($_POST['zip'] ?? '')),
                trim((string)($_POST['country'] ?? '')),
                trim((string)($_POST['site_url'] ?? '')),
                $hosting, $template, $status,
                empty($_POST['sms_opt_in']) ? 0 : 1,
            ];

            if ($code !== '') {
                $sql = "UPDATE affiliates SET first_name=?, last_name=?, store_name=?, email=?,
                          phone=?, street=?, city=?, state=?, zip=?, country=?, site_url=?,
                          hosting=?, template=?, status=?, sms_opt_in=?";
                /* a password only when one was typed; an empty box means keep it */
                if ($pass !== '') { $sql .= ", pass_hash=?"; $vals[] = password_hash($pass, PASSWORD_DEFAULT); }
                $sql .= " WHERE code=?";
                $vals[] = $code;
                $db->prepare($sql)->execute($vals);
                $msg = $code . ' saved.';
                $saved = $code;

            } else {
                $dup = $db->prepare("SELECT code FROM affiliates WHERE email = ?");
                $dup->execute([$email]);
                if ($x = $dup->fetchColumn()) {
                    throw new RuntimeException('That email already belongs to ' . $x . '.');
                }

                /* AFF + four letters of the surname + two digits. The digits are
                   not decoration: Johnson, Johnston and Johns all give AFFJOHN,
                   and two people sharing a code means commission paid to the
                   wrong person. */
                $stem = substr(strtoupper(preg_replace('/[^A-Za-z]/', '', $last)), 0, 4) ?: 'AFFL';
                $new = '';
                for ($i = 0; $i < 60; $i++) {
                    $try = 'AFF' . $stem . str_pad((string)random_int(0, 99), 2, '0', STR_PAD_LEFT);
                    $c = $db->prepare("SELECT 1 FROM affiliates WHERE code = ?");
                    $c->execute([$try]);
                    if (!$c->fetch()) { $new = $try; break; }
                }
                if ($new === '') $new = 'AFF' . $stem . substr(bin2hex(random_bytes(2)), 0, 3);

                array_unshift($vals, $new);
                $vals[] = $pass !== '' ? password_hash($pass, PASSWORD_DEFAULT) : '';

                $db->prepare("INSERT INTO affiliates
                    (code, first_name, last_name, store_name, email, phone, street, city,
                     state, zip, country, site_url, hosting, template, status, sms_opt_in,
                     pass_hash, opened)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURDATE())")->execute($vals);

                $msg = 'Added ' . $new . '.';
                $saved = $new;
            }

            $pct = (float)($_POST['rate'] ?? 10);
            if ($pct >= 0 && $pct <= 100) {
                $db->prepare("INSERT INTO affiliate_rates (ref, percent, updated_by) VALUES (?,?,?)
                              ON DUPLICATE KEY UPDATE percent=VALUES(percent),
                                                      updated_by=VALUES(updated_by)")
                   ->execute([$saved, $pct, $my_email]);
            }
        }

    } catch (Throwable $e) {
        $err = $e->getMessage();
        /* keep them on the form with what they typed, rather than throwing it away */
        $editing = $_POST;
        $editing['code'] = $code;
    }
}

/* opening the form */
if ($editing === null && isset($_GET['edit'])) {
    $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$_GET['edit']));
    if ($c === '') {
        $editing = ['code' => '', 'status' => 'trial', 'hosting' => 'diy',
                    'template' => 'template1', 'country' => 'United States', 'rate' => 10];
    } else {
        try {
            $q = mb_db()->prepare("SELECT * FROM affiliates WHERE code = ?");
            $q->execute([$c]);
            $editing = $q->fetch() ?: null;
            if ($editing) {
                $r = mb_db()->prepare("SELECT percent FROM affiliate_rates WHERE ref = ?");
                $r->execute([$c]);
                $editing['rate'] = ($v = $r->fetchColumn()) !== false ? (float)$v : 10.0;
            }
        } catch (Throwable $e) { $err = $e->getMessage(); }
    }
}

/* ── the list ─────────────────────────────────────────────────────────── */
$rows = [];
$rates = [];

try {
    $db = mb_db();
    $rows = $db->query(
        "SELECT a.code, a.first_name, a.last_name, a.store_name, a.email,
                a.hosting, a.status, a.opened, a.site_url
           FROM affiliates a
          ORDER BY a.opened DESC, a.id DESC")->fetchAll();

    foreach ($db->query("SELECT ref, percent FROM affiliate_rates")->fetchAll() as $r) {
        $rates[strtolower($r['ref'])] = (float)$r['percent'];
    }
} catch (Throwable $e) {
    $err = $err ?: $e->getMessage();
}

page_open('Affiliates', 'affiliates');
?>

<style>
.af-head{display:flex;justify-content:space-between;align-items:center;gap:14px;
  margin-bottom:16px;flex-wrap:wrap}
.af-head h1{font-size:19px;font-weight:700;color:var(--txt)}
.af-head .sub{font-size:12px;color:var(--mute);margin-top:2px}
.af-note{background:rgba(78,201,122,.09);border:1px solid rgba(78,201,122,.35);
  color:#a8e6bd;border-radius:9px;padding:11px 14px;font-size:13px;margin-bottom:14px}
.af-err{background:rgba(242,104,94,.08);border:1px solid rgba(242,104,94,.35);
  color:#ffb3ac;border-radius:9px;padding:11px 14px;font-size:13px;margin-bottom:14px}

.af-add{background:var(--gold);border:1px solid var(--gold);border-radius:9px;
  color:#0f1115;padding:10px 18px;font-size:13px;font-weight:700;cursor:pointer;
  font-family:inherit;text-decoration:none;display:inline-block;white-space:nowrap}
.af-add:hover{background:#e8bf44;color:#0f1115}

.af-tabs{display:flex;gap:7px;margin-bottom:16px;flex-wrap:wrap}
.af-tab{border:1px solid var(--line2);background:transparent;color:var(--mute);
  border-radius:9px;padding:9px 17px;font-size:12.5px;font-weight:700;
  text-decoration:none;display:inline-block;white-space:nowrap}
.af-tab:hover{border-color:var(--gold);color:var(--gold)}
.af-tab.on{background:rgba(216,169,75,.12);border-color:var(--gold);color:var(--gold)}

/* Five columns and room to breathe. Everything else is on the form, because a
   table with fourteen columns is a table nobody reads. */
.af-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:11px}
.af-tbl{width:100%;border-collapse:collapse;font-size:13.5px;min-width:720px}
.af-tbl th{text-align:left;padding:12px 16px;font-size:9.5px;color:var(--mute);
  text-transform:uppercase;letter-spacing:.06em;font-weight:700;background:var(--panel2);
  border-bottom:1px solid var(--line2);white-space:nowrap}
.af-tbl th.r,.af-tbl td.r{text-align:right}
.af-tbl th.c,.af-tbl td.c{text-align:center}
.af-tbl td{padding:13px 16px;border-bottom:1px solid rgba(36,40,50,.7);color:var(--txt2);
  vertical-align:middle}
.af-tbl tbody tr:last-child td{border-bottom:none}
.af-tbl tbody tr:hover{background:rgba(255,255,255,.02)}

.af-code{font-family:ui-monospace,Menlo,monospace;color:var(--gold);font-size:13px;
  white-space:nowrap}
.af-name{font-weight:600;color:var(--txt);font-size:14.5px}
.af-store{font-size:12.5px;color:var(--mute);margin-top:2px}
.af-email{font-size:13px;color:var(--txt2);word-break:break-all}
.af-dim{font-size:12.5px;color:var(--mute)}
.af-mono{font-family:ui-monospace,Menlo,monospace;white-space:nowrap}

select.af-st{background:var(--panel2);border:1px solid var(--line2);border-radius:7px;
  padding:7px 11px;font-size:12px;outline:none;font-family:inherit;cursor:pointer;
  font-weight:700}
select.af-st.trial{color:var(--warn);border-color:rgba(224,163,64,.5)}
select.af-st.active{color:var(--ok);border-color:rgba(78,201,122,.5)}
select.af-st.inactive{color:#e8d44b;border-color:rgba(232,212,75,.5)}
select.af-st.banned{color:var(--bad);border-color:rgba(242,104,94,.5)}
select.af-st option{background:var(--panel2);color:var(--txt)}

.af-rate{width:60px;background:var(--panel2);border:1px solid var(--line2);
  border-radius:6px;padding:6px 8px;color:var(--txt);font-size:12.5px;text-align:right;
  outline:none;font-family:ui-monospace,monospace}
.af-rate:focus{border-color:var(--gold)}

.af-pill{display:inline-block;border-radius:5px;padding:3px 10px;font-size:10.5px;
  font-weight:700;white-space:nowrap}
.af-pill.managed{background:rgba(78,201,122,.14);color:var(--ok)}
.af-pill.diy{background:rgba(123,129,148,.16);color:var(--mute)}

.af-btn{background:none;border:1px solid var(--line2);border-radius:7px;
  color:var(--mute);padding:6px 13px;font-size:11.5px;font-weight:700;cursor:pointer;
  font-family:inherit;text-decoration:none;display:inline-block}
.af-btn:hover{border-color:var(--gold);color:var(--gold)}
.af-btn.del:hover{border-color:var(--bad);color:var(--bad)}
.af-empty{text-align:center;padding:44px;color:var(--faint);font-size:13.5px}

/* ── the form ─────────────────────────────────────────────────────────── */
.af-form{background:var(--panel);border:1px solid var(--line2);border-radius:14px;
  padding:22px;margin-bottom:20px}
.af-form h2{font-size:17px;font-weight:700;color:var(--txt);margin-bottom:3px}
.af-form .fsub{font-size:12.5px;color:var(--mute);margin-bottom:18px}
.af-sec{font-size:10px;color:var(--gold);text-transform:uppercase;letter-spacing:.09em;
  font-weight:700;margin:20px 0 11px;padding-bottom:7px;border-bottom:1px solid var(--line)}
.af-sec:first-of-type{margin-top:0}
.af-grid{display:grid;grid-template-columns:1fr 1fr;gap:13px}
@media(max-width:640px){.af-grid{grid-template-columns:1fr}}
.af-grid .full{grid-column:1/-1}
.af-l{display:block;font-size:9.5px;color:var(--mute);text-transform:uppercase;
  letter-spacing:.08em;font-weight:700;margin-bottom:6px}
.af-in{width:100%;background:var(--panel2);border:1px solid var(--line2);border-radius:9px;
  padding:11px 13px;color:var(--txt);font-size:15px;outline:none;font-family:inherit}
.af-in:focus{border-color:var(--gold)}
.af-in[readonly]{color:var(--gold);font-family:ui-monospace,monospace}
.af-chk{display:flex;gap:11px;align-items:center;font-size:13.5px;color:var(--txt2);
  cursor:pointer;padding:10px 0}
.af-chk input{width:20px;height:20px;accent-color:var(--gold)}
.af-hint{font-size:11.5px;color:var(--faint);margin-top:5px;line-height:1.55}
.af-fbtns{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap}
.af-save{background:var(--gold);border:1px solid var(--gold);border-radius:10px;
  color:#0f1115;padding:12px 26px;font-size:14.5px;font-weight:700;cursor:pointer;
  font-family:inherit}
.af-save:hover{background:#e8bf44}
.af-cancel{background:transparent;border:1px solid var(--line2);border-radius:10px;
  color:var(--txt2);padding:12px 22px;font-size:14.5px;font-weight:700;cursor:pointer;
  font-family:inherit;text-decoration:none;display:inline-block}
.af-cancel:hover{border-color:var(--gold);color:var(--gold)}
</style>

<div class="af-head">
  <div>
    <h1>Affiliates</h1>
    <div class="sub"><?= count($rows) ?> registered</div>
  </div>
  <?php if (!$editing): ?>
    <a class="af-add" href="?edit=">+ Add affiliate</a>
  <?php endif; ?>
</div>

<div class="af-tabs">
  <a class="af-tab on" href="affiliates.php">Affiliates</a>
  <a class="af-tab" href="sales.php?view=sales">Sales</a>
  <a class="af-tab" href="sales.php?view=billing">Billing</a>
</div>

<?php if ($msg): ?><div class="af-note"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="af-err"><?= e($err) ?></div><?php endif; ?>

<?php if ($editing):
  $isNew = empty($editing['code']);
  $g = fn($k, $d = '') => e($editing[$k] ?? $d);
?>
  <form class="af-form" method="post" action="affiliates.php">
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="code" value="<?= e($editing['code'] ?? '') ?>">

    <h2><?= $isNew ? 'Add an affiliate' : 'Edit ' . e($editing['code']) ?></h2>
    <div class="fsub"><?= $isNew
      ? 'Their code is made from their surname once you save.'
      : 'Leave the password blank to keep the one they have.' ?></div>

    <div class="af-sec">Identity</div>
    <div class="af-grid">
      <?php if (!$isNew): ?>
        <div><label class="af-l">Affiliate ID</label>
          <input class="af-in" value="<?= $g('code') ?>" readonly></div>
        <div></div>
      <?php endif; ?>
      <div><label class="af-l">First name</label>
        <input class="af-in" name="first_name" value="<?= $g('first_name') ?>"></div>
      <div><label class="af-l">Last name</label>
        <input class="af-in" name="last_name" value="<?= $g('last_name') ?>" required></div>
      <div class="full"><label class="af-l">Store name</label>
        <input class="af-in" name="store_name" value="<?= $g('store_name') ?>"></div>
      <div><label class="af-l">Email</label>
        <input class="af-in" type="email" name="email" value="<?= $g('email') ?>" required></div>
      <div><label class="af-l">Phone</label>
        <input class="af-in" name="phone" value="<?= $g('phone') ?>"></div>
      <div class="full"><label class="af-l">Password</label>
        <input class="af-in" type="password" name="password" autocomplete="new-password">
        <div class="af-hint"><?= $isNew
          ? 'How they sign in. Leave blank and they cannot, until one is set.'
          : 'Only fill this in to change it.' ?></div></div>
    </div>

    <div class="af-sec">Address</div>
    <div class="af-grid">
      <div class="full"><label class="af-l">Street</label>
        <input class="af-in" name="street" value="<?= $g('street') ?>"></div>
      <div><label class="af-l">City</label>
        <input class="af-in" name="city" value="<?= $g('city') ?>"></div>
      <div><label class="af-l">State</label>
        <input class="af-in" name="state" value="<?= $g('state') ?>"></div>
      <div><label class="af-l">Postcode</label>
        <input class="af-in" name="zip" value="<?= $g('zip') ?>"></div>
      <div><label class="af-l">Country</label>
        <input class="af-in" name="country" value="<?= $g('country', 'United States') ?>"></div>
    </div>

    <div class="af-sec">Account</div>
    <div class="af-grid">
      <div><label class="af-l">Hosting</label>
        <select class="af-in" name="hosting">
          <option value="diy"<?= ($editing['hosting'] ?? '') === 'diy' ? ' selected' : '' ?>>Do it yourself</option>
          <option value="managed"<?= ($editing['hosting'] ?? '') === 'managed' ? ' selected' : '' ?>>Managed</option>
        </select></div>
      <div><label class="af-l">Template</label>
        <select class="af-in" name="template">
          <option value="template1"<?= ($editing['template'] ?? '') === 'template1' ? ' selected' : '' ?>>Classic</option>
          <option value="template2"<?= ($editing['template'] ?? '') === 'template2' ? ' selected' : '' ?>>Banner</option>
        </select></div>
      <div><label class="af-l">Status</label>
        <select class="af-in" name="status">
          <?php foreach (['trial'=>'Trial','active'=>'Active',
                          'inactive'=>'Inactive','banned'=>'Banned'] as $k => $v): ?>
            <option value="<?= $k ?>"<?= ($editing['status'] ?? '') === $k ? ' selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label class="af-l">Commission rate %</label>
        <input class="af-in" name="rate" value="<?= e(number_format((float)($editing['rate'] ?? 10), 1)) ?>"></div>
      <div class="full"><label class="af-l">Site address</label>
        <input class="af-in" name="site_url" value="<?= $g('site_url') ?>"
               placeholder="https://theirstore.com">
        <div class="af-hint">Filled in automatically when we build it for them.</div></div>
      <label class="af-chk full">
        <input type="checkbox" name="sms_opt_in" value="1"<?= !empty($editing['sms_opt_in']) ? ' checked' : '' ?>>
        <span>Happy to receive texts</span></label>
    </div>

    <div class="af-fbtns">
      <button class="af-save" type="submit"><?= $isNew ? 'Add affiliate' : 'Save changes' ?></button>
      <a class="af-cancel" href="affiliates.php">Cancel</a>
    </div>
  </form>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="af-empty">Nobody has registered yet.</div>
<?php else: ?>

<div class="af-wrap">
  <table class="af-tbl">
    <thead>
      <tr>
        <th>Affiliate ID</th>
        <th>Name</th>
        <th>Email</th>
        <th class="c">Hosting</th>
        <th class="c">Status</th>
        <th class="r">Rate</th>
        <th>Opened</th>
        <th class="r">Add/Edit</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
      $rate = $rates[strtolower($r['code'])] ?? 20.0;
    ?>
      <tr>
        <td class="af-code"><?= e($r['code']) ?></td>

        <td>
          <div class="af-name"><?= e(trim($r['first_name'].' '.$r['last_name'])) ?: '—' ?></div>
          <?php if ($r['store_name']): ?>
            <div class="af-store"><?= e($r['store_name']) ?></div>
          <?php endif; ?>
        </td>

        <td class="af-email"><?= e($r['email'] ?: '—') ?></td>

        <td class="c"><span class="af-pill <?= e($r['hosting']) ?>">
          <?= $r['hosting'] === 'managed' ? 'Managed' : 'DIY' ?></span></td>

        <td class="c">
          <form method="post" style="display:inline">
            <input type="hidden" name="do" value="status">
            <input type="hidden" name="code" value="<?= e($r['code']) ?>">
            <select name="status" class="af-st <?= e($r['status']) ?>" onchange="this.form.submit()">
              <?php foreach (['trial'=>'Trial','active'=>'Active',
                              'inactive'=>'Inactive','banned'=>'Banned'] as $k => $v): ?>
                <option value="<?= $k ?>"<?= $r['status']===$k?' selected':'' ?>><?= $v ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </td>

        <td class="r">
          <form method="post" style="display:inline">
            <input type="hidden" name="do" value="rate">
            <input type="hidden" name="code" value="<?= e($r['code']) ?>">
            <input class="af-rate" name="percent" value="<?= number_format($rate, 1) ?>"
                   onchange="this.form.submit()">%
          </form>
        </td>

        <td class="af-dim af-mono"><?= $r['opened'] ? e(date('j M y', strtotime($r['opened']))) : '—' ?></td>

        <td class="r">
          <a class="af-btn" href="?edit=<?= e($r['code']) ?>">Edit</a>
          <form method="post" style="display:inline"
                onsubmit="return confirm('Delete <?= e($r['code']) ?>?\n\nThe record and the ID photographs go with it. This cannot be undone.')">
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="code" value="<?= e($r['code']) ?>">
            <button class="af-btn del" type="submit">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<?php page_close(); ?>
