<?php
/* ═══════════════════════════════════════════════════════════════════════════
   Customers — the main page.

   Name over email, which is the shape that reads on a phone. Tapping a name
   opens the customer: name and phone, then their orders. The fulfilment
   location is the first line of each order and taps through to that
   location's stock.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/shell.php';

$search = trim((string)($_GET['q'] ?? ''));
$after  = $_GET['after'] ?? null;

$gql = <<<'GQL'
query Customers($n: Int!, $q: String, $after: String) {
  customers(first: $n, query: $q, after: $after, sortKey: UPDATED_AT, reverse: true) {
    edges { node {
      id
      displayName
      firstName
      lastName
      email
      phone
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
], ($search === '' && !$after) ? 'customers' : null);

$rows = [];
$more = false;
$next = null;
if ($res['ok']) {
    foreach ($res['data']['customers']['edges'] ?? [] as $ed) $rows[] = $ed['node'];
    $more = !empty($res['data']['customers']['pageInfo']['hasNextPage']);
    $next = $res['data']['customers']['pageInfo']['endCursor'] ?? null;
}

page_open('Customers', 'index');
?>

<header class="top">
  <div>
    <div class="app"><?= e(APP_NAME) ?><?= page_gear() ?><?php if (!can_write()): ?><span class="readonly">read only</span><?php endif; ?></div>
    <h1>Customers</h1>
  </div>
  <div class="right"><?= count($rows) ?><?= $more ? '+' : '' ?></div>
</header>

<form class="bar" method="get" action="">
  <input type="search" name="q" value="<?= e($search) ?>"
         placeholder="Name, email or phone" autocomplete="off">
  <button type="submit">Find</button>
  <?php if ($search !== ''): ?><a class="clear" href="?">Clear</a><?php endif; ?>
</form>

<?php if (can_write()): ?>
  <div class="wrap" style="padding-bottom:10px">
    <button class="act" type="button" onclick="newCustomer()">+ Add a customer</button>
  </div>
<?php endif; ?>

<div class="wrap">
<?php if (!$res['ok']): problem_block($res);
      elseif (!$rows): ?>
  <div class="empty"><?= $search !== '' ? 'Nobody matches that.' : 'No customers yet.' ?></div>
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
        <div class="sub" id="shPhone"></div>
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
function pillClass(s){
  s=String(s||'').toUpperCase();
  if(s==='FULFILLED'||s==='PAID')return 'ok';
  if(s==='UNFULFILLED'||s==='PENDING'||s==='PARTIALLY_FULFILLED')return 'warn';
  if(s==='REFUNDED'||s==='VOIDED'||s==='CANCELLED')return 'bad';
  return '';
}
function pretty(s){return String(s||'').replace(/_/g,' ').toLowerCase();}

var sheet=document.getElementById('sheet');

function openCustomer(id){
  document.getElementById('shName').textContent='Loading\u2026';
  document.getElementById('shPhone').textContent='';
  document.getElementById('shBody').innerHTML='<div class="load">Loading\u2026</div>';
  sheet.classList.add('on');sheet.setAttribute('aria-hidden','false');
  document.body.style.overflow='hidden';
  document.getElementById('shClose').focus();

  fetch('api.php?action=orders&id='+encodeURIComponent(id),{credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(d){
      if(!d.ok){
        document.getElementById('shName').textContent='Could not load';
        document.getElementById('shBody').innerHTML=
          '<div class="problem"><p>'+esc(d.error)+'</p></div>';
        return;
      }
      /* name and phone only, as asked */
      document.getElementById('shName').textContent=d.customer.name;
      document.getElementById('shPhone').innerHTML = (d.customer.phone
        ? '<a href="tel:'+esc(d.customer.phone)+'">'+esc(d.customer.phone)+'</a>'
        : '<span style="color:#4d5364">no phone on file</span>')
        + ' <button class="act sm" onclick="custForm(\''+esc(id)+'\')">Edit</button>';
      CUR_CUST = d.customer;
      CUR_ID = id;

      if(!d.orders.length){
        document.getElementById('shBody').innerHTML='<div class="empty">No orders yet.</div>';
        return;
      }

      var h='<div class="grp"><h3>Orders</h3>';
      d.orders.forEach(function(o){
        h+='<div class="ord">';
        h+='<div class="ord-top"><div><span class="ord-no">'+esc(o.name)+'</span> '+
           '<span class="ord-date">'+esc(when(o.created))+'</span></div>'+
           '<div class="ord-tot">'+money(o.total,o.cur)+'</div></div>';

        /* the location line comes first inside the order, and taps through */
        if(o.locations.length){
          o.locations.forEach(function(L){
            /* Trendsi fulfils as a service, so a location may have no page of
               its own; send those to the catalogue it came from. */
            var href = /trendsi/i.test(L.name) ? 'catalog.php?v=trendsi' : 'catalog.php?v=shop';
            h+='<a class="loc-line" href="'+href+'">'+
               '<span>'+esc(L.name)+'</span>'+
               '<span class="hint">stock \u203a</span></a>';
          });
        }else{
          h+='<div class="loc-line" style="color:#7b8194;background:none;cursor:default">'+
             '<span>No fulfilment location</span></div>';
        }

        /* where it is going, and how far along */
        if(o.ship_to||o.ship_method||o.tracking.length){
          h+='<div class="ship">';
          if(o.ship_name)  h+='<div class="ship-l"><b>'+esc(o.ship_name)+'</b></div>';
          if(o.ship_to)    h+='<div class="ship-l">'+esc(o.ship_to)+'</div>';
          if(o.ship_phone) h+='<div class="ship-l"><a href="tel:'+esc(o.ship_phone)+'">'+
                              esc(o.ship_phone)+'</a></div>';
          if(o.ship_method)h+='<div class="ship-l" style="color:#7b8194">'+esc(o.ship_method)+
                              (o.ship_cost?' \u00b7 '+money(o.ship_cost,o.cur):'')+'</div>';
          o.tracking.forEach(function(t){
            var label=(t.company?esc(t.company)+' ':'')+(t.number?esc(t.number):'tracking');
            h+= t.url
              ? '<a class="track" href="'+esc(t.url)+'" target="_blank" rel="noopener">'+
                label+' \u2197</a>'
              : '<div class="track">'+label+'</div>';
          });
          h+='</div>';
        }

        o.items.forEach(function(it){
          h+='<div class="li">'+
             (it.image?'<img src="'+esc(it.image)+'" alt="" loading="lazy">':'<div class="noimg">no img</div>')+
             '<div class="t"><b>'+esc(it.title)+'</b>'+
             '<small>'+(it.sku?esc(it.sku):'no sku')+' \u00b7 \u00d7'+it.qty+'</small></div>'+
             '<div class="p">'+money(it.total,it.cur)+'</div></div>';
        });

        if(o.discount && Number(o.discount)>0){
          h+='<div class="li"><div class="t"><b style="color:#4ec97a">Discount</b>'+
             (o.codes.length?'<small>'+esc(o.codes.join(', '))+'</small>':'')+'</div>'+
             '<div class="p" style="color:#4ec97a">\u2212'+money(o.discount,o.cur)+'</div></div>';
        }
        var unshipped = /UNFULFILLED|PARTIALLY/i.test(o.fulfil||'');
        var unpaid    = /PENDING|AUTHORIZED|PARTIALLY_PAID/i.test(o.financial||'');
        var paid      = /^PAID$/i.test(o.financial||'');
        var dead      = /REFUNDED|VOIDED|CANCELLED/i.test(o.financial||'');

        h+='<div class="li acts" style="justify-content:space-between;gap:6px">'+
           '<span class="btns">'+
             (unshipped ? '<button class="act" onclick="fulfilForm(\''+esc(o.id)+'\',\''+
                          esc(o.name)+'\')">Mark shipped</button>' : '')+
             (unpaid ? '<button class="act" onclick="payForm(\''+esc(o.id)+'\',\''+
                       esc(o.name)+'\')">Take payment</button>' : '')+
             (paid ? '<button class="act" onclick="refundForm(\''+esc(o.id)+'\',\''+esc(o.name)+
                     '\','+(+o.total||0)+',\''+esc(o.cur)+'\')">Refund</button>' : '')+
             '<button class="act" onclick="orderForm(\''+esc(o.id)+'\',\''+esc(o.name)+'\')">Edit</button>'+
             (!dead ? '<button class="act danger" onclick="cancelForm(\''+esc(o.id)+'\',\''+
                      esc(o.name)+'\')">Cancel</button>' : '')+
           '</span>'+
           '<span><span class="pill '+pillClass(o.financial)+'">'+esc(pretty(o.financial))+'</span> '+
           '<span class="pill '+pillClass(o.fulfil)+'">'+esc(pretty(o.fulfil))+'</span></span></div>';
        h+='</div>';
      });
      h+='</div>';
      document.getElementById('shBody').innerHTML=h;
    })
    .catch(function(e){
      document.getElementById('shBody').innerHTML=
        '<div class="problem"><p>'+esc(e.message)+'</p></div>';
    });
}

