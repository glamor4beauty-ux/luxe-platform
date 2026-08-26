<?php
/* ═══════════════════════════════════════════════════════════════════════════
   dashboard.php — an affiliate's own.

   Her profile, her link, and her sales. The same layout as the admin view of
   her, minus the parts that are the studio's business: her ID photograph, the
   IP she signed from, and anybody else's figures.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/config.php';
$me = mb_require_affiliate();
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<meta name="robots" content="noindex,nofollow"/>
<title>Your account · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"/>
<style>
:root{--bg:#0a0d14;--panel:#111622;--panel2:#0e1219;--line:#1e2330;--line2:#2a3040;
  --txt:#e8eaf0;--txt2:#b9bcc6;--mute:#8a8fa8;--faint:#5a6070;
  --gold:#d4a830;--gold2:#e8bf44;--ok:#3fb950;--warn:#e0a340;--bad:#f85149}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--txt);
  font-family:'DM Sans',system-ui,-apple-system,'Segoe UI',sans-serif;
  font-size:14.5px;line-height:1.55;padding:22px 18px 70px}
.wrap{max-width:1080px;margin:0 auto}
a{color:var(--gold);text-decoration:none}
a:hover{color:var(--gold2)}

.top{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;
  margin-bottom:20px;flex-wrap:wrap}
h1{font-size:21px;font-weight:700;letter-spacing:-.3px}
h1 span{color:var(--gold)}
.sub{font-size:12.5px;color:var(--mute);margin-top:2px}
.who{font-size:12px;color:var(--mute);text-align:right}
.who a{display:block;margin-top:3px}
.dtabs{display:flex;gap:7px;margin-bottom:20px;flex-wrap:wrap}
.dtab{border:1px solid var(--line2);background:transparent;color:var(--mute);
  border-radius:9px;padding:9px 17px;font-size:12.5px;font-weight:700;
  text-decoration:none;display:inline-block;white-space:nowrap}
.dtab:hover{border-color:var(--gold);color:var(--gold)}
.dtab.on{background:rgba(212,168,48,.12);border-color:var(--gold);color:var(--gold)}

/* photograph left, details across the middle and right */
.pro{display:grid;grid-template-columns:170px 1fr 1fr;gap:22px;margin-bottom:6px}
@media(max-width:820px){.pro{grid-template-columns:1fr}}
.shot{width:100%;aspect-ratio:1;background:var(--panel2);border:1px solid var(--line2);
  border-radius:13px;overflow:hidden;display:flex;align-items:center;justify-content:center}
.shot img{width:100%;height:100%;object-fit:cover}
.shot i{font-size:42px;color:var(--faint)}
.f{display:flex;justify-content:space-between;gap:12px;padding:7px 0;
  border-bottom:1px solid rgba(30,35,48,.7);font-size:13.5px}
.f:last-child{border-bottom:none}
.f .k{color:var(--mute);white-space:nowrap}
.f .v{text-align:right;color:var(--txt2);word-break:break-word}
.f .v.gold{color:var(--gold);font-family:ui-monospace,monospace}

.sec{font-size:10px;color:var(--gold);text-transform:uppercase;letter-spacing:.09em;
  font-weight:700;margin:26px 0 12px;padding-bottom:7px;border-bottom:1px solid var(--line)}

.link{display:flex;gap:10px;align-items:center;background:var(--panel);
  border:1px solid var(--line2);border-radius:11px;padding:13px 15px}
.link code{flex:1;min-width:0;font-size:13px;color:var(--gold);word-break:break-all;
  font-family:ui-monospace,monospace}
.btn{border:1px solid var(--line2);background:transparent;color:var(--txt2);
  border-radius:9px;padding:9px 16px;font-size:13px;font-weight:700;cursor:pointer;
  font-family:inherit;white-space:nowrap}
