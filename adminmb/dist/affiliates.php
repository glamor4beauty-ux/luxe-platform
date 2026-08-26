<?php
/* ═══════════════════════════════════════════════════════════════════════════
   affiliates.php — Affiliate's Admin.

   The table is the list; clicking a name opens everything about that person —
   her details, her subscription, and every sale credited to her. The same
   layout serves as the billing view, because a person's account and their
   billing are the same conversation.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/config.php';
$admin = mb_require_admin();
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<meta name="robots" content="noindex,nofollow"/>
<title>Affiliate's Admin · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"/>
<style>
:root{--bg:#0a0d14;--panel:#111622;--panel2:#0e1219;--line:#1e2330;--line2:#2a3040;
  --txt:#e8eaf0;--txt2:#b9bcc6;--mute:#8a8fa8;--faint:#5a6070;
  --gold:#d4a830;--gold2:#e8bf44;--ok:#3fb950;--warn:#e0a340;--yell:#e8d44b;--bad:#f85149}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--txt);
  font-family:'DM Sans',system-ui,-apple-system,'Segoe UI',sans-serif;
  font-size:14px;line-height:1.5;padding:22px 18px 70px}
.wrap{max-width:1280px;margin:0 auto}
a{color:var(--gold);text-decoration:none}

.top{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;
  margin-bottom:18px;flex-wrap:wrap}
h1{font-size:20px;font-weight:700}
.sub{font-size:12px;color:var(--mute);margin-top:2px}
.who{font-size:12px;color:var(--mute);text-align:right}
.who a{display:block;margin-top:3px}

.bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;align-items:center}
.btn{border:1px solid var(--line2);background:transparent;color:var(--txt2);
  border-radius:9px;padding:9px 15px;font-size:12.5px;font-weight:700;cursor:pointer;
  font-family:inherit;white-space:nowrap}
.btn:hover{border-color:var(--gold);color:var(--gold)}
.btn.gold{background:var(--gold);border-color:var(--gold);color:#0a0d14}
.btn.gold:hover{background:var(--gold2)}
.btn.sm{padding:5px 11px;font-size:11px}
.btn.danger:hover{border-color:var(--bad);color:var(--bad)}
.search{flex:1;min-width:180px;background:var(--panel2);border:1px solid var(--line2);
  border-radius:9px;padding:9px 13px;color:var(--txt);font-size:13.5px;outline:none;
  font-family:inherit}
.search:focus{border-color:var(--gold)}

.tblwrap{overflow-x:auto;border:1px solid var(--line);border-radius:11px}
table{width:100%;border-collapse:collapse;font-size:13px;min-width:1040px}
th{text-align:left;padding:11px 12px;font-size:9.5px;color:var(--mute);
  text-transform:uppercase;letter-spacing:.06em;font-weight:700;background:var(--panel2);
  border-bottom:1px solid var(--line2);white-space:nowrap}
th.r,td.r{text-align:right}
th.c,td.c{text-align:center}
td{padding:10px 12px;border-bottom:1px solid rgba(30,35,48,.7);color:var(--txt2);
  vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover{background:rgba(255,255,255,.02)}

.name{background:none;border:none;color:var(--txt);font-weight:600;font-size:14px;
  cursor:pointer;padding:0;font-family:inherit;text-align:left}
.name:hover{color:var(--gold)}
.code{font-family:ui-monospace,Menlo,monospace;color:var(--gold);font-size:12.5px;
  white-space:nowrap}
.dim{font-size:12px;color:var(--mute)}
.site{font-size:12px;color:var(--gold);word-break:break-all}
.site:hover{text-decoration:underline}
.mono{font-family:ui-monospace,Menlo,monospace;white-space:nowrap}

select.st{background:var(--panel2);border:1px solid var(--line2);border-radius:7px;
  padding:6px 9px;font-size:11.5px;outline:none;font-family:inherit;cursor:pointer;
  font-weight:700}
select.st.trial{color:var(--warn);border-color:rgba(224,163,64,.5)}
select.st.active{color:var(--ok);border-color:rgba(63,185,80,.5)}
select.st.inactive{color:var(--yell);border-color:rgba(232,212,75,.5)}
select.st.banned{color:var(--bad);border-color:rgba(248,81,73,.5)}
select.st option{background:var(--panel2);color:var(--txt)}

.rate{width:56px;background:var(--panel2);border:1px solid var(--line2);border-radius:6px;
  padding:5px 7px;color:var(--txt);font-size:12px;text-align:right;outline:none;
  font-family:ui-monospace,monospace}
.rate:focus{border-color:var(--gold)}
.pill{display:inline-block;border-radius:5px;padding:2px 9px;font-size:10.5px;font-weight:700}
.pill.managed{background:rgba(63,185,80,.14);color:var(--ok)}
.pill.diy{background:rgba(138,143,168,.14);color:var(--mute)}

.empty{text-align:center;padding:44px;color:var(--faint);font-size:13.5px}
.loading{text-align:center;padding:34px;color:var(--faint);font-size:13px}
.alert{background:rgba(248,81,73,.08);border:1px solid rgba(248,81,73,.35);color:#ffb4b0;
  border-radius:9px;padding:12px 15px;font-size:12.5px;margin-bottom:14px}

/* ── the profile, which is also the billing view ─────────────────────── */
.modal{position:fixed;inset:0;background:rgba(0,0,0,.74);display:none;z-index:900;
  padding:18px;overflow-y:auto}
