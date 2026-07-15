/* Clip4Sale v2 — totals on top, sortable inventory, spreadsheet-style sales table.
   Admin: window.c4sLoadAdmin -> renders to #c4sAdminRoot
   Performer (read-only): window.c4sLoadPerformer(name) -> renders to #c4sPerfRoot */
(function(){
  var API = 'https://luxetalentsystems.com/api/clip4sale.php';
  var STATUS_VALUES = ['draft','new','live','sold','paid','inactive','denied'];

  // State (admin)
  var STATE = {
    readOnly: false,
    scopeName: '',
    clips: [],
    sales: [],
    clipSort:  { field: 'created_at', dir: 'desc' },
    saleSort:  { field: 'payment_date', dir: 'desc' },
    saleSearch: '',
    invSearch: '',
    editingSaleId: 0,
    addingSale: false
  };

  function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function money(n){ var v = parseFloat(n)||0; return '$' + v.toFixed(2); }
  function dateOnly(s){ if(!s) return ''; return String(s).split(' ')[0]; }

  function api(action, opts){
    opts = opts || {};
    var qs = '?action=' + action + (opts.qs ? '&' + opts.qs : '');
    var req = { method: opts.method || 'GET', headers: {} };
    if (opts.body){ req.headers['Content-Type'] = 'application/json'; req.body = JSON.stringify(opts.body); }
    return fetch(API + qs, req).then(function(r){ return r.json(); });
  }

  function statusBadge(st){
    var st2 = (st||'new').toLowerCase();
    var bg = {'new':'#3b4252','live':'#16a34a','sold':'#d4a830','paid':'#2563eb','inactive':'#6b7280'}[st2] || '#3b4252';
    return '<span style="display:inline-block;padding:3px 9px;background:'+bg+';color:#fff;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px">'+esc(st2)+'</span>';
  }
  function statusDropdown(currentSt, clipId){
    var opts = STATUS_VALUES.map(function(s){
      return '<option value="'+s+'"'+(s===currentSt?' selected':'')+'>'+s.charAt(0).toUpperCase()+s.slice(1)+'</option>';
    }).join('');
    return '<select onchange="c4sInlineStatus('+clipId+', this.value)" style="background:#0e1117;border:1px solid #2a2f3e;border-radius:6px;padding:5px 8px;color:#e8eaf0;font-size:12px;cursor:pointer">'+opts+'</select>';
  }
  function counterCard(label, value, color){
    var col = color || '#e8eaf0';
    return '<div style="background:#1a1325;border:1px solid #3a2d50;border-radius:8px;padding:10px 14px;text-align:center;min-width:0">'
      + '<div style="font-size:11px;color:'+col+';font-weight:700;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'+esc(label)+'</div>'
      + '<div style="font-size:22px;color:'+col+';font-weight:800;font-variant-numeric:tabular-nums">'+esc(value)+'</div>'
      + '</div>';
  }
  function thSort(label, field, sortObj, onSortName){
    var arrow = (sortObj.field === field) ? (sortObj.dir === 'asc' ? ' ▲' : ' ▼') : '';
    return '<th style="padding:11px 12px;text-align:left;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase;letter-spacing:.5px;cursor:pointer;user-select:none" onclick="'+onSortName+'(\''+field+'\')">'+esc(label)+arrow+'</th>';
  }

  /* ─────────── ADMIN ─────────── */
  window.c4sLoadAdmin = async function(){
    STATE.readOnly = false;
    STATE.scopeName = '';
    await reloadAll(document.getElementById('c4sAdminRoot'), null);
  };
  /* ─────────── PERFORMER ─────────── */
  window.c4sLoadPerformer = async function(performerName){
    STATE.readOnly = true;
    STATE.scopeName = performerName || '';
    await reloadAll(document.getElementById('c4sPerfRoot'), performerName || '');
  };

  async function reloadAll(root, performerFilter){
    if(!root) return;
    root.innerHTML = '<div style="padding:24px;text-align:center;color:#8a8fa8">Loading…</div>';
    try {
      var qs = performerFilter ? ('performer=' + encodeURIComponent(performerFilter)) : '';
      var [stats, clipsRes, salesRes, totals] = await Promise.all([
        api('stats'),
        api('clips_list', { qs: qs }),
        api('sales_list', { qs: (qs ? qs + '&' : '') + (STATE.saleSearch ? 'search=' + encodeURIComponent(STATE.saleSearch) : '') }),
        api('totals', { qs: qs })
      ]);
      STATE.clips = clipsRes.clips || [];
      STATE.sales = salesRes.sales || [];
      render(root, stats, totals);
    } catch(e){
      root.innerHTML = '<div style="padding:24px;color:#f87171">Failed to load: ' + esc(e.message) + '</div>';
    }
  }

  function render(root, stats, totals){
    var scope = STATE.scopeName ? (' — ' + esc(STATE.scopeName)) : '';
    var html = '';

    // Top bar: logo + (admin: search) (left), counter cards (right)
    var cardsHtml;
    if (STATE.readOnly) {
      // Performer view: 4 cards — Live, Total Uploads, Total Sold, Total Payout
      cardsHtml = '<div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px">'
        + counterCard('Live',          stats.live || 0,             '#16a34a')
        + counterCard('Total Uploads', stats.total_uploads || 0,    '#f59e0b')
        + counterCard('Total Sold',    money(totals.total_sold||0), '#16a34a')
        + counterCard('Total Payout',  money(totals.total_payout||0),'#2563eb')
        + '</div>';
    } else {
      // Admin view: 6 cards
      cardsHtml = '<div style="display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px">'
        + counterCard('Live',             stats.live || 0,                   '#16a34a')
        + counterCard('Total Uploads',    stats.total_uploads || 0,          '#f59e0b')
        + counterCard('New Uploads',      stats.new_uploads || 0,            '#f59e0b')
        + counterCard('Total Sold',       money(totals.total_sold||0),       '#16a34a')
        + counterCard('Total Commission', money(totals.total_commission||0), '#f59e0b')
        + counterCard('Total Payout',     money(totals.total_payout||0),     '#2563eb')
        + '</div>';
    }
    var leftPanel = STATE.readOnly
      ? '<div><img src="https://luxetalentsystems.com/clip4-1d.png" alt="Clips4Sale" style="display:block;max-width:100%;height:auto"/></div>'
      : '<div><img src="https://luxetalentsystems.com/clip4-1d.png" alt="Clips4Sale" style="display:block;max-width:100%;height:auto;margin-bottom:10px"/><input id="c4sInvSearch" placeholder="Search inventory…" value="'+esc(STATE.invSearch)+'" oninput="c4sSearchInv(this.value)" style="width:100%;background:#1a1325;border:1px solid #3a2d50;border-radius:8px;padding:8px 12px;color:#e8eaf0;font-size:13px;box-sizing:border-box;outline:none"/></div>';
    html += '<div style="background:#231B30;border:1px solid #2d2340;border-radius:12px;padding:16px 18px;margin-bottom:22px;display:grid;grid-template-columns:minmax(220px,300px) 1fr;gap:22px;align-items:center">'
      + leftPanel
      + cardsHtml
      + '</div>';

    // 3. Video List (inventory) — sortable
    html += renderInventory(scope);

    // 4. Sales/Payouts table — spreadsheet-style
    html += renderSales(scope);

    root.innerHTML = html;
  }

  window.c4sSearchInv = function(v){
    STATE.invSearch = v;
    clearTimeout(window.__c4sInvSearchT);
    window.__c4sInvSearchT = setTimeout(function(){
      // re-render without refetching by re-using current data
      var root = STATE.readOnly ? document.getElementById('c4sPerfRoot') : document.getElementById('c4sAdminRoot');
      // simplest: just refresh
      if (STATE.readOnly) c4sLoadPerformer(STATE.scopeName); else c4sLoadAdmin();
      setTimeout(function(){ var el=document.getElementById('c4sInvSearch'); if(el){ el.focus(); el.setSelectionRange(el.value.length, el.value.length); } }, 30);
    }, 200);
  };
  function renderInventory(scope){
    var q = (STATE.invSearch||'').toLowerCase();
    var sorted = [...STATE.clips].filter(function(c){
      if (!q) return true;
      return (c.clip_name||'').toLowerCase().indexOf(q) > -1
          || (c.clip_id||'').toLowerCase().indexOf(q) > -1
          || (c.performer||'').toLowerCase().indexOf(q) > -1
          || (c.tracking_id||'').toLowerCase().indexOf(q) > -1;
    }).sort(clipComparator);
    var addBtn = STATE.readOnly ? '' :
      '<button onclick="c4sOpenClip(0)" style="padding:8px 16px;background:#d4a830;color:#0e1117;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer">+ Add Clip</button>';

    var head = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">'
      + '<h3 style="margin:0;color:#e8eaf0;font-size:16px">Video List / Studio Inventory'+scope+'</h3>' + addBtn
      + '</div>';

    var rows;
    if (STATE.readOnly) {
      rows = sorted.length ? sorted.map(function(c){
        return '<tr style="border-bottom:1px solid #1e2330">'
          + '<td style="padding:10px 12px;color:#8a8fa8;font-family:ui-monospace,monospace;font-size:12px">'+esc(c.clip_id||'')+'</td>'
          + '<td style="padding:10px 12px;color:#e8eaf0">'+esc(c.clip_name||'')+'</td>'
          + '<td style="padding:10px 12px;color:#e8eaf0;font-variant-numeric:tabular-nums">'+money(c.clip_price)+'</td>'
          + '<td style="padding:10px 12px">'+statusBadge(c.status)+'</td>'
          + '</tr>';
      }).join('') : '<tr><td colspan="4" style="padding:30px;text-align:center;color:#6a6f88;font-size:13px">No clips yet.</td></tr>';
    } else {
      rows = sorted.length ? sorted.map(function(c){
        var actions = '<button onclick="c4sOpenClip('+c.id+')" style="padding:5px 10px;background:transparent;border:1px solid #2563eb;color:#2563eb;border-radius:6px;font-size:11px;cursor:pointer;margin-right:4px">Edit</button>'
          + '<button onclick="c4sDeleteClip('+c.id+')" style="padding:5px 10px;background:transparent;border:1px solid #f87171;color:#f87171;border-radius:6px;font-size:11px;cursor:pointer">×</button>';
        return '<tr style="border-bottom:1px solid #1e2330">'
          + '<td style="padding:10px 12px;color:#8a8fa8;font-family:ui-monospace,monospace;font-size:12px">'+esc(c.clip_id||'')+'</td>'
          + '<td style="padding:10px 12px;color:#e8eaf0">'+esc(c.performer||'')+'</td>'
          + '<td style="padding:10px 12px;color:#e8eaf0">'+esc(c.clip_name||'')+'</td>'
          + '<td style="padding:10px 12px;color:#8a8fa8;font-size:12px;font-variant-numeric:tabular-nums">'+esc(dateOnly(c.published_date))+'</td>'
          + '<td style="padding:10px 12px;color:#8a8fa8;font-size:12px">'+esc(c.tracking_id||'')+'</td>'
          + '<td style="padding:10px 12px;color:#e8eaf0;font-variant-numeric:tabular-nums">'+money(c.clip_price)+'</td>'
          + '<td style="padding:10px 12px">'+statusDropdown(c.status, c.id)+'</td>'
          + '<td style="padding:10px 12px;text-align:right;white-space:nowrap">'+actions+'</td>'
          + '</tr>';
      }).join('') : '<tr><td colspan="8" style="padding:30px;text-align:center;color:#6a6f88;font-size:13px">No clips yet.</td></tr>';
    }

    var headerRow;
    if (STATE.readOnly) {
      headerRow = '<tr style="background:#0e1117;position:sticky;top:0;z-index:2">'
        + thSort('Clip ID',   'clip_id',     STATE.clipSort, 'c4sSortClips')
        + thSort('Clip Name', 'clip_name',   STATE.clipSort, 'c4sSortClips')
        + thSort('Price',     'clip_price',  STATE.clipSort, 'c4sSortClips')
        + thSort('Status',    'status',      STATE.clipSort, 'c4sSortClips')
        + '</tr>';
    } else {
      headerRow = '<tr style="background:#0e1117;position:sticky;top:0;z-index:2">'
        + thSort('Clip ID',     'clip_id',        STATE.clipSort, 'c4sSortClips')
        + thSort('Performer',   'performer',      STATE.clipSort, 'c4sSortClips')
        + thSort('Clip Name',   'clip_name',      STATE.clipSort, 'c4sSortClips')
        + thSort('Published',   'published_date', STATE.clipSort, 'c4sSortClips')
        + thSort('Tracking ID', 'tracking_id',    STATE.clipSort, 'c4sSortClips')
        + thSort('Price',       'clip_price',     STATE.clipSort, 'c4sSortClips')
        + thSort('Status',      'status',         STATE.clipSort, 'c4sSortClips')
        + '<th style="padding:11px 12px;text-align:right;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase">Actions</th>'
        + '</tr>';
    }

    var table = '<div style="background:#131720;border:1px solid #1e2330;border-radius:10px;margin-bottom:26px;max-height:420px;overflow:auto">'
      + '<table style="width:100%;border-collapse:collapse">'
      + '<thead>' + headerRow + '</thead><tbody>' + rows + '</tbody></table></div>';
    return head + table;
  }

  function clipComparator(a, b){
    var f = STATE.clipSort.field, d = STATE.clipSort.dir === 'asc' ? 1 : -1;
    var av = a[f], bv = b[f];
    if (f === 'clip_price') { av = parseFloat(av)||0; bv = parseFloat(bv)||0; return (av - bv) * d; }
    av = (av==null?'':String(av)).toLowerCase(); bv = (bv==null?'':String(bv)).toLowerCase();
    if (av < bv) return -1 * d; if (av > bv) return 1 * d; return 0;
  }

  window.c4sSortClips = function(field){
    if (STATE.clipSort.field === field) STATE.clipSort.dir = (STATE.clipSort.dir === 'asc' ? 'desc' : 'asc');
    else { STATE.clipSort.field = field; STATE.clipSort.dir = 'asc'; }
    // re-render without refetching
    var root = STATE.readOnly ? document.getElementById('c4sPerfRoot') : document.getElementById('c4sAdminRoot');
    if (STATE.readOnly) c4sLoadPerformer(STATE.scopeName); else c4sLoadAdmin();
  };

  /* ─── Sales / Payouts spreadsheet table ─── */
  function renderSales(scope){
    var sorted = [...STATE.sales].sort(saleComparator);
    var controls = '';
    if (!STATE.readOnly) {
      controls = '<input id="c4sSaleSearch" placeholder="Search by Clip ID, Title, or notes…" value="'+esc(STATE.saleSearch)+'" oninput="c4sSearchSales(this.value)" style="background:#0e1117;border:1px solid #2a2f3e;border-radius:8px;padding:7px 11px;color:#e8eaf0;font-size:12px;width:300px"/>'
        + '<button onclick="c4sStartAddSale()" style="padding:7px 14px;background:#d4a830;color:#0e1117;border:none;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer">+ Add Sale</button>';
    }

    var head = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:14px;flex-wrap:wrap">'
      + '<h3 style="margin:0;color:#e8eaf0;font-size:16px">Sales / Payouts'+scope+'</h3>'
      + (controls ? '<div style="display:flex;gap:10px;align-items:center">'+controls+'</div>' : '')
      + '</div>';

    var addRow = (STATE.addingSale && !STATE.readOnly) ? renderAddRow() : '';
    var emptyColspan = STATE.readOnly ? 4 : 7;
    var rows;
    if (STATE.readOnly) {
      rows = sorted.length ? sorted.map(function(s){
        return '<tr style="border-bottom:1px solid #1e2330">'
          + '<td style="padding:9px 12px;color:#8a8fa8;font-family:ui-monospace,monospace;font-size:12px">'+esc(s.clip_id||'')+'</td>'
          + '<td style="padding:9px 12px;color:#e8eaf0;font-size:13px">'+esc(s.clip_name||'')+'</td>'
          + '<td style="padding:9px 12px;color:#e8eaf0;font-variant-numeric:tabular-nums">'+money(s.sold_amount)+'</td>'
          + '<td style="padding:9px 12px;color:#2563eb;font-variant-numeric:tabular-nums;font-weight:700">'+money(s.payout_amount)+'</td>'
          + '</tr>';
      }).join('') : '<tr><td colspan="'+emptyColspan+'" style="padding:28px;text-align:center;color:#6a6f88;font-size:13px">No sales recorded yet.</td></tr>';
    } else {
      rows = sorted.length ? sorted.map(function(srow){
        return (STATE.editingSaleId === srow.id) ? renderEditRow(srow) : renderViewRow(srow);
      }).join('') : (addRow ? '' : '<tr><td colspan="'+emptyColspan+'" style="padding:28px;text-align:center;color:#6a6f88;font-size:13px">No sales recorded yet.</td></tr>');
    }

    var headerRow;
    if (STATE.readOnly) {
      headerRow = '<tr style="background:#0e1117;position:sticky;top:0;z-index:2">'
        + '<th style="padding:11px 12px;text-align:left;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase">Clip ID</th>'
        + '<th style="padding:11px 12px;text-align:left;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase">Clip Title</th>'
        + '<th style="padding:11px 12px;text-align:left;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase">Sold Amount</th>'
        + '<th style="padding:11px 12px;text-align:left;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase">Payout</th>'
        + '</tr>';
    } else {
      headerRow = '<tr style="background:#0e1117;position:sticky;top:0;z-index:2">'
        + '<th style="padding:11px 12px;text-align:left;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase">Clip ID</th>'
        + '<th style="padding:11px 12px;text-align:left;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase">Clip Title (optional)</th>'
        + thSort('Payment Date', 'payment_date',  STATE.saleSort, 'c4sSortSales')
        + '<th style="padding:11px 12px;text-align:left;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase">Sold Amount</th>'
        + '<th style="padding:11px 12px;text-align:left;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase">Commission</th>'
        + '<th style="padding:11px 12px;text-align:left;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase">Payout</th>'
        + '<th style="padding:11px 12px;text-align:right;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase">Actions</th>'
        + '</tr>';
    }

    var table = '<div style="background:#131720;border:1px solid #1e2330;border-radius:10px;margin-bottom:24px;max-height:480px;overflow:auto">'
      + '<table style="width:100%;border-collapse:collapse">'
      + '<thead>' + headerRow + '</thead>'
      + '<tbody>' + addRow + rows + '</tbody>'
      + '</table></div>';
    return head + table;
  }

  function saleComparator(a, b){
    var f = STATE.saleSort.field, d = STATE.saleSort.dir === 'asc' ? 1 : -1;
    var av = a[f]||'', bv = b[f]||'';
    if (av < bv) return -1 * d; if (av > bv) return 1 * d;
    // tie-break by id desc
    return (b.id - a.id);
  }
  window.c4sSortSales = function(field){
    if (STATE.saleSort.field === field) STATE.saleSort.dir = (STATE.saleSort.dir === 'asc' ? 'desc' : 'asc');
    else { STATE.saleSort.field = field; STATE.saleSort.dir = 'desc'; }
    if (STATE.readOnly) c4sLoadPerformer(STATE.scopeName); else c4sLoadAdmin();
  };
  window.c4sSearchSales = function(v){
    STATE.saleSearch = v;
    clearTimeout(window.__c4sSearchT);
    window.__c4sSearchT = setTimeout(function(){
      if (STATE.readOnly) c4sLoadPerformer(STATE.scopeName); else c4sLoadAdmin();
      // restore focus
      setTimeout(function(){ var el=document.getElementById('c4sSaleSearch'); if(el){ el.focus(); el.setSelectionRange(el.value.length, el.value.length); } }, 30);
    }, 300);
  };

  function clipOptions(selectedClipPk){
    return '<option value="">— select clip —</option>' + STATE.clips.map(function(c){
      return '<option value="'+c.id+'"'+(Number(c.id)===Number(selectedClipPk)?' selected':'')+'>'+esc(c.clip_id||'(no clip id)')+'</option>';
    }).join('');
  }

  function renderAddRow(){
    return '<tr style="background:#1a2230;border-bottom:2px solid #d4a830">'
      + '<td style="padding:8px 10px"><select id="addClipPk" onchange="c4sAutofillTitle(this)" style="width:100%;background:#0e1117;border:1px solid #2a2f3e;border-radius:6px;padding:6px;color:#e8eaf0;font-size:12px">'+clipOptions(0)+'</select></td>'
      + '<td style="padding:8px 10px"><input id="addClipTitle" placeholder="(optional)" style="width:100%;background:#0e1117;border:1px solid #2a2f3e;border-radius:6px;padding:6px 8px;color:#e8eaf0;font-size:12px;box-sizing:border-box"/></td>'
      + '<td style="padding:8px 10px"><input id="addPayDate" type="date" style="background:#0e1117;border:1px solid #2a2f3e;border-radius:6px;padding:6px;color:#e8eaf0;font-size:12px;color-scheme:dark"/></td>'
      + '<td style="padding:8px 10px"><input id="addSold" type="number" step="0.01" placeholder="0.00" style="width:100px;background:#0e1117;border:1px solid #2a2f3e;border-radius:6px;padding:6px;color:#e8eaf0;font-size:12px"/></td>'
      + '<td style="padding:8px 10px"><input id="addComm" type="number" step="0.01" placeholder="0.00" style="width:100px;background:#0e1117;border:1px solid #2a2f3e;border-radius:6px;padding:6px;color:#e8eaf0;font-size:12px"/></td>'
      + '<td style="padding:8px 10px"><input id="addPayout" type="number" step="0.01" placeholder="0.00" style="width:100px;background:#0e1117;border:1px solid #2a2f3e;border-radius:6px;padding:6px;color:#e8eaf0;font-size:12px"/></td>'
      + '<td style="padding:8px 10px;text-align:right;white-space:nowrap">'
        + '<button onclick="c4sSaveNewSale()" title="Save" style="padding:5px 10px;background:#16a34a;color:#fff;border:none;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;margin-right:4px">✓ Save</button>'
        + '<button onclick="c4sCancelAddSale()" title="Cancel" style="padding:5px 10px;background:transparent;border:1px solid #6b7280;color:#8a8fa8;border-radius:6px;font-size:11px;cursor:pointer">✕</button>'
      + '</td>'
      + '</tr>';
  }

  function renderViewRow(s){
    var actions = STATE.readOnly ? '' :
      '<button onclick="c4sEditSale('+s.id+')" style="padding:5px 10px;background:transparent;border:1px solid #2563eb;color:#2563eb;border-radius:6px;font-size:11px;cursor:pointer;margin-right:4px">Edit</button>'
      + '<button onclick="c4sDeleteSale('+s.id+')" style="padding:5px 10px;background:transparent;border:1px solid #f87171;color:#f87171;border-radius:6px;font-size:11px;cursor:pointer">Delete</button>';
    return '<tr style="border-bottom:1px solid #1e2330">'
      + '<td style="padding:9px 12px;color:#8a8fa8;font-family:ui-monospace,monospace;font-size:12px">'+esc(s.clip_id||'')+'</td>'
      + '<td style="padding:9px 12px;color:#e8eaf0;font-size:13px">'+esc(s.clip_name||'')+'</td>'
      + '<td style="padding:9px 12px;color:#8a8fa8;font-size:12px;font-variant-numeric:tabular-nums">'+esc(dateOnly(s.payment_date))+'</td>'
      + '<td style="padding:9px 12px;color:#e8eaf0;font-variant-numeric:tabular-nums">'+money(s.sold_amount)+'</td>'
      + '<td style="padding:9px 12px;color:#16a34a;font-variant-numeric:tabular-nums;font-weight:700">'+money(s.commission)+'</td>'
      + '<td style="padding:9px 12px;color:#2563eb;font-variant-numeric:tabular-nums;font-weight:700">'+money(s.payout_amount)+'</td>'
      + (STATE.readOnly ? '' : '<td style="padding:9px 12px;text-align:right;white-space:nowrap">'+actions+'</td>')
      + '</tr>';
  }

  function renderEditRow(s){
    return '<tr style="background:#1a2230;border-bottom:2px solid #2563eb">'
      + '<td style="padding:8px 10px"><select id="ed_'+s.id+'_clip" onchange="c4sAutofillTitle(this,\'ed_'+s.id+'_title\')" style="width:100%;background:#0e1117;border:1px solid #2a2f3e;border-radius:6px;padding:6px;color:#e8eaf0;font-size:12px">'+clipOptions(s.clip_pk)+'</select></td>'
      + '<td style="padding:8px 10px"><input id="ed_'+s.id+'_title" value="'+esc(s.clip_name||'')+'" style="width:100%;background:#0e1117;border:1px solid #2a2f3e;border-radius:6px;padding:6px 8px;color:#e8eaf0;font-size:12px;box-sizing:border-box"/></td>'
      + '<td style="padding:8px 10px"><input id="ed_'+s.id+'_date" type="date" value="'+esc(dateOnly(s.payment_date))+'" style="background:#0e1117;border:1px solid #2a2f3e;border-radius:6px;padding:6px;color:#e8eaf0;font-size:12px;color-scheme:dark"/></td>'
      + '<td style="padding:8px 10px"><input id="ed_'+s.id+'_sold" type="number" step="0.01" value="'+esc(s.sold_amount)+'" style="width:100px;background:#0e1117;border:1px solid #2a2f3e;border-radius:6px;padding:6px;color:#e8eaf0;font-size:12px"/></td>'
      + '<td style="padding:8px 10px"><input id="ed_'+s.id+'_comm" type="number" step="0.01" value="'+esc(s.commission)+'" style="width:100px;background:#0e1117;border:1px solid #2a2f3e;border-radius:6px;padding:6px;color:#e8eaf0;font-size:12px"/></td>'
      + '<td style="padding:8px 10px"><input id="ed_'+s.id+'_payout" type="number" step="0.01" value="'+esc(s.payout_amount)+'" style="width:100px;background:#0e1117;border:1px solid #2a2f3e;border-radius:6px;padding:6px;color:#e8eaf0;font-size:12px"/></td>'
      + '<td style="padding:8px 10px;text-align:right;white-space:nowrap">'
        + '<button onclick="c4sSaveEdit('+s.id+')" title="Save" style="padding:5px 10px;background:#16a34a;color:#fff;border:none;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;margin-right:4px">✓ Save</button>'
        + '<button onclick="c4sCancelEdit()" title="Cancel" style="padding:5px 10px;background:transparent;border:1px solid #6b7280;color:#8a8fa8;border-radius:6px;font-size:11px;cursor:pointer">✕</button>'
      + '</td>'
      + '</tr>';
  }

  /* ── Action handlers ── */
  window.c4sStartAddSale = function(){ STATE.addingSale = true; STATE.editingSaleId = 0; c4sLoadAdmin(); };
  window.c4sCancelAddSale = function(){ STATE.addingSale = false; c4sLoadAdmin(); };
  window.c4sEditSale = function(id){ STATE.editingSaleId = id; STATE.addingSale = false; c4sLoadAdmin(); };
  window.c4sCancelEdit = function(){ STATE.editingSaleId = 0; c4sLoadAdmin(); };

  window.c4sAutofillTitle = function(selectEl, titleInputId){
    var pk = parseInt(selectEl.value, 10);
    var clip = STATE.clips.find(function(c){ return Number(c.id) === pk; });
    var targetId = titleInputId || 'addClipTitle';
    var input = document.getElementById(targetId);
    if (input && clip && !input.value) input.value = clip.clip_name || '';
  };

  window.c4sSaveNewSale = function(){
    var clipPk = parseInt(document.getElementById('addClipPk').value, 10);
    if (!clipPk) { alert('Please select a Clip ID.'); return; }
    var body = {
      clip_pk:       clipPk,
      payment_date:  document.getElementById('addPayDate').value,
      sold_amount:   parseFloat(document.getElementById('addSold').value)   || 0,
      commission:    parseFloat(document.getElementById('addComm').value)   || 0,
      payout_amount: parseFloat(document.getElementById('addPayout').value) || 0,
      notes:         document.getElementById('addClipTitle').value || ''   // optional title rides in notes
    };
    api('sale_save', { method:'POST', body: body }).then(function(d){
      if (!d.success){ alert('Save failed: ' + (d.error||'')); return; }
      STATE.addingSale = false; c4sLoadAdmin();
    });
  };

  window.c4sSaveEdit = function(id){
    var clipPk = parseInt(document.getElementById('ed_'+id+'_clip').value, 10);
    if (!clipPk) { alert('Please select a Clip ID.'); return; }
    var body = {
      id: id,
      clip_pk:       clipPk,
      payment_date:  document.getElementById('ed_'+id+'_date').value,
      sold_amount:   parseFloat(document.getElementById('ed_'+id+'_sold').value)   || 0,
      commission:    parseFloat(document.getElementById('ed_'+id+'_comm').value)   || 0,
      payout_amount: parseFloat(document.getElementById('ed_'+id+'_payout').value) || 0,
      notes:         document.getElementById('ed_'+id+'_title').value || ''
    };
    api('sale_save', { method:'POST', body: body }).then(function(d){
      if (!d.success){ alert('Save failed: ' + (d.error||'')); return; }
      STATE.editingSaleId = 0; c4sLoadAdmin();
    });
  };

  window.c4sDeleteSale = function(id){
    if (!confirm('Delete this sale? This action cannot be undone.')) return;
    api('sale_delete', { method:'POST', body: { id: id } }).then(function(d){
      if (d.success) c4sLoadAdmin(); else alert('Delete failed: ' + (d.error||''));
    });
  };

  /* ── Clip status / add / delete (inventory) ── */
  window.c4sInlineStatus = function(id, status){
    api('clip_status', { method:'POST', body: { id: id, status: status } })
      .then(function(d){ if(!d.success) alert('Status update failed: ' + (d.error||'')); else c4sLoadAdmin(); });
  };

  window.c4sOpenClip = async function(id){
    var clip = { id: 0, performer:'', performer_email:'', published_date:'', clip_name:'', clip_id:'', tracking_id:'', clip_price:0, status:'new' };
    if (id) { var found = STATE.clips.find(function(c){ return Number(c.id) === Number(id); }); if (found) clip = Object.assign(clip, found); }
    showClipDialog(clip);
  };

  function showClipDialog(clip){
    var dlg = document.getElementById('c4sClipDlg');
    if (!dlg) { dlg = document.createElement('div'); dlg.id = 'c4sClipDlg'; dlg.style.cssText='display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9100;align-items:flex-start;justify-content:center;overflow-y:auto'; document.body.appendChild(dlg); }
    var statusOpts = STATUS_VALUES.map(function(s){ return '<option value="'+s+'"'+(s===clip.status?' selected':'')+'>'+s.charAt(0).toUpperCase()+s.slice(1)+'</option>'; }).join('');
    dlg.innerHTML = '<div style="background:#11151c;border:1px solid #2a2f3e;border-radius:14px;width:560px;max-width:94vw;margin-top:60px;padding:24px;color:#e8eaf0">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px"><h3 style="margin:0">'+(clip.id?'Edit Clip':'Add Clip')+'</h3><button onclick="document.getElementById(\'c4sClipDlg\').style.display=\'none\'" style="background:transparent;border:none;color:#8a8fa8;font-size:22px;cursor:pointer">&times;</button></div>'
      + fld('Performer (stage name)', 'cdPerformer', clip.performer)
      + fld('Performer email',        'cdEmail',     clip.performer_email, 'email')
      + fld('Published date',         'cdPubDate',   dateOnly(clip.published_date), 'date')
      + fld('Clip name',              'cdName',      clip.clip_name)
      + fld('Clip ID',                'cdClipId',    clip.clip_id)
      + fld('Tracking ID',            'cdTracking',  clip.tracking_id)
      + fld('Clip price',             'cdPrice',     clip.clip_price, 'number')
      + '<div style="margin-bottom:14px"><label style="display:block;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase;margin-bottom:4px">Status</label><select id="cdStatus" style="width:100%;background:#0e1117;border:1px solid #2a2f3e;border-radius:8px;padding:9px;color:#e8eaf0;font-size:13px">'+statusOpts+'</select></div>'
      + '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px"><button onclick="document.getElementById(\'c4sClipDlg\').style.display=\'none\'" style="padding:9px 18px;background:transparent;border:1px solid #2a2f3e;color:#e8eaf0;border-radius:8px;cursor:pointer">Cancel</button><button onclick="c4sSaveClip('+clip.id+')" style="padding:9px 18px;background:#d4a830;color:#0e1117;border:none;border-radius:8px;font-weight:700;cursor:pointer">Save</button></div>'
      + '</div>';
    dlg.style.display = 'flex';
  }
  function fld(label, id, value, type){
    return '<div style="margin-bottom:14px"><label style="display:block;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase;margin-bottom:4px">'+esc(label)+'</label><input id="'+id+'" type="'+(type||'text')+'" value="'+esc(value)+'" style="width:100%;background:#0e1117;border:1px solid #2a2f3e;border-radius:8px;padding:9px;color:#e8eaf0;font-size:13px;box-sizing:border-box"/></div>';
  }

  window.c4sSaveClip = function(id){
    var body = {
      id: id,
      performer:       document.getElementById('cdPerformer').value.trim(),
      performer_email: document.getElementById('cdEmail').value.trim(),
      published_date:  document.getElementById('cdPubDate').value.trim(),
      clip_name:       document.getElementById('cdName').value.trim(),
      clip_id:         document.getElementById('cdClipId').value.trim(),
      tracking_id:     document.getElementById('cdTracking').value.trim(),
      clip_price:      parseFloat(document.getElementById('cdPrice').value) || 0,
      status:          document.getElementById('cdStatus').value
    };
    api('clip_save', { method:'POST', body: body }).then(function(d){
      if (d.success){ document.getElementById('c4sClipDlg').style.display='none'; c4sLoadAdmin(); }
      else alert('Save failed: ' + (d.error||''));
    });
  };

  window.c4sDeleteClip = function(id){
    if (!confirm('Delete this clip and all its sales? This cannot be undone.')) return;
    api('clip_delete', { method:'POST', body: { id: id } }).then(function(d){
      if (d.success) c4sLoadAdmin(); else alert('Delete failed: ' + (d.error||''));
    });
  };
})();
