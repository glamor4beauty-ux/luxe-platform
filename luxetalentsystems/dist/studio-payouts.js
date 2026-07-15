/* ═══════════════════════════════════════════════════════════════════════════
   studio-payouts.js — Studio Payouts admin table (Clip4Sale-style external JS)
   Builds the whole table into #aspRoot. Triggered by aspLoad() on tab open
   (dashboard.html line ~3412: if (tab==='studio-payouts') aspLoad()).

   Columns: Performer · Description · Date From · Date To · Amount Earned ·
            Commission % · Payout · Date Paid   + Totals row.
   Payout = Amount Earned − (Amount Earned × Commission% / 100).
   API: /api/studio-payouts-admin.php  (list / save / delete)
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  var API = '/api/studio-payouts-admin.php';
  var ROWS = [];

  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
  function money(n){ var v=Number(n||0),neg=v<0; v=Math.abs(v); return (neg?'-':'')+'$'+v.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,','); }
  function fmtDate(s){ if(!s) return '—'; var d=new Date(s+(String(s).length===10?'T00:00:00':'')); return isNaN(d)?s:d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); }
  function payoutOf(amt,pct){ return Math.round((amt-(amt*pct/100))*100)/100; }

  function shell(){
    return ''
    + '<style>'
    + '#aspRoot .sp-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:14px}'
    + '#aspRoot .sp-bar input,#aspRoot .sp-bar select{background:#1a1f2e;border:1px solid #2a2f3e;border-radius:8px;color:#e8eaf0;font-size:13px;padding:9px 11px;outline:none;font-family:inherit}'
    + '#aspRoot .sp-search{flex:1 1 260px}'
    + '#aspRoot .sp-btn{background:#d4a830;color:#0a0d14;border:none;border-radius:8px;padding:9px 18px;font-size:13px;font-weight:700;cursor:pointer}'
    + '#aspRoot .sp-btn.ghost{background:transparent;border:1px solid #2a2f3e;color:#e8eaf0;font-weight:600}'
    + '#aspRoot table{width:100%;border-collapse:collapse;background:#0f1218;border:1px solid #1e2330;border-radius:10px;overflow:hidden}'
    + '#aspRoot thead th{background:#151a24;color:#8a8fa8;font-size:11px;text-transform:uppercase;letter-spacing:.05em;text-align:left;padding:11px 12px;font-weight:600;white-space:nowrap;cursor:pointer;user-select:none}'
    + '#aspRoot thead th.num{text-align:right}'
    + '#aspRoot tbody td{padding:10px 12px;border-top:1px solid #1e2330;font-size:13px;color:#e8eaf0;vertical-align:middle}'
    + '#aspRoot tbody td.num{text-align:right;font-family:ui-monospace,monospace}'
    + '#aspRoot tbody tr:hover{background:#12161f}'
    + '#aspRoot .sp-pay{color:#16a34a;font-weight:700}'
    + '#aspRoot tfoot td{padding:12px;border-top:2px solid #2a2f3e;font-weight:700;color:#e8eaf0;font-size:13px;font-family:ui-monospace,monospace}'
    + '#aspRoot tfoot td.lbl{font-family:inherit;color:#8a8fa8;text-transform:uppercase;font-size:11px;letter-spacing:.05em}'
    + '#aspRoot input.cell{width:100%;background:#1a1f2e;border:1px solid #2a2f3e;border-radius:6px;color:#e8eaf0;font-size:13px;padding:7px 8px;outline:none;font-family:inherit}'
    + '#aspRoot input.cell.num{text-align:right;font-family:ui-monospace,monospace}'
    + '#aspRoot .sp-row-actions button{background:transparent;border:1px solid #2a2f3e;color:#e8eaf0;border-radius:5px;padding:5px 10px;font-size:11px;cursor:pointer;margin-left:4px}'
    + '#aspRoot .sp-row-actions button.del{border-color:#ef4444;color:#ef4444}'
    + '#aspRoot .sp-row-actions button.save{border-color:#16a34a;color:#16a34a}'
    + '</style>'
    + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">'
    +   '<span style="display:flex;align-items:center;gap:8px;font-size:18px;font-weight:700;color:#e8eaf0"><span style="font-size:22px">\uD83D\uDCB0</span> Studio Payouts</span>'
    +   '<button class="sp-btn" id="aspAddBtn">+ Add Payout</button>'
    + '</div>'
    + '<div class="sp-bar">'
    +   '<input class="sp-search" id="aspSearch" type="text" placeholder="Search performer or description\u2026">'
    +   '<select id="aspSort">'
    +     '<option value="payout_date">Sort: Date Paid</option>'
    +     '<option value="performer_name">Sort: Performer</option>'
    +     '<option value="amount">Sort: Amount Earned</option>'
    +     '<option value="commission_pct">Sort: Commission %</option>'
    +     '<option value="period_start">Sort: Date From</option>'
    +   '</select>'
    +   '<select id="aspDir"><option value="desc">\u2193 Desc</option><option value="asc">\u2191 Asc</option></select>'
    +   '<button class="sp-btn ghost" id="aspRefresh">Refresh</button>'
    + '</div>'
    + '<table>'
    +   '<thead><tr>'
    +     '<th data-sort="performer_name">Performer</th>'
    +     '<th data-sort="work_performed">Description</th>'
    +     '<th data-sort="period_start">Date From</th>'
    +     '<th data-sort="period_end">Date To</th>'
    +     '<th class="num" data-sort="amount">Amount Earned</th>'
    +     '<th class="num" data-sort="commission_pct">Commission %</th>'
    +     '<th class="num">Payout</th>'
    +     '<th data-sort="payout_date">Date Paid</th>'
    +     '<th></th>'
    +   '</tr></thead>'
    +   '<tbody id="aspBody"><tr><td colspan="9" style="padding:24px;text-align:center;color:#8a8fa8">Loading\u2026</td></tr></tbody>'
    +   '<tfoot><tr>'
    +     '<td class="lbl" colspan="4">Totals</td>'
    +     '<td class="num" id="aspTotEarned">$0.00</td>'
    +     '<td class="num" id="aspTotComm">$0.00</td>'
    +     '<td class="num sp-pay" id="aspTotPayout">$0.00</td>'
    +     '<td colspan="2"></td>'
    +   '</tr></tfoot>'
    + '</table>';
  }

  function rowHtml(p){
    return '<tr data-id="'+p.id+'">'
      + '<td><div style="font-weight:600">'+esc(p.performer_name||'\u2014')+'</div>'+(p.performer_email?'<div style="font-size:11px;color:#8a8fa8">'+esc(p.performer_email)+'</div>':'')+'</td>'
      + '<td>'+esc(p.work_performed||'\u2014')+'</td>'
      + '<td>'+fmtDate(p.period_start)+'</td>'
      + '<td>'+fmtDate(p.period_end)+'</td>'
      + '<td class="num">'+money(p.amount)+'</td>'
      + '<td class="num">'+Number(p.commission_pct||0).toFixed(2)+'%</td>'
      + '<td class="num sp-pay">'+money(p.payout)+'</td>'
      + '<td>'+fmtDate(p.payout_date)+'</td>'
      + '<td class="sp-row-actions" style="text-align:right;white-space:nowrap"><button data-edit="'+p.id+'">Edit</button><button class="del" data-del="'+p.id+'">Delete</button></td>'
      + '</tr>';
  }

  function editRowHtml(p){
    p=p||{id:0,performer_name:'',performer_email:'',work_performed:'',period_start:'',period_end:'',amount:'',commission_pct:'',payout_date:''};
    return '<tr data-id="'+(p.id||0)+'" data-edit="1">'
      + '<td><input class="cell f-name" placeholder="Performer name" value="'+esc(p.performer_name)+'"><input class="cell f-email" placeholder="email (optional)" value="'+esc(p.performer_email||'')+'" style="margin-top:4px;font-size:11px"></td>'
      + '<td><input class="cell f-desc" placeholder="Description" value="'+esc(p.work_performed)+'"></td>'
      + '<td><input class="cell f-from" type="date" value="'+esc(p.period_start||'')+'"></td>'
      + '<td><input class="cell f-to" type="date" value="'+esc(p.period_end||'')+'"></td>'
      + '<td><input class="cell num f-amt" type="number" step="0.01" placeholder="0.00" value="'+esc(p.amount)+'"></td>'
      + '<td><input class="cell num f-pct" type="number" step="0.01" min="0" max="100" placeholder="0" value="'+esc(p.commission_pct)+'"></td>'
      + '<td class="num sp-pay f-payout">$0.00</td>'
      + '<td><input class="cell f-paid" type="date" value="'+esc(p.payout_date||'')+'"></td>'
      + '<td class="sp-row-actions" style="text-align:right;white-space:nowrap"><button class="save" data-save="1">Save</button><button data-cancel="1">Cancel</button></td>'
      + '</tr>';
  }

  function $(id){ return document.getElementById(id); }
  function recalc(tr){
    var amt=parseFloat(tr.querySelector('.f-amt').value)||0;
    var pct=parseFloat(tr.querySelector('.f-pct').value)||0;
    tr.querySelector('.f-payout').textContent=money(payoutOf(amt,pct));
  }
  function bindRecalc(tr){
    ['.f-amt','.f-pct'].forEach(function(s){ var el=tr.querySelector(s); if(el) el.addEventListener('input',function(){recalc(tr);}); });
    recalc(tr);
  }

  function fetchList(){
    var body=$('aspBody'); if(!body) return;
    var q=encodeURIComponent($('aspSearch').value.trim());
    var sort=$('aspSort').value, dir=$('aspDir').value;
    body.innerHTML='<tr><td colspan="9" style="padding:24px;text-align:center;color:#8a8fa8">Loading\u2026</td></tr>';
    fetch(API+'?action=list&q='+q+'&sort='+sort+'&dir='+dir,{credentials:'include'})
      .then(function(r){return r.json();})
      .then(function(d){
        if(!d||!d.ok){ body.innerHTML='<tr><td colspan="9" style="padding:24px;text-align:center;color:#f87171">'+esc((d&&d.error)||'Failed to load')+'</td></tr>'; return; }
        ROWS=d.rows||[];
        body.innerHTML = ROWS.length ? ROWS.map(rowHtml).join('')
          : '<tr><td colspan="9" style="padding:28px;text-align:center;color:#8a8fa8">No payouts yet. Click \u201C+ Add Payout\u201D.</td></tr>';
        var t=d.totals||{};
        $('aspTotEarned').textContent=money(t.earned||0);
        $('aspTotComm').textContent=money(t.commission||0);
        $('aspTotPayout').textContent=money(t.payout||0);
      })
      .catch(function(e){ body.innerHTML='<tr><td colspan="9" style="padding:24px;text-align:center;color:#f87171">Error: '+esc(e.message)+'</td></tr>'; });
  }

  function addNew(){
    var body=$('aspBody'); if(!body) return;
    if(body.querySelector('td[colspan]')) body.innerHTML='';
    var tmp=document.createElement('tbody'); tmp.innerHTML=editRowHtml(null);
    var tr=tmp.firstChild; body.insertBefore(tr,body.firstChild); bindRecalc(tr);
  }
  function editRow(id){
    var tr=document.querySelector('#aspBody tr[data-id="'+id+'"]');
    var p=ROWS.filter(function(x){return String(x.id)===String(id);})[0];
    if(!tr||!p) return;
    var tmp=document.createElement('tbody'); tmp.innerHTML=editRowHtml(p);
    var nt=tmp.firstChild; tr.replaceWith(nt); bindRecalc(nt);
  }
  function saveRow(tr){
    var row={
      id:parseInt(tr.getAttribute('data-id'),10)||0,
      performer_name:tr.querySelector('.f-name').value.trim(),
      performer_email:tr.querySelector('.f-email').value.trim(),
      work_performed:tr.querySelector('.f-desc').value.trim(),
      period_start:tr.querySelector('.f-from').value,
      period_end:tr.querySelector('.f-to').value,
      amount:parseFloat(tr.querySelector('.f-amt').value)||0,
      commission_pct:parseFloat(tr.querySelector('.f-pct').value)||0,
      payout_date:tr.querySelector('.f-paid').value
    };
    if(!row.performer_name && !row.performer_email){ alert('Enter a performer name'); return; }
    var btn=tr.querySelector('[data-save]'); if(btn) btn.disabled=true;
    fetch(API+'?action=save',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify(row)})
      .then(function(r){return r.json();})
      .then(function(d){ if(d&&d.ok){ fetchList(); } else { alert('Save failed: '+((d&&d.error)||'?')); if(btn) btn.disabled=false; } })
      .catch(function(e){ alert('Save error: '+e.message); if(btn) btn.disabled=false; });
  }
  function delRow(id){
    if(!confirm('Delete this payout?')) return;
    fetch(API+'?action=delete',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:id})})
      .then(function(r){return r.json();}).then(function(){fetchList();}).catch(function(){fetchList();});
  }

  function build(){
    var root=$('aspRoot'); if(!root) return;
    if(!root.getAttribute('data-built')){
      root.innerHTML=shell();
      root.setAttribute('data-built','1');
      // Wire controls (event delegation on the body for row buttons)
      $('aspAddBtn').addEventListener('click',addNew);
      $('aspRefresh').addEventListener('click',fetchList);
      $('aspSearch').addEventListener('input',function(){clearTimeout(window.__aspT);window.__aspT=setTimeout(fetchList,300);});
      $('aspSort').addEventListener('change',fetchList);
      $('aspDir').addEventListener('change',fetchList);
      root.querySelectorAll('thead th[data-sort]').forEach(function(th){
        th.addEventListener('click',function(){ $('aspSort').value=th.getAttribute('data-sort'); fetchList(); });
      });
      $('aspBody').addEventListener('click',function(ev){
        var t=ev.target;
        if(t.hasAttribute('data-edit'))   editRow(t.getAttribute('data-edit'));
        else if(t.hasAttribute('data-del')) delRow(t.getAttribute('data-del'));
        else if(t.hasAttribute('data-save')) saveRow(t.closest('tr'));
        else if(t.hasAttribute('data-cancel')) fetchList();
      });
    }
    fetchList();
  }

  // Hook called by the dashboard tab switcher.
  window.aspLoad = build;
  // If the tab is already visible at load, build now.
  if (document.getElementById('aspRoot')) {
    if (document.readyState==='loading') document.addEventListener('DOMContentLoaded', function(){ if(document.getElementById('tab-studio-payouts').classList.contains('active')) build(); });
  }
})();
