<?php
/* ═══════════════════════════════════════════════════════════════════════════
   Payments — the same list, a different question.

   Sorted by what each customer has spent rather than when they last changed,
   because on a payments screen the largest balances are what you are looking
   for.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/shell.php';

$search = trim((string)($_GET['q'] ?? ''));
$after  = $_GET['after'] ?? null;

$gql = <<<'GQL'
query PayingCustomers($n: Int!, $q: String, $after: String) {
  customers(first: $n, query: $q, after: $after, sortKey: UPDATED_AT, reverse: true) {
    edges { node {
      id
      displayName
      firstName
      lastName
      email
      numberOfOrders
      amountSpent { amount currencyCode }
    } }
    pageInfo { hasNextPage endCursor }
  }
}
GQL;

$res = shop_query($gql, [
    'n' => 50,
    'q' => $search !== '' ? $search : null,
    'after' => $after,
], ($search === '' && !$after) ? 'paying' : null);

$rows = [];
$more = false;
$next = null;
$totalSpent = 0.0;
$cur = 'USD';
if ($res['ok']) {
    foreach ($res['data']['customers']['edges'] ?? [] as $ed) {
        $rows[] = $ed['node'];
        $totalSpent += (float)($ed['node']['amountSpent']['amount'] ?? 0);
        $cur = $ed['node']['amountSpent']['currencyCode'] ?? $cur;
    }
    $more = !empty($res['data']['customers']['pageInfo']['hasNextPage']);
    $next = $res['data']['customers']['pageInfo']['endCursor'] ?? null;

    /* Shopify has no TOTAL_SPENT sort key, so order the page here. It sorts
       what was fetched rather than the whole store — worth knowing when
       paging, but on a payments screen the big spenders are what you want at
       the top of the page you are looking at. */
    usort($rows, function ($a, $b) {
        return (float)($b['amountSpent']['amount'] ?? 0) <=> (float)($a['amountSpent']['amount'] ?? 0);
    });
}

page_open('Payments', 'payments');
?>

<header class="top">
  <div>
    <div class="app"><?= e(APP_NAME) ?><?= page_gear() ?><?php if (!can_write()): ?><span class="readonly">read only</span><?php endif; ?></div>
    <h1>Payments</h1>
  </div>
  <div class="right">
    <?php if ($rows): ?>
      <?= e(money($totalSpent, $cur)) ?><br>
      <span style="font-size:10.5px">on this page</span>
    <?php endif; ?>
  </div>
</header>

<form class="bar" method="get" action="">
  <input type="search" name="q" value="<?= e($search) ?>"
         placeholder="Name or email" autocomplete="off">
  <button type="submit">Find</button>
  <?php if ($search !== ''): ?><a class="clear" href="?">Clear</a><?php endif; ?>
</form>

<div class="wrap">
<?php if (!$res['ok']): problem_block($res);
      elseif (!$rows): ?>
  <div class="empty"><?= $search !== '' ? 'Nobody matches that.' : 'Nothing paid yet.' ?></div>
<?php else: ?>

  <div class="list">
    <?php foreach ($rows as $c):
      $name = trim((string)($c['displayName'] ?? ''));
      if ($name === '') $name = trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? ''));
      if ($name === '') $name = 'No name';
      $n = (int)($c['numberOfOrders'] ?? 0);
    ?>
      <button class="row" type="button" data-id="<?= e(gid_num($c['id'])) ?>">
        <span class="nm"><?= e($name) ?></span>
        <span class="sub"><?= e($c['email'] ?: 'no email') ?></span>
        <span class="end">
          <b><?= e(money($c['amountSpent']['amount'] ?? null, $c['amountSpent']['currencyCode'] ?? 'USD')) ?></b>
          <?= $n ?> order<?= $n === 1 ? '' : 's' ?>
        </span>
      </button>
    <?php endforeach; ?>
  </div>

  <?php if ($more && $next): ?>
    <a class="more" href="?<?= e(http_build_query(array_filter(['q' => $search, 'after' => $next]))) ?>">
      Next 50
    </a>
  <?php endif; ?>

<?php endif; ?>
</div>

<div class="sheet" id="sheet" aria-hidden="true">
  <div class="sheet-box" role="dialog" aria-modal="true" aria-labelledby="shName">
    <div class="sheet-head">
      <div>
        <h2 id="shName">…</h2>
        <div class="sub" id="shSub"></div>
      </div>
      <button class="x" type="button" id="shClose" aria-label="Close">&times;</button>
    </div>
    <div class="sheet-body" id="shBody"><div class="load">Loading…</div></div>
  </div>
