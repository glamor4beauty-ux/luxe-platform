<?php
/* ═══════════════════════════════════════════════════════════════════════════
   costs.php — dropship costs, in one pass.

   Entering these one product at a time through the product page is slow, and
   what is slow does not get done — which is why forty-nine of fifty products
   show a profit that ignores shipping.

   So: every product on one page, a box against each, and one Save. Type down
   the column and be finished.

   Setting a cost writes it to the product and to every variant, because the
   catalogue reads the variant first and falls back to the product. Writing
   both means it is right whichever is read.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/authz.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/shell.php';

auth_start();
if (!auth_user()) { header('Location: login.php'); exit; }

$msg = '';
$err = '';

/* ── saving ───────────────────────────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    $costs = (array)($_POST['cost'] ?? []);
    $saved = 0;
    $cleared = 0;
    $failed = [];

    foreach ($costs as $pid => $raw) {
        $pid = preg_replace('/[^0-9]/', '', (string)$pid);
        if ($pid === '') continue;

        $was = trim((string)($_POST['was'][$pid] ?? ''));
        $now = trim((string)$raw);

        /* only what changed, so a page of forty products is not forty
           needless writes to Shopify */
        if ($now === $was) continue;

        $gid = 'gid://shopify/Product/' . $pid;

        /* which variants to write to */
        $v = shop_query('query($id: ID!) { product(id: $id) {
                variants(first: 100) { edges { node { id } } } } }',
            ['id' => $gid]);

        $vids = [];
        foreach ($v['data']['product']['variants']['edges'] ?? [] as $e) {
            $vids[] = $e['node']['id'];
        }

        if ($now === '') {
            /* clearing: the metafield is removed rather than set to zero,
               because zero is a claim that shipping is free and empty is a
               claim that nobody has said */
            $ids = [];
            $q = shop_query('query($id: ID!) { product(id: $id) {
                    metafield(namespace:"trendsi", key:"dropship_cost") { id }
                    variants(first:100){edges{node{
                      metafield(namespace:"trendsi", key:"dropship_cost"){id} }}} } }',
                ['id' => $gid]);

            $pm = $q['data']['product']['metafield']['id'] ?? null;
            if ($pm) $ids[] = ['id' => $pm];
            foreach ($q['data']['product']['variants']['edges'] ?? [] as $e) {
                $mid = $e['node']['metafield']['id'] ?? null;
                if ($mid) $ids[] = ['id' => $mid];
            }

            if ($ids) {
                $r = shop_query('mutation($m: [MetafieldIdentifierInput!]!) {
                        metafieldsDelete(metafields: $m) {
                          deletedMetafields { key }
                          userErrors { message }
                        } }', ['m' => array_map(fn($x) => $x, $ids)]);
                /* deletion by id is fussy across API versions; if it refuses,
                   fall back to writing an empty value rather than leaving a
                   stale figure in place */
                if (!empty($r['data']['metafieldsDelete']['userErrors'])) {
                    $now = '0';
                } else {
                    $cleared++;
                    continue;
                }
            } else {
                $cleared++;
                continue;
            }
        }

        if (!is_numeric($now)) {
            $failed[] = $pid . ' — "' . htmlspecialchars($now) . '" is not a number';
            continue;
        }

        $fields = [[
            'ownerId'   => $gid,
            'namespace' => 'trendsi',
            'key'       => 'dropship_cost',
            'type'      => 'number_decimal',
            'value'     => number_format((float)$now, 2, '.', ''),
        ]];

        foreach ($vids as $vid) {
            $fields[] = [
                'ownerId'   => $vid,
                'namespace' => 'trendsi',
                'key'       => 'dropship_cost',
                'type'      => 'number_decimal',
                'value'     => number_format((float)$now, 2, '.', ''),
            ];
        }

        $r = shop_query('mutation($m: [MetafieldsSetInput!]!) {
                metafieldsSet(metafields: $m) {
                  metafields { key }
                  userErrors { field message }
                } }', ['m' => $fields]);

        $ue = $r['data']['metafieldsSet']['userErrors'] ?? [];
        if ($ue) {
            $failed[] = $pid . ' — ' . ($ue[0]['message'] ?? 'refused');
        } else {
            $saved++;
        }
    }

    $bits = [];
    if ($saved)   $bits[] = $saved . ' set';
    if ($cleared) $bits[] = $cleared . ' cleared';
    $msg = $bits ? implode(', ', $bits) . '.' : 'Nothing changed.';
    if ($failed) $err = implode(' · ', array_slice($failed, 0, 4));

    /* Shopify's own cache would otherwise show the old figures back */
    if (function_exists('shop_cache_clear')) shop_cache_clear();
}