var CUR_CUST=null, CUR_ID=null;

/* Writes change live data with no undo, so each one says what it is about to
   do and reports what actually happened rather than assuming it worked. */
function post(action, body){
  return fetch('write.php?action='+action,{
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify(body)
  }).then(function(r){ return r.json(); });
}

function panel(title, inner, onSave, saveLabel){
  var b=document.getElementById('shBody');
  b.dataset.prev = b.innerHTML;
  b.innerHTML='<div class="form"><h3>'+esc(title)+'</h3>'+inner+
    '<div class="form-msg" id="fMsg"></div>'+
    '<div class="form-b">'+
      '<button class="act ghost" onclick="panelBack()">Cancel</button>'+
      '<button class="act go" id="fGo">'+esc(saveLabel||'Save')+'</button>'+
    '</div></div>';
  document.getElementById('fGo').onclick=onSave;
}
function panelBack(){
  var b=document.getElementById('shBody');
  if(b.dataset.prev){ b.innerHTML=b.dataset.prev; b.dataset.prev=''; }
}
function fmsg(t,cls){
  var m=document.getElementById('fMsg');
  if(m){ m.textContent=t; m.className='form-msg '+(cls||''); }
}
function fld(label,id,val,type,ph){
  return '<label class="fl">'+esc(label)+
    '<input id="'+id+'" type="'+(type||'text')+'" value="'+esc(val==null?'':val)+
    '" placeholder="'+esc(ph||'')+'"></label>';
}
function fval(id){ var e=document.getElementById(id); return e?e.value.trim():''; }

