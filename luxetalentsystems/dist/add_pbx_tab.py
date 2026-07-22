#!/usr/bin/env python3
# Inserts a PBX tab (nav item + tab content + JS) into the live dashboard.html.
# Safe: only inserts; run on the server so it preserves jssip/logo/card edits.
import re, sys
f='/var/www/sites/luxetalentsystems/dist/dashboard.html'
s=open(f).read()

if 'id="tab-pbx"' in s:
    print("PBX tab already present — aborting to avoid duplicate."); sys.exit(0)

# --- 1) NAV item: add "PBX" after the Settings nav link ---
# find the Settings nav item (data-tab or onclick referencing settings)
nav_pat = re.compile(r'(<[^>]*(?:data-tab="settings"|showTab\(\s*[\'"]settings[\'"]\s*\))[^>]*>.*?</[a-z]+>)', re.S)
m = nav_pat.search(s)
if m:
    settings_nav = m.group(1)
    # clone it for PBX
    pbx_nav = settings_nav.replace('settings','pbx').replace('Settings','PBX')
    # strip any icon-specific text duplication; keep it simple
    s = s.replace(settings_nav, settings_nav + '\n' + pbx_nav, 1)
    print("nav item inserted after Settings")
else:
    print("WARN: Settings nav not found by pattern — will still add tab content; add nav link manually")

# --- 2) TAB CONTENT: insert a #tab-pbx block. Put it right before the Settings tab content ---
tab_anchor = re.search(r'<div[^>]*id="tab-settings"[^>]*>', s)
pbx_tab = '''
    <div class="tab-content" id="tab-pbx" style="display:none">
      <h1 style="font-size:22px;font-weight:700;margin:0 0 16px">PBX — Phone System</h1>

      <!-- Status -->
      <div style="background:#131720;border:1px solid #2a2f3e;border-radius:12px;padding:16px;margin-bottom:14px">
        <div style="font-size:13px;font-weight:700;color:#d4a830;margin-bottom:10px">Status</div>
        <div id="pbxStatus" style="font-size:13px;color:#8a8fa8">Loading…</div>
      </div>

      <!-- Extensions -->
      <div style="background:#131720;border:1px solid #2a2f3e;border-radius:12px;padding:16px;margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
          <div style="font-size:13px;font-weight:700;color:#d4a830">Extensions</div>
          <button onclick="pbxLoad()" style="background:#1a1f2e;border:1px solid #2a2f3e;color:#8a8fa8;border-radius:6px;padding:4px 10px;font-size:11px;cursor:pointer">Refresh</button>
        </div>
        <div id="pbxExtList" style="margin-bottom:14px"><div style="color:#8a8fa8;font-size:12px">Loading…</div></div>
        <div style="border-top:1px solid #2a2f3e;padding-top:12px">
          <div style="font-size:12px;color:#8a8fa8;margin-bottom:8px">Add Extension</div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
            <label style="font-size:11px;color:#8a8fa8">Extension<br><input id="pbxNewExt" placeholder="1002" style="width:90px;margin-top:4px;background:#0e1117;border:1px solid #2a2f3e;border-radius:8px;padding:8px;color:#e8eaf0;font-size:13px;outline:none"></label>
            <label style="font-size:11px;color:#8a8fa8">Password<br><input id="pbxNewPass" placeholder="min 6 chars" style="width:160px;margin-top:4px;background:#0e1117;border:1px solid #2a2f3e;border-radius:8px;padding:8px;color:#e8eaf0;font-size:13px;outline:none"></label>
            <button onclick="pbxAdd()" id="pbxAddBtn" style="background:#4ade80;color:#0e1117;border:none;border-radius:8px;padding:9px 18px;font-size:12px;font-weight:700;cursor:pointer">Add</button>
          </div>
          <div id="pbxAddMsg" style="font-size:12px;margin-top:8px"></div>
        </div>
      </div>

      <!-- Trunk (Phase 2) -->
      <div style="background:#131720;border:1px solid #2a2f3e;border-radius:12px;padding:16px;margin-bottom:14px">
        <div style="font-size:13px;font-weight:700;color:#d4a830;margin-bottom:10px">Trunk (Outside Calls)</div>
        <div style="font-size:13px;color:#8a8fa8">Not configured. Set up in Phase 2 (Twilio SIP trunk) to enable calls to/from real phone numbers.</div>
      </div>

      <!-- Phone Numbers (Phase 2) -->
      <div style="background:#131720;border:1px solid #2a2f3e;border-radius:12px;padding:16px">
        <div style="font-size:13px;font-weight:700;color:#d4a830;margin-bottom:10px">Phone Numbers</div>
        <div style="font-size:13px;color:#8a8fa8">No numbers yet — added with the trunk in Phase 2.</div>
      </div>
    </div>
'''
if tab_anchor:
    s = s[:tab_anchor.start()] + pbx_tab + '\n' + s[tab_anchor.start():]
    print("tab content inserted before Settings tab")
