/* PBX tab v2 — takes over #tab-pbx with sub-tabs: Extensions | Trunks | Routes | Features.
   CSP-safe: inline styles only, no eval, event delegation. Talks to /api/pbx.php. */
(function(){
  "use strict";
  var API = '/api/pbx.php';
  function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  var S = {
    card:'background:#131720;border:1px solid #1e2330;border-radius:12px;padding:16px;margin-bottom:14px',
    h:'font-size:13px;font-weight:700;color:#d4a830;margin-bottom:10px',
    sub:'padding:8px 16px;border-radius:8px 8px 0 0;font-size:13px;font-weight:700;cursor:pointer;border:1px solid #1e2330;border-bottom:none;background:#0e1117;color:#8a8fa8',
    subOn:'padding:8px 16px;border-radius:8px 8px 0 0;font-size:13px;font-weight:700;cursor:pointer;border:1px solid #2a2f3e;border-bottom:none;background:#131720;color:#d4a830',
    inp:'background:#0e1117;border:1px solid #2a2f3e;border-radius:8px;padding:8px 10px;color:#e8eaf0;font-size:13px;min-width:0',
    sel:'background:#0e1117;border:1px solid #2a2f3e;border-radius:8px;padding:8px 10px;color:#e8eaf0;font-size:13px;cursor:pointer',
    lbl:'font-size:11px;color:#8a8fa8',
    btn:'background:#4ade80;color:#0e1117;border:none;border-radius:8px;padding:9px 18px;font-size:12px;font-weight:700;cursor:pointer',
    btnGold:'background:#d4a830;color:#0e1117;border:none;border-radius:8px;padding:9px 18px;font-size:12px;font-weight:700;cursor:pointer',
    btnDel:'background:#3a1a1a;color:#f87171;border:1px solid #5a2a2a;border-radius:6px;padding:3px 10px;font-size:11px;cursor:pointer',
    row:'display:flex;justify-content:space-between;align-items:center;padding:9px 12px;border-bottom:1px solid #161b27;gap:10px;flex-wrap:wrap',
    tag:'font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px',
    listWrap:'border:1px solid #1e2330;border-radius:10px;background:#0e1117;margin-bottom:14px;max-height:300px;overflow-y:auto'
  };
  function tagOn(txt){ return '<span style="'+S.tag+';background:#12321f;color:#4ade80">'+esc(txt)+'</span>'; }
  function tagOff(txt){ return '<span style="'+S.tag+';background:#3a1a1a;color:#f87171">'+esc(txt)+'</span>'; }
  function tagDim(txt){ return '<span style="'+S.tag+';background:#1a1f2e;color:#8a8fa8">'+esc(txt)+'</span>'; }

  var ST = { sub:'ext', trunks:[] };

  function get(a){ return fetch(API+'?action='+a, {credentials:'include'}).then(function(r){ return r.json(); }); }
  function post(a, b){ return fetch(API+'?action='+a, {method:'POST',credentials:'include',
    headers:{'Content-Type':'application/json'}, body:JSON.stringify(b||{})}).then(function(r){ return r.json(); }); }
  function val(id){ var e=document.getElementById(id); return e ? e.value.trim() : ''; }
  function setmsg(id, txt, ok){ var e=document.getElementById(id); if(e){ e.style.color = ok?'#4ade80':'#f87171'; e.textContent = txt; } }

  /* ═══════════ PANELS ═══════════ */
  function panelExt(){
    return '<div style="'+S.card+'"><div style="'+S.h+'">Status</div><div id="pbx2Status" style="font-size:13px;color:#8a8fa8">Loading&hellip;</div></div>'
      + '<div style="'+S.card+'">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px"><div style="'+S.h+';margin:0">Extensions</div>'
      + '<button data-pbx="refresh" style="'+S.btnDel+';background:#1a1f2e;color:#8a8fa8;border-color:#2a2f3e">Refresh</button></div>'
      + '<div id="pbx2ExtList" style="'+S.listWrap+'"><div style="'+S.row+';color:#8a8fa8;font-size:12px">Loading&hellip;</div></div>'
      + '<div style="'+S.h+'">Add Extension</div>'
      + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">'
      + '<label style="'+S.lbl+'">Extension<br><input id="pbx2NewExt" placeholder="1002" style="'+S.inp+';width:90px;margin-top:4px"></label>'
      + '<label style="'+S.lbl+'">Password<br><input id="pbx2NewPass" placeholder="min 6 chars" style="'+S.inp+';width:160px;margin-top:4px"></label>'
      + '<button data-pbx="ext-add" style="'+S.btn+'">Add</button></div>'
      + '<div id="pbx2ExtMsg" style="font-size:12px;margin-top:8px"></div></div>';
  }

  function trunkFields(type){
    var f = '<label style="'+S.lbl+'">Name<br><input id="tk_name" placeholder="twilio1" style="'+S.inp+';width:130px;margin-top:4px"></label>';
    if (type === 'twilio'){
      f += '<label style="'+S.lbl+'">Termination URI<br><input id="tk_host" placeholder="yourtrunk.pstn.twilio.com" style="'+S.inp+';width:240px;margin-top:4px"></label>';
    } else {
      f += '<label style="'+S.lbl+'">Host / IP<br><input id="tk_host" placeholder="sip.provider.com or 1.2.3.4" style="'+S.inp+';width:200px;margin-top:4px"></label>';
    }
    if (type === 'generic-reg'){
      f += '<label style="'+S.lbl+'">Username<br><input id="tk_user" style="'+S.inp+';width:140px;margin-top:4px"></label>'
         + '<label style="'+S.lbl+'">Password<br><input id="tk_pass" style="'+S.inp+';width:140px;margin-top:4px"></label>';
    }
    if (type !== 'generic-reg'){
      f += '<label style="'+S.lbl+'">'+(type==='twilio'?'Extra allowed IPs (optional)':'Allowed IPs (blank = host)')+'<br><input id="tk_match" placeholder="1.2.3.4, 5.6.7.0/24" style="'+S.inp+';width:200px;margin-top:4px"></label>';
    }
    return f;
  }

  function panelTrunks(){
    var type = ST.trunkType || 'twilio';
    return '<div style="'+S.card+'">'
      + '<div style="'+S.h+'">Trunks</div>'
      + '<div id="pbx2TrunkList" style="'+S.listWrap+'"><div style="'+S.row+';color:#8a8fa8;font-size:12px">Loading&hellip;</div></div>'
      + '<div style="'+S.h+'">Add Trunk</div>'
      + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:8px">'
      + '<label style="'+S.lbl+'">Type<br><select id="tk_type" style="'+S.sel+';margin-top:4px">'
      + '<option value="twilio"'+(type==='twilio'?' selected':'')+'>Twilio (Elastic SIP, IP auth)</option>'
      + '<option value="generic-reg"'+(type==='generic-reg'?' selected':'')+'>Generic SIP (registration)</option>'
      + '<option value="generic-ip"'+(type==='generic-ip'?' selected':'')+'>Generic SIP (IP auth)</option>'
      + '<option value="centrex"'+(type==='centrex'?' selected':'')+'>Centrex (IP auth)</option>'
      + '</select></label>'
      + '<span id="tk_fields" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">'+trunkFields(type)+'</span>'
      + '<button data-pbx="trunk-add" style="'+S.btnGold+'">Add Trunk</button></div>'
      + '<div id="pbx2TrunkMsg" style="font-size:12px;margin-top:4px"></div>'
      + '<div style="font-size:11px;color:#8a8fa8;margin-top:10px;line-height:1.5">Twilio: create an Elastic SIP Trunk in the Twilio console, set its Termination URI here, and add <b style="color:#e8eaf0">162.35.180.58</b> to the trunk\u2019s IP Access Control List. Twilio does not use SIP registration.</div>'
      + '</div>';
  }

  function panelRoutes(){
    return '<div style="'+S.card+'">'
      + '<div style="'+S.h+'">Inbound Routes &mdash; DID &rarr; Extension</div>'
      + '<div id="pbx2InList" style="'+S.listWrap+'"><div style="'+S.row+';color:#8a8fa8;font-size:12px">Loading&hellip;</div></div>'
      + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">'
      + '<label style="'+S.lbl+'">DID (number or catchall)<br><input id="rt_did" placeholder="+14105551234" style="'+S.inp+';width:170px;margin-top:4px"></label>'
      + '<label style="'+S.lbl+'">Extension<br><input id="rt_ext" placeholder="1001" style="'+S.inp+';width:90px;margin-top:4px"></label>'
      + '<button data-pbx="in-add" style="'+S.btnGold+'">Add</button></div>'
      + '<div id="pbx2InMsg" style="font-size:12px;margin-top:8px"></div></div>'

      + '<div style="'+S.card+'">'
      + '<div style="'+S.h+'">Outbound Routes &mdash; Pattern &rarr; Trunk</div>'
      + '<div id="pbx2OutList" style="'+S.listWrap+'"><div style="'+S.row+';color:#8a8fa8;font-size:12px">Loading&hellip;</div></div>'
      + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">'
      + '<label style="'+S.lbl+'">Dial pattern<br><input id="rt_pat" placeholder="_1NXXNXXXXXX" style="'+S.inp+';width:150px;margin-top:4px"></label>'
      + '<label style="'+S.lbl+'">Trunk<br><select id="rt_trunk" style="'+S.sel+';margin-top:4px;min-width:130px"></select></label>'
      + '<label style="'+S.lbl+'">Strip digits<br><input id="rt_strip" type="number" value="0" min="0" max="15" style="'+S.inp+';width:70px;margin-top:4px"></label>'
      + '<label style="'+S.lbl+'">Prepend<br><input id="rt_prep" placeholder="+1" style="'+S.inp+';width:80px;margin-top:4px"></label>'
      + '<button data-pbx="out-add" style="'+S.btnGold+'">Add</button></div>'
      + '<div id="pbx2OutMsg" style="font-size:12px;margin-top:8px"></div>'
      + '<div style="font-size:11px;color:#8a8fa8;margin-top:10px;line-height:1.5">Patterns: <b style="color:#e8eaf0">_1NXXNXXXXXX</b> = US 11-digit &bull; <b style="color:#e8eaf0">_NXXNXXXXXX</b> = US 10-digit &bull; <b style="color:#e8eaf0">_9X.</b> = anything after a 9 (set Strip 1) &bull; <b style="color:#e8eaf0">_+X.</b> = E.164.</div>'
      + '</div>';
  }

  var FEATURES = ['ADSI On-Screen Menu','Alarm Receiver','Append Message','Authentication','Automated Attendant','Blacklists','Blind Transfer','Call Detail Records','Call Forward on Busy','Call Forward on No Answer','Call Forward Variable','Call Monitoring','Call Parking','Call Queuing','Call Recording','Call Retrieval','Call Routing (DID & ANI)','Call Snooping','Call Transfer','Call Waiting','Caller ID','Caller ID Blocking','Caller ID on Call Waiting','Calling Cards','Conference Bridging','Database Store/Retrieve','Database Integration','Dial by Name','Direct Inward System Access','Distinctive Ring','DUNDi','Do Not Disturb','E911','ENUM','Fax (T.38)','Flexible Extension Logic','Interactive Directory Listing','IVR','Local & Remote Call Agents','Macros','Music On Hold','Music On Transfer'];
  function panelFeatures(){
    var live = { 'Authentication':1, 'Caller ID':1, 'Flexible Extension Logic':1, 'Call Routing (DID & ANI)':1, 'Blind Transfer':1, 'Call Transfer':1 };
    var h = '<div style="'+S.card+'"><div style="'+S.h+'">Asterisk Feature Set</div>'
      + '<div style="font-size:12px;color:#8a8fa8;margin-bottom:12px">All of these are built into the Asterisk 22 install on this server. Green = already active via current config. The rest are enabled per-feature (dialplan/modules) &mdash; tell Claude which one to build next and it gets its own controls here.</div>'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px">';
    for (var i=0;i<FEATURES.length;i++){
      var on = live[FEATURES[i]];
      h += '<div style="background:#0e1117;border:1px solid #1e2330;border-radius:8px;padding:9px 12px;display:flex;justify-content:space-between;align-items:center;gap:8px">'
        + '<span style="font-size:12px;color:#e8eaf0">'+esc(FEATURES[i])+'</span>'
        + (on ? tagOn('active') : tagDim('available')) + '</div>';
    }
    return h + '</div></div>';
  }

  /* ═══════════ RENDER ═══════════ */
  function subBtn(id, label){
    return '<div data-pbxsub="'+id+'" style="'+(ST.sub===id?S.subOn:S.sub)+'">'+label+'</div>';
  }
  function render(){
    var root = document.getElementById('tab-pbx'); if (!root) return;
    var body = ST.sub==='ext' ? panelExt() : ST.sub==='trunks' ? panelTrunks() : ST.sub==='routes' ? panelRoutes() : panelFeatures();
    root.innerHTML = '<div style="padding:18px 22px;max-width:1100px">'
      + '<div style="display:flex;gap:6px;margin-bottom:0">'
      + subBtn('ext','Extensions') + subBtn('trunks','Trunks') + subBtn('routes','Routes') + subBtn('features','Features')
      + '</div><div style="border-top:1px solid #2a2f3e;padding-top:14px">' + body + '</div></div>';
    wire(root);
    loadSub();
  }

  function loadSub(){
    if (ST.sub === 'ext') loadExt();
    else if (ST.sub === 'trunks') loadTrunks();
    else if (ST.sub === 'routes'){ loadTrunks(loadRoutes); }
  }

  /* ═══════════ LOADERS ═══════════ */
  function loadExt(){
    get('list').then(function(d){
      var list = document.getElementById('pbx2ExtList'), st = document.getElementById('pbx2Status');
      if (!list) return;
      if (d.error){ list.innerHTML = '<div style="'+S.row+';color:#f87171">'+esc(d.error)+'</div>'; return; }
      var exts = d.extensions||[], reg = d.status||{}, n = 0, h = '';
      for (var i=0;i<exts.length;i++){
        var e = exts[i], on = reg[e.ext]==='registered'; if (on) n++;
        h += '<div style="'+S.row+'"><span style="color:#e8eaf0;font-size:13px;font-weight:700">'+esc(e.ext)+'</span>'
          + '<span>'+(on?tagOn('registered'):tagOff('offline'))+' '
          + (e.ext==='1001' ? tagDim('primary') : '<button data-pbx="ext-del" data-ext="'+esc(e.ext)+'" style="'+S.btnDel+'">Delete</button>')
          + '</span></div>';
      }
      list.innerHTML = h || '<div style="'+S.row+';color:#8a8fa8">No extensions.</div>';
      if (st) st.innerHTML = 'Asterisk PBX active &mdash; <b style="color:#4ade80">'+n+'</b> of '+exts.length+' extensions registered &mdash; WSS: pbx.divafans.club:8089';
      var nums = exts.map(function(e){ return parseInt(e.ext,10); }); var next = 1002;
      while (nums.indexOf(next) !== -1) next++;
      var ne = document.getElementById('pbx2NewExt'); if (ne) ne.placeholder = String(next);
    }).catch(function(){ });
  }

  function trunkTag(live){
    if (live === 'registered')     return '<span style="'+S.tag+';background:#12321f;color:#4ade80">\uD83D\uDFE2 Registered</span>';
    if (live === 'not-registered') return '<span style="'+S.tag+';background:#3a1a1a;color:#f87171">\uD83D\uDD34 Not Registered</span>';
    return '<span style="'+S.tag+';background:#332b12;color:#facc15">\uD83D\uDFE1 Not Required</span>';
  }
  function loadTrunks(cb){
    get('trunk_list').then(function(d){
      ST.trunks = (d && d.trunks) || [];
      var list = document.getElementById('pbx2TrunkList');
      if (list){
        var h = '';
        for (var i=0;i<ST.trunks.length;i++){
          var t = ST.trunks[i];
          h += '<div style="'+S.row+'"><span><span style="color:#e8eaf0;font-size:13px;font-weight:700">'+esc(t.name)+'</span>'
            + ' <span style="color:#8a8fa8;font-size:11px">'+esc(t.type)+' &middot; '+esc(t.meta)+'</span></span>'
            + '<span>'+trunkTag(t.live)+' <button data-pbx="trunk-del" data-name="'+esc(t.name)+'" style="'+S.btnDel+'">Delete</button></span></div>';
        }
        list.innerHTML = h || '<div style="'+S.row+';color:#8a8fa8">No trunks yet.</div>';
      }
      var sel = document.getElementById('rt_trunk');
      if (sel){
        var o = '';
        for (var j=0;j<ST.trunks.length;j++) o += '<option value="'+esc(ST.trunks[j].name)+'">'+esc(ST.trunks[j].name)+'</option>';
        sel.innerHTML = o || '<option value="">(add a trunk first)</option>';
      }
      if (cb) cb();
    }).catch(function(){ if (cb) cb(); });
  }

  function loadRoutes(){
    get('route_list').then(function(d){
      var inL = document.getElementById('pbx2InList'), outL = document.getElementById('pbx2OutList');
      if (inL){
        var h = '';
        var rin = (d && d.in) || [];
        for (var i=0;i<rin.length;i++){ var r = rin[i];
          h += '<div style="'+S.row+'"><span style="color:#e8eaf0;font-size:13px">'+esc(r.did)+' &rarr; ext '+esc(r.ext)+'</span>'
            + '<button data-pbx="route-del" data-kind="in" data-id="'+r.id+'" style="'+S.btnDel+'">Delete</button></div>';
        }
        inL.innerHTML = h || '<div style="'+S.row+';color:#8a8fa8">No inbound routes &mdash; incoming trunk calls hang up.</div>';
      }
      if (outL){
        var g = '';
        var rout = (d && d.out) || [];
        for (var j=0;j<rout.length;j++){ var q = rout[j];
          g += '<div style="'+S.row+'"><span style="color:#e8eaf0;font-size:13px">'+esc(q.pattern)+' &rarr; '+esc(q.trunk)
            + (q.strip?(' &middot; strip '+q.strip):'') + (q.prepend?(' &middot; prepend '+esc(q.prepend)):'') + '</span>'
            + '<button data-pbx="route-del" data-kind="out" data-id="'+q.id+'" style="'+S.btnDel+'">Delete</button></div>';
        }
        outL.innerHTML = g || '<div style="'+S.row+';color:#8a8fa8">No outbound routes &mdash; extensions can only dial each other.</div>';
      }
    }).catch(function(){});
  }

  /* ═══════════ EVENTS ═══════════ */
  function wire(root){
    if (root.dataset.pbx2Wired === '1') return;
    root.dataset.pbx2Wired = '1';

    root.addEventListener('click', function(ev){
      var t = ev.target.closest ? ev.target.closest('[data-pbxsub],[data-pbx]') : null;
      if (!t) return;
      var sub = t.getAttribute('data-pbxsub');
      if (sub){ ST.sub = sub; render(); return; }
      var act = t.getAttribute('data-pbx');
      if (act === 'refresh'){ loadSub(); return; }
      if (act === 'ext-add'){
        var ne = document.getElementById('pbx2NewExt');
        post('add', { ext: (ne.value||ne.placeholder||''), password: val('pbx2NewPass') }).then(function(d){
          if (d.ok){ setmsg('pbx2ExtMsg','Added extension '+d.ext, true); ne.value=''; document.getElementById('pbx2NewPass').value=''; loadExt(); }
          else setmsg('pbx2ExtMsg', d.error||'Failed', false);
        }).catch(function(e){ setmsg('pbx2ExtMsg','Error: '+e.message, false); });
        return;
      }
      if (act === 'ext-del'){
        var x = t.getAttribute('data-ext');
        if (!confirm('Delete extension '+x+'?')) return;
        post('delete', {ext:x}).then(function(d){ if (d.ok) loadExt(); else alert(d.error||'Delete failed'); });
        return;
      }
      if (act === 'trunk-add'){
        post('trunk_add', { type: val('tk_type'), name: val('tk_name'), host: val('tk_host'),
          user: val('tk_user'), pass: val('tk_pass'), match: val('tk_match') }).then(function(d){
          if (d.ok){ setmsg('pbx2TrunkMsg','Trunk '+d.trunk+' added + pjsip reloaded.', true); loadTrunks(); }
          else setmsg('pbx2TrunkMsg', d.error||'Failed', false);
        }).catch(function(e){ setmsg('pbx2TrunkMsg','Error: '+e.message, false); });
        return;
      }
      if (act === 'trunk-del'){
        var n = t.getAttribute('data-name');
        if (!confirm('Delete trunk '+n+'? Outbound routes using it will fail.')) return;
        post('trunk_delete', {name:n}).then(function(d){ if (d.ok) loadTrunks(); else alert(d.error||'Delete failed'); });
        return;
      }
      if (act === 'in-add'){
        post('route_add', { kind:'in', did: val('rt_did'), ext: val('rt_ext') }).then(function(d){
          if (d.ok){ setmsg('pbx2InMsg','Inbound route added + dialplan reloaded.', true); loadRoutes(); }
          else setmsg('pbx2InMsg', d.error||'Failed', false);
        });
        return;
      }
      if (act === 'out-add'){
        post('route_add', { kind:'out', pattern: val('rt_pat'), trunk: val('rt_trunk'),
          strip: val('rt_strip'), prepend: val('rt_prep') }).then(function(d){
          if (d.ok){ setmsg('pbx2OutMsg','Outbound route added + dialplan reloaded.', true); loadRoutes(); }
          else setmsg('pbx2OutMsg', d.error||'Failed', false);
        });
        return;
      }
      if (act === 'route-del'){
        post('route_delete', { kind: t.getAttribute('data-kind'), id: parseInt(t.getAttribute('data-id'),10) })
          .then(function(d){ if (d.ok) loadRoutes(); else alert(d.error||'Delete failed'); });
        return;
      }
    });

    root.addEventListener('change', function(ev){
      if (ev.target.id === 'tk_type'){
        ST.trunkType = ev.target.value;
        var f = document.getElementById('tk_fields');
        if (f) f.innerHTML = trunkFields(ST.trunkType);
      }
    });
  }

  /* take over the tab + keep the old MutationObserver hook working */
  function boot(){
    var root = document.getElementById('tab-pbx');
    if (!root) return;
    window.pbxLoad = function(){ loadSub(); };
    window.pbxAdd = function(){};
    window.pbxDel = function(){};
    render();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