/* ── the list ─────────────────────────────────────────────────────────── */
$cursor = trim((string)($_GET['after'] ?? ''));
$only   = trim((string)($_GET['only'] ?? ''));

$r = shop_query('
    query($n: Int!, $after: String) {
      products(first: $n, after: $after, sortKey: TITLE) {
        edges { node {
          id title status
          featuredImage { url }
          metafield(namespace:"trendsi", key:"dropship_cost") { value }
          variants(first: 1) { edges { node {
            price
            inventoryItem { unitCost { amount currencyCode } }
          } } }
        } }
        pageInfo { hasNextPage endCursor }
      }
    }', ['n' => 40, 'after' => $cursor ?: null]);

$conn = $r['data']['products'] ?? [];
$rows = [];
$missing = 0;

foreach ($conn['edges'] ?? [] as $e) {
    $p = $e['node'];
    $v = $p['variants']['edges'][0]['node'] ?? null;

    $cost = $v['inventoryItem']['unitCost']['amount'] ?? null;
    $ship = $p['metafield']['value'] ?? '';
    if ($ship === '') $missing++;

    if ($only === 'missing' && $ship !== '') continue;

    $rows[] = [
        'id'    => preg_replace('/[^0-9]/', '', $p['id']),
        'title' => $p['title'],
        'img'   => $p['featuredImage']['url'] ?? '',
        'status'=> $p['status'],
        'cost'  => $cost,
        'price' => $v['price'] ?? null,
        'ship'  => $ship,
        'cur'   => $v['inventoryItem']['unitCost']['currencyCode'] ?? 'USD',
    ];
}

$hasMore = !empty($conn['pageInfo']['hasNextPage']);
$next    = $conn['pageInfo']['endCursor'] ?? '';

page_open('Dropship costs', 'store');
?>

<style>
.dc-head{display:flex;justify-content:space-between;align-items:center;gap:14px;
  margin-bottom:14px;flex-wrap:wrap}
.dc-head h1{font-size:19px;font-weight:700;color:var(--txt)}
.dc-head .sub{font-size:12px;color:var(--mute);margin-top:2px}
.dc-note{background:rgba(78,201,122,.09);border:1px solid rgba(78,201,122,.35);
  color:#a8e6bd;border-radius:9px;padding:11px 14px;font-size:13px;margin-bottom:14px}
.dc-err{background:rgba(242,104,94,.08);border:1px solid rgba(242,104,94,.35);
  color:#ffb3ac;border-radius:9px;padding:11px 14px;font-size:13px;margin-bottom:14px}
.dc-tip{font-size:12.5px;color:var(--mute);line-height:1.7;margin-bottom:16px;
  background:rgba(216,169,75,.05);border-left:2px solid rgba(216,169,75,.4);
  border-radius:0 9px 9px 0;padding:12px 14px}
.dc-tip b{color:var(--gold)}

.dc-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
.dc-btn{border:1px solid var(--line2);background:transparent;color:var(--txt2);
  border-radius:9px;padding:9px 15px;font-size:12.5px;font-weight:700;cursor:pointer;
  font-family:inherit;text-decoration:none;display:inline-block;white-space:nowrap}
.dc-btn:hover{border-color:var(--gold);color:var(--gold)}
.dc-btn.on{background:rgba(216,169,75,.12);border-color:var(--gold);color:var(--gold)}
.dc-btn.gold{background:var(--gold);border-color:var(--gold);color:#0f1115}
.dc-btn.gold:hover{background:#e8bf44}
.dc-fill{display:flex;gap:7px;align-items:center;margin-left:auto}
.dc-fill input{width:88px;background:var(--panel2);border:1px solid var(--line2);
  border-radius:8px;padding:8px 11px;color:var(--txt);font-size:13.5px;outline:none;
  font-family:ui-monospace,monospace;text-align:right}
.dc-fill input:focus{border-color:var(--gold)}

.dc-wrap{border:1px solid var(--line);border-radius:11px;overflow:hidden}
.dc-row{display:grid;grid-template-columns:46px 1fr 90px 90px 110px 100px;gap:12px;
  align-items:center;padding:11px 15px;border-bottom:1px solid rgba(36,40,50,.7)}
.dc-row:last-child{border-bottom:none}
.dc-row:hover{background:rgba(255,255,255,.02)}
.dc-row.head{background:var(--panel2);border-bottom:1px solid var(--line2);
  font-size:9.5px;color:var(--mute);text-transform:uppercase;letter-spacing:.06em;
  font-weight:700;padding:11px 15px}
.dc-row.head .r{text-align:right}
@media(max-width:760px){
  .dc-row{grid-template-columns:40px 1fr 96px;row-gap:6px}
  .dc-row .hide{display:none}
  .dc-row.head{grid-template-columns:40px 1fr 96px}
}

.dc-shot{width:46px;height:46px;border-radius:8px;object-fit:cover;background:var(--panel2);
  display:block}
.dc-noshot{width:46px;height:46px;border-radius:8px;background:var(--panel2)}
.dc-title{font-size:14px;color:var(--txt);font-weight:600;line-height:1.35}
.dc-draft{font-size:10px;color:var(--warn);font-weight:700;text-transform:uppercase;
  letter-spacing:.05em;margin-top:2px}
.dc-num{font-family:ui-monospace,Menlo,monospace;font-size:13px;color:var(--txt2);
  text-align:right}
.dc-num.dim{color:var(--faint)}
.dc-in{width:100%;background:var(--panel2);border:1px solid var(--line2);border-radius:8px;
  padding:9px 11px;color:var(--txt);font-size:14px;outline:none;
  font-family:ui-monospace,monospace;text-align:right}
.dc-in:focus{border-color:var(--gold);background:var(--bg)}
.dc-in.set{border-color:rgba(78,201,122,.45)}
.dc-margin{font-family:ui-monospace,Menlo,monospace;font-size:12.5px;text-align:right;
  color:var(--mute)}
.dc-margin.good{color:var(--ok)}
.dc-margin.thin{color:var(--warn)}
.dc-margin.loss{color:var(--bad)}

.dc-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;
  margin-top:16px;flex-wrap:wrap;position:sticky;bottom:0;background:var(--bg);
  padding:12px 0}
.dc-count{font-size:12.5px;color:var(--mute)}
.dc-empty{text-align:center;padding:44px;color:var(--faint);font-size:13.5px}
</style>

<div class="dc-head">
  <div>
    <h1>Dropship costs</h1>
    <div class="sub"><?= count($rows) ?> shown<?= $missing ? ' · ' . $missing . ' without a cost' : '' ?></div>
  </div>
</div>

<?php if ($msg): ?><div class="dc-note"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="dc-err"><?= e($err) ?></div><?php endif; ?>

<div class="dc-tip">
  What the supplier charges to ship one of these. It comes straight out of the sale,
  so <b>profit is wrong until it is filled in</b> — which is why the catalogue has been
  showing more than you make.<br>
  Type down the column and press Save once. Empty means <b>not known</b>, which is
  different from zero.
</div>

<form method="post" id="dcForm">

  <div class="dc-bar">
    <a class="dc-btn <?= $only === '' ? 'on' : '' ?>" href="costs.php">All</a>
    <a class="dc-btn <?= $only === 'missing' ? 'on' : '' ?>" href="costs.php?only=missing">Without a cost</a>

    <div class="dc-fill">
      <input id="dcAll" placeholder="0.00" inputmode="decimal">
      <button class="dc-btn" type="button" onclick="fillAll()">Fill the empty ones</button>
    </div>
  </div>

  <?php if (!$rows): ?>
    <div class="dc-empty">Nothing to show.</div>
  <?php else: ?>

  <div class="dc-wrap">
    <div class="dc-row head">
      <div></div>
      <div>Product</div>
      <div class="r hide">Cost</div>
      <div class="r hide">Price</div>
      <div class="r">Dropship</div>
      <div class="r hide">Margin</div>
    </div>

    <?php foreach ($rows as $r):
      $cost  = $r['cost'] !== null ? (float)$r['cost'] : null;
      $price = $r['price'] !== null ? (float)$r['price'] : null;
      $ship  = $r['ship'] !== '' ? (float)$r['ship'] : null;

      $margin = null;
      if ($cost !== null && $price !== null) {
          $margin = $price - $cost - (float)($ship ?? 0);
      }
      $cls = '';
      if ($margin !== null) {
          $cls = $margin < 0 ? 'loss' : ($price > 0 && $margin / $price < 0.25 ? 'thin' : 'good');
      }
    ?>
      <div class="dc-row">
        <div>
          <?php if ($r['img']): ?>
            <img class="dc-shot" src="<?= e($r['img']) ?>" alt="" loading="lazy">
          <?php else: ?><div class="dc-noshot"></div><?php endif; ?>
        </div>

        <div>
          <div class="dc-title"><?= e($r['title']) ?></div>
          <?php if ($r['status'] !== 'ACTIVE'): ?>
            <div class="dc-draft"><?= e(strtolower($r['status'])) ?></div>
          <?php endif; ?>
        </div>

        <div class="dc-num hide<?= $cost === null ? ' dim' : '' ?>">
          <?= $cost !== null ? e(money($cost, $r['cur'])) : '—' ?></div>

        <div class="dc-num hide<?= $price === null ? ' dim' : '' ?>">
          <?= $price !== null ? e(money($price, $r['cur'])) : '—' ?></div>

        <div>
          <input type="hidden" name="was[<?= e($r['id']) ?>]" value="<?= e($r['ship']) ?>">
          <input class="dc-in<?= $r['ship'] !== '' ? ' set' : '' ?>"
                 name="cost[<?= e($r['id']) ?>]"
                 value="<?= e($r['ship']) ?>"
                 placeholder="—" inputmode="decimal" autocomplete="off">
        </div>

        <div class="dc-margin hide <?= $cls ?>">
          <?= $margin !== null ? e(money($margin, $r['cur'])) : '—' ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="dc-foot">
    <div class="dc-count">
      <?php if ($cursor): ?><a class="dc-btn" href="costs.php<?= $only ? '?only=' . e($only) : '' ?>">&larr; Back to the start</a><?php endif; ?>
      <?php if ($hasMore): ?>
        <a class="dc-btn" href="costs.php?after=<?= urlencode($next) ?><?= $only ? '&only=' . e($only) : '' ?>">Next 40 &rarr;</a>
      <?php endif; ?>
    </div>
    <button class="dc-btn gold" type="submit">Save this page</button>
  </div>

  <?php endif; ?>
</form>

<script>
/* Filling only the empty boxes, never overwriting a figure somebody has
   already checked and entered. */
function fillAll() {
  var v = document.getElementById('dcAll').value.trim();
  if (!v) { document.getElementById('dcAll').focus(); return; }
  var n = 0;
  document.querySelectorAll('.dc-in').forEach(function (i) {
    if (i.value.trim() === '') { i.value = v; i.classList.add('set'); n++; }
  });
  if (!n) alert('Every box on this page already has a figure.');
}

/* Moving on with Enter, so a column can be typed without reaching for the
   mouse. */
document.querySelectorAll('.dc-in').forEach(function (input, i, all) {
  input.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    if (all[i + 1]) { all[i + 1].focus(); all[i + 1].select(); }
    else document.getElementById('dcForm').submit();
  });
});

/* Leaving with unsaved figures loses them, so say so. */
var dirty = false;
document.querySelectorAll('.dc-in').forEach(function (i) {
  i.addEventListener('input', function () { dirty = true; });
});
document.getElementById('dcForm').addEventListener('submit', function () { dirty = false; });
window.addEventListener('beforeunload', function (e) {
  if (dirty) { e.preventDefault(); e.returnValue = ''; }
});
</script>

<?php page_close(); ?>