else:
    # fallback: insert before </div> that closes main content — put before last </body>
    s = s.replace('</body>', pbx_tab+'\n</body>', 1)
    print("WARN: settings tab anchor not found; appended tab content near end")

# --- 3) JS ---
js = '''
<script>
/* PBX tab — talks to /api/pbx.php */
(function(){
  function esc(t){return String(t==null?'':t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
  window.pbxLoad=async function(){
    var st=document.getElementById('pbxStatus'), list=document.getElementById('pbxExtList');
    if(!list) return;
    try{
      var r=await fetch('/api/pbx.php?action=list',{credentials:'include'});
      if(r.status===401){ list.innerHTML='<div style="color:#f59e0b;font-size:12px">Sign in to manage the PBX</div>'; if(st)st.textContent='Not signed in'; return; }
      var d=await r.json();
      if(!d.ok){ list.innerHTML='<div style="color:#f59e0b;font-size:12px">'+esc(d.error||'Error')+'</div>'; return; }
      var exts=d.extensions||[], status=d.status||{};
      var reg=exts.filter(function(e){return status[e.ext]==='registered';}).length;
      if(st) st.innerHTML='Asterisk PBX active · <b style="color:#4ade80">'+reg+'</b> of '+exts.length+' extensions registered · WSS: pbx.divafans.club:8089';
      list.innerHTML = exts.length ? exts.map(function(e){
        var on=status[e.ext]==='registered';
        var dot=on?'#4ade80':'#64748b';
        var del=e.ext==='1001'?'<span style="font-size:10px;color:#64748b">primary</span>':'<button onclick="pbxDel(\\''+esc(e.ext)+'\\')" style="background:#3a1a1a;color:#f87171;border:1px solid #5a2a2a;border-radius:6px;padding:3px 10px;font-size:11px;cursor:pointer">Delete</button>';
        return '<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #1e2330"><span style="width:8px;height:8px;border-radius:50%;background:'+dot+'"></span><span style="font-size:14px;font-weight:600;color:#e8eaf0;min-width:60px">'+esc(e.ext)+'</span><span style="font-size:11px;color:'+(on?'#4ade80':'#8a8fa8')+'">'+(on?'registered':'offline')+'</span><span style="margin-left:auto">'+del+'</span></div>';
      }).join('') : '<div style="color:#8a8fa8;font-size:12px">No extensions</div>';
      // suggest next free number
      var nums=exts.map(function(e){return parseInt(e.ext,10);}).filter(function(n){return n>=1000&&n<2000;});
      var next=1002; while(nums.indexOf(next)!==-1) next++;
      var ne=document.getElementById('pbxNewExt'); if(ne&&!ne.value) ne.placeholder=String(next);
    }catch(e){ list.innerHTML='<div style="color:#ef4444;font-size:12px">Error: '+esc(e.message)+'</div>'; }
  };
  window.pbxAdd=async function(){
    var ext=(document.getElementById('pbxNewExt').value||document.getElementById('pbxNewExt').placeholder||'').trim();
    var pass=(document.getElementById('pbxNewPass').value||'').trim();
    var msg=document.getElementById('pbxAddMsg'), btn=document.getElementById('pbxAddBtn');
    msg.style.color='#8a8fa8'; msg.textContent='Adding…'; btn.disabled=true;
    try{
      var r=await fetch('/api/pbx.php?action=add',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify({ext:ext,password:pass})});
      var d=await r.json();
      if(d.ok){ msg.style.color='#4ade80'; msg.textContent='Added extension '+d.ext; document.getElementById('pbxNewExt').value=''; document.getElementById('pbxNewPass').value=''; pbxLoad(); }
      else { msg.style.color='#f87171'; msg.textContent=d.error||'Failed'; }
    }catch(e){ msg.style.color='#ef4444'; msg.textContent='Error: '+e.message; }
    btn.disabled=false;
  };
  window.pbxDel=async function(ext){
    if(!confirm('Delete extension '+ext+'?')) return;
    try{
      var r=await fetch('/api/pbx.php?action=delete',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify({ext:ext})});
      var d=await r.json();
      if(d.ok) pbxLoad(); else alert(d.error||'Delete failed');
    }catch(e){ alert('Error: '+e.message); }
  };
  // load when the PBX tab is shown (poll for visibility as a simple hook)
  document.addEventListener('DOMContentLoaded',function(){
    var t=document.getElementById('tab-pbx');
    if(t){ var obs=new MutationObserver(function(){ if(t.style.display!=='none') pbxLoad(); }); obs.observe(t,{attributes:true,attributeFilter:['style']}); }
  });
})();
</script>
'''
s = s.replace('</body>', js+'\n</body>', 1)
print("JS inserted")

open(f,'w').write(s)
# balance check
print("div diff", s.count('<div')-s.count('</div>'))
print("DONE")
