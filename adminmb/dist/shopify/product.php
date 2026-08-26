<?php
/* ═══════════════════════════════════════════════════════════════════════════
   One product — what it is, what it costs, where it sits, and editing it.

   Cost is per variant rather than per product, so it appears on each variant
   line as well as the summary. Two variants of the same shoe can be bought at
   different prices and often are.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/shell.php';

$id = preg_replace('/\D/', '', (string)($_GET['id'] ?? ''));
if ($id === '') { header('Location: index.php'); exit; }

$gql = <<<'GQL'
query Product($id: ID!) {
  product(id: $id) {
    id
    title
    handle
    status
    vendor
    productType
    tags
    description
    totalInventory
    options { name values }
    metafields(first: 20, namespace: "trendsi") {
      edges { node { key value type } }
    }
    featuredImage { url altText }
    collections(first: 10) { edges { node { id title } } }
    priceRangeV2 {
      minVariantPrice { amount currencyCode }
      maxVariantPrice { amount currencyCode }
    }
    variants(first: 50) {
      edges { node {
        id
        title
        sku
        price
        image { url }
        inventoryQuantity
        metafields(first: 20, namespace: "trendsi") {
          edges { node { key value type } }
        }
        inventoryItem {
          id
          unitCost { amount currencyCode }
          inventoryLevels(first: 10) {
            edges { node {
              location { id name }
              quantities(names: ["available"]) { name quantity }
            } }
          }
        }
      } }
    }
  }
}
GQL;

$res = shop_query($gql, ['id' => 'gid://shopify/Product/' . $id]);
$p = $res['ok'] ? ($res['data']['product'] ?? null) : null;

/* Read before the variants, because a variant with no shipping cost of its
   own inherits the product's. Setting it once on the product is the common
   case; a variant only needs its own when it genuinely ships differently. */
$pmf = [];
foreach ($p['metafields']['edges'] ?? [] as $me) {
    $pmf[$me['node']['key']] = $me['node']['value'];
}
$productShip = isset($pmf['dropship_cost']) && $pmf['dropship_cost'] !== ''
             ? (float)$pmf['dropship_cost'] : null;

$variants = [];
$costMin = null; $costMax = null;
$stockValue = 0.0;
$landedMin = null; $landedMax = null;
$landedStockValue = 0.0;
$anyShip = false;

if ($p) {
    foreach ($p['variants']['edges'] ?? [] as $ed) {
        $v = $ed['node'];
        $cost = $v['inventoryItem']['unitCost']['amount'] ?? null;
        $qty  = $v['inventoryQuantity'] ?? null;

        if ($cost !== null) {
            $c = (float)$cost;
            if ($costMin === null || $c < $costMin) $costMin = $c;
            if ($costMax === null || $c > $costMax) $costMax = $c;
            if ($qty > 0) $stockValue += $c * (int)$qty;
        }

        $where = [];
        foreach ($v['inventoryItem']['inventoryLevels']['edges'] ?? [] as $le) {
            $n = $le['node'];
            $q = null;
            foreach ($n['quantities'] ?? [] as $qq) {
                if (($qq['name'] ?? '') === 'available') $q = (int)$qq['quantity'];
            }
            $where[] = ['name' => $n['location']['name'] ?? '', 'qty' => $q];
        }

        $vmf = [];
        foreach ($v['metafields']['edges'] ?? [] as $me) {
            $vmf[$me['node']['key']] = $me['node']['value'];
        }

        /* What it truly costs to put this in a customer's hands: the item
           plus what Trendsi charges to ship it. Kept alongside the two parts
           rather than replacing them, so a wrong figure can be traced to
           whichever half is wrong. */
        $ownShip = isset($vmf['dropship_cost']) && $vmf['dropship_cost'] !== ''
                 ? (float)$vmf['dropship_cost'] : null;
        $ship = $ownShip ?? $productShip;
        $shipInherited = ($ownShip === null && $productShip !== null);
        $landed = ($cost !== null || $ship !== null)
                ? (float)($cost ?? 0) + (float)($ship ?? 0) : null;

        if ($ship !== null) $anyShip = true;
        if ($landed !== null) {
            if ($landedMin === null || $landed < $landedMin) $landedMin = $landed;
            if ($landedMax === null || $landed > $landedMax) $landedMax = $landed;
            if ($qty > 0) $landedStockValue += $landed * (int)$qty;
        }

        $variants[] = [
            'ship'   => $ship,
            'ship_inherited' => $shipInherited,
            'landed' => $landed,
            'mf'    => $vmf,
            'id'    => gid_num($v['id'] ?? ''),
            'title' => $v['title'] ?? '',
            'sku'   => $v['sku'] ?? '',
            'price' => $v['price'] ?? null,
            'cost'  => $cost,
            'image' => $v['image']['url'] ?? '',
            'qty'   => $qty,
            'where' => $where,
        ];
    }
}

