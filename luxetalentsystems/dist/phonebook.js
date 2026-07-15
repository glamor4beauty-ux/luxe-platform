/* Self-contained Phonebook — photo + stage/real name + phone + radio select.
   No external dependencies. Selecting a row fills the dialpad number. */
(function(){
  var API_BASE = 'https://luxetalentsystems.com';
  var DATA = [];
  var SELECTED = '';

  function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  // Resolve a profile_photo value (full URL, or JSON-array local path) to a usable src.
  function photoSrc(pp){
    if(!pp) return '';
    pp = String(pp).trim();
    if(pp.charAt(0) === '['){ try { var a = JSON.parse(pp); pp = (a && a[0]) ? a[0] : ''; } catch(e){ pp=''; } }
    if(!pp) return '';
    if(pp.indexOf('http') === 0) return pp;
    return API_BASE + '/' + pp.replace(/^\/+/, '');
  }

  function initials(stage, real){
    var base = (stage || real || '?').trim();
    var parts = base.split(/\s+/);
    var i = (parts[0]||'?').charAt(0) + (parts[1] ? parts[1].charAt(0) : '');
    return i.toUpperCase();
  }

  function ensureModal(){
    if(document.getElementById('pbModal')) return;
    var m = document.createElement('div');
    m.id = 'pbModal';
    m.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99999;align-items:flex-start;justify-content:center';
    m.innerHTML = '<style>#pbModal input[name="pbradio"]{accent-color:#9aa0ad}#pbModal input[name="pbradio"]:checked{accent-color:#4ade80}</style>' +
      '<div style="background:#11151c;border:1px solid #2a2f3e;border-radius:14px;width:720px;max-width:96vw;margin-top:50px;max-height:82vh;display:flex;flex-direction:column;overflow:hidden;font-family:inherit">'
      + '<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #1e2330">'
        + '<span style="font-size:15px;font-weight:700;color:#d4a830">Phonebook</span>'
        + '<button id="pbClose" style="background:transparent;border:none;color:#8a8fa8;font-size:22px;cursor:pointer;line-height:1">&times;</button>'
      + '</div>'
      + '<div style="padding:12px 18px;border-bottom:1px solid #1e2330">'
        + '<input id="pbSearch" type="text" placeholder="Search stage or real name..." style="width:100%;background:#1a1f2e;border:1px solid #2a2f3e;border-radius:8px;padding:9px 12px;color:#e8eaf0;font-size:13px;outline:none"/>'
      + '</div>'
      + '<div id="pbList" style="overflow-y:auto;flex:1;padding:4px 0"></div>'
      + '<div style="padding:12px 18px;border-top:1px solid #1e2330;display:flex;align-items:center;gap:12px">'
        + '<span id="pbSel" style="flex:1;font-size:12px;color:#8a8fa8">No contact selected</span>'
        + '<button id="pbUse" style="padding:9px 18px;background:#d4a830;color:#0e1117;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;opacity:.5" disabled>Use Number</button>'
      + '</div>'
      + '</div>';
    document.body.appendChild(m);
    document.getElementById('pbClose').addEventListener('click', closePB);
    m.addEventListener('click', function(e){ if(e.target===m) closePB(); });
    document.getElementById('pbSearch').addEventListener('input', render);
    document.getElementById('pbUse').addEventListener('click', useNumber);
  }

  function closePB(){ var m=document.getElementById('pbModal'); if(m) m.style.display='none'; }

  var SELECTED_EMAIL = '';
  function onPick(phone, label, email){
    SELECTED = phone;
    SELECTED_EMAIL = email || '';
    var sel = document.getElementById('pbSel');
    if(sel) sel.textContent = 'Selected: ' + label + '  (' + phone + ')';
    var btn = document.getElementById('pbUse');
    if(btn){ btn.disabled = false; btn.style.opacity = '1'; }
  }

  function useNumber(){
    if(!SELECTED) return;
    var videoActive = !!document.querySelector('#cv-video.active');
    if(videoActive && SELECTED_EMAIL){
      // Wire the Phonebook pick to the Video sub-view target
      var hidden = document.getElementById('vidPerfSelect'); if(hidden) hidden.value = SELECTED_EMAIL;
      var label = document.getElementById('vidTarget');
      if(label){ label.textContent = 'Target: ' + (document.querySelector('input[name="pbradio"]:checked').getAttribute('data-label') || SELECTED_EMAIL); label.style.color = '#4ade80'; }
      closePB();
      return;
    }
    var digits = SELECTED.replace(/[^0-9+]/g,'');
    try {
      if(typeof window.dialStr !== 'undefined'){ window.dialStr = digits.replace(/\D/g,''); }
      var d = document.getElementById('dialDisplay'); if(d) d.textContent = digits;
      if(typeof window.commsSwitch === 'function') window.commsSwitch('voice');
    } catch(e){}
    closePB();
  }

  function render(){
    var q = (document.getElementById('pbSearch').value||'').toLowerCase();
    var list = document.getElementById('pbList');
    var rows = DATA.filter(function(p){
      if(!q) return true;
      return (p.stage_name||'').toLowerCase().indexOf(q)>-1
          || (p.first_name||'').toLowerCase().indexOf(q)>-1
          || (p.last_name||'').toLowerCase().indexOf(q)>-1;
    });
    if(!rows.length){ list.innerHTML = '<div style="padding:24px;text-align:center;color:#6a6f88;font-size:12px">No contacts with a phone number</div>'; return; }
    list.innerHTML = rows.map(function(p, idx){
      var stage = esc(p.stage_name || '(no stage name)');
      var real  = esc(((p.first_name||'')+' '+(p.last_name||'')).trim() || '-');
      var phone = esc(p.phone || '');
      var src   = photoSrc(p.profile_photo);
      var avatar = src
        ? '<img src="'+esc(src)+'" style="width:54px;height:54px;border-radius:50%;object-fit:cover;flex-shrink:0;background:#1a1f2e" onerror="this.style.display=\'none\';this.nextSibling.style.display=\'flex\'"/><div style="display:none;width:54px;height:54px;border-radius:50%;background:#1a1f2e;color:#d4a830;font-weight:700;align-items:center;justify-content:center;font-size:16px;flex-shrink:0">'+esc(initials(p.stage_name,((p.first_name||'')+' '+(p.last_name||''))))+'</div>'
        : '<div style="display:flex;width:54px;height:54px;border-radius:50%;background:#1a1f2e;color:#d4a830;font-weight:700;align-items:center;justify-content:center;font-size:16px;flex-shrink:0">'+esc(initials(p.stage_name,((p.first_name||'')+' '+(p.last_name||''))))+'</div>';
      return '<label style="display:flex;align-items:center;gap:16px;padding:14px 20px;border-bottom:1px solid #1e2330;cursor:pointer">'
        + '<input type="radio" name="pbradio" value="'+esc(p.phone||'')+'" data-label="'+esc(p.stage_name||real)+'" data-email="'+esc(p.email||'')+'" style="width:22px;height:22px;accent-color:#9aa0ad;cursor:pointer;flex-shrink:0"/>'
        + avatar
        + '<div style="font-size:17px;font-weight:700;color:#e8eaf0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0;flex:1.2">'+stage+'</div>'
        + '<div style="font-size:16px;color:#b8bdd0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0;flex:1.2">'+real+'</div>'
        + '<div style="font-size:16px;color:#8a8fa8;font-family:ui-monospace,monospace;white-space:nowrap;flex-shrink:0">'+phone+'</div>'
        + '</label>';
    }).join('');
    list.querySelectorAll('input[name="pbradio"]').forEach(function(r){
      r.addEventListener('change', function(){ onPick(r.value, r.getAttribute('data-label'), r.getAttribute('data-email')); });
    });
  }

  window.openPhonebook = function(){
    ensureModal();
    SELECTED = '';
    var m = document.getElementById('pbModal');
    m.style.display = 'flex';
    document.getElementById('pbSearch').value = '';
    document.getElementById('pbSel').textContent = 'No contact selected';
    var btn = document.getElementById('pbUse'); btn.disabled = true; btn.style.opacity = '.5';
    var list = document.getElementById('pbList');
    list.innerHTML = '<div style="padding:24px;text-align:center;color:#6a6f88;font-size:12px">Loading...</div>';
    fetch(API_BASE+'/api/performers.php?action=list')
      .then(function(r){ return r.json(); })
      .then(function(rows){
        DATA = (Array.isArray(rows)?rows:[]).filter(function(p){ return p.phone && String(p.phone).trim()!==''; });
        render();
      })
      .catch(function(e){
        list.innerHTML = '<div style="padding:24px;text-align:center;color:#f87171;font-size:12px">Failed to load: '+esc(e.message)+'</div>';
      });
  };
})();
