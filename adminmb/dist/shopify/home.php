<?php
/* ═══════════════════════════════════════════════════════════════════════════
   home.php — where the dashboard opens.

   The few numbers worth seeing before deciding what to do: what the shop is
   doing, who is selling, and what is owed. Each panel leads somewhere rather
   than being an ornament.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/authz.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/shell.php';

/* The store dashboard's own login, so this is one dashboard rather than two
   sitting next to each other. */
auth_start();
if (!auth_user()) { header('Location: login.php'); exit; }

if (!defined('AFFILIATE_SITE')) {
    define('AFFILIATE_SITE', 'https://affiliate.modelsboutique.com');
}

/* The affiliate side keeps its own database. Read separately rather than
   joined, so a fault on one side leaves the other still showing. */
function mb_db(): ?PDO {
    static $pdo = null;
    static $tried = false;
    if ($tried) return $pdo;
    $tried = true;
    try {
        $pdo = new PDO('mysql:host=127.0.0.1;dbname=models_boutique;charset=utf8mb4',
                       'mb_app', 'Mb7#kRq2vLx9Twn4', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        error_log('[home] affiliate db: ' . $e->getMessage());
        $pdo = null;
    }
    return $pdo;
}

$aff = ['total' => 0, 'active' => 0, 'trial' => 0, 'built' => 0, 'paying' => 0, 'mrr' => 0.0];
$recent = [];
$affErr = '';

if ($db = mb_db()) {
    try {
        $r = $db->query("SELECT
              COUNT(*) total,
              SUM(status='active') active,
              SUM(status='trial') trial,
              SUM(site_url<>'') built
            FROM affiliates")->fetch();
        $aff['total']  = (int)$r['total'];
        $aff['active'] = (int)$r['active'];
        $aff['trial']  = (int)$r['trial'];
        $aff['built']  = (int)$r['built'];

        $s = $db->query("SELECT COUNT(*) n, COALESCE(SUM(amount),0) amt
                           FROM affiliate_subs WHERE stripe_status='active'")->fetch();
        $aff['paying'] = (int)$s['n'];
        $aff['mrr']    = (float)$s['amt'];

        $recent = $db->query(
            "SELECT code, first_name, last_name, store_name, status, opened, site_url
               FROM affiliates ORDER BY id DESC LIMIT 6")->fetchAll();
    } catch (Throwable $e) {
        $affErr = $e->getMessage();
    }
} else {
    $affErr = 'The affiliate database could not be reached.';
}

page_open('Home', 'home');
?>

<style>
.hm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
  gap:12px;margin-bottom:22px}
.hm-card{background:var(--panel);border:1px solid var(--line);border-radius:13px;
  padding:17px;text-decoration:none;display:block;transition:border-color .18s}
a.hm-card:hover{border-color:var(--gold)}
.hm-card b{display:block;font-family:ui-monospace,Menlo,monospace;font-size:25px;
  color:var(--txt);line-height:1.15}
.hm-card b.gold{color:var(--gold)}
.hm-card b.ok{color:var(--ok)}
.hm-card span{display:block;font-size:9.5px;color:var(--mute);text-transform:uppercase;
  letter-spacing:.07em;font-weight:700;margin-top:5px}

.hm-sec{font-size:10px;color:var(--gold);text-transform:uppercase;letter-spacing:.09em;
  font-weight:700;margin:26px 0 12px;padding-bottom:7px;border-bottom:1px solid var(--line)}

.hm-rows{border:1px solid var(--line);border-radius:12px;overflow:hidden}
.hm-row{display:flex;align-items:center;gap:12px;padding:13px 15px;
  border-bottom:1px solid rgba(36,40,50,.7);text-decoration:none}
.hm-row:last-child{border-bottom:none}
.hm-row:hover{background:rgba(255,255,255,.02)}
.hm-row .who{flex:1;min-width:0}
.hm-row .nm{font-size:14px;color:var(--txt);font-weight:600}
.hm-row .st{font-size:11.5px;color:var(--mute);margin-top:2px}
.hm-row .cd{font-family:ui-monospace,Menlo,monospace;font-size:12px;color:var(--gold);
  white-space:nowrap}
.pill{display:inline-block;border-radius:5px;padding:2px 8px;font-size:10px;font-weight:700}
.pill.trial{background:rgba(224,163,64,.14);color:var(--warn)}
.pill.active{background:rgba(78,201,122,.14);color:var(--ok)}
.pill.inactive{background:rgba(232,212,75,.14);color:#e8d44b}
.pill.banned{background:rgba(242,104,94,.14);color:var(--bad)}

.hm-links{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
.hm-link{background:var(--panel);border:1px solid var(--line);border-radius:12px;
  padding:16px;text-decoration:none;display:block}
.hm-link:hover{border-color:var(--gold)}
.hm-link b{display:block;font-size:14.5px;color:var(--txt);margin-bottom:3px}
.hm-link span{font-size:12px;color:var(--mute);line-height:1.55}
.hm-alert{background:rgba(242,104,94,.08);border:1px solid rgba(242,104,94,.35);
  color:#ffb3ac;border-radius:10px;padding:12px 15px;font-size:13px;margin-bottom:18px}
.hm-empty{text-align:center;padding:30px;color:var(--faint);font-size:13px}
</style>

<?php if ($affErr): ?>
  <div class="hm-alert"><?= e($affErr) ?></div>
<?php endif; ?>

<div class="hm-sec" style="margin-top:0">Affiliates</div>

<div class="hm-grid">
  <a class="hm-card" href="affiliates.php">
    <b><?= (int)$aff['total'] ?></b><span>Registered</span>
  </a>
  <a class="hm-card" href="affiliates.php">
    <b class="ok"><?= (int)$aff['active'] ?></b><span>Active</span>
  </a>
  <a class="hm-card" href="affiliates.php">
    <b><?= (int)$aff['built'] ?></b><span>Sites built</span>
  </a>
  <a class="hm-card" href="affiliates.php">
    <b class="gold">$<?= number_format($aff['mrr'], 2) ?></b><span>Subscriptions</span>
  </a>
</div>

<div class="hm-sec">Latest sign-ups</div>

<?php if (!$recent): ?>
  <div class="hm-empty">Nobody has registered yet.</div>
<?php else: ?>
  <div class="hm-rows">
    <?php foreach ($recent as $r): ?>
      <a class="hm-row" href="affiliates.php">
        <div class="who">
          <div class="nm"><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?: '—' ?></div>
          <div class="st">
            <?= e($r['store_name'] ?: 'no store name') ?>
            &middot; <span class="pill <?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span>
            <?= $r['site_url'] ? ' &middot; site built' : '' ?>
          </div>
        </div>
        <div class="cd"><?= e($r['code']) ?></div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="hm-sec">The shop</div>

<div class="hm-links">
  <a class="hm-link" href="index.php">
    <b>Customers</b><span>Who has bought, and what they spent</span>
  </a>
  <a class="hm-link" href="payments.php">
    <b>Payments</b><span>Orders, refunds and payouts</span>
  </a>
  <a class="hm-link" href="catalog.php">
    <b>Catalogue</b><span>Products, stock and landed cost</span>
  </a>
  <a class="hm-link" href="<?= e(AFFILIATE_SITE) ?>" target="_blank" rel="noopener">
    <b>The affiliate site &nearr;</b><span>Where they sign up</span>
  </a>
</div>

<?php page_close(); ?>
