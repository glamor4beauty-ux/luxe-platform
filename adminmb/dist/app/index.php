<?php
/* ═══════════════════════════════════════════════════════════════════════════
   index.php — the app, inside Shopify's admin.

   What a merchant sees: their affiliate code, what they have sold, what they
   have earned, and how to put the shop on their storefront.

   It runs in an iframe inside Shopify, so it cannot rely on cookies — the
   session token from App Bridge is what proves who is asking. Everything the
   page fetches carries one.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/lib.php';

$shop = app_shop($_GET['shop'] ?? '');
$host = (string)($_GET['host'] ?? '');

if ($shop === '') { header('Location: install.php'); exit; }

$db = app_db();
app_tables($db);

$q = $db->prepare("SELECT * FROM mb_shops WHERE shop = ? AND uninstalled IS NULL");
$q->execute([$shop]);
$row = $q->fetch();

/* Not installed, or the token has gone — send them round the install again
   rather than showing an empty app. */
if (!$row || $row['token'] === '') {
    header('Location: install.php?shop=' . urlencode($shop) . '&host=' . urlencode($host));
    exit;
}

$db->prepare("UPDATE mb_shops SET last_seen = NOW() WHERE shop = ?")->execute([$shop]);

$code = (string)$row['code'];
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Models Boutique</title>

<!-- App Bridge, which is what makes this an embedded app rather than an
     iframe of somebody's website. Shopify checks for it. -->
<meta name="shopify-api-key" content="<?= e(APP_KEY) ?>"/>
<script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>

<style>
:root{--ink:#1a1c1e;--soft:#616161;--line:#e3e3e3;--wash:#f7f7f7;--g:#d4a830;
  --ok:#0f7c3f;--warn:#8a6116}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
  background:var(--wash);color:var(--ink);font-size:14px;line-height:1.5;padding:20px}
.wrap{max-width:960px;margin:0 auto}

.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px;
  margin-bottom:16px}
.card h2{font-size:15px;font-weight:650;margin-bottom:4px}
.card .sub{font-size:13px;color:var(--soft);margin-bottom:16px}

.hello{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;
  flex-wrap:wrap;margin-bottom:18px}
.hello h1{font-size:20px;font-weight:650}
.hello p{font-size:13.5px;color:var(--soft);margin-top:2px}
.badge{background:#eef7f0;color:var(--ok);border:1px solid #cde8d6;border-radius:20px;
  padding:5px 14px;font-size:12.5px;font-weight:600;white-space:nowrap}

.figs{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;
  margin-bottom:16px}
.fig{background:#fff;border:1px solid var(--line);border-radius:12px;padding:18px;
  text-align:center}
.fig b{display:block;font-size:26px;font-weight:650;line-height:1.15}
.fig b.money{color:var(--g)}
.fig b.earn{color:var(--ok)}
.fig span{display:block;font-size:11.5px;color:var(--soft);margin-top:5px}

.code{display:flex;align-items:center;gap:12px;background:var(--wash);
  border:1px solid var(--line);border-radius:10px;padding:14px 16px}
.code code{flex:1;font-size:17px;font-weight:650;letter-spacing:.5px;color:var(--g);
  font-family:ui-monospace,Menlo,monospace}
.btn{background:var(--ink);color:#fff;border:none;border-radius:8px;padding:9px 18px;
  font-size:13.5px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap;
  text-decoration:none;display:inline-block}
