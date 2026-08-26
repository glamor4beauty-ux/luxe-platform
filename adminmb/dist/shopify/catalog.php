<?php
/* ═══════════════════════════════════════════════════════════════════════════
   One vendor's catalogue — product name, price, SKU.

   Trendsi supplies through a fulfilment service rather than a Shopify
   location, so there is no stock location to list against. The vendor on each
   product is what actually separates the two catalogues.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/shell.php';

$kind  = preg_replace('/[^a-z]/', '', strtolower((string)($_GET['v'] ?? 'trendsi')));
if ($kind !== 'trendsi') $kind = 'shop';
$after = $_GET['after'] ?? null;
$q     = trim((string)($_GET['q'] ?? ''));

/* which vendor this tab means */
$vendor = null;
$label  = ucfirst($kind);
foreach (shop_tabs() as $t) {
    if ($t['kind'] === $kind) { $vendor = $t['vendor']; $label = $t['label']; break; }
}

$res  = ['ok' => false, 'reason' => 'no_vendor',
         'message' => 'No products found for this vendor yet.'];
$rows = [];
$more = false;
$next = null;

if ($vendor !== null) {
    /* Shopify's product query language: vendor:"X", plus whatever was typed */
    $filter = 'vendor:"' . str_replace('"', '', $vendor) . '"';
    if ($q !== '') $filter .= ' AND (title:*' . str_replace('"', '', $q) . '* OR sku:*'
                            . str_replace('"', '', $q) . '*)';

    $gql = <<<'GQL'
    query VendorProducts($n: Int!, $q: String!, $after: String) {
      products(first: $n, query: $q, after: $after, sortKey: TITLE) {
        edges { node {
          id
          title
          status
          totalInventory
          featuredImage { url }
          metafields(first: 5, namespace: "trendsi") {
            edges { node { key value } }
          }
          priceRangeV2 {
            minVariantPrice { amount currencyCode }
            maxVariantPrice { amount currencyCode }
          }
          variants(first: 25) { edges { node {
            sku
            price
            inventoryQuantity
            inventoryItem { unitCost { amount currencyCode } }
            metafields(first: 5, namespace: "trendsi") {
              edges { node { key value } }
            }
          } } }
        } }
        pageInfo { hasNextPage endCursor }
      }
    }
    GQL;

    $res = shop_query($gql, ['n' => 50, 'q' => $filter, 'after' => $after]);

    if ($res['ok']) {
        foreach ($res['data']['products']['edges'] ?? [] as $ed) {
            $p = $ed['node'];
            $min = $p['priceRangeV2']['minVariantPrice']['amount'] ?? null;
            $max = $p['priceRangeV2']['maxVariantPrice']['amount'] ?? null;
            $cur = $p['priceRangeV2']['minVariantPrice']['currencyCode'] ?? 'USD';

            /* Cost is per variant, so the value of what is on the shelf is
               unit cost times quantity, summed. Taking the first variant's
               cost and multiplying by the total would be wrong wherever
               variants differ in price. */
            $unit   = null;      /* cost of one */
            $shipOne = null;     /* what Trendsi charges to ship one */
            $landedOne = null;   /* the two together */
            $margin = null;      /* profit on one, after shipping */
            $value  = 0.0;       /* what the stock cost, landed */
            $profit = 0.0;       /* what it would make if it all sold */
            $known  = true;
            $anyShip = false;

            /* The dropship cost may be set on the product as a default and
               overridden per variant. Reading only the variant — as this page
               did — makes a product-level cost invisible, so the profit shown
               ignored money that genuinely leaves the business. */
            $prodShip = null;
            foreach ($p['metafields']['edges'] ?? [] as $me) {
                if (($me['node']['key'] ?? '') === 'dropship_cost'
                    && ($me['node']['value'] ?? '') !== '') {
                    $prodShip = (float)$me['node']['value'];
                }
            }

            foreach ($p['variants']['edges'] ?? [] as $ve) {
                $v = $ve['node'];
                $c = $v['inventoryItem']['unitCost']['amount'] ?? null;
                $pr = $v['price'] ?? null;
                $q = (int)($v['inventoryQuantity'] ?? 0);

                /* shipping is a metafield, set per variant */
                $sh = null;
                foreach ($v['metafields']['edges'] ?? [] as $me) {
                    if (($me['node']['key'] ?? '') === 'dropship_cost'
                        && ($me['node']['value'] ?? '') !== '') {
                        $sh = (float)$me['node']['value'];
                    }
                }
                /* the variant wins where it has one; otherwise the product's */
                if ($sh === null) $sh = $prodShip;
                if ($sh !== null) $anyShip = true;

                if ($c === null) { $known = false; continue; }

                /* Landed cost is what actually leaves the business, so it is
                   what both the stock value and the margin are measured
                   against. Shipping charged by the supplier is not overhead —
                   it comes straight out of the sale. */
                $landed = (float)$c + (float)($sh ?? 0);

                if ($unit === null)      $unit = (float)$c;
                if ($shipOne === null)   $shipOne = $sh;
                if ($landedOne === null) $landedOne = $landed;
                if ($margin === null && $pr !== null) $margin = (float)$pr - $landed;

                if ($q > 0) {
                    $value += $landed * $q;
                    if ($pr !== null) $profit += ((float)$pr - $landed) * $q;
                }
            }

            $rows[] = [
                'id'    => gid_num($p['id'] ?? ''),
                'title' => $p['title'] ?? '',
                'sku'   => $p['variants']['edges'][0]['node']['sku'] ?? '',
                'price' => ($min !== null && $max !== null && $min !== $max)
                         ? money($min, $cur) . ' – ' . money($max, $cur)
                         : money($min, $cur),
                'image' => $p['featuredImage']['url'] ?? '',
                'stock' => $p['totalInventory'] ?? null,
                'live'  => ($p['status'] ?? '') === 'ACTIVE',
                'cost'   => $unit,
                'ship'   => $shipOne,
                'landed' => $landedOne,
                'any_ship' => $anyShip,
                'margin' => $margin,
                'value'  => $value,
                'profit' => $profit,
                'cost_known' => $known,
                'cur'    => $cur,
            ];
        }
        $more = !empty($res['data']['products']['pageInfo']['hasNextPage']);
        $next = $res['data']['products']['pageInfo']['endCursor'] ?? null;

        $totalValue  = 0.0;
        $totalProfit = 0.0;
        $anyUnknown  = false;
        $anyLoss     = false;
        $anyShipping = false;
        $noShipSet   = 0;
        foreach ($rows as $r) {
            $totalValue  += $r['value'];
            $totalProfit += $r['profit'];
            if (!$r['cost_known']) $anyUnknown = true;
            if ($r['margin'] !== null && $r['margin'] < 0) $anyLoss = true;
            if (!empty($r['any_ship'])) $anyShipping = true;
            elseif ($r['cost'] !== null) $noShipSet++;
        }
    }
}