.modal.on{display:block}
.sheet{background:var(--panel);border:1px solid var(--line2);border-radius:15px;
  max-width:1080px;margin:0 auto}
.shead{display:flex;justify-content:space-between;align-items:center;gap:12px;
  padding:15px 20px;border-bottom:1px solid var(--line);position:sticky;top:0;
  background:var(--panel);border-radius:15px 15px 0 0;z-index:2}
.shead h2{font-size:17px;font-weight:700}
.x{background:none;border:none;color:var(--mute);font-size:25px;cursor:pointer;line-height:1}
.x:hover{color:var(--txt)}
.sbody{padding:20px}

/* photograph left, details across the middle and right */
.pro{display:grid;grid-template-columns:180px 1fr 1fr;gap:22px;margin-bottom:24px}
@media(max-width:820px){.pro{grid-template-columns:1fr}}
.shot{width:100%;aspect-ratio:1;background:var(--panel2);border:1px solid var(--line2);
  border-radius:13px;overflow:hidden;display:flex;align-items:center;justify-content:center}
.shot img{width:100%;height:100%;object-fit:cover}
.shot i{font-size:44px;color:var(--faint)}
.f{display:flex;justify-content:space-between;gap:12px;padding:7px 0;
  border-bottom:1px solid rgba(30,35,48,.7);font-size:13px}
.f:last-child{border-bottom:none}
.f .k{color:var(--mute);white-space:nowrap}
.f .v{text-align:right;color:var(--txt2);word-break:break-word}
.f .v.gold{color:var(--gold);font-family:ui-monospace,monospace}

.sec{font-size:10px;color:var(--gold);text-transform:uppercase;letter-spacing:.09em;
  font-weight:700;margin:22px 0 11px;padding-bottom:7px;border-bottom:1px solid var(--line)}

.sums{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:11px;
  margin-bottom:16px}
.sum{background:var(--panel2);border:1px solid var(--line);border-radius:10px;
  padding:13px;text-align:center}
.sum b{display:block;font-family:ui-monospace,Menlo,monospace;font-size:19px;color:var(--txt)}
.sum b.gold{color:var(--gold)}
.sum b.ok{color:var(--ok)}
.sum span{font-size:9.5px;color:var(--mute);text-transform:uppercase;letter-spacing:.06em;
  font-weight:700}

.dl{display:inline-block;border-radius:5px;padding:2px 9px;font-size:10.5px;font-weight:700;
  white-space:nowrap}
.dl.notshipped{background:rgba(138,143,168,.14);color:var(--mute)}
.dl.partshipped{background:rgba(224,163,64,.14);color:var(--warn)}
.dl.shipped{background:rgba(212,168,48,.14);color:var(--gold)}
.dl.intransit{background:rgba(88,166,255,.14);color:#58a6ff}
.dl.delivered{background:rgba(63,185,80,.14);color:var(--ok)}
.dl.returned{background:rgba(248,81,73,.13);color:var(--bad)}

/* ── the editor ──────────────────────────────────────────────────────── */
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:620px){.grid{grid-template-columns:1fr}}
.grid .full{grid-column:1/-1}
label.l{display:block;font-size:9.5px;color:var(--mute);text-transform:uppercase;
  letter-spacing:.08em;font-weight:700;margin-bottom:5px}
