/* Clip4Sale — multi-sale model
   Admin renders to #c4sAdminRoot (window.c4sLoadAdmin)
   Performer renders to #c4sPerfRoot, read-only (window.c4sLoadPerformer)
   Backend: /api/clip4sale.php */
(function(){
  var API = 'https://luxetalentsystems.com/api/clip4sale.php';
  var STATUS_VALUES = ['new','live','sold','paid','inactive'];

  function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function money(n){ var v = parseFloat(n)||0; return '$' + v.toFixed(2); }
  function dateOnly(s){ if(!s) return ''; return String(s).split(' ')[0]; }
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

  function api(action, opts){
    opts = opts || {};
    var qs = action ? ('?action=' + action + (opts.qs ? '&' + opts.qs : '')) : '';
    var req = { method: opts.method || 'GET', headers: {} };
    if (opts.body){ req.headers['Content-Type'] = 'application/json'; req.body = JSON.stringify(opts.body); }
    return fetch(API + qs, req).then(function(r){ return r.json(); });
  }

  /* ═══════════════════════════════════════════════
     ADMIN VIEW  (full editing, all performers)
     ═══════════════════════════════════════════════ */
  window.c4sLoadAdmin = async function(){
    var root = document.getElementById('c4sAdminRoot');
    if(!root) return;
    root.innerHTML = '<div style="padding:24px;text-align:center;color:#8a8fa8">Loading…</div>';
    try {
      var [stats, clipsRes, totals] = await Promise.all([
        api('stats'),
        api('clips_list'),
        api('totals')
      ]);
      renderAdmin(root, stats, clipsRes.clips || [], totals, /*readOnly=*/false, /*scopeName=*/'');
    } catch(e){
      root.innerHTML = '<div style="padding:24px;color:#f87171">Failed to load: ' + esc(e.message) + '</div>';
    }
  };

  /* ═══════════════════════════════════════════════
     PERFORMER VIEW  (read-only, scoped to one performer)
     ═══════════════════════════════════════════════ */
  window.c4sLoadPerformer = async function(performerName){
    var root = document.getElementById('c4sPerfRoot');
    if(!root) return;
    root.innerHTML = '<div style="padding:24px;text-align:center;color:#8a8fa8">Loading…</div>';
    try {
      var [stats, clipsRes, totals] = await Promise.all([
        api('stats'),                                              // top counters are always system-wide
        api('clips_list', { qs: 'performer=' + encodeURIComponent(performerName||'') }),
        api('totals',     { qs: 'performer=' + encodeURIComponent(performerName||'') })
      ]);
      renderAdmin(root, stats, clipsRes.clips || [], totals, /*readOnly=*/true, /*scopeName=*/(performerName||''));
    } catch(e){
      root.innerHTML = '<div style="padding:24px;color:#f87171">Failed to load: ' + esc(e.message) + '</div>';
    }
  };

  /* ── Single renderer used by both views ── */
  function renderAdmin(root, stats, clips, totals, readOnly, scopeName){
    var counters = '<div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px">'
      + counterCard('Live',              stats.live || 0,           '#16a34a')
      + counterCard('Total Uploads',     stats.total_uploads || 0,  '#d4a830')
      + counterCard('Total New Uploads', stats.new_uploads || 0,    '#2563eb')
      + '</div>';

    var headerActions = readOnly
      ? '<div style="font-size:12px;color:#8a8fa8">Read-only view'+(scopeName?(' — '+esc(scopeName)):'')+'</div>'
      : '<button onclick="c4sOpenClip(0)" style="padding:8px 16px;background:#d4a830;color:#0e1117;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer">+ Add Clip</button>';

    var listHead = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">'
      + '<h3 style="margin:0;color:#e8eaf0;font-size:16px">Video List'+(scopeName?(' — '+esc(scopeName)):'')+'</h3>'
      + headerActions
      + '</div>';

    var rows = clips.length ? clips.map(function(c){
      var actions = readOnly
        ? '<button onclick="c4sViewSales('+c.id+', \''+esc(c.clip_name).replace(/'/g,"\\'")+'\')" style="padding:5px 10px;background:transparent;border:1px solid #2a2f3e;color:#8a8fa8;border-radius:6px;font-size:11px;cursor:pointer">View Sales ('+(c.sale_count||0)+')</button>'
        : '<button onclick="c4sViewSales('+c.id+', \''+esc(c.clip_name).replace(/'/g,"\\'")+'\')" style="padding:5px 10px;background:transparent;border:1px solid #d4a830;color:#d4a830;border-radius:6px;font-size:11px;cursor:pointer;margin-right:4px">Sales ('+(c.sale_count||0)+')</button>'
            + '<button onclick="c4sOpenClip('+c.id+')" style="padding:5px 10px;background:transparent;border:1px solid #2563eb;color:#2563eb;border-radius:6px;font-size:11px;cursor:pointer;margin-right:4px">Edit</button>'
            + '<button onclick="c4sDeleteClip('+c.id+')" style="padding:5px 10px;background:transparent;border:1px solid #f87171;color:#f87171;border-radius:6px;font-size:11px;cursor:pointer">×</button>';
      var statusCell = readOnly ? statusBadge(c.status) : statusDropdown(c.status, c.id);
      return '<tr style="border-bottom:1px solid #1e2330">'
        + '<td style="padding:10px 12px;color:#e8eaf0">'+esc(c.performer||'')+'</td>'
        + '<td style="padding:10px 12px;color:#8a8fa8;font-size:12px;font-variant-numeric:tabular-nums">'+esc(dateOnly(c.published_date))+'</td>'
        + '<td style="padding:10px 12px;color:#e8eaf0">'+esc(c.clip_name||'')+'</td>'
        + '<td style="padding:10px 12px;color:#8a8fa8;font-family:ui-monospace,monospace;font-size:12px">'+esc(c.clip_id||'')+'</td>'
        + '<td style="padding:10px 12px;color:#8a8fa8;font-size:12px">'+esc(c.tracking_id||'')+'</td>'
        + '<td style="padding:10px 12px;color:#e8eaf0;font-variant-numeric:tabular-nums">'+money(c.clip_price)+'</td>'
        + '<td style="padding:10px 12px">'+statusCell+'</td>'
        + '<td style="padding:10px 12px;text-align:right;white-space:nowrap">'+actions+'</td>'
        + '</tr>';
    }).join('') : '<tr><td colspan="8" style="padding:30px;text-align:center;color:#6a6f88;font-size:13px">No clips yet.'+(readOnly?'':' Click "+ Add Clip" to add one.')+'</td></tr>';

    var table = '<div style="background:#131720;border:1px solid #1e2330;border-radius:10px;overflow:hidden;margin-bottom:24px">'
      + '<table style="width:100%;border-collapse:collapse">'
      + '<thead><tr style="background:#0e1117">'
        + '<th style="padding:11px 12px;text-align:left;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Performer</th>'
        + '<th style="padding:11px 12px;text-align:left;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Published</th>'
        + '<th style="padding:11px 12px;text-align:left;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Clip Name</th>'
        + '<th style="padding:11px 12px;text-align:left;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Clip ID</th>'
        + '<th style="padding:11px 12px;text-align:left;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Tracking ID</th>'
        + '<th style="padding:11px 12px;text-align:left;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Price</th>'
        + '<th style="padding:11px 12px;text-align:left;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Status</th>'
        + '<th style="padding:11px 12px;text-align:right;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Actions</th>'
      + '</tr></thead><tbody>' + rows + '</tbody></table></div>';

    var paymentsTotals = '<h3 style="margin:0 0 10px;color:#e8eaf0;font-size:16px">Payments Running Totals'+(scopeName?(' — '+esc(scopeName)):'')+'</h3>'
      + '<div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px">'
        + counterCard('Total Sold',       money(totals.total_sold||0),       '#d4a830')
        + counterCard('Total Commission', money(totals.total_commission||0), '#16a34a')
        + counterCard('Total Paid',       money(totals.total_paid||0),       '#2563eb')
        + counterCard('Outstanding',      money(totals.outstanding||0),      '#f87171')
      + '</div>';

    root.innerHTML = counters + listHead + table + paymentsTotals;
  }

  function counterCard(label, value, color){
    return '<div style="background:#131720;border:1px solid #1e2330;border-radius:10px;padding:18px;border-left:4px solid '+color+'">'
      + '<div style="font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">'+esc(label)+'</div>'
      + '<div style="font-size:28px;color:#e8eaf0;font-weight:800;font-variant-numeric:tabular-nums">'+esc(value)+'</div>'
      + '</div>';
  }

  /* ── Inline status change (admin only) ── */
  window.c4sInlineStatus = function(id, status){
    api('clip_status', { method:'POST', body: { id: id, status: status } })
      .then(function(d){ if(!d.success) alert('Status update failed: ' + (d.error||'')); else c4sLoadAdmin(); })
      .catch(function(e){ alert('Error: ' + e.message); });
  };

  /* ── Clip Add/Edit dialog ── */
  window.c4sOpenClip = async function(id){
    var clip = { id: 0, performer:'', performer_email:'', published_date:'', clip_name:'', clip_id:'', tracking_id:'', clip_price:0, status:'new' };
    if (id) {
      var d = await api('clips_list');
      var found = (d.clips||[]).find(function(c){ return Number(c.id) === Number(id); });
      if (found) clip = Object.assign(clip, found);
    }
    showClipDialog(clip);
  };

  function showClipDialog(clip){
    var dlg = document.getElementById('c4sClipDlg');
    if (!dlg) {
      dlg = document.createElement('div');
      dlg.id = 'c4sClipDlg';
      dlg.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9100;align-items:flex-start;justify-content:center;overflow-y:auto';
      document.body.appendChild(dlg);
    }
    var statusOpts = STATUS_VALUES.map(function(s){ return '<option value="'+s+'"'+(s===clip.status?' selected':'')+'>'+s.charAt(0).toUpperCase()+s.slice(1)+'</option>'; }).join('');
    dlg.innerHTML = '<div style="background:#11151c;border:1px solid #2a2f3e;border-radius:14px;width:560px;max-width:94vw;margin-top:60px;padding:24px;color:#e8eaf0">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px"><h3 style="margin:0">'+(clip.id?'Edit Clip':'Add Clip')+'</h3><button onclick="document.getElementById(\'c4sClipDlg\').style.display=\'none\'" style="background:transparent;border:none;color:#8a8fa8;font-size:22px;cursor:pointer">&times;</button></div>'
      + clipField('Performer (stage name)',   'cdPerformer',     clip.performer)
      + clipField('Performer email',          'cdEmail',         clip.performer_email, 'email')
      + clipField('Published date',           'cdPubDate',       dateOnly(clip.published_date), 'date')
      + clipField('Clip name',                'cdName',          clip.clip_name)
      + clipField('Clip ID',                  'cdClipId',        clip.clip_id)
      + clipField('Tracking ID',              'cdTracking',      clip.tracking_id)
      + clipField('Clip price',               'cdPrice',         clip.clip_price, 'number')
      + '<div style="margin-bottom:14px"><label style="display:block;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase;margin-bottom:4px">Status</label><select id="cdStatus" style="width:100%;background:#0e1117;border:1px solid #2a2f3e;border-radius:8px;padding:9px;color:#e8eaf0;font-size:13px">'+statusOpts+'</select></div>'
      + '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px"><button onclick="document.getElementById(\'c4sClipDlg\').style.display=\'none\'" style="padding:9px 18px;background:transparent;border:1px solid #2a2f3e;color:#e8eaf0;border-radius:8px;cursor:pointer">Cancel</button><button onclick="c4sSaveClip('+clip.id+')" style="padding:9px 18px;background:#d4a830;color:#0e1117;border:none;border-radius:8px;font-weight:700;cursor:pointer">Save</button></div>'
      + '</div>';
    dlg.style.display = 'flex';
  }
  function clipField(label, id, value, type){
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
    api('clip_save', { method:'POST', body: body })
      .then(function(d){ if(d.success){ document.getElementById('c4sClipDlg').style.display='none'; c4sLoadAdmin(); } else alert('Save failed: ' + (d.error||'')); })
      .catch(function(e){ alert('Error: ' + e.message); });
  };

  window.c4sDeleteClip = function(id){
    if (!confirm('Delete this clip and all its sales? This cannot be undone.')) return;
    api('clip_delete', { method:'POST', body: { id: id } })
      .then(function(d){ if(d.success) c4sLoadAdmin(); else alert('Delete failed: ' + (d.error||'')); });
  };

  /* ── Sales pane for a clip (lists sales + Add Sale button) ── */
  window.c4sViewSales = async function(clipPk, clipName){
    var d = await api('sales_list', { qs: 'clip_pk=' + clipPk });
    showSalesDialog(clipPk, clipName, d.sales || []);
  };

  function showSalesDialog(clipPk, clipName, sales){
    var dlg = document.getElementById('c4sSalesDlg');
    if (!dlg) {
      dlg = document.createElement('div');
      dlg.id = 'c4sSalesDlg';
      dlg.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9100;align-items:flex-start;justify-content:center;overflow-y:auto';
      document.body.appendChild(dlg);
    }
    var readOnly = !window.c4sLoadAdmin || (document.getElementById('c4sPerfRoot') && !document.getElementById('c4sAdminRoot'));
    // Real check: are we currently on the performer screen?
    readOnly = !document.getElementById('c4sAdminRoot') || document.getElementById('c4sAdminRoot').offsetParent === null;
    // Simpler: presence of admin root means admin context
    readOnly = !document.getElementById('c4sAdminRoot');

    var rows = sales.length ? sales.map(function(s){
      var actions = readOnly ? '' :
        '<button onclick="c4sOpenSale('+clipPk+','+s.id+')" style="padding:4px 9px;background:transparent;border:1px solid #2563eb;color:#2563eb;border-radius:5px;font-size:11px;cursor:pointer;margin-right:4px">Edit</button>'
        + '<button onclick="c4sDeleteSale('+clipPk+','+s.id+',\''+esc(clipName).replace(/\x27/g,"\\x27")+'\')" style="padding:4px 9px;background:transparent;border:1px solid #f87171;color:#f87171;border-radius:5px;font-size:11px;cursor:pointer">×</button>';
      return '<tr style="border-bottom:1px solid #1e2330">'
        + '<td style="padding:8px 10px;color:#e8eaf0;font-variant-numeric:tabular-nums">'+money(s.sale_price)+'</td>'
        + '<td style="padding:8px 10px;color:#e8eaf0;font-variant-numeric:tabular-nums">'+money(s.sold_amount)+'</td>'
        + '<td style="padding:8px 10px;color:#16a34a;font-variant-numeric:tabular-nums;font-weight:700">'+money(s.commission)+'</td>'
        + '<td style="padding:8px 10px;color:#8a8fa8;font-size:12px">'+esc(dateOnly(s.sale_date))+'</td>'
        + '<td style="padding:8px 10px;color:#8a8fa8;font-size:12px">'+esc(dateOnly(s.date_paid))+'</td>'
        + '<td style="padding:8px 10px">'+statusBadge(s.status)+'</td>'
        + (readOnly ? '' : '<td style="padding:8px 10px;text-align:right;white-space:nowrap">'+actions+'</td>')
        + '</tr>';
    }).join('') : '<tr><td colspan="'+(readOnly?6:7)+'" style="padding:24px;text-align:center;color:#6a6f88;font-size:12px">No sales recorded.</td></tr>';

    var addBtn = readOnly ? '' :
      '<button onclick="c4sOpenSale('+clipPk+',0)" style="padding:8px 14px;background:#d4a830;color:#0e1117;border:none;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer">+ Add Sale</button>';
    var actionsHdr = readOnly ? '' : '<th style="padding:8px 10px;text-align:right;font-size:10px;color:#8a8fa8;text-transform:uppercase">Actions</th>';

    dlg.innerHTML = '<div style="background:#11151c;border:1px solid #2a2f3e;border-radius:14px;width:780px;max-width:94vw;margin-top:60px;padding:22px;color:#e8eaf0">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">'
        + '<h3 style="margin:0;font-size:16px">Sales — '+esc(clipName)+'</h3>'
        + '<div>'+addBtn+' <button onclick="document.getElementById(\'c4sSalesDlg\').style.display=\'none\'" style="background:transparent;border:none;color:#8a8fa8;font-size:22px;cursor:pointer;margin-left:8px">&times;</button></div>'
      + '</div>'
      + '<table style="width:100%;border-collapse:collapse">'
        + '<thead><tr style="background:#0e1117"><th style="padding:8px 10px;text-align:left;font-size:10px;color:#8a8fa8;text-transform:uppercase">Sale Price</th><th style="padding:8px 10px;text-align:left;font-size:10px;color:#8a8fa8;text-transform:uppercase">Sold Amount</th><th style="padding:8px 10px;text-align:left;font-size:10px;color:#8a8fa8;text-transform:uppercase">Commission</th><th style="padding:8px 10px;text-align:left;font-size:10px;color:#8a8fa8;text-transform:uppercase">Sale Date</th><th style="padding:8px 10px;text-align:left;font-size:10px;color:#8a8fa8;text-transform:uppercase">Date Paid</th><th style="padding:8px 10px;text-align:left;font-size:10px;color:#8a8fa8;text-transform:uppercase">Status</th>'+actionsHdr+'</tr></thead>'
        + '<tbody>' + rows + '</tbody>'
      + '</table></div>';
    dlg.style.display = 'flex';
  }

  window.c4sOpenSale = async function(clipPk, saleId){
    var sale = { id: 0, clip_pk: clipPk, sale_price:0, sold_amount:0, commission:0, sale_date:'', date_paid:'', status:'sold', notes:'' };
    if (saleId) {
      var d = await api('sales_list', { qs: 'clip_pk=' + clipPk });
      var found = (d.sales||[]).find(function(s){ return Number(s.id) === Number(saleId); });
      if (found) sale = Object.assign(sale, found);
    }
    showSaleDialog(sale);
  };

  function showSaleDialog(sale){
    var dlg = document.getElementById('c4sSaleDlg');
    if (!dlg) {
      dlg = document.createElement('div');
      dlg.id = 'c4sSaleDlg';
      dlg.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:9200;align-items:flex-start;justify-content:center;overflow-y:auto';
      document.body.appendChild(dlg);
    }
    var statusOpts = ['sold','paid'].map(function(s){ return '<option value="'+s+'"'+(s===sale.status?' selected':'')+'>'+s.charAt(0).toUpperCase()+s.slice(1)+'</option>'; }).join('');
    dlg.innerHTML = '<div style="background:#11151c;border:1px solid #2a2f3e;border-radius:14px;width:520px;max-width:94vw;margin-top:60px;padding:22px;color:#e8eaf0">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px"><h3 style="margin:0">'+(sale.id?'Edit Sale':'Add Sale')+'</h3><button onclick="document.getElementById(\'c4sSaleDlg\').style.display=\'none\'" style="background:transparent;border:none;color:#8a8fa8;font-size:22px;cursor:pointer">&times;</button></div>'
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">'
        + clipField('Sale Price',  'sdSalePrice',  sale.sale_price,  'number')
        + clipField('Sold Amount', 'sdSoldAmount', sale.sold_amount, 'number')
        + clipField('Commission',  'sdCommission', sale.commission,  'number')
        + '<div style="margin-bottom:14px"><label style="display:block;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase;margin-bottom:4px">Status</label><select id="sdStatus" style="width:100%;background:#0e1117;border:1px solid #2a2f3e;border-radius:8px;padding:9px;color:#e8eaf0;font-size:13px;box-sizing:border-box">'+statusOpts+'</select></div>'
        + clipField('Sale Date',   'sdSaleDate',   dateOnly(sale.sale_date), 'date')
        + clipField('Date Paid',   'sdDatePaid',   dateOnly(sale.date_paid), 'date')
      + '</div>'
      + '<div style="margin-bottom:14px"><label style="display:block;font-size:11px;color:#8a8fa8;font-weight:700;text-transform:uppercase;margin-bottom:4px">Notes</label><textarea id="sdNotes" rows="2" style="width:100%;background:#0e1117;border:1px solid #2a2f3e;border-radius:8px;padding:9px;color:#e8eaf0;font-size:13px;box-sizing:border-box;resize:vertical">'+esc(sale.notes||'')+'</textarea></div>'
      + '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px"><button onclick="document.getElementById(\'c4sSaleDlg\').style.display=\'none\'" style="padding:9px 18px;background:transparent;border:1px solid #2a2f3e;color:#e8eaf0;border-radius:8px;cursor:pointer">Cancel</button><button onclick="c4sSaveSale('+sale.clip_pk+','+sale.id+')" style="padding:9px 18px;background:#d4a830;color:#0e1117;border:none;border-radius:8px;font-weight:700;cursor:pointer">Save</button></div>'
      + '</div>';
    dlg.style.display = 'flex';
  }

  window.c4sSaveSale = function(clipPk, saleId){
    var body = {
      id: saleId,
      clip_pk:     clipPk,
      sale_price:  parseFloat(document.getElementById('sdSalePrice').value)  || 0,
      sold_amount: parseFloat(document.getElementById('sdSoldAmount').value) || 0,
      commission:  parseFloat(document.getElementById('sdCommission').value) || 0,
      sale_date:   document.getElementById('sdSaleDate').value,
      date_paid:   document.getElementById('sdDatePaid').value,
      status:      document.getElementById('sdStatus').value,
      notes:       document.getElementById('sdNotes').value
    };
    api('sale_save', { method:'POST', body: body })
      .then(function(d){
        if (!d.success) { alert('Save failed: ' + (d.error||'')); return; }
        document.getElementById('c4sSaleDlg').style.display = 'none';
        // Refresh sales list for the same clip, and the admin view's totals
        var listDlg = document.getElementById('c4sSalesDlg');
        if (listDlg) listDlg.style.display = 'none';
        c4sLoadAdmin();
      })
      .catch(function(e){ alert('Error: ' + e.message); });
  };

  window.c4sDeleteSale = function(clipPk, saleId, clipName){
    if (!confirm('Delete this sale?')) return;
    api('sale_delete', { method:'POST', body: { id: saleId } })
      .then(function(d){
        if (d.success){
          // re-render sales list
          c4sViewSales(clipPk, clipName);
          c4sLoadAdmin();
        } else alert('Delete failed: ' + (d.error||''));
      });
  };
})();