window.fulfilForm=function(orderId, orderName){
  panel('Mark '+orderName+' shipped',
    '<p class="fp">This tells Shopify the items have gone. Tracking is optional, '+
    'but without it the customer has nothing to follow.</p>'+
    fld('Carrier','fCo','','text','USPS, UPS, DHL…')+
    fld('Tracking number','fNum','','text','')+
    fld('Tracking URL','fUrl','','url','optional')+
    '<label class="fc"><input type="checkbox" id="fNotify" checked> '+
    'Email the customer</label>',
    function(){
      var btn=document.getElementById('fGo');
      btn.disabled=true; fmsg('Sending…');
      post('fulfil',{order_id:orderId, tracking_company:fval('fCo'),
        tracking_number:fval('fNum'), tracking_url:fval('fUrl'),
        notify:document.getElementById('fNotify').checked})
        .then(function(d){
          btn.disabled=false;
          if(!d.ok){ fmsg(d.error,'bad'); return; }
          var where=d.fulfilled.map(function(f){return f.where;}).filter(Boolean).join(', ');
          fmsg(d.order+' marked shipped'+(where?' from '+where:'')+
               (d.notified?', customer emailed':'')+'.','ok');
          setTimeout(function(){ openCustomer(CUR_ID); },1200);
        }).catch(function(e){ btn.disabled=false; fmsg(e.message,'bad'); });
    }, 'Mark shipped');
};

window.custForm=function(id){
  var c=CUR_CUST||{};
  var parts=(c.name||'').split(' ');
  panel('Edit customer',
    fld('First name','cFirst',parts[0]||'')+
    fld('Last name','cLast',parts.slice(1).join(' '))+
    fld('Email','cEmail',c.email||'','email')+
    fld('Phone','cPhone',c.phone||'','tel','+1…')+
    fld('Note','cNote',c.note||''),
    function(){
      var btn=document.getElementById('fGo');
      btn.disabled=true; fmsg('Saving…');
      post('customer',{id:id, first_name:fval('cFirst'), last_name:fval('cLast'),
        email:fval('cEmail'), phone:fval('cPhone'), note:fval('cNote')})
        .then(function(d){
          btn.disabled=false;
          if(!d.ok){ fmsg(d.error,'bad'); return; }
          fmsg('Saved.','ok');
          setTimeout(function(){ openCustomer(id); },900);
        }).catch(function(e){ btn.disabled=false; fmsg(e.message,'bad'); });
    });
};