</div>

<script>
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){
  return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function money(a,c){if(a==null||a==='')return '\u2014';
  var s={USD:'$',CAD:'CA$',GBP:'\u00a3',EUR:'\u20ac'}[c]||(c+' ');return s+Number(a).toFixed(2);}
function when(iso){if(!iso)return '';var d=new Date(iso);
  return isNaN(d)?'':d.toLocaleDateString(undefined,{day:'numeric',month:'short',year:'numeric'});}
function pretty(s){return String(s||'').replace(/_/g,' ').toLowerCase();}
function payClass(s){
  s=String(s||'').toUpperCase();
  if(s==='PAID'||s==='SUCCESS')return 'ok';
  if(s==='PENDING'||s==='AUTHORIZED'||s==='PARTIALLY_PAID')return 'warn';
  if(s==='REFUNDED'||s==='VOIDED'||s==='FAILURE'||s==='ERROR')return 'bad';
  return '';
}

var sheet=document.getElementById('sheet');

function openPayments(id){
  document.getElementById('shName').textContent='Loading\u2026';
  document.getElementById('shSub').textContent='';
  document.getElementById('shBody').innerHTML='<div class="load">Loading\u2026</div>';
  sheet.classList.add('on');sheet.setAttribute('aria-hidden','false');
  document.body.style.overflow='hidden';
  document.getElementById('shClose').focus();

  fetch('api.php?action=payments&id='+encodeURIComponent(id),{credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(d){
      if(!d.ok){
        document.getElementById('shName').textContent='Could not load';
        document.getElementById('shBody').innerHTML='<div class="problem"><p>'+esc(d.error)+'</p></div>';
        return;
      }
      document.getElementById('shName').textContent=d.customer.name;
      document.getElementById('shSub').textContent =
        money(d.customer.spent,d.customer.cur)+' in total';

      if(!d.orders.length){
        document.getElementById('shBody').innerHTML='<div class="empty">No payments recorded.</div>';
        return;
      }

      var h='<div class="grp"><h3>Payments</h3>';
      d.orders.forEach(function(o){
        h+='<div class="ord">';
        h+='<div class="ord-top"><div><span class="ord-no">'+esc(o.name)+'</span> '+
           '<span class="ord-date">'+esc(when(o.created))+'</span></div>'+
           '<div class="ord-tot">'+money(o.total,o.cur)+'</div></div>';

        if(!o.tx.length){
          h+='<div class="li"><div class="t"><small>no transactions recorded</small></div></div>';
        }
        o.tx.forEach(function(t){
          h+='<div class="li"><div class="t">'+
             '<b>'+esc(pretty(t.kind))+(t.card?' \u00b7 '+esc(t.card):'')+'</b>'+
             '<small>'+esc(t.gateway||'')+(t.when?' \u00b7 '+esc(when(t.when)):'')+'</small></div>'+
             '<div class="p">'+money(t.amount,t.cur)+
             '<div style="text-align:right;margin-top:3px"><span class="pill '+payClass(t.status)+'">'+
             esc(pretty(t.status))+'</span></div></div></div>';
        });

        if(o.refunded){
          h+='<div class="li"><div class="t"><b style="color:#f2685e">Refunded</b></div>'+
             '<div class="p" style="color:#f2685e">\u2212'+money(o.refunded,o.cur)+'</div></div>';
        }

        h+='<div class="li" style="justify-content:flex-end">'+
           '<span class="pill '+payClass(o.financial)+'">'+esc(pretty(o.financial))+'</span></div>';
        h+='</div>';
      });
      h+='</div>';
      document.getElementById('shBody').innerHTML=h;
    })
    .catch(function(e){
      document.getElementById('shBody').innerHTML='<div class="problem"><p>'+esc(e.message)+'</p></div>';
    });
}

function closeSheet(){
  sheet.classList.remove('on');sheet.setAttribute('aria-hidden','true');
  document.body.style.overflow='';
}
document.addEventListener('click',function(ev){
  var r=ev.target.closest('.row');
  if(r){openPayments(r.getAttribute('data-id'));return;}
  if(ev.target.id==='shClose'||ev.target.id==='sheet')closeSheet();
});
document.addEventListener('keydown',function(ev){if(ev.key==='Escape')closeSheet();});
</script>

<?php page_close('payments'); ?>