$collections = [];
foreach ($p['collections']['edges'] ?? [] as $ce) {
    $collections[] = $ce['node']['title'] ?? '';
}

/* Size and colour are product options in Shopify, whatever they happen to be
   called on a given product — matching loosely so "Colour" and "Shoe Size"
   land in the right place. */
$optRows = [];
foreach ($p['options'] ?? [] as $o) {
    $name = trim((string)($o['name'] ?? ''));
    $vals = array_filter((array)($o['values'] ?? []));
    if ($name === '' || !$vals) continue;
    if (strcasecmp($name, 'Title') === 0) continue;      /* the placeholder option */
    $optRows[$name] = $vals;
}

/* price varies or it does not — worth saying which */
$priceVaries = false;
$prices = array_filter(array_map(fn($v) => $v['price'], $variants), fn($x) => $x !== null);
if ($prices && (min($prices) !== max($prices))) $priceVaries = true;
$costVaries = ($costMin !== null && $costMax !== null && $costMin !== $costMax);

$cur = $p['priceRangeV2']['minVariantPrice']['currencyCode'] ?? 'USD';

page_open($p['title'] ?? 'Product', 'product');
?>

<header class="top">
  <div>
    <a class="back" href="javascript:history.back()">&lsaquo; Back</a>
    <h1><?= e($p['title'] ?? 'Product') ?></h1>
  </div>
  <?php if (can_write() && $p): ?>
    <button class="act" type="button" onclick="mfSetup()">Set up fields</button>
    <button class="act" type="button" onclick="editProduct()">Edit</button>
  <?php elseif (!can_write()): ?>
    <span class="readonly">read only</span>
  <?php endif; ?>
</header>

<div class="wrap" style="padding-top:14px">
<?php if (!$res['ok']): problem_block($res);
      elseif (!$p): ?>
  <div class="empty">No such product.</div>