.btn:hover{background:#000;color:#fff}
.btn.light{background:#fff;color:var(--ink);border:1px solid var(--line)}
.btn.light:hover{background:var(--wash);color:var(--ink)}

.steps{counter-reset:s}
.step{display:flex;gap:14px;padding:14px 0;border-bottom:1px solid var(--line)}
.step:last-child{border-bottom:none}
.step .n{counter-increment:s;flex:0 0 26px;height:26px;border-radius:50%;
  background:var(--ink);color:#fff;font-size:12.5px;font-weight:650;text-align:center;
  line-height:26px}
.step .n:before{content:counter(s)}
.step b{display:block;font-size:14px;font-weight:600;margin-bottom:3px}
.step p{font-size:13px;color:var(--soft);margin:0}
.step code{background:var(--wash);border:1px solid var(--line);border-radius:4px;
  padding:1px 6px;font-size:12px}

table{width:100%;border-collapse:collapse;font-size:13.5px}
th{text-align:left;padding:10px 12px;font-size:11px;color:var(--soft);font-weight:600;
  text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid var(--line)}
th.r,td.r{text-align:right}
td{padding:12px;border-bottom:1px solid var(--line);color:#3a3d40}
tbody tr:last-child td{border-bottom:none}
.mono{font-family:ui-monospace,Menlo,monospace;font-size:12.5px}
.pill{display:inline-block;border-radius:5px;padding:2px 9px;font-size:11px;font-weight:600}
.pill.paid{background:#eef7f0;color:var(--ok)}
.pill.wait{background:#fdf5e6;color:var(--warn)}

.empty{text-align:center;padding:36px;color:var(--soft);font-size:13.5px}
.loading{text-align:center;padding:30px;color:var(--soft);font-size:13px}
.note{font-size:12.5px;color:var(--soft);line-height:1.65;background:var(--wash);
  border-radius:8px;padding:12px 14px;margin-top:14px}
</style>
</head>
<body>
<div class="wrap">

  <div class="hello">
    <div>
      <h1>Models Boutique</h1>
      <p><?= e($row['shop_name'] ?: $shop) ?></p>
    </div>
    <span class="badge">Connected</span>
  </div>

  <!-- ══ what they have earned ══ -->
  <div class="figs" id="figs">
    <div class="fig"><b>—</b><span>Orders</span></div>
    <div class="fig"><b>—</b><span>Sold</span></div>
    <div class="fig"><b>—</b><span>Your rate</span></div>
    <div class="fig"><b>—</b><span>Commission</span></div>
  </div>

  <!-- ══ their code ══ -->
  <div class="card">
    <h2>Your affiliate code</h2>
    <div class="sub">This credits every sale to you. It is already in the block.</div>
    <div class="code">
      <code id="mycode"><?= e($code ?: '—') ?></code>
      <button class="btn light" onclick="copyCode()">Copy</button>
    </div>
  </div>

  <!-- ══ putting it on their shop ══ -->
  <div class="card">
    <h2>Put the shop on your storefront</h2>
    <div class="sub">Two minutes, and no code to write.</div>

    <div class="steps">
      <div class="step">
        <div class="n"></div>
        <div>
          <b>Open your theme editor</b>
          <p>Online Store → Themes → Customise.</p>
        </div>
      </div>
      <div class="step">
        <div class="n"></div>
        <div>
          <b>Add a section</b>
          <p>Click <code>Add section</code> where you want the shop to appear, and
             choose <b>Models Boutique</b> under Apps.</p>
        </div>
      </div>
      <div class="step">
        <div class="n"></div>
        <div>
          <b>Save</b>
          <p>Your customers can browse and buy. We hold the stock, take the payment
             and ship the order.</p>
        </div>
      </div>
    </div>

    <div class="note">
      Your header, your footer, your theme — the block sits wherever you put it and
      takes its fonts and colours from your own design.
    </div>
  </div>

  <!-- ══ their sales ══ -->
  <div class="card">
    <h2>Your sales</h2>
    <div class="sub">Every order placed through your shop.</div>
    <div id="sales"><div class="loading">Loading…</div></div>
  </div>

</div>

<script>
/* ── App Bridge ───────────────────────────────────────────────────────────
   The session token is what proves this request came from a real admin
   session. It expires in a minute, so one is fetched per request rather than
   held. */
var host = <?= json_encode($host) ?>;
var shop = <?= json_encode($shop) ?>;

var app = null;
if (window['app-bridge']) {
  var AB = window['app-bridge'];
  app = AB.createApp({
    apiKey: <?= json_encode(APP_KEY) ?>,
    host: host,
    forceRedirect: true
  });
}

function token() {
  if (window.shopify && window.shopify.idToken) return window.shopify.idToken();
  if (app && window['app-bridge-utils']) {
    return window['app-bridge-utils'].getSessionToken(app);
  }
  return Promise.resolve('');
}

function ask(action) {
  return token().then(function (t) {
    return fetch('data.php?action=' + action + '&shop=' + encodeURIComponent(shop), {
      headers: t ? { 'Authorization': 'Bearer ' + t } : {}
    }).then(function (r) { return r.json(); });
  });
}

function usd(n) { return '$' + Number(n || 0).toFixed(2); }
function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
  });
}

ask('summary').then(function (d) {
  if (!d.ok) return;
  var t = d.totals || {};
  document.getElementById('figs').innerHTML =
    '<div class="fig"><b>' + (t.orders || 0) + '</b><span>Orders</span></div>' +
    '<div class="fig"><b class="money">' + usd(t.sold) + '</b><span>Sold</span></div>' +
    '<div class="fig"><b>' + Number(t.rate || 0).toFixed(1) + '%</b><span>Your rate</span></div>' +
    '<div class="fig"><b class="earn">' + usd(t.commission) + '</b><span>Commission</span></div>';
}).catch(function () {});

ask('sales').then(function (d) {
  var box = document.getElementById('sales');
  if (!d.ok) { box.innerHTML = '<div class="empty">Could not load your sales.</div>'; return; }
  if (!d.sales || !d.sales.length) {
    box.innerHTML = '<div class="empty">No sales yet. Add the block to your storefront ' +
      'and they will appear here.</div>';
    return;
  }

  var h = '<table><thead><tr><th>Order</th><th>Date</th><th>Customer</th>' +
    '<th class="r">Amount</th><th class="r">Commission</th><th>Status</th>' +
    '</tr></thead><tbody>';

  d.sales.forEach(function (s) {
    h += '<tr>' +
      '<td class="mono">' + esc(s.order) + '</td>' +
      '<td class="mono">' + esc(s.date) + '</td>' +
      '<td>' + esc(s.customer) + '</td>' +
      '<td class="r mono">' + usd(s.amount) + '</td>' +
      '<td class="r mono">' + (s.counts ? usd(s.commission) : '—') + '</td>' +
      '<td><span class="pill ' + (s.counts ? 'paid' : 'wait') + '">' +
        esc(s.status) + '</span></td>' +
    '</tr>';
  });

  box.innerHTML = h + '</tbody></table>';
}).catch(function () {
  document.getElementById('sales').innerHTML =
    '<div class="empty">Could not load your sales.</div>';
});

function copyCode() {
  var t = document.getElementById('mycode').textContent.trim();
  if (!navigator.clipboard) return;
  navigator.clipboard.writeText(t).then(function () {
    var b = document.querySelector('.code .btn');
    b.textContent = 'Copied';
    setTimeout(function () { b.textContent = 'Copy'; }, 1500);
  }).catch(function () {});
}
</script>

</body>
</html>