window.newCustomer=function(){
  document.getElementById('shName').textContent='New customer';
  document.getElementById('shPhone').textContent='';
  document.getElementById('shBody').innerHTML='';
  sheet.classList.add('on'); sheet.setAttribute('aria-hidden','false');
  document.body.style.overflow='hidden';
  CUR_ID=null;

  panel('New customer',
    '<p class="fp">An email or a phone number is enough; the rest can wait.</p>'+
    fld('First name','nFirst','')+
    fld('Last name','nLast','')+
    fld('Email','nEmail','','email')+
    fld('Phone','nPhone','','tel')+
    fld('Address','nAddr1','')+
    fld('City','nCity','')+
    fld('State / province','nProv','')+
    fld('Postcode','nZip','')+
    fld('Country','nCountry','')+
    fld('Note','nNote',''),
    function(){
      var b=document.getElementById('fGo');
      if(!fval('nEmail') && !fval('nPhone')){ fmsg('An email or a phone number is needed.','bad'); return; }
      b.disabled=true; fmsg('Creating…');
      post('customer_create',{first_name:fval('nFirst'), last_name:fval('nLast'),
        email:fval('nEmail'), phone:fval('nPhone'), note:fval('nNote'),
        address1:fval('nAddr1'), city:fval('nCity'), province:fval('nProv'),
        zip:fval('nZip'), country:fval('nCountry')})
        .then(function(d){
          b.disabled=false;
          if(!d.ok){ fmsg(d.error,'bad'); return; }
          fmsg('Created.','ok');
          setTimeout(function(){ closeSheet(); location.reload(); },900);
        }).catch(function(e){ b.disabled=false; fmsg(e.message,'bad'); });
    }, 'Create');
};

window.orderForm=function(orderId, orderName){
  panel('Edit '+orderName,
    '<p class="fp">Notes and tags are for you; the customer never sees them. '+
    'Changing the shipping address here does not reprint anything already sent.</p>'+
    fld('Note','oNote','')+
    fld('Tags','oTags','','text','comma separated')+
    fld('Ship to name','oName','')+
    fld('Address','oAddr1','')+
    fld('Address line 2','oAddr2','')+
    fld('City','oCity','')+
    fld('State / province','oProv','')+
    fld('Postcode','oZip','')+
    fld('Country','oCountry',''),
    function(){
      var b=document.getElementById('fGo'); b.disabled=true; fmsg('Saving…');
      var body={id:orderId};
      /* only send what was filled in, so blanks do not wipe what is there */
      if(fval('oNote'))    body.note=fval('oNote');
      if(fval('oTags'))    body.tags=fval('oTags');
      if(fval('oName'))    body.ship_name=fval('oName');
      if(fval('oAddr1'))   body.ship_address1=fval('oAddr1');
      if(fval('oAddr2'))   body.ship_address2=fval('oAddr2');
      if(fval('oCity'))    body.ship_city=fval('oCity');
      if(fval('oProv'))    body.ship_province=fval('oProv');
      if(fval('oZip'))     body.ship_zip=fval('oZip');
      if(fval('oCountry')) body.ship_country=fval('oCountry');
      if(Object.keys(body).length===1){ b.disabled=false; fmsg('Nothing filled in.','bad'); return; }
      post('order',body).then(function(d){
        b.disabled=false;
        if(!d.ok){ fmsg(d.error,'bad'); return; }
        fmsg('Saved.','ok');
        setTimeout(function(){ openCustomer(CUR_ID); },900);
      }).catch(function(e){ b.disabled=false; fmsg(e.message,'bad'); });
    });
};

window.payForm=function(orderId, orderName){
  panel('Payment on '+orderName,
    '<p class="fp"><b>Take payment</b> charges the card that was authorised at checkout. '+
    '<b>Mark as paid</b> only records that money arrived some other way — it moves nothing.</p>'+
    '<div class="form-b" style="justify-content:flex-start;margin:0 0 14px">'+
      '<button class="act go" id="pCap">Take payment</button>'+
      '<button class="act" id="pMark">Mark as paid</button>'+
    '</div>', function(){}, 'Save');
  document.getElementById('fGo').style.display='none';

  document.getElementById('pCap').onclick=function(){
    this.disabled=true; fmsg('Charging…');
    post('order_capture',{id:orderId}).then(function(d){
      if(!d.ok){ fmsg(d.error,'bad'); document.getElementById('pCap').disabled=false; return; }
      fmsg('Payment taken.','ok');
      setTimeout(function(){ openCustomer(CUR_ID); },1100);
    }).catch(function(e){ fmsg(e.message,'bad'); });
  };
  document.getElementById('pMark').onclick=function(){
    this.disabled=true; fmsg('Recording…');
    post('order_paid',{id:orderId}).then(function(d){
      if(!d.ok){ fmsg(d.error,'bad'); document.getElementById('pMark').disabled=false; return; }
      fmsg(d.note||'Marked paid.','ok');
      setTimeout(function(){ openCustomer(CUR_ID); },1100);
    }).catch(function(e){ fmsg(e.message,'bad'); });
  };
};