<?php else: ?>

  <div class="phead">
    <?php if (!empty($p['featuredImage']['url'])): ?>
      <img src="<?= e($p['featuredImage']['url']) ?>" alt="<?= e($p['featuredImage']['altText'] ?? '') ?>">
    <?php endif; ?>
    <div class="pmeta">
      <div class="ptitle"><?= e($p['title']) ?></div>
      <div class="pprice">
        <?php
          $min = $p['priceRangeV2']['minVariantPrice']['amount'] ?? null;
          $max = $p['priceRangeV2']['maxVariantPrice']['amount'] ?? null;
          echo e($min !== null && $max !== null && $min !== $max
                 ? money($min, $cur) . ' – ' . money($max, $cur)
                 : money($min, $cur));
        ?>
      </div>
      <div class="ppills">
        <?php $tot = (int)($p['totalInventory'] ?? 0); ?>
        <span class="pill <?= ($p['status'] ?? '') === 'ACTIVE' ? 'ok' : '' ?>">
          <?= e(strtolower((string)($p['status'] ?? ''))) ?>
        </span>
        <span class="pill <?= $tot > 0 ? 'ok' : 'warn' ?>">
          <?= $tot > 0 ? $tot . ' in stock' : 'out of stock' ?>
        </span>
      </div>
    </div>
  </div>

  <div class="grp">
    <h3>Details</h3>
    <div class="dgrid">
      <dl>
        <div class="f"><dt>Vendor</dt>
          <dd<?= empty($p['vendor']) ? ' class="none"' : '' ?>><?= e($p['vendor'] ?: 'none') ?></dd></div>
        <div class="f"><dt>Type</dt>
          <dd<?= empty($p['productType']) ? ' class="none"' : '' ?>><?= e($p['productType'] ?: 'none') ?></dd></div>
        <div class="f"><dt>Tags</dt>
          <dd<?= empty($p['tags']) ? ' class="none"' : '' ?>><?= $p['tags'] ? e(implode(', ', $p['tags'])) : 'none' ?></dd></div>
      </dl>
      <dl>
        <div class="f"><dt>Description</dt>
          <dd<?= empty($p['description']) ? ' class="none"' : '' ?>><?php
            /* mbstring is not on every server, so trim without it */
            $d = trim((string)($p['description'] ?? ''));
            if ($d === '') {
                echo 'none';
            } else {
                $short = (strlen($d) > 400) ? rtrim(substr($d, 0, 400)) . '…' : $d;
                echo nl2br(e($short));
            }
          ?></dd></div>
        <div class="f"><dt>Collection</dt>
          <dd<?= !$collections ? ' class="none"' : '' ?>><?= $collections ? e(implode(', ', $collections)) : 'none' ?></dd></div>
        <div class="f"><dt>Cost</dt>
          <dd<?= $costMin === null ? ' class="none"' : '' ?>><?php
            if ($costMin === null) { echo 'not set'; }
            else {
              echo e($costMin === $costMax ? money($costMin, $cur)
                                           : money($costMin, $cur) . ' – ' . money($costMax, $cur));
              if ($costVaries) echo '<span class="sub2">varies by variant</span>';
              if ($stockValue > 0) {
                echo '<span class="sub2">' . e(money($stockValue, $cur)) . ' of stock at cost</span>';
              }
            }
          ?></dd></div>

        <?php foreach ($optRows as $name => $vals): ?>
          <div class="f"><dt><?= e($name) ?></dt>
            <dd><?= e(implode(', ', $vals)) ?>
              <span class="sub2"><?= count($vals) ?> option<?= count($vals) === 1 ? '' : 's' ?></span>
            </dd></div>
        <?php endforeach; ?>

        <div class="f"><dt>Dropship cost</dt>
          <dd<?= empty($pmf['dropship_cost']) ? ' class="none"' : '' ?>><?php
            echo !empty($pmf['dropship_cost'])
                 ? e(money($pmf['dropship_cost'], $cur))
                 : 'not set';
          ?><span class="sub2">what Trendsi charges to ship</span></dd></div>

        <?php if ($landedMin !== null && $anyShip): ?>
          <div class="f"><dt>Total cost</dt>
            <dd><b class="landed"><?php
              echo e($landedMin === $landedMax ? money($landedMin, $cur)
                     : money($landedMin, $cur) . ' – ' . money($landedMax, $cur));
            ?></b>
            <span class="sub2">item plus shipping<?php
              if ($landedStockValue > 0) {
                echo ' · ' . e(money($landedStockValue, $cur)) . ' of stock landed';
              }
            ?></span></dd></div>
        <?php endif; ?>

        <div class="f"><dt>MPN</dt>
          <dd<?= empty($pmf['mpn']) ? ' class="none"' : '' ?>><?php
            echo !empty($pmf['mpn']) ? e($pmf['mpn']) : 'not set';
          ?></dd></div>

        <?php if ($priceVaries): ?>
          <div class="f"><dt>Price</dt>
            <dd><?= e(money(min($prices), $cur) . ' – ' . money(max($prices), $cur)) ?>
              <span class="sub2">varies by variant</span></dd></div>
        <?php endif; ?>
      </dl>
    </div>
  </div>

  <div class="grp">
    <h3><?= count($variants) ?> variant<?= count($variants) === 1 ? '' : 's' ?></h3>
    <div class="inv">
      <?php foreach ($variants as $v): ?>
        <div class="inv-row vrow">
          <?php if ($v['image']): ?>
            <img src="<?= e($v['image']) ?>" alt="" loading="lazy">
          <?php elseif (!empty($p['featuredImage']['url'])): ?>
            <img src="<?= e($p['featuredImage']['url']) ?>" alt="" loading="lazy">
          <?php else: ?>
            <div class="noimg">no img</div>
          <?php endif; ?>

          <div class="m">
            <b><?= e($v['title'] !== '' && $v['title'] !== 'Default Title' ? $v['title'] : 'Single variant') ?></b>
            <span class="sku<?= $v['sku'] === '' ? ' plain' : '' ?>">
              <?= $v['sku'] !== '' ? e($v['sku']) : 'no sku' ?>
            </span>
            <?php if ($v['cost'] !== null): ?>
              <span class="cost">cost <?= e(money($v['cost'], $cur)) ?><?php
                if ($v['price'] !== null) {
                  /* against the landed cost where shipping is known, because
                     that is what the sale actually leaves behind */
                  $base = $v['landed'] ?? (float)$v['cost'];
                  $m = (float)$v['price'] - $base;
                  echo '</span><span class="profit' . ($m < 0 ? ' loss' : '') . '">'
                     . ($m < 0 ? 'loses ' : 'profit ') . e(money(abs($m), $cur))
                     . ($v['ship'] !== null ? ' after shipping' : '');
                }
              ?></span>
            <?php endif; ?>

            <?php if ($v['ship'] !== null || !empty($v['mf']['mpn'])): ?>
              <div class="vmf">
                <?php if ($v['ship'] !== null): ?>
                  <span>ship <?= e(money($v['ship'], $cur)) ?><?php
                    if ($v['ship_inherited']) echo '<span class="inh"> from product</span>';
                  ?></span>
                  <?php if ($v['landed'] !== null): ?>
                    <span class="landed">total <?= e(money($v['landed'], $cur)) ?></span>
                  <?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($v['mf']['mpn'])): ?>
                  <span class="mpn">MPN <?= e($v['mf']['mpn']) ?></span>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <?php if ($v['where']): ?>
              <div class="locs">
                <?php foreach ($v['where'] as $w): ?>
                  <span class="loc">
                    <?= e($w['name']) ?> —
                    <span class="<?= $w['qty'] === null ? '' : ((int)$w['qty'] > 0 ? 'in' : 'out') ?>">
                      <?= $w['qty'] === null ? '—' : (int)$w['qty'] ?>
                    </span>
                  </span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="r">
            <div class="pr"><?= e(money($v['price'], $cur)) ?></div>
            <span class="qty<?= $v['qty'] === null ? '' : ((int)$v['qty'] > 0 ? ' in' : ' out') ?>">
              <?= $v['qty'] === null ? '—' : (int)$v['qty'] . ' total' ?>
            </span>
            <?php if (can_write()): ?>
              <button class="act sm vedit" type="button"
                      data-v="<?= e($v['id']) ?>"
                      data-sku="<?= e($v['sku']) ?>"
                      data-price="<?= e($v['price']) ?>"
                      data-cost="<?= e($v['cost'] ?? '') ?>"
                      data-ship="<?= e($v['mf']['dropship_cost'] ?? '') ?>"
                      data-mpn="<?= e($v['mf']['mpn'] ?? '') ?>"
                      data-name="<?= e($v['title']) ?>">Edit</button>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <a class="more" href="https://<?= e(SHOP_DOMAIN) ?>/admin/products/<?= e($id) ?>"
     target="_blank" rel="noopener">Open in Shopify admin</a>