.in{width:100%;background:var(--panel2);border:1px solid var(--line2);border-radius:9px;
  padding:10px 12px;color:var(--txt);font-size:14px;outline:none;font-family:inherit}
.in:focus{border-color:var(--gold)}
.chk{display:flex;gap:10px;align-items:center;font-size:13px;color:var(--txt2);
  cursor:pointer;padding:9px 0}
.chk input{width:19px;height:19px;accent-color:var(--gold)}
.sfoot{display:flex;justify-content:flex-end;gap:9px;padding:14px 20px;
  border-top:1px solid var(--line)}
.msg{font-size:12.5px;color:var(--mute);margin-top:10px;min-height:17px}
.msg.bad{color:var(--bad)}
.msg.ok{color:var(--ok)}
.toast{position:fixed;bottom:22px;left:50%;transform:translate(-50%,80px);
  background:#1a1f2e;border:1px solid var(--gold);color:var(--txt);padding:11px 20px;
  border-radius:9px;font-size:13px;z-index:1000;opacity:0;transition:all .25s;
  pointer-events:none}
.toast.show{transform:translate(-50%,0);opacity:1}
.toast.bad{border-color:var(--bad);color:#ffb4b0}
</style>
</head>
<body>
<div class="wrap">

  <div class="top">
    <div>
      <h1>Affiliate's Admin</h1>
      <div class="sub" id="sub">Loading…</div>
    </div>
    <div class="who">
      <?= e($admin['email']) ?>
      <a href="/logout.php">Sign out</a>
    </div>
  </div>

  <div class="bar">
    <input class="search" id="q" placeholder="Search name, store, email or code" oninput="render()">
    <button class="btn gold" onclick="edit(null)">+ Add affiliate</button>
    <button class="btn" onclick="load()">Refresh</button>
  </div>

  <div id="body"><div class="loading">Loading…</div></div>
</div>

<div class="modal" id="modal">
  <div class="sheet">
    <div class="shead">
      <h2 id="mTitle"></h2>
      <button class="x" onclick="shut()">&times;</button>
    </div>
    <div class="sbody" id="mBody"></div>
    <div class="sfoot" id="mFoot"></div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
var API = '/api/mb-api.php';
var DATA = null, CUR = null;

function el(id){ return document.getElementById(id); }
function v(id){ var e = el(id); return e ? e.value.trim() : ''; }
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
  return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function usd(n){ return '$' + Number(n||0).toFixed(2); }
function when(d){ if(!d) return '—';
  var x = new Date(String(d).replace(' ','T'));
  return isNaN(x) ? String(d) : x.toLocaleDateString(undefined,
    {day:'numeric',month:'short',year:'numeric'}); }

function api(action, payload){
  var opt = { credentials:'same-origin' };
  if (payload !== undefined) {
    opt.method='POST';
    opt.headers={'Content-Type':'application/json'};
    opt.body=JSON.stringify(payload);
  }
  return fetch(API+'?action='+action, opt).then(function(r){
    return r.text().then(function(t){
      var j; try { j=JSON.parse(t); }
      catch(e){ throw new Error('HTTP '+r.status+' — '+t.slice(0,150)); }
      if(!j.ok) throw new Error(j.error||'Request failed');
      return j;
    });
  });
}

function toast(m, bad){
  var t = el('toast');
  t.textContent = m;
  t.className = 'toast show' + (bad?' bad':'');
  clearTimeout(toast._t);
  toast._t = setTimeout(function(){ t.className='toast'; }, bad?6000:3000);
}

/* ═══ the table ═══════════════════════════════════════════════════════ */
function load(){
  el('body').innerHTML = '<div class="loading">Loading…</div>';
  api('list').then(function(d){
    DATA = d;
    el('sub').textContent = d.affiliates.length + ' registered';
    render();
  }).catch(function(e){
    el('body').innerHTML = '<div class="alert">'+esc(e.message)+'</div>';
  });
}

function render(){
  if(!DATA) return;
  var q = (v('q')||'').toLowerCase();

  var rows = DATA.affiliates.filter(function(a){
    if(!q) return true;
    return ((a.first_name||'')+' '+(a.last_name||'')+' '+(a.store_name||'')+' '+
            (a.email||'')+' '+(a.code||'')).toLowerCase().indexOf(q) >= 0;
  });

  if(!rows.length){
    el('body').innerHTML = '<div class="empty">'+
      (q?'Nothing matches that.':'Nobody has registered yet.')+'</div>';
    return;
  }

  var h = '<div class="tblwrap"><table><thead><tr>'+
    '<th>Affiliate ID</th><th>Name</th><th>Store</th><th>Email</th><th>Phone</th>'+
    '<th class="c">Hosting</th><th>Site</th><th class="c">Status</th>'+
    '<th class="r">Rate</th><th></th>'+
    '</tr></thead><tbody>';

  rows.forEach(function(a){
    h += '<tr>'+
      '<td class="code">'+esc(a.code)+'</td>'+
      '<td><button class="name" data-open="'+esc(a.code)+'">'+
        esc(((a.first_name||'')+' '+(a.last_name||'')).trim()||'—')+'</button></td>'+
      '<td class="dim">'+esc(a.store_name||'—')+'</td>'+
      '<td class="dim">'+esc(a.email||'—')+'</td>'+
      '<td class="dim mono">'+esc(a.phone||'—')+'</td>'+
      '<td class="c"><span class="pill '+esc(a.hosting)+'">'+
        (a.hosting==='managed'?'Managed':'DIY')+'</span></td>'+
      '<td>'+(a.site_url
        ? '<a href="'+esc(a.site_url)+'" target="_blank" rel="noopener" class="site">'+
          esc(a.site_url.replace(/^https?:\/\//,''))+'</a>'
        : '<span class="dim">not built</span>')+'</td>'+
      '<td class="c"><select class="st '+esc(a.status)+'" data-status="'+esc(a.code)+'">'+
        ['trial','active','inactive','banned'].map(function(s){
          return '<option value="'+s+'"'+(a.status===s?' selected':'')+'>'+
                 s.charAt(0).toUpperCase()+s.slice(1)+'</option>';
        }).join('')+'</select></td>'+
      '<td class="r"><input class="rate" value="'+Number(a.rate).toFixed(1)+
        '" data-rate="'+esc(a.code)+'">%</td>'+
      '<td class="r">'+
        '<button class="btn sm" data-open="'+esc(a.code)+'">View</button> '+
        '<button class="btn sm" data-edit="'+esc(a.code)+'">Edit</button> '+
        '<button class="btn sm danger" data-del="'+esc(a.code)+'">Delete</button>'+
      '</td></tr>';
  });

  h += '</tbody></table></div>';
  el('body').innerHTML = h;
}

/* ═══ the profile, which is also billing ══════════════════════════════ */
function open(code){
  el('modal').classList.add('on');
  el('mBody').innerHTML = '<div class="loading">Loading…</div>';
  el('mFoot').innerHTML = '';
  el('mTitle').textContent = code;

  api('one&code='+encodeURIComponent(code)).then(function(d){
    CUR = d.affiliate;
    var a = CUR;
    el('mTitle').textContent = ((a.first_name||'')+' '+(a.last_name||'')).trim()||a.code;

    var img = a.profile_img
      ? '<img src="/uploads/affiliates/'+esc(a.profile_img)+'" alt="">'
      : '<i class="bi bi-person"></i>';

    var h = '<div class="pro">'+
      '<div class="shot">'+img+'</div>'+

      '<div>'+
        f('Affiliate ID', esc(a.code), true)+
        f('First name', esc(a.first_name))+
        f('Last name', esc(a.last_name))+
        f('Store name', esc(a.store_name))+
        f('Email', esc(a.email))+
        f('Phone', esc(a.phone))+
        f('Subscription', subLabel(a))+
      '</div>'+

      '<div>'+
        f('Street', esc(a.street))+
        f('City', esc(a.city))+
        f('State', esc(a.state))+
        f('Postcode', esc(a.zip))+
        f('Country', esc(a.country))+
        f('Hosting', a.hosting==='managed'?'Managed':'Do it yourself')+
        f('Template', a.template==='template2'?'Banner':'Classic')+
        f('Texts', +a.sms_opt_in ? 'Yes' : 'No')+
        f('Site', a.site_url
          ? '<a href="'+esc(a.site_url)+'" target="_blank" rel="noopener">'+
            esc(a.site_url.replace(/^https?:\/\//,''))+'</a>'
          : 'not built yet')+
      '</div>'+
    '</div>';

    h += '<div class="sec">Billing</div>'+
      '<div class="pro" style="grid-template-columns:1fr 1fr;margin-bottom:0">'+
        '<div>'+
          f('Plan', a.plan ? (a.plan==='quarterly'?'Quarterly':'Monthly') : '—')+
          f('Amount', a.amount ? usd(a.amount) : '—')+
          f('Stripe status', esc(a.stripe_status||'—'))+
        '</div>'+
        '<div>'+
          f('Started', when(a.started))+
          f('Renews', when(a.renews))+
          f('Last paid', when(a.last_paid))+
          (a.last_failure ? f('Last failure', esc(a.last_failure)) : '')+
        '</div>'+
      '</div>';

    h += '<div class="sec">Sales</div><div id="sales"><div class="loading">Loading sales…</div></div>';

    el('mBody').innerHTML = h;

    var edit = document.createElement('button');
    edit.className = 'btn';
    edit.textContent = 'Edit';
    edit.onclick = function(){ editAffiliate(a.code); };
    el('mFoot').appendChild(edit);

    var close = document.createElement('button');
    close.className = 'btn gold';
    close.textContent = 'Close';
    close.onclick = shut;
    el('mFoot').appendChild(close);

    loadSales(a.code);
  }).catch(function(e){
    el('mBody').innerHTML = '<div class="alert">'+esc(e.message)+'</div>';
  });
}

function f(k, val, gold){
  return '<div class="f"><span class="k">'+k+'</span>'+
         '<span class="v'+(gold?' gold':'')+'">'+(val||'—')+'</span></div>';
}

function subLabel(a){
  if(a.hosting !== 'managed') return 'Free';
  var st = a.stripe_status||'';
  if(st==='active') return 'Subscribed &middot; '+usd(a.amount);
  if(st==='past_due') return 'Payment failed';
  if(st==='cancelled') return 'Cancelled';
  return 'Not started';
}

function loadSales(code){
  api('sales&code='+encodeURIComponent(code)).then(function(d){
    var box = el('sales');
    if(!box) return;

    var t = d.totals;
    var h = '<div class="sums">'+
      '<div class="sum"><b>'+t.orders+'</b><span>Orders</span></div>'+
      '<div class="sum"><b class="gold">'+usd(t.sold)+'</b><span>Sold</span></div>'+
      '<div class="sum"><b>'+Number(t.rate).toFixed(1)+'%</b><span>Rate</span></div>'+
      '<div class="sum"><b class="ok">'+usd(t.commission)+'</b><span>Commission</span></div>'+
      '</div>';

    if(!d.sales.length){
      h += '<div class="empty">No sales yet.</div>';
      box.innerHTML = h;
      return;
    }

    h += '<div class="tblwrap"><table><thead><tr>'+
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

    h += '</tbody></table></div>';
    box.innerHTML = h;
  }).catch(function(e){
    var box = el('sales');
    if(box) box.innerHTML = '<div class="alert">'+esc(e.message)+'</div>';
  });
}

/* ═══ add and edit ════════════════════════════════════════════════════ */
function edit(code){ code ? editAffiliate(code) : editForm(null); }

function editAffiliate(code){
  api('one&code='+encodeURIComponent(code)).then(function(d){
    editForm(d.affiliate);
  }).catch(function(e){ toast(e.message, true); });
}

function editForm(a){
  a = a || {};
  var isNew = !a.code;

  el('modal').classList.add('on');
  el('mTitle').textContent = isNew ? 'Add an affiliate' : 'Edit ' + a.code;

  el('mBody').innerHTML =
    '<div class="sec" style="margin-top:0">Identity</div>'+
    '<div class="grid">'+
      (isNew ? '' : fld('Affiliate ID','fCode',a.code,'text',true))+
      fld('First name','fFirst',a.first_name)+
      fld('Last name','fLast',a.last_name)+
      fld('Store name','fStore',a.store_name,'text',false,'full')+
      fld('Email','fEmail',a.email,'email')+
      fld('Phone','fPhone',a.phone,'tel')+
      fld(isNew?'Password':'New password (leave blank to keep)','fPass','','password',false,'full')+
    '</div>'+

    '<div class="sec">Address</div>'+
    '<div class="grid">'+
      fld('Street','fStreet',a.street,'text',false,'full')+
      fld('City','fCity',a.city)+
      fld('State','fState',a.state)+
      fld('Postcode','fZip',a.zip)+
      fld('Country','fCountry',a.country)+
    '</div>'+

    '<div class="sec">Account</div>'+
    '<div class="grid">'+
      sel('Hosting','fHosting',[['diy','Do it yourself'],['managed','Managed']],a.hosting)+
      sel('Template','fTemplate',[['template1','Classic'],['template2','Banner']],a.template)+
      sel('Status','fStatus',[['trial','Trial'],['active','Active'],
          ['inactive','Inactive'],['banned','Banned']],a.status)+
      fld('Commission rate %','fRate',a.rate!=null?Number(a.rate).toFixed(1):'10.0')+
      '<label class="chk full"><input type="checkbox" id="fSms"'+
        (+a.sms_opt_in?' checked':'')+'><span>Happy to receive texts</span></label>'+
    '</div>'+
    '<div class="msg" id="fMsg"></div>';

  el('mFoot').innerHTML = '';

  var save = document.createElement('button');
  save.className = 'btn gold';
  save.textContent = isNew ? 'Create' : 'Save';
  save.onclick = function(){ doSave(a.code||''); };
  el('mFoot').appendChild(save);

  var cancel = document.createElement('button');
  cancel.className = 'btn';
  cancel.textContent = 'Cancel';
  cancel.onclick = shut;
  el('mFoot').appendChild(cancel);
}

function fld(label,id,val,type,ro,cls){
  return '<div class="'+(cls||'')+'"><label class="l">'+label+'</label>'+
    '<input class="in" id="'+id+'" type="'+(type||'text')+'" value="'+esc(val||'')+'"'+
    (ro?' readonly':'')+'></div>';
}
function sel(label,id,opts,val){
  return '<div><label class="l">'+label+'</label><select class="in" id="'+id+'">'+
    opts.map(function(o){
      return '<option value="'+o[0]+'"'+(val===o[0]?' selected':'')+'>'+o[1]+'</option>';
    }).join('')+'</select></div>';
}

function doSave(code){
  var payload = {
    code: code,
    first_name: v('fFirst'), last_name: v('fLast'), store_name: v('fStore'),
    email: v('fEmail'), phone: v('fPhone'),
    street: v('fStreet'), city: v('fCity'), state: v('fState'),
    zip: v('fZip'), country: v('fCountry'),
    hosting: v('fHosting'), template: v('fTemplate'), status: v('fStatus'),
    rate: parseFloat(v('fRate'))||0,
    sms_opt_in: el('fSms').checked
  };
  if(v('fPass')) payload.password = v('fPass');

  api('save', payload).then(function(d){
    toast(d.created ? ('Created ' + d.code) : 'Saved');
    shut();
    load();
  }).catch(function(e){
    var m = el('fMsg');
    m.textContent = e.message;
    m.className = 'msg bad';
  });
}

function shut(){ el('modal').classList.remove('on'); }

/* codes are data, never built into an onclick */
document.addEventListener('click', function(ev){
  var o = ev.target.closest('[data-open]');
  if(o){ open(o.getAttribute('data-open')); return; }

  var e2 = ev.target.closest('[data-edit]');
  if(e2){ editAffiliate(e2.getAttribute('data-edit')); return; }

  var d = ev.target.closest('[data-del]');
  if(d){
    var c = d.getAttribute('data-del');
    if(!confirm('Delete '+c+'?\n\nThe record and the photographs go with it. '+
                'This cannot be undone.')) return;
    api('delete',{code:c}).then(function(){ toast(c+' deleted'); load(); })
      .catch(function(err){ toast(err.message,true); });
    return;
  }

  if(ev.target.id === 'modal') shut();
});

document.addEventListener('change', function(ev){
  var s = ev.target.closest('[data-status]');
  if(s){
    api('save',{code:s.getAttribute('data-status'), status:s.value})
      .then(function(){ toast('Status changed'); load(); })
      .catch(function(e){ toast(e.message,true); });
    return;
  }
  var r = ev.target.closest('[data-rate]');
  if(r){
    api('rate',{code:r.getAttribute('data-rate'), percent:parseFloat(r.value)||0})
      .then(function(d){ toast('Rate set to '+Number(d.percent).toFixed(1)+'%'); })
      .catch(function(e){ toast(e.message,true); });
  }
});

document.addEventListener('keydown', function(e){ if(e.key==='Escape') shut(); });

load();
</script>
</body>
</html>
