/* Self-contained Phonebook — no external dependencies.
   Creates its own button + modal, fetches performers, lists stage/real/phone. */
(function(){
  var API_BASE = 'https://luxetalentsystems.com/api';
  var DATA = [];

  function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  function ensureModal(){
    if(document.getElementById('pbModal')) return;
    var m = document.createElement('div');
    m.id = 'pbModal';
    m.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99999;align-items:flex-start;justify-content:center';
    m.innerHTML =
      '<div style="background:#11151c;border:1px solid #2a2f3e;border-radius:14px;width:560px;max-width:94vw;margin-top:60px;max-height:80vh;display:flex;flex-direction:column;overflow:hidden;font-family:inherit">'
      + '<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #1e2330">'
        + '<span style="font-size:15px;font-weight:700;color:#d4a830">Phonebook</span>'
        + '<button id="pbClose" style="background:transparent;border:none;color:#8a8fa8;font-size:22px;cursor:pointer;line-height:1">&times;</button>'
      + '</div>'
      + '<div style="padding:12px 18px;border-bottom:1px solid #1e2330">'
        + '<input id="pbSearch" type="text" placeholder="Search stage or real name..." style="width:100%;background:#1a1f2e;border:1px solid #2a2f3e;border-radius:8px;padding:9px 12px;color:#e8eaf0;font-size:13px;outline:none"/>'
      + '</div>'
      + '<div id="pbList" style="overflow-y:auto;padding:4px 0"></div>'
      + '</div>';
    document.body.appendChild(m);
    document.getElementById('pbClose').addEventListener('click', closePB);
    m.addEventListener('click', function(e){ if(e.target===m) closePB(); });
    document.getElementById('pbSearch').addEventListener('input', render);
  }

  function closePB(){ var m=document.getElementById('pbModal'); if(m) m.style.display='none'; }

  function render(){
    var q = (document.getElementById('pbSearch').value||'').toLowerCase();
    var list = document.getElementById('pbList');
    var rows = DATA.filter(function(p){
      if(!q) return true;
      return (p.stage_name||'').toLowerCase().indexOf(q)>-1
          || (p.first_name||'').toLowerCase().indexOf(q)>-1
          || (p.last_name||'').toLowerCase().indexOf(q)>-1;
    });
    if(!rows.length){ list.innerHTML = '<div style="padding:20px;text-align:center;color:#6a6f88;font-size:12px">No contacts with a phone number</div>'; return; }
    list.innerHTML = rows.map(function(p){
      var stage = esc(p.stage_name || '(no stage name)');
      var real  = esc(((p.first_name||'')+' '+(p.last_name||'')).trim() || '-');
      var phone = esc(p.phone || '');
      var pj = (p.phone||'').replace(/[^0-9+]/g,'');
      return '<div style="display:flex;align-items:center;gap:10px;padding:10px 18px;border-bottom:1px solid #1e2330">'
        + '<div style="flex:1;min-width:0">'
          + '<div style="font-size:13px;font-weight:600;color:#e8eaf0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'+stage+'</div>'
          + '<div style="font-size:11px;color:#8a8fa8">'+real+'</div>'
          + '<div style="font-size:11px;color:#6a6f88;font-family:ui-monospace,monospace">'+phone+'</div>'
        + '</div>'
        + '<button data-pbcall="'+pj+'" style="padding:6px 12px;background:#4ade80;border:none;border-radius:6px;color:#0e1117;font-size:12px;font-weight:700;cursor:pointer">Call</button>'
        + '<button data-pbtext="'+pj+'" style="padding:6px 12px;background:transparent;border:1px solid #d4a830;border-radius:6px;color:#d4a830;font-size:12px;font-weight:600;cursor:pointer">Text</button>'
      + '</div>';
    }).join('');
    list.querySelectorAll('[data-pbcall]').forEach(function(b){ b.addEventListener('click', function(){ doCall(b.getAttribute('data-pbcall')); }); });
    list.querySelectorAll('[data-pbtext]').forEach(function(b){ b.addEventListener('click', function(){ doText(b.getAttribute('data-pbtext')); }); });
  }

  function doCall(phone){
    closePB();
    var digits = (phone||'').replace(/\D/g,'');
    // Load into the dialpad if it exists; otherwise just inform.
    try {
      if(typeof window.dialStr !== 'undefined'){ window.dialStr = digits; }
      var d = document.getElementById('dialDisplay'); if(d) d.textContent = digits;
      if(typeof window.commsSwitch === 'function') window.commsSwitch('voice');
    } catch(e){}
  }

  function doText(phone){
    closePB();
    var msg = prompt('Message to '+phone+':');
    if(!msg) return;
    fetch(API_BASE+'/sms.php?action=send', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({to:phone, body:msg})})
      .then(function(r){return r.json();})
      .then(function(d){ alert(d && d.success ? 'SMS sent' : ('Error: '+((d&&d.error)||'failed'))); })
      .catch(function(e){ alert('Error: '+e.message); });
  }

  window.openPhonebook = function(){
    ensureModal();
    var m = document.getElementById('pbModal');
    m.style.display = 'flex';
    document.getElementById('pbSearch').value = '';
    var list = document.getElementById('pbList');
    list.innerHTML = '<div style="padding:20px;text-align:center;color:#6a6f88;font-size:12px">Loading...</div>';
    fetch(API_BASE+'/performers.php?action=list')
      .then(function(r){ return r.json(); })
      .then(function(rows){
        DATA = (Array.isArray(rows)?rows:[]).filter(function(p){ return p.phone && String(p.phone).trim()!==''; });
        render();
      })
      .catch(function(e){
        list.innerHTML = '<div style="padding:20px;text-align:center;color:#f87171;font-size:12px">Failed to load: '+esc(e.message)+'</div>';
      });
  };
})();