<?php endif; ?>
</div>

<?php if (can_write() && $p): ?>
<div class="sheet" id="sheet" aria-hidden="true">
  <div class="sheet-box">
    <div class="sheet-head">
      <div><h2 id="shName">Edit</h2><div class="sub" id="shSub"></div></div>
      <button class="x" type="button" onclick="closeSheet()" aria-label="Close">&times;</button>
    </div>
    <div class="sheet-body" id="shBody"></div>
  </div>
</div>

<script>
var PROD = <?= json_encode([
    'id'          => $id,
    'title'       => $p['title'] ?? '',
    'status'      => $p['status'] ?? '',
    'description' => $p['description'] ?? '',
    'ship'        => $pmf['dropship_cost'] ?? '',
    'mpn'         => $pmf['mpn'] ?? '',
    /* offered as the starting value only when every variant already agrees —
       showing one variant's cost where they differ would invite overwriting
       the others by accident */
    'cost'        => ($costMin !== null && $costMin === $costMax)
                     ? number_format($costMin, 2, '.', '') : '',
    'variantCount' => count($variants),
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){
  return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function fval(id){var e=document.getElementById(id);return e?e.value.trim():'';}
function fmsg(t,c){var m=document.getElementById('fMsg');if(m){m.textContent=t;m.className='form-msg '+(c||'');}}

var sheet=document.getElementById('sheet');
function openSheet(title, sub, html){
  document.getElementById('shName').textContent=title;
  document.getElementById('shSub').textContent=sub||'';
  document.getElementById('shBody').innerHTML=html;
  sheet.classList.add('on'); sheet.setAttribute('aria-hidden','false');
  document.body.style.overflow='hidden';
}
function closeSheet(){
  sheet.classList.remove('on'); sheet.setAttribute('aria-hidden','true');
  document.body.style.overflow='';
}
function post(action, body){
  return fetch('write.php?action='+action,{method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'},body:JSON.stringify(body)})
    .then(function(r){return r.json();});
}

/* Creates the two metafield definitions on products and variants. Run once;
   running it again is harmless and says so. */
window.mfSetup=function(){
  if(!confirm('Create the Trendsi dropshipping cost and MPN fields?\n\n'+
     'They will appear on every product and variant, here and in Shopify admin. '+
     'Existing values are untouched.')) return;
  openSheet('Setting up fields','','<div class="form-msg" id="fMsg">Creating…</div>');
  post('metafield_setup',{}).then(function(d){
    if(!d.ok){ fmsg(d.error,'bad'); return; }
    var parts=[];
    if(d.created.length) parts.push(d.created.length+' created');
    if(d.existed.length) parts.push(d.existed.length+' already there');
    if(d.failed.length)  parts.push(d.failed.length+' failed');
    fmsg(parts.join(', ')+'. '+(d.note||''), d.failed.length?'bad':'ok');
    setTimeout(function(){ location.reload(); }, 2200);
  }).catch(function(e){ fmsg(e.message,'bad'); });
};

window.editProduct=function(){
  openSheet('Edit product','',
    '<label class="fl">Title<input id="eTitle" value="'+esc(PROD.title)+'"></label>'+
    '<label class="fl">Description<textarea id="eDesc" rows="5">'+esc(PROD.description)+'</textarea></label>'+
    '<label class="fl">Status<select id="eStatus">'+
      ['ACTIVE','DRAFT','ARCHIVED'].map(function(s){
        return '<option value="'+s+'"'+(PROD.status===s?' selected':'')+'>'+s.toLowerCase()+'</option>';
      }).join('')+'</select></label>'+
    '<div class="mfhead">Costs — applied to all '+PROD.variantCount+' variants</div>'+
    '<p class="fp" style="margin:-4px 0 12px">Filling these in sets every variant at once. '+
    'Anything set differently on an individual variant is overwritten, so leave a field '+
    'blank to leave it alone.</p>'+
    '<label class="fl">Cost each<input id="eCost" type="number" step="0.01" value="'+
      esc(PROD.cost)+'" placeholder="leave blank to keep per-variant costs"></label>'+
    '<label class="fl">Dropshipping cost<input id="eShip" type="number" step="0.01" value="'+
      esc(PROD.ship)+'" placeholder="what Trendsi charges to ship"></label>'+
    '<label class="fl">MPN<input id="eMpn" value="'+esc(PROD.mpn)+
      '" placeholder="manufacturer part number"></label>'+
    '<div class="form-msg" id="fMsg"></div>'+
    '<div class="form-b">'+
      '<button class="act ghost" onclick="closeSheet()">Cancel</button>'+
      '<button class="act go" id="fGo">Save</button>'+
    '</div>');
  document.getElementById('fGo').onclick=function(){
    var b=this; b.disabled=true; fmsg('Saving…');
    /* two calls: the product's own fields, then its metafields. Both are
       reported, so a half-save says so rather than looking finished. */
    var calls=[
      post('product',{id:PROD.id, title:fval('eTitle'),
        description:document.getElementById('eDesc').value, status:fval('eStatus')}),
      post('metafield',{id:PROD.id, kind:'product', mpn:fval('eMpn')})
    ];

    /* cost and shipping go through the bulk route, which reaches every variant
       and clears any per-variant override so "all" means all */
    var cost=fval('eCost'), ship=fval('eShip');
    if(cost!=='' || ship!==PROD.ship){
      calls.push(post('cost_all',{id:PROD.id, cost:cost, dropship_cost:ship}));
    }

    Promise.all(calls).then(function(rs){
      b.disabled=false;
      var bad=rs.filter(function(r){ return !r.ok; }).map(function(r){ return r.error; });
      if(bad.length===rs.length){ fmsg(bad.join(' · '),'bad'); return; }
      fmsg(bad.length ? 'Saved, but: '+bad.join(' · ') : 'Saved.', bad.length?'bad':'ok');
      setTimeout(function(){ location.reload(); }, bad.length?1800:900);
    }).catch(function(e){ b.disabled=false; fmsg(e.message,'bad'); });
  };
};

document.addEventListener('click',function(ev){
  var b=ev.target.closest('.vedit');
  if(!b) { if(ev.target.id==='sheet') closeSheet(); return; }
  var vid=b.getAttribute('data-v');
  openSheet('Edit variant', b.getAttribute('data-name')||'',
    '<label class="fl">SKU<input id="vSku" value="'+esc(b.getAttribute('data-sku'))+'"></label>'+
    '<label class="fl">Price<input id="vPrice" type="number" step="0.01" value="'+
      esc(b.getAttribute('data-price'))+'"></label>'+
    '<label class="fl">Cost<input id="vCost" type="number" step="0.01" value="'+
      esc(b.getAttribute('data-cost'))+'" placeholder="what you pay for it"></label>'+
    '<div class="mfhead">Trendsi</div>'+
    '<label class="fl">Dropshipping cost<input id="vShip" type="number" step="0.01" value="'+
      esc(b.getAttribute('data-ship'))+'" placeholder="blank is fine"></label>'+
    '<label class="fl">MPN<input id="vMpn" value="'+esc(b.getAttribute('data-mpn'))+
      '" placeholder="blank is fine"></label>'+
    '<div class="form-msg" id="fMsg"></div>'+
    '<div class="form-b">'+
      '<button class="act ghost" onclick="closeSheet()">Cancel</button>'+
      '<button class="act go" id="fGo">Save</button>'+
    '</div>');
  document.getElementById('fGo').onclick=function(){
    var btn=this; btn.disabled=true; fmsg('Saving…');
    Promise.all([
      post('product',{id:PROD.id, variant_id:vid, sku:fval('vSku'),
        price:fval('vPrice'), cost:fval('vCost')}),
      post('metafield',{id:vid, kind:'variant',
        dropship_cost:fval('vShip'), mpn:fval('vMpn')})
    ]).then(function(rs){
      btn.disabled=false;
      var bad=rs.filter(function(r){ return !r.ok; }).map(function(r){ return r.error; });
      if(bad.length===rs.length){ fmsg(bad.join(' · '),'bad'); return; }
      fmsg(bad.length ? 'Saved, but: '+bad.join(' · ') : 'Saved.', bad.length?'bad':'ok');
      setTimeout(function(){ location.reload(); }, bad.length?1800:900);
    }).catch(function(e){ btn.disabled=false; fmsg(e.message,'bad'); });
  };
});
document.addEventListener('keydown',function(e){ if(e.key==='Escape') closeSheet(); });
</script>
<?php endif; ?>

<style>
.phead{display:flex;gap:14px;align-items:flex-start;margin-bottom:18px}
.phead img{width:96px;height:96px;object-fit:cover;border-radius:10px;flex:0 0 96px}
.pmeta{flex:1;min-width:0}
.ptitle{font-size:17px;font-weight:700;line-height:1.3}
.pprice{font-family:ui-monospace,monospace;color:var(--gold);font-size:15px;margin-top:5px}
.ppills{margin-top:7px;display:flex;gap:6px;flex-wrap:wrap}
.dgrid{display:grid;grid-template-columns:1fr;gap:0}
.sub2{display:block;font-size:11px;color:var(--mute);margin-top:2px}
.vrow{align-items:flex-start}
.vmf{margin-top:4px;display:flex;gap:9px;flex-wrap:wrap;font-size:11px;color:var(--txt2)}
.vmf .mpn{font-family:ui-monospace,monospace;color:var(--mute)}
.vmf .landed{color:var(--gold);font-weight:600}
.vmf .inh{color:var(--faint);font-size:10px}
.landed{color:var(--gold)}
.mfhead{font-size:10px;color:var(--gold);text-transform:uppercase;letter-spacing:.08em;
  font-weight:700;margin:16px 0 9px;padding-top:12px;border-top:1px solid var(--line)}
.locs{margin-top:5px;display:flex;flex-direction:column;gap:2px}
.loc{font-size:11.5px;color:var(--txt2)}
.inv-row .r .act.sm{margin-top:7px}
.fl textarea{display:block;width:100%;box-sizing:border-box;margin-top:5px;
  background:var(--panel2);border:1px solid var(--line2);border-radius:9px;
  padding:11px 13px;color:var(--txt);font-size:15px;outline:none;font-family:inherit;
  font-weight:400;text-transform:none;letter-spacing:0;resize:vertical;line-height:1.5}
.fl textarea:focus{border-color:var(--gold)}
@media(min-width:700px){
  .dgrid{grid-template-columns:1fr 1fr;gap:0 26px}
}
</style>

<?php page_close('product'); ?>
