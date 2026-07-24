/* Clip4Sale — v6.
   Data model: `clip4sale` table (store_id + performer_id + stage_name) is the key table.
   Search Performer + all right-side tables key off it. Revenue = independent query (performer + month).
   CSP-safe: inline styles only, no <style> injection, no eval. Event delegation only. */
(function(){
  "use strict";
  var API = 'https://luxetalentsystems.com/api/clip4sale.php';

  function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function money(n){ n = parseFloat(n)||0; return '$'+n.toFixed(2); }
  function nowMonth(){ var d=new Date(); return d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2); }

  var S = {
    card:'background:#131720;border:1px solid #1e2330;border-radius:12px;padding:16px',
    h:'font-size:15px;font-weight:700;color:#e8eaf0;margin:0 0 12px',
    tblwrapC:'overflow-y:auto;overflow-x:hidden;max-height:190px;border:1px solid #1e2330;border-radius:10px;background:#0e1117',
    thC:'padding:8px 6px;text-align:left;font-size:10px;color:#d4a830;font-weight:700;text-transform:uppercase;letter-spacing:.3px;border-bottom:1px solid #1e2330;position:sticky;top:0;background:#0e1117',
    thCS:'padding:8px 6px;text-align:left;font-size:10px;color:#d4a830;font-weight:700;text-transform:uppercase;letter-spacing:.3px;border-bottom:1px solid #1e2330;position:sticky;top:0;background:#0e1117;cursor:pointer;user-select:none',
    tdC:'padding:7px 6px;font-size:12px;color:#e8eaf0;border-bottom:1px solid #161b27;white-space:normal;word-break:break-word',
    btnGold:'padding:7px 14px;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;background:#d4a830;color:#0e1117',
    btnGhost:'padding:7px 14px;border:1px solid #2a2f3e;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;background:transparent;color:#8a8fa8',
    btnDel:'padding:3px 9px;border:1px solid #4a2020;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;background:transparent;color:#e05555',
    inp:'background:#0e1117;border:1px solid #2a2f3e;border-radius:8px;padding:7px 10px;color:#e8eaf0;font-size:13px;min-width:0',
    sel:'background:#0e1117;border:1px solid #2a2f3e;border-radius:8px;padding:7px 10px;color:#e8eaf0;font-size:13px;cursor:pointer',
    form:'background:#0e1117;border:1px solid #2a2f3e;border-radius:10px;padding:12px;margin-bottom:12px;display:none;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px',
    lbl:'color:#8a8fa8;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px'
  };

  var ST = { perf:'', start:'', end:'',
             docsQ:'', docsSort:'performer_id', docsDir:1,
             c4s:[], docs:[] };

  /* options built from the clip4sale table */
  function c4sOptions(sel, q){
    q = String(q||'').toLowerCase();
    var h = '<option value="">All Performers</option>';
    for (var i=0;i<ST.c4s.length;i++){
      var p = ST.c4s[i];
      var lbl = (p.stage_name||'')+' (#'+p.performer_id+') \u2014 store '+p.store_id;
      if (q && lbl.toLowerCase().indexOf(q) === -1) continue;
      h += '<option value="'+p.performer_id+'"'+(String(sel)===String(p.performer_id)?' selected':'')+'>'+esc(lbl)+'</option>';
    }
    return h;
  }

  function api(action, params){
    var q = ''; params = params||{};
    for (var k in params){ if (params[k]!=='' && params[k]!=null) q += '&'+k+'='+encodeURIComponent(params[k]); }
    return fetch(API+'?action='+action+q).then(function(r){ return r.json(); });
  }
  function apiPost(action, body){
    return fetch(API+'?action='+action, { method:'POST',
      headers:{'Content-Type':'application/json'}, body:JSON.stringify(body||{})
    }).then(function(r){ return r.json(); });
  }

  /* ═══════════ SHELL ═══════════ */
  function render(root, scopeName){
    if(!root) return;
    var wide = window.innerWidth >= 980;
    var gridCols = wide ? 'minmax(0,4fr) minmax(0,6fr)' : '1fr';

    /* LEFT 2 — Revenue: totals for the current selection (performer + date range) */
    var revenue = '<div style="'+S.card+';margin-bottom:16px">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px">'
      + '<div style="'+S.h+';margin:0">Revenue</div>'
      + '<span id="c4sRevScope" style="color:#8a8fa8;font-size:12px"></span></div>'
      + '<div id="c4sRevRows"></div></div>';

    /* LEFT 2 — Search Performer (from clip4sale table) + date range */
    var c4sAddForm = '<div id="c4sAddForm" style="'+S.form+';margin-top:12px;margin-bottom:0">'
      + '<input id="c4sA_store" placeholder="Store ID" value="492439" style="'+S.inp+'">'
      + '<input id="c4sA_perf" placeholder="Performer ID (C4S)" style="'+S.inp+'">'
      + '<button data-act="c4s-save" style="'+S.btnGold+'">Save</button>'
      + '<button data-act="cancel" data-form="c4sAddForm" style="'+S.btnGhost+'">Cancel</button></div>';
    var searchPerf = '<div style="'+S.card+';margin-bottom:16px">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">'
      + '<div style="'+S.h+';margin:0">Search Performer</div>'
      + '<button data-act="c4s-toggle" style="'+S.btnGold+'">+ Add to C4S</button></div>'
      + '<input id="c4sPerfQ" placeholder="Type to search performers&hellip;" style="'+S.inp+';width:100%;box-sizing:border-box;margin-bottom:8px">'
      + '<select id="c4sPerfSel" style="'+S.sel+';width:100%;box-sizing:border-box">'+c4sOptions(ST.perf)+'</select>'
      + '<div style="margin-top:12px"><div style="'+S.lbl+';margin-bottom:6px">Search Date Range</div>'
      + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
      + '<input type="date" id="c4sDateStart" value="'+esc(ST.start)+'" style="'+S.inp+';flex:1">'
      + '<span style="color:#8a8fa8">&ndash;</span>'
      + '<input type="date" id="c4sDateEnd" value="'+esc(ST.end)+'" style="'+S.inp+';flex:1">'
      + '<button data-act="range-clear" style="'+S.btnGhost+'">Clear</button></div></div>'
      + c4sAddForm + '</div>';

    /* LEFT 3 — My Stores */
    var stores = '<div style="'+S.card+'">'
      + '<div style="'+S.h+'">My Stores</div>'
      + '<div style="background:#0e1117;border:1px solid #1e2330;border-radius:10px;padding:14px;display:flex;align-items:center;gap:12px">'
      + '<div style="width:48px;height:48px;border-radius:8px;background:#3a1a1a;display:flex;align-items:center;justify-content:center;font-size:10px;color:#d4a830;font-weight:700">RAL</div>'
      + '<div style="flex:1"><div style="color:#e8eaf0;font-weight:700;font-size:15px">Real Amateur Lust</div>'
      + '<div style="color:#8a8fa8;font-size:12px">Store ID: 492439</div>'
      + '<div style="color:#8a8fa8;font-size:12px"><span id="c4sClipCount">&mdash; clips</span> &bull; <span style="color:#16a34a">Open</span></div></div>'
      + '<span style="color:#8a8fa8;font-size:18px">&#8250;</span></div></div>';

    /* RIGHT 1 — Track Sales */
    var tsForm = '<div id="c4sTsForm" style="'+S.form+'">'
      + '<input id="tsF_date" type="date" style="'+S.inp+'">'
      + '<input id="tsF_income" type="number" step="0.01" placeholder="Income" style="'+S.inp+'">'
      + '<input id="tsF_etp" type="number" step="0.01" placeholder="ETP" style="'+S.inp+'">'
      + '<input id="tsF_amd" type="number" step="0.01" placeholder="AMD" style="'+S.inp+'">'
      + '<input id="tsF_pv" type="number" placeholder="Page Views" style="'+S.inp+'">'
      + '<input id="tsF_sold" type="number" placeholder="Clips Sold" style="'+S.inp+'">'
      + '<input id="tsF_promo" type="number" step="0.01" placeholder="Promo Sales" style="'+S.inp+'">'
      + '<input id="tsF_perf" placeholder="Performer ID or stage name" style="'+S.inp+'">'
      + '<button data-act="ts-save" style="'+S.btnGold+'">Save</button>'
      + '<button data-act="cancel" data-form="c4sTsForm" style="'+S.btnGhost+'">Cancel</button></div>';
    var tsUp = '<div id="c4sTsUp" style="'+S.form+'">'
      + '<input id="tsU_file" type="file" accept=".csv,text/csv" style="'+S.inp+';grid-column:1/-1">'
      + '<input id="tsU_perf" placeholder="Performer ID or stage name" style="'+S.inp+'">'
      + '<button data-act="ts-import" style="'+S.btnGold+'">Upload CSV</button>'
      + '<button data-act="cancel" data-form="c4sTsUp" style="'+S.btnGhost+'">Cancel</button>'
      + '<span id="tsU_msg" style="color:#8a8fa8;font-size:12px;align-self:center"></span></div>';
    var trackSales = '<div style="'+S.card+';margin-bottom:16px">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">'
      + '<div style="'+S.h+';margin:0">Track Sales</div>'
      + '<div style="display:flex;gap:8px"><button data-act="ts-upload" style="'+S.btnGhost+'">Upload File</button>'
      + '<button data-act="ts-toggle" style="'+S.btnGold+'">+ Manual Entry</button></div></div>'
      + tsForm + tsUp
      + '<div style="'+S.tblwrapC+'"><table style="border-collapse:collapse;width:100%;table-layout:auto"><thead><tr>'
      + '<th style="'+S.thC+'">Date</th><th style="'+S.thC+'">Income</th><th style="'+S.thC+'">ETP</th><th style="'+S.thC+'">AMD</th>'
      + '<th style="'+S.thC+'">Page Views</th><th style="'+S.thC+'">Clip Sold</th><th style="'+S.thC+'">Promo Sales</th><th style="'+S.thC+'"></th>'
      + '</tr></thead><tbody id="c4sTsBody"></tbody></table></div></div>';

    /* RIGHT 2 — Content Management */
    var cmForm = '<div id="c4sCmForm" style="'+S.form+'">'
      + '<input id="cmF_file" placeholder="File Name" style="'+S.inp+'">'
      + '<input id="cmF_cat" placeholder="Category" style="'+S.inp+'">'
      + '<input id="cmF_type" placeholder="Type" style="'+S.inp+'">'
      + '<select id="cmF_vis" style="'+S.sel+'"><option>Public</option><option>Members</option><option>Private</option></select>'
      + '<input id="cmF_perf" placeholder="Performer ID or stage name" style="'+S.inp+'">'
      + '<input id="cmF_price" type="number" step="0.01" placeholder="Price" style="'+S.inp+'">'
      + '<input id="cmF_date" type="date" style="'+S.inp+'">'
      + '<button data-act="cm-save" style="'+S.btnGold+'">Save</button>'
      + '<button data-act="cancel" data-form="c4sCmForm" style="'+S.btnGhost+'">Cancel</button></div>';
    var cmUp = '<div id="c4sCmUp" style="'+S.form+'">'
      + '<input id="cmU_file" type="file" accept=".csv,text/csv" style="'+S.inp+';grid-column:1/-1">'
      + '<input id="cmU_perf" placeholder="Performer ID or stage name" style="'+S.inp+'">'
      + '<button data-act="cm-import" style="'+S.btnGold+'">Upload CSV</button>'
      + '<button data-act="cancel" data-form="c4sCmUp" style="'+S.btnGhost+'">Cancel</button>'
      + '<span id="cmU_msg" style="color:#8a8fa8;font-size:12px;align-self:center"></span></div>';
    var contentMgmt = '<div style="'+S.card+';margin-bottom:16px">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">'
      + '<div style="'+S.h+';margin:0">Content Management</div>'
      + '<div style="display:flex;gap:8px"><button data-act="cm-upload" style="'+S.btnGhost+'">Upload File</button>'
      + '<button data-act="cm-toggle" style="'+S.btnGold+'">+ Manual Entry</button></div></div>'
      + cmForm + cmUp
      + '<div style="'+S.tblwrapC+'"><table style="border-collapse:collapse;width:100%;table-layout:auto"><thead><tr>'
      + '<th style="'+S.thC+'">File Name</th><th style="'+S.thC+'">Category</th><th style="'+S.thC+'">Type</th><th style="'+S.thC+'">Visibility</th>'
      + '<th style="'+S.thC+'">Performers</th><th style="'+S.thC+'">Price</th><th style="'+S.thC+'">Publish Date</th><th style="'+S.thC+'"></th>'
      + '</tr></thead><tbody id="c4sCmBody"></tbody></table></div></div>';

    /* RIGHT 3 — Performers Documents */
    var docsForm = '<div id="c4sDocForm" style="'+S.form+'">'
      + '<input id="docF_perf" placeholder="Performer ID or stage name" style="'+S.inp+'">'
      + '<input id="docF_file" placeholder="File Name" style="'+S.inp+'">'
      + '<input id="docF_type" placeholder="Type" style="'+S.inp+'">'
      + '<select id="docF_status" style="'+S.sel+'"><option>Active</option><option>Pending</option><option>Expired</option></select>'
      + '<label style="display:flex;align-items:center;gap:6px;color:#e8eaf0;font-size:13px"><input id="docF_def" type="checkbox"> Default</label>'
      + '<button data-act="doc-save" style="'+S.btnGold+'">Save</button>'
      + '<button data-act="cancel" data-form="c4sDocForm" style="'+S.btnGhost+'">Cancel</button></div>';
    function arrow(k){ return ST.docsSort===k ? (ST.docsDir===1?' \u25B2':' \u25BC') : ''; }
    var perfDocs = '<div style="'+S.card+'">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">'
      + '<div style="'+S.h+';margin:0">Performers Documents</div>'
      + '<div style="display:flex;gap:8px;flex-wrap:wrap">'
      + '<input id="c4sDocQ" placeholder="Search documents&hellip;" value="'+esc(ST.docsQ)+'" style="'+S.inp+';width:180px">'
      + '<button data-act="doc-toggle" style="'+S.btnGold+'">+ Manual Entry</button></div></div>'
      + docsForm
      + '<div style="'+S.tblwrapC+'"><table style="border-collapse:collapse;width:100%;table-layout:auto"><thead><tr>'
      + '<th data-sort="performer_id" style="'+S.thCS+'">ID'+arrow('performer_id')+'</th>'
      + '<th data-sort="stage_name" style="'+S.thCS+'">Stage Name'+arrow('stage_name')+'</th>'
      + '<th style="'+S.thC+'">File Name</th>'
      + '<th data-sort="doc_type" style="'+S.thCS+'">Type'+arrow('doc_type')+'</th>'
      + '<th data-sort="status" style="'+S.thCS+'">Status'+arrow('status')+'</th>'
      + '<th style="'+S.thC+'">Default</th><th style="'+S.thC+'"></th>'
      + '</tr></thead><tbody id="c4sDocBody"></tbody></table></div></div>';

    var header = '<h2 style="margin:0 0 16px;color:#e8eaf0;font-size:20px">Dashboard'+(scopeName?(' &mdash; '+esc(scopeName)):'')+'</h2>';
    root.innerHTML = header
      + '<div style="display:grid;grid-template-columns:'+gridCols+';gap:16px;align-items:start">'
      + '<div>'+searchPerf+revenue+stores+'</div>'
      + '<div>'+trackSales+contentMgmt+perfDocs+'</div></div>';

    wire(root);
    loadAll();
  }

  /* ═══════════ LOADERS ═══════════ */
  function loadAll(){
    api('c4s_list').then(function(d){
      ST.c4s = (d && d.rows) || [];
      var sel = document.getElementById('c4sPerfSel');
      if (sel){ var q = (document.getElementById('c4sPerfQ')||{}).value || ''; sel.innerHTML = c4sOptions(ST.perf, q); }
    }).catch(function(){});
    loadRevenue(); loadTS(); loadCM(); loadDocs(); loadClipCount();
  }
  function loadClipCount(){
    api('clips_list').then(function(d){
      var n=(d&&d.clips&&d.clips.length)||0; var el=document.getElementById('c4sClipCount'); if(el) el.textContent=n+' clips';
    }).catch(function(){ var el=document.getElementById('c4sClipCount'); if(el) el.textContent='0 clips'; });
  }
  function revRow(label, amt){
    return '<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #161b27">'
      + '<span style="color:#e8eaf0;font-size:14px">'+esc(label)+'</span>'
      + '<span style="color:#e8eaf0;font-weight:700">'+esc(amt)+'</span></div>';
  }
  function revScopeLabel(){
    var who = 'All Performers';
    for (var i=0;i<ST.c4s.length;i++){ if (String(ST.c4s[i].performer_id)===String(ST.perf)){ who = ST.c4s[i].stage_name+' (#'+ST.perf+')'; break; } }
    var when = (ST.start||ST.end) ? ((ST.start||'\u2026')+' \u2013 '+(ST.end||'\u2026')) : 'All Dates';
    return who+' \u00B7 '+when;
  }
  function loadRevenue(){
    var box = document.getElementById('c4sRevRows'); if(!box) return;
    var sc = document.getElementById('c4sRevScope'); if (sc) sc.textContent = revScopeLabel();
    api('revenue', {perf:ST.perf, start:ST.start, end:ST.end}).then(function(d){
      d = d || {};
      box.innerHTML = revRow('Income', money(d.income))
        + revRow('ETP', money(d.etp))
        + revRow('AMD', money(d.amd))
        + revRow('Promo Sales', money(d.promo_sales))
        + revRow('Clips Sold', String(parseInt(d.clips_sold,10)||0))
        + revRow('Page Views', String(parseInt(d.page_views,10)||0));
    }).catch(function(){ box.innerHTML = revRow('Income','$0.00'); });
  }
  function loadTS(){
    var tb = document.getElementById('c4sTsBody'); if(!tb) return;
    api('ts_list', {perf:ST.perf, start:ST.start, end:ST.end}).then(function(d){
      var rows = (d && d.rows) || [], h='';
      for (var i=0;i<rows.length;i++){ var r=rows[i];
        h += '<tr><td style="'+S.tdC+';color:#8a8fa8;font-size:12px">'+esc(r.sale_date)+'</td>'
          + '<td style="'+S.tdC+'">'+money(r.income)+'</td><td style="'+S.tdC+'">'+money(r.etp)+'</td>'
          + '<td style="'+S.tdC+'">'+money(r.amd)+'</td><td style="'+S.tdC+'">'+esc(r.page_views)+'</td>'
          + '<td style="'+S.tdC+'">'+esc(r.clips_sold)+'</td><td style="'+S.tdC+'">'+money(r.promo_sales)+'</td>'
          + '<td style="'+S.tdC+'"><button data-del-ts="'+r.id+'" style="'+S.btnDel+'">&#10005;</button></td></tr>';
      }
      tb.innerHTML = h || '<tr><td colspan="8" style="'+S.tdC+';color:#8a8fa8">No sales &mdash; use + Manual Entry or Upload File.</td></tr>';
    }).catch(function(){ tb.innerHTML='<tr><td colspan="8" style="'+S.tdC+';color:#e05555">Load failed.</td></tr>'; });
  }
  function loadCM(){
    var tb = document.getElementById('c4sCmBody'); if(!tb) return;
    api('cm_list', {perf:ST.perf}).then(function(d){
      var rows = (d && d.rows) || [], h='';
      for (var i=0;i<rows.length;i++){ var r=rows[i];
        h += '<tr><td style="'+S.tdC+'">'+esc(r.file_name)+'</td><td style="'+S.tdC+'">'+esc(r.category)+'</td>'
          + '<td style="'+S.tdC+'">'+esc(r.type)+'</td><td style="'+S.tdC+'">'+esc(r.visibility)+'</td>'
          + '<td style="'+S.tdC+'">'+esc(r.performers)+'</td><td style="'+S.tdC+'">'+money(r.price)+'</td>'
          + '<td style="'+S.tdC+';color:#8a8fa8;font-size:12px">'+esc(r.published_date||'')+'</td>'
          + '<td style="'+S.tdC+'"><button data-del-cm="'+r.id+'" style="'+S.btnDel+'">&#10005;</button></td></tr>';
      }
      tb.innerHTML = h || '<tr><td colspan="8" style="'+S.tdC+';color:#8a8fa8">No content &mdash; use + Manual Entry or Upload File.</td></tr>';
    }).catch(function(){ tb.innerHTML='<tr><td colspan="8" style="'+S.tdC+';color:#e05555">Load failed.</td></tr>'; });
  }
  function loadDocs(){
    var tb = document.getElementById('c4sDocBody'); if(!tb) return;
    api('docs_list', {perf:ST.perf, q:ST.docsQ}).then(function(d){
      ST.docs = (d && d.rows) || [];
      renderDocs();
    }).catch(function(){ tb.innerHTML='<tr><td colspan="7" style="'+S.tdC+';color:#e05555">Load failed.</td></tr>'; });
  }
  function renderDocs(){
    var tb = document.getElementById('c4sDocBody'); if(!tb) return;
    var rows = ST.docs.slice();
    var k = ST.docsSort, dir = ST.docsDir;
    rows.sort(function(a,b){
      var x=a[k], y=b[k];
      if (k==='performer_id'){ x=parseInt(x,10)||0; y=parseInt(y,10)||0; return (x-y)*dir; }
      x=String(x||'').toLowerCase(); y=String(y||'').toLowerCase();
      return (x<y?-1:x>y?1:0)*dir;
    });
    var h='';
    for (var i=0;i<rows.length;i++){ var r=rows[i];
      h += '<tr><td style="'+S.tdC+'">'+esc(r.performer_id)+'</td><td style="'+S.tdC+'">'+esc(r.stage_name)+'</td>'
        + '<td style="'+S.tdC+'">'+esc(r.file_name)+'</td><td style="'+S.tdC+'">'+esc(r.doc_type)+'</td>'
        + '<td style="'+S.tdC+'">'+esc(r.status)+'</td>'
        + '<td style="'+S.tdC+';text-align:center"><input type="checkbox" data-docdef="'+r.id+'"'+((parseInt(r.is_default,10)===1)?' checked':'')+' style="cursor:pointer;width:16px;height:16px"></td>'
        + '<td style="'+S.tdC+'"><button data-del-doc="'+r.id+'" style="'+S.btnDel+'">&#10005;</button></td></tr>';
    }
    tb.innerHTML = h || '<tr><td colspan="7" style="'+S.tdC+';color:#8a8fa8">No documents &mdash; use + Manual Entry.</td></tr>';
  }

  /* ═══════════ EVENTS ═══════════ */
  function toggleForm(id, show){
    var f = document.getElementById(id); if(!f) return;
    if (show === false){ f.style.display = 'none'; return; }
    f.style.display = (f.style.display==='grid') ? 'none' : 'grid';
  }
  function val(id){ var e=document.getElementById(id); return e ? e.value : ''; }
  function chk(id){ var e=document.getElementById(id); return (e && e.checked) ? 1 : 0; }
  function reloadTables(){ loadTS(); loadCM(); loadDocs(); }

  function wire(root){
    if (root.dataset.c4sWired === '1') return;
    root.dataset.c4sWired = '1';

    root.addEventListener('click', function(ev){
      var t = ev.target.closest ? ev.target.closest('[data-act],[data-del-cm],[data-del-ts],[data-del-doc],[data-sort]') : null;
      if (!t) return;
      var act = t.getAttribute('data-act');
      if (act === 'cancel'){ toggleForm(t.getAttribute('data-form'), false); return; }
      if (act === 'ts-toggle') { toggleForm('c4sTsForm'); return; }
      if (act === 'cm-toggle') { toggleForm('c4sCmForm'); return; }
      if (act === 'doc-toggle'){ toggleForm('c4sDocForm'); return; }
      if (act === 'c4s-toggle'){ toggleForm('c4sAddForm'); return; }
      if (act === 'ts-upload'){ toggleForm('c4sTsUp'); return; }
      if (act === 'cm-upload'){ toggleForm('c4sCmUp'); return; }
      if (act === 'range-clear'){
        ST.start=''; ST.end='';
        var a=document.getElementById('c4sDateStart'), b=document.getElementById('c4sDateEnd');
        if(a) a.value=''; if(b) b.value='';
        loadTS(); loadRevenue(); return;
      }
      if (act === 'c4s-save'){
        apiPost('c4s_add', { store_id:val('c4sA_store'), performer_id:val('c4sA_perf') })
          .then(function(d){
            if (d && d.success){ toggleForm('c4sAddForm', false); var p=document.getElementById('c4sA_perf'); if(p) p.value=''; loadAll(); }
            else alert('Add failed: '+((d&&d.error)||'unknown'));
          }).catch(function(){ alert('Add failed (network).'); });
        return;
      }
      if (act === 'ts-save'){
        apiPost('ts_save', { sale_date:val('tsF_date'), income:val('tsF_income'), etp:val('tsF_etp'), amd:val('tsF_amd'),
          page_views:val('tsF_pv'), clips_sold:val('tsF_sold'), promo_sales:val('tsF_promo'), performer:val('tsF_perf')
        }).then(function(d){ if(d&&d.success){ toggleForm('c4sTsForm',false); loadTS(); loadRevenue(); } else alert('Save failed: '+((d&&d.error)||'unknown')); })
          .catch(function(){ alert('Save failed (network).'); });
        return;
      }
      if (act === 'cm-save'){
        apiPost('cm_save', { file_name:val('cmF_file'), category:val('cmF_cat'), type:val('cmF_type'),
          visibility:val('cmF_vis'), performer:val('cmF_perf'), price:val('cmF_price'), published_date:val('cmF_date')
        }).then(function(d){ if(d&&d.success){ toggleForm('c4sCmForm',false); loadCM(); } else alert('Save failed: '+((d&&d.error)||'unknown')); })
          .catch(function(){ alert('Save failed (network).'); });
        return;
      }
      if (act === 'doc-save'){
        apiPost('docs_save', { performer:val('docF_perf'), file_name:val('docF_file'),
          doc_type:val('docF_type'), status:val('docF_status'), is_default:chk('docF_def')
        }).then(function(d){ if(d&&d.success){ toggleForm('c4sDocForm',false); loadDocs(); } else alert('Save failed: '+((d&&d.error)||'unknown')); })
          .catch(function(){ alert('Save failed (network).'); });
        return;
      }
      if (act === 'cm-import' || act === 'ts-import'){
        var isCm = (act === 'cm-import');
        var fEl = document.getElementById(isCm?'cmU_file':'tsU_file');
        var msg = document.getElementById(isCm?'cmU_msg':'tsU_msg');
        var pv  = val(isCm?'cmU_perf':'tsU_perf');
        if (!fEl || !fEl.files || !fEl.files[0]){ if(msg) msg.textContent='Choose a .csv file first.'; return; }
        if (msg) msg.textContent = 'Uploading\u2026';
        var rd = new FileReader();
        rd.onload = function(){
          apiPost(isCm?'cm_import':'ts_import', { csv:String(rd.result||''), perf:pv })
            .then(function(d){
              if (d && d.success){
                if (msg) msg.textContent = 'Imported '+d.imported+' rows.';
                fEl.value = '';
                if (isCm){ loadCM(); } else { loadTS(); loadRevenue(); }
              } else if (msg) msg.textContent = 'Failed: '+((d&&d.error)||'unknown');
            })
            .catch(function(){ if(msg) msg.textContent='Upload failed (network).'; });
        };
        rd.onerror = function(){ if(msg) msg.textContent='Could not read file.'; };
        rd.readAsText(fEl.files[0]);
        return;
      }
      var id = t.getAttribute('data-del-cm');
      if (id){ if(confirm('Delete this content row?')) apiPost('cm_delete',{id:id}).then(loadCM); return; }
      id = t.getAttribute('data-del-ts');
      if (id){ if(confirm('Delete this sales row?')) apiPost('ts_delete',{id:id}).then(function(){ loadTS(); loadRevenue(); }); return; }
      id = t.getAttribute('data-del-doc');
      if (id){ if(confirm('Delete this document?')) apiPost('docs_delete',{id:id}).then(loadDocs); return; }
      var sk = t.getAttribute('data-sort');
      if (sk){
        if (ST.docsSort === sk) ST.docsDir = -ST.docsDir; else { ST.docsSort = sk; ST.docsDir = 1; }
        var hd = t.parentNode.querySelectorAll('[data-sort]');
        for (var i=0;i<hd.length;i++){ var key=hd[i].getAttribute('data-sort');
          hd[i].innerHTML = hd[i].innerHTML.replace(/\s*[\u25B2\u25BC]\s*$/,'')
            + (key===ST.docsSort ? (ST.docsDir===1?' \u25B2':' \u25BC') : '');
        }
        renderDocs(); return;
      }
    });

    root.addEventListener('change', function(ev){
      var t = ev.target;
      if (t.id === 'c4sPerfSel'){ ST.perf = t.value; reloadTables(); loadRevenue(); return; }
      if (t.id === 'c4sDateStart'){ ST.start = t.value; loadTS(); loadRevenue(); return; }
      if (t.id === 'c4sDateEnd'){ ST.end = t.value; loadTS(); loadRevenue(); return; }
      var dd = t.getAttribute && t.getAttribute('data-docdef');
      if (dd){ apiPost('docs_default', {id:dd, is_default:(t.checked?1:0)})
        .catch(function(){ alert('Update failed.'); t.checked = !t.checked; }); return; }
    });

    var qt, pqt;
    root.addEventListener('input', function(ev){
      if (ev.target.id === 'c4sDocQ'){
        clearTimeout(qt); var v = ev.target.value;
        qt = setTimeout(function(){ ST.docsQ = v; loadDocs(); }, 300);
      }
      if (ev.target.id === 'c4sPerfQ'){
        clearTimeout(pqt); var pv = ev.target.value;
        pqt = setTimeout(function(){
          var sel = document.getElementById('c4sPerfSel'); if (!sel) return;
          sel.innerHTML = c4sOptions(ST.perf, pv);
          var opts = sel.querySelectorAll('option');
          if (pv && opts.length === 2){
            var only = opts[1].value;
            if (String(ST.perf) !== String(only)){ sel.value = only; ST.perf = only; reloadTables(); loadRevenue(); }
          }
        }, 250);
      }
    });
  }

  window.c4sLoadAdmin = function(){ render(document.getElementById('c4sAdminRoot'), ''); };
  window.c4sLoadPerformer = function(name){ render(document.getElementById('c4sPerfRoot'), name||''); };

  window.c4sOpenClip   = window.c4sOpenClip   || function(){};
  window.c4sViewSales  = window.c4sViewSales  || function(){};
  window.c4sDeleteClip = window.c4sDeleteClip || function(){};
  window.c4sInlineStatus = window.c4sInlineStatus || function(){};

  var _t; window.addEventListener('resize', function(){ clearTimeout(_t); _t=setTimeout(function(){
    var a=document.getElementById('c4sAdminRoot'); if(a && a.offsetParent!==null){ render(a,''); }
    var p=document.getElementById('c4sPerfRoot'); if(p && p.offsetParent!==null){ render(p,''); }
  },250); });
})();
