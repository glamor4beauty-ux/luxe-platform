<?php
/* ═══════════════════════════════════════════════════════════════════════════
   proxy.php — the shop as a page on the merchant's own domain.

   Shopify forwards theirshop.com/apps/boutique here, signed, and renders what
   we return inside their theme. So the page has their header, their footer,
   their fonts and their navigation — and a real URL a customer can bookmark,
   share, or find in a search engine.

   That last part is why this is worth having as well as the block. A block is
   a section inside a page somebody else made; this is a page.

   What comes back is Liquid, not HTML. Shopify runs it through their theme
   before the customer sees it, which is what makes it look like part of the
   shop rather than something embedded in it.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/lib.php';

/* ── is this really Shopify ───────────────────────────────────────────────
   The proxy signs its parameters differently from everything else: sorted,
   joined without separators, and hashed hex. Getting the format wrong means
   either refusing every real request or accepting every forged one. */
function proxy_verified(array $q): bool {
    $sig = (string)($q['signature'] ?? '');
    if ($sig === '') return false;

    unset($q['signature']);
    ksort($q);

    $parts = [];
    foreach ($q as $k => $v) {
        /* an array parameter joins with commas, which is Shopify's rule
           rather than an obvious one */
        $parts[] = $k . '=' . (is_array($v) ? implode(',', $v) : $v);
    }

    return hash_equals(hash_hmac('sha256', implode('', $parts), APP_SECRET), $sig);
}

if (!proxy_verified($_GET)) {
    app_log('proxy: bad signature');
    http_response_code(403);
    exit('That request could not be verified.');
}

$shop = app_shop($_GET['shop'] ?? '');
if ($shop === '') { http_response_code(400); exit('No shop.'); }

$db = app_db();
app_tables($db);

$q = $db->prepare("SELECT code, shop_name FROM mb_shops WHERE shop = ? AND uninstalled IS NULL");
$q->execute([$shop]);
$row = $q->fetch();

/* Uninstalled, or never installed. Answered as a page rather than an error,
   because a customer may have followed an old link and none of this is their
   doing. */
if (!$row) {
    header('Content-Type: application/liquid');
    echo '<div style="text-align:center;padding:60px 20px">'
       . '<h1>Not available</h1>'
       . '<p>This shop is not taking orders through us at the moment.</p>'
       . '</div>';
    exit;
}

$code = (string)$row['code'];

/* what the customer asked for */
$view    = (string)($_GET['view'] ?? '');
$collect = preg_replace('/[^a-z0-9\-]/i', '', (string)($_GET['c'] ?? ''));
$handle  = preg_replace('/[^a-z0-9\-]/i', '', (string)($_GET['p'] ?? ''));

/* Liquid, so Shopify wraps it in the theme. Anything else is served as a bare
   page with no header or footer, which defeats the point. */
header('Content-Type: application/liquid');

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>

{% comment %}
  Models Boutique — served through the app proxy and rendered in this theme.
{% endcomment %}

<div class="mb-page" data-code="<?= h($code) ?>"
     data-collection="<?= h($collect) ?>" data-product="<?= h($handle) ?>">

  <div class="mb-page-head">
    <h1>Shop the collection</h1>
    <p>Hand-picked women's fashion, delivered to your door.</p>
  </div>

  <div class="mb-page-cats" id="mbPageCats"></div>

  <div class="mb-page-goods" id="mbPageGoods">
    <div class="mb-page-skel"></div>
    <div class="mb-page-skel"></div>
    <div class="mb-page-skel"></div>
    <div class="mb-page-skel"></div>
    <div class="mb-page-skel"></div>
    <div class="mb-page-skel"></div>
    <div class="mb-page-skel"></div>
    <div class="mb-page-skel"></div>
  </div>

  <div class="mb-page-more" id="mbPageMore" style="display:none">
    <button type="button" class="mb-page-btn">Show more</button>
  </div>

</div>

<style>
  /* Everything prefixed, and typography inherited rather than declared. The
     page should read as part of this shop, not as a widget dropped into it. */
  .mb-page { max-width: 1200px; margin: 0 auto; padding: 40px 20px 60px; }

  .mb-page-head { text-align: center; margin-bottom: 30px; }
  .mb-page-head h1 { margin: 0 0 10px; }
  .mb-page-head p { margin: 0; opacity: .7; }

  .mb-page-cats {
    display: flex; gap: 8px; flex-wrap: wrap; justify-content: center;
    margin-bottom: 28px;
  }
  .mb-page-cat {
    background: none; border: 1px solid currentColor; opacity: .5;
    border-radius: 10px; padding: 9px 18px; font: inherit; font-size: .9em;
    cursor: pointer; color: inherit;
  }
  .mb-page-cat:hover { opacity: .8; }
  .mb-page-cat.on { opacity: 1; }

  .mb-page-goods {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 24px;
  }
  @media (max-width: 600px) {
    .mb-page-goods { grid-template-columns: 1fr 1fr; gap: 14px; }
    .mb-page { padding: 24px 14px 40px; }
  }

  .mb-page-good { cursor: pointer; }
  .mb-page-shot {
    position: relative; aspect-ratio: 3/4; overflow: hidden; border-radius: 10px;
    background: rgba(128,128,128,.08); margin-bottom: 10px;
  }
  .mb-page-shot img {
    width: 100%; height: 100%; object-fit: cover; display: block;
    transition: transform .5s ease;
  }
  .mb-page-good:hover .mb-page-shot img { transform: scale(1.04); }
  .mb-page-good.out .mb-page-shot img { opacity: .45; }

  .mb-page-tag {
    position: absolute; top: 10px; left: 10px; font-size: .68em; font-weight: 700;
    letter-spacing: .06em; text-transform: uppercase; padding: 4px 10px;
    border-radius: 5px; background: rgba(0,0,0,.75); color: #fff;
  }
  .mb-page-name { font-size: .95em; margin: 0 0 4px; }
  .mb-page-price { font-size: .95em; font-weight: 600; }
  .mb-page-price s { opacity: .45; font-weight: 400; margin-left: 6px; }
  .mb-page-from { opacity: .6; font-size: .82em; font-weight: 400; }

  .mb-page-more { text-align: center; padding-top: 32px; }
  .mb-page-btn {
    background: none; border: 1px solid currentColor; border-radius: 10px;
    padding: 13px 32px; font: inherit; cursor: pointer; color: inherit; opacity: .7;
  }
  .mb-page-btn:hover { opacity: 1; }

  .mb-page-msg { text-align: center; padding: 50px 20px; opacity: .55;
    grid-column: 1 / -1; }

  .mb-page-skel {
    aspect-ratio: 3/4; border-radius: 10px; background: rgba(128,128,128,.08);
    animation: mbPagePulse 1.4s ease-in-out infinite;
  }
  @keyframes mbPagePulse { 0%,100% { opacity: 1 } 50% { opacity: .5 } }
</style>

<script src="https://admin.modelsboutique.com/api/boutique.js?v=5" defer></script>
<script src="https://admin.modelsboutique.com/api/page.js?v=1" defer></script>