page_open($label, 'catalog');
?>

<header class="top">
  <div>
    <div class="app">Catalogue<?= page_gear() ?><?php if (!can_write()): ?><span class="readonly">read only</span><?php endif; ?></div>
    <h1><?= e($label) ?></h1>
  </div>
  <div class="right"><?= count($rows) ?><?= $more ? '+' : '' ?> product<?= count($rows) === 1 ? '' : 's' ?></div>
</header>

<form class="bar" method="get" action="">
  <input type="hidden" name="v" value="<?= e($kind) ?>">
  <input type="search" name="q" value="<?= e($q) ?>"
         placeholder="Product name or SKU" autocomplete="off">
  <button type="submit">Find</button>
  <?php if ($q !== ''): ?><a class="clear" href="?v=<?= e($kind) ?>">Clear</a><?php endif; ?>
</form>

<div class="wrap">
<?php if (!$res['ok'] && ($res['reason'] ?? '') !== 'no_vendor'): problem_block($res);
      elseif ($vendor === null): ?>
  <div class="empty">
    No products from this supplier yet.<br>
    <span style="font-size:12.5px">Products are matched on their vendor field.</span>
  </div>
<?php elseif (!$rows): ?>
  <div class="empty"><?= $q !== '' ? 'Nothing matches that.' : 'No products yet.' ?></div>