window.refundForm=function(orderId, orderName, total, cur){
  panel('Refund '+orderName,
    '<p class="fp">This sends money back to the customer and <b>cannot be undone</b>. '+
    'Leave the amount blank to refund everything still outstanding.</p>'+
    fld('Amount','rAmt','','number','full amount')+
    fld('Reason, for your records','rNote','')+
    '<label class="fc"><input type="checkbox" id="rNotify"> Email the customer</label>'+
    fld('Type REFUND to confirm','rConf','','text','REFUND'),
    function(){
      var b=document.getElementById('fGo');
      if(fval('rConf').toUpperCase()!=='REFUND'){ fmsg('Type REFUND to confirm.','bad'); return; }
      b.disabled=true; fmsg('Sending the money back…');
      post('refund',{id:orderId, amount:fval('rAmt'), note:fval('rNote'),
        notify:document.getElementById('rNotify').checked, confirm:fval('rConf')})
        .then(function(d){
          b.disabled=false;
          if(!d.ok){ fmsg(d.error,'bad'); return; }
          fmsg('Refunded.','ok');
          setTimeout(function(){ openCustomer(CUR_ID); },1100);
        }).catch(function(e){ b.disabled=false; fmsg(e.message,'bad'); });
    }, 'Refund');
};

window.cancelForm=function(orderId, orderName){
  panel('Cancel '+orderName,
    '<p class="fp">Cancelling <b>cannot be undone</b>. It does not refund anything unless you '+
    'tick the box below — returning money is a separate decision.</p>'+
    '<label class="fl">Reason<select id="cReason">'+
      '<option value="CUSTOMER">Customer changed their mind</option>'+
      '<option value="INVENTORY">Out of stock</option>'+
      '<option value="FRAUD">Fraud</option>'+
      '<option value="DECLINED">Payment declined</option>'+
      '<option value="STAFF">Our mistake</option>'+
      '<option value="OTHER">Other</option>'+
    '</select></label>'+
    '<label class="fc"><input type="checkbox" id="cRestock" checked> Put the stock back</label>'+
    '<label class="fc"><input type="checkbox" id="cRefund"> Refund the money too</label>'+
    '<label class="fc"><input type="checkbox" id="cNotify"> Email the customer</label>'+
    fld('Type CANCEL to confirm','cConf','','text','CANCEL'),
    function(){
      var b=document.getElementById('fGo');
      if(fval('cConf').toUpperCase()!=='CANCEL'){ fmsg('Type CANCEL to confirm.','bad'); return; }
      b.disabled=true; fmsg('Cancelling…');
      post('order_cancel',{id:orderId, reason:fval('cReason'),
        restock:document.getElementById('cRestock').checked,
        refund:document.getElementById('cRefund').checked,
        notify:document.getElementById('cNotify').checked, confirm:fval('cConf')})
        .then(function(d){
          b.disabled=false;
          if(!d.ok){ fmsg(d.error,'bad'); return; }
          fmsg(d.note||'Cancelled.','ok');
          setTimeout(function(){ openCustomer(CUR_ID); },1600);
        }).catch(function(e){ b.disabled=false; fmsg(e.message,'bad'); });
    }, 'Cancel the order');
};

function closeSheet(){
  sheet.classList.remove('on');sheet.setAttribute('aria-hidden','true');
  document.body.style.overflow='';
}
document.addEventListener('click',function(ev){
  var r=ev.target.closest('.row');
  if(r){openCustomer(r.getAttribute('data-id'));return;}
  if(ev.target.id==='shClose'||ev.target.id==='sheet')closeSheet();
});
document.addEventListener('keydown',function(ev){if(ev.key==='Escape')closeSheet();});
</script>

<?php page_close('index'); ?>