.btn:hover{border-color:var(--gold);color:var(--gold)}
.btn.gold{background:var(--gold);border-color:var(--gold);color:#0a0d14}
.btn.gold:hover{background:var(--gold2)}

.sums{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px}
.sum{background:var(--panel);border:1px solid var(--line);border-radius:12px;
  padding:16px;text-align:center}
.sum b{display:block;font-family:ui-monospace,Menlo,monospace;font-size:22px;color:var(--txt)}
.sum b.gold{color:var(--gold)}
.sum b.ok{color:var(--ok)}
.sum span{font-size:9.5px;color:var(--mute);text-transform:uppercase;letter-spacing:.06em;
  font-weight:700;margin-top:4px;display:block}
.sum.big{border-color:var(--gold);background:rgba(212,168,48,.07)}

.tblwrap{overflow-x:auto;border:1px solid var(--line);border-radius:11px}
table{width:100%;border-collapse:collapse;font-size:13px;min-width:760px}
th{text-align:left;padding:11px 12px;font-size:9.5px;color:var(--mute);
  text-transform:uppercase;letter-spacing:.06em;font-weight:700;background:var(--panel2);
  border-bottom:1px solid var(--line2);white-space:nowrap}
th.r,td.r{text-align:right}
td{padding:11px 12px;border-bottom:1px solid rgba(30,35,48,.7);color:var(--txt2)}
tbody tr:last-child td{border-bottom:none}
.mono{font-family:ui-monospace,Menlo,monospace;white-space:nowrap}
.dim{color:var(--mute);font-size:12px}

.dl{display:inline-block;border-radius:5px;padding:2px 9px;font-size:10.5px;font-weight:700;
  white-space:nowrap}
.dl.notshipped{background:rgba(138,143,168,.14);color:var(--mute)}
.dl.partshipped{background:rgba(224,163,64,.14);color:var(--warn)}
.dl.shipped{background:rgba(212,168,48,.14);color:var(--gold)}
.dl.intransit{background:rgba(88,166,255,.14);color:#58a6ff}
.dl.delivered{background:rgba(63,185,80,.14);color:var(--ok)}
.dl.returned{background:rgba(248,81,73,.13);color:var(--bad)}

.note{font-size:12.5px;color:var(--mute);line-height:1.7;margin-top:14px;
  padding:12px 14px;background:rgba(212,168,48,.05);
  border-left:2px solid rgba(212,168,48,.4);border-radius:0 8px 8px 0}
.note b{color:var(--gold)}
.empty{text-align:center;padding:38px;color:var(--faint);font-size:13.5px}
.loading{text-align:center;padding:32px;color:var(--faint);font-size:13px}
.alert{background:rgba(248,81,73,.08);border:1px solid rgba(248,81,73,.35);color:#ffb4b0;
  border-radius:10px;padding:13px 16px;font-size:13px}
</style>
</head>
<body>
<div class="wrap">

  <div class="top">
    <div>
      <h1>Models <span>Boutique</span></h1>
      <div class="sub">Your account</div>
    </div>
    <div class="who">
      <?= e($me['email']) ?>
      <a href="/logout.php">Sign out</a>
    </div>
  </div>

  <div class="dtabs">
    <a class="dtab on" href="dashboard.php">Your account</a>
    <a class="dtab" href="my-sales.php?view=sales">Sales</a>
    <a class="dtab" href="my-sales.php?view=billing">Billing</a>
  </div>

  <div id="body"><div class="loading">Loading…</div></div>
</div>

<script>
var API = '/api/mb-api.php';
var SITE = '<?= e(AFFILIATE_URL) ?>';

function el(id){ return document.getElementById(id); }
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
  return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function usd(n){ return '$' + Number(n||0).toFixed(2); }
function when(d){ if(!d) return '—';
  var x = new Date(String(d).replace(' ','T'));
  return isNaN(x) ? String(d) : x.toLocaleDateString(undefined,
    {day:'numeric',month:'short',year:'numeric'}); }

function api(qs){
  return fetch(API+'?action='+qs,{credentials:'same-origin'}).then(function(r){
    return r.text().then(function(t){
      var j; try { j=JSON.parse(t); }
      catch(e){ throw new Error('Could not reach the server.'); }
      if(!j.ok) throw new Error(j.error||'Something went wrong.');
      return j;
    });
  });
}

function f(k,v,gold){
  return '<div class="f"><span class="k">'+k+'</span>'+
         '<span class="v'+(gold?' gold':'')+'">'+(v||'—')+'</span></div>';
}

api('mine').then(function(d){
  var a = d.affiliate;
  var link = SITE + '/?ref=' + encodeURIComponent(a.code);

  var img = a.profile_img
    ? '<img src="/uploads/affiliates/'+esc(a.profile_img)+'" alt="">'
    : '<i class="bi bi-person"></i>';

  var sub = a.hosting !== 'managed' ? 'Free'
    : (a.stripe_status === 'active' ? 'Subscribed · ' + usd(a.amount)
    : (a.stripe_status === 'past_due' ? 'Payment failed'
    : (a.stripe_status === 'cancelled' ? 'Cancelled' : 'Not started')));

  var h = '<div class="pro">'+
    '<div class="shot">'+img+'</div>'+
    '<div>'+
      f('Affiliate ID', esc(a.code), true)+
      f('First name', esc(a.first_name))+
      f('Last name', esc(a.last_name))+
      f('Store name', esc(a.store_name))+
      f('Email', esc(a.email))+
      f('Phone', esc(a.phone))+
      f('Subscription', sub)+
    '</div>'+
    '<div>'+
      f('Street', esc(a.street))+
      f('City', esc(a.city))+
      f('State', esc(a.state))+
      f('Postcode', esc(a.zip))+
      f('Country', esc(a.country))+
      f('Hosting', a.hosting==='managed'?'Managed':'Do it yourself')+
      f('Template', a.template==='template2'?'Banner':'Classic')+
      f('Commission rate', Number(a.rate).toFixed(1)+'%')+
    '</div>'+
  '</div>';

  h += '<div class="sec">Your link</div>'+
    '<div class="link"><code id="lnk">'+esc(link)+'</code>'+
    '<button class="btn gold" onclick="copyLink()">Copy</button></div>'+
    '<div class="note">Share this anywhere. Anyone who opens the boutique through it '+
    'is credited to you for <b>30 days</b>, even if they buy days later. You earn <b>'+
    Number(a.rate).toFixed(1)+'%</b> of what they spend on products, before shipping '+
    'and tax.</div>';

  h += '<div class="sec">Sales</div><div id="sales"><div class="loading">Loading…</div></div>';

  el('body').innerHTML = h;
  loadSales();

}).catch(function(e){
  el('body').innerHTML = '<div class="alert">'+esc(e.message)+'</div>';
});

function loadSales(){
  api('my_sales').then(function(d){
    var t = d.totals;

    var h = '<div class="sums">'+
      '<div class="sum"><b>'+t.orders+'</b><span>Orders</span></div>'+
      '<div class="sum"><b class="gold">'+usd(t.sold)+'</b><span>Sold</span></div>'+
      '<div class="sum"><b>'+Number(t.rate).toFixed(1)+'%</b><span>Your rate</span></div>'+
      '<div class="sum big"><b class="ok">'+usd(t.commission)+'</b><span>Commission</span></div>'+
      '</div>';

    if(!d.sales.length){
      h += '<div class="empty">No sales yet. Share your link and they will appear here.</div>';
      el('sales').innerHTML = h;
      return;
    }

    h += '<div class="tblwrap" style="margin-top:16px"><table><thead><tr>'+
      '<th>Product</th><th>SKU</th><th>Date</th><th class="r">Amount</th>'+
      '<th class="r">Rate</th><th class="r">Commission</th><th>Delivery</th>'+
      '</tr></thead><tbody>';

    d.sales.forEach(function(s){
      h += '<tr>'+
        '<td>'+esc(s.product)+(s.qty>1?' <span class="dim">&times;'+s.qty+'</span>':'')+'</td>'+
        '<td class="dim mono">'+esc(s.sku)+'</td>'+
        '<td class="dim mono">'+esc(s.date)+'</td>'+
        '<td class="r mono">'+usd(s.amount)+'</td>'+
        '<td class="r dim">'+Number(s.rate).toFixed(1)+'%</td>'+
        '<td class="r mono">'+(s.counts?usd(s.commission):'—')+'</td>'+
        '<td><span class="dl '+s.delivery.toLowerCase().replace(/[^a-z]/g,'')+'">'+
          esc(s.delivery)+'</span></td>'+
      '</tr>';
    });

    h += '</tbody></table></div>'+
      '<div class="note">Commission is earned once an order is paid for and not '+
      'refunded. If something here looks wrong, tell us and we will go through it '+
      'with you.</div>';

    el('sales').innerHTML = h;
  }).catch(function(e){
    el('sales').innerHTML = '<div class="alert">'+esc(e.message)+'</div>';
  });
}

function copyLink(){
  var t = el('lnk').textContent;
  if(navigator.clipboard){
    navigator.clipboard.writeText(t).then(function(){
      var b = document.querySelector('.btn.gold');
      b.textContent = 'Copied';
      setTimeout(function(){ b.textContent = 'Copy'; }, 1600);
    }).catch(function(){});
  }
}
</script>
</body>
</html>