<?php else: ?>

  <div class="inv">
    <?php foreach ($rows as $r): ?>
      <div class="inv-row">
        <?php if ($r['image']): ?>
          <img src="<?= e($r['image']) ?>" alt="" loading="lazy">
        <?php else: ?>
          <div class="noimg">no img</div>
        <?php endif; ?>

        <div class="m">
          <a class="pname" href="product.php?id=<?= e($r['id']) ?>"><?= e($r['title']) ?></a>
          <span class="sku<?= $r['sku'] === '' ? ' plain' : '' ?>">
            <?= $r['sku'] !== '' ? e($r['sku']) : 'no sku' ?>
            <?php if ($r['cost'] !== null): ?>
              <span class="cost">cost <?= e(money($r['cost'], $r['cur'])) ?></span>
              <?php if ($r['ship'] !== null): ?>
                <span class="cost">+ ship <?= e(money($r['ship'], $r['cur'])) ?></span>
                <span class="cost landed">= <?= e(money($r['landed'], $r['cur'])) ?></span>
              <?php endif; ?>
              <?php if ($r['margin'] !== null): ?>
                <span class="profit <?= $r['margin'] < 0 ? 'loss' : '' ?>">
                  <?= $r['margin'] < 0 ? 'loses ' : 'profit ' ?><?= e(money(abs($r['margin']), $r['cur'])) ?>
                </span>
              <?php endif; ?>
            <?php else: ?>
              <span class="cost none">no cost set</span>
            <?php endif; ?>
          </span>
        </div>

        <div class="r">
          <div class="pr"><?= e($r['price']) ?></div>
          <?php
            /* Out of stock is the thing worth spotting at a glance, so it is
               the colour that carries — green means there is something to
               sell, orange means there is not. */
            $q = $r['stock'];
            $cls = !$r['live'] ? 'qty' : ($q === null ? 'qty' : ($q > 0 ? 'qty in' : 'qty out'));
          ?>
          <span class="<?= $cls ?>">
            <?php if (!$r['live']): ?>draft<?php
                  elseif ($q === null): ?>—<?php
                  elseif ($q > 0): ?><?= (int)$q ?> in stock<?php
                  else: ?>out of stock<?php endif; ?>
          </span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="totals">
    <div class="totals-row">
      <span>Stock at <?= $anyShipping ? 'landed cost' : 'cost' ?><?= $more ? ' (this page)' : '' ?></span>
      <b><?= e(money($totalValue, $rows[0]['cur'] ?? 'USD')) ?></b>
    </div>
    <div class="totals-row">
      <span>Profit if it all sells</span>
      <b class="<?= $totalProfit < 0 ? 'loss' : 'gain' ?>">
        <?= e(money($totalProfit, $rows[0]['cur'] ?? 'USD')) ?>
      </b>
    </div>
    <div class="totals-note">
      Unit cost times the quantity on hand, across <?= count($rows) ?> product<?= count($rows) === 1 ? '' : 's' ?>.
      <?php if ($anyUnknown): ?>
        Some have no cost recorded in Shopify and count as nothing here, so the real
        figure is higher.
      <?php endif; ?>
      <?php if ($anyShipping): ?>
        Cost here is the item plus what Trendsi charges to ship it — what actually
        leaves the business per sale. Profit is the price less that.
        <?php if ($noShipSet): ?>
          <?= $noShipSet ?> product<?= $noShipSet === 1 ? ' has' : 's have' ?> no shipping cost
          recorded and are counted at item cost alone, so their margin looks better than it is.
        <?php endif; ?>
      <?php else: ?>
        Profit is the current price less cost, on the quantity in stock.
        No shipping costs are recorded yet, so nothing is deducted for them.
      <?php endif; ?>
      These are what the shelf would make if every piece sold at today's price,
      not money already earned.
      <?php if ($anyLoss): ?>
        Something here is priced below what it cost.
      <?php endif; ?>
      <?php if ($more): ?>
        There are more products than fit on one page — this covers what is shown.
      <?php endif; ?>
    </div>
  </div>

  <?php if ($more && $next): ?>
    <a class="more" href="?v=<?= e($kind) ?><?= $q !== '' ? '&q=' . rawurlencode($q) : '' ?>&after=<?= e($next) ?>">
      Next 50
    </a>
  <?php endif; ?>

  <div style="margin-top:14px;font-size:12.5px;color:#7b8194">
    Tap a product name for its details.
  </div>

<?php endif; ?>
</div>

<?php page_close('catalog', $kind); ?>
