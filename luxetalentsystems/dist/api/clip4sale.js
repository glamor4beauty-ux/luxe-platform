/* Clip4Sale — rebuilt from scratch (design step).
   Renders to #c4sAdminRoot via window.c4sLoadAdmin, and #c4sPerfRoot via window.c4sLoadPerformer.
   CSP-safe: inline styles only, no <style> injection, no eval/new Function. */
(function(){
  "use strict";
  var API = 'https://luxetalentsystems.com/api/clip4sale.php';

  function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  var S = {
    card:'background:#131720;border:1px solid #1e2330;border-radius:12px;padding:16px',
    h:'font-size:15px;font-weight:700;color:#e8eaf0;margin:0 0 12px',
    tblwrap:'overflow:auto;max-height:340px;border:1px solid #1e2330;border-radius:10px;background:#0e1117',
    th:'padding:10px 12px;text-align:left;font-size:11px;color:#d4a830;font-weight:700;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #1e2330;white-space:nowrap;position:sticky;top:0;background:#0e1117',
    td:'padding:9px 12px;font-size:13px;color:#e8eaf0;border-bottom:1px solid #161b27;white-space:nowrap',
    btnGold:'padding:7px 14px;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;background:#d4a830;color:#0e1117',
    btnGhost:'padding:7px 14px;border:1px solid #2a2f3e;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;background:transparent;color:#8a8fa8'
  };

  function revRow(label, amt, pct){
    return '<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #161b27">'
      + '<span style="color:#e8eaf0;font-size:14px">'+esc(label)+'</span>'
      + '<span style="display:flex;gap:16px;align-items:center">'
      + '<span style="color:#e8eaf0;font-weight:700">'+esc(amt)+'</span>'
      + '<span style="color:#16a34a;font-size:12px;min-width:52px;text-align:right">'+esc(pct)+'</span></span></div>';
  }
  function cmRow(fn,cat,ty,vis,perf,price,date){
    return '<tr><td style="'+S.td+'">'+esc(fn)+'</td><td style="'+S.td+'">'+esc(cat)+'</td><td style="'+S.td+'">'+esc(ty)+'</td>'
      + '<td style="'+S.td+'">'+esc(vis)+'</td><td style="'+S.td+'">'+esc(perf)+'</td><td style="'+S.td+'">'+esc(price)+'</td>'
      + '<td style="'+S.td+';color:#8a8fa8;font-size:12px">'+esc(date)+'</td></tr>';
  }
  function tsRow(date,inc,etp,amd,pv,sold,promo){
    return '<tr><td style="'+S.td+';color:#8a8fa8;font-size:12px">'+esc(date)+'</td><td style="'+S.td+'">'+esc(inc)+'</td>'
      + '<td style="'+S.td+'">'+esc(etp)+'</td><td style="'+S.td+'">'+esc(amd)+'</td><td style="'+S.td+'">'+esc(pv)+'</td>'
      + '<td style="'+S.td+'">'+esc(sold)+'</td><td style="'+S.td+'">'+esc(promo)+'</td></tr>';
  }

  function render(root, scopeName){
    if(!root) return;
    var wide = window.innerWidth >= 980;
    var gridCols = wide ? 'minmax(0,4fr) minmax(0,6fr)' : '1fr';

    var ranking = '<div style="'+S.card+';margin-bottom:16px">'
      + '<div style="'+S.h+'">Store Global Ranking</div>'
      + '<div style="background:#0e1117;border:1px solid #1e2330;border-radius:8px;padding:12px;display:flex;align-items:center;gap:12px">'
      + '<span style="color:#e8eaf0;font-weight:700">Real Amateur Lust</span>'
      + '<div style="flex:1;height:10px;background:#3730a3;border-radius:5px"></div></div></div>';

    var revenue = '<div style="'+S.card+';margin-bottom:16px">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">'
      + '<div style="'+S.h+';margin:0">Revenue</div>'
      + '<select style="background:#0e1117;border:1px solid #2a2f3e;border-radius:8px;padding:5px 10px;color:#e8eaf0;font-size:12px;cursor:pointer">'
      + '<option>This Month</option><option>Last Month</option><option>This Year</option><option>All Time</option></select></div>'
      + revRow('Income','$0.00','0.00%') + revRow('ETP','$0.00','0.00%')
      + revRow('AMP','$0.00','0.00%') + revRow('Referral Income','$0.00','0.00%') + '</div>';

    var stores = '<div style="'+S.card+'">'
      + '<div style="'+S.h+'">My Stores</div>'
      + '<div style="background:#0e1117;border:1px solid #1e2330;border-radius:10px;padding:14px;display:flex;align-items:center;gap:12px">'
      + '<div style="width:48px;height:48px;border-radius:8px;background:#3a1a1a;display:flex;align-items:center;justify-content:center;font-size:10px;color:#d4a830;font-weight:700">RAL</div>'
      + '<div style="flex:1"><div style="color:#e8eaf0;font-weight:700;font-size:15px">Real Amateur Lust</div>'
      + '<div style="color:#8a8fa8;font-size:12px">Store ID: 492439</div>'
      + '<div style="color:#8a8fa8;font-size:12px"><span id="c4sClipCount">— clips</span> &bull; <span style="color:#16a34a">Open</span></div></div>'
      + '<span style="color:#8a8fa8;font-size:18px">&#8250;</span></div></div>';

    var cmRows = cmRow('mia_naked.mp4','Solo','Video','Public','Dulce','$7.99','2026-05-28')
      + cmRow('vibrator_friend.mp4','Toys','Video','Public','Dulce','$13.99','2026-05-29')
      + cmRow('lisa_bellydance.mp4','Dance','Video','Members','LisaX8X','$11.99','2026-06-05');
    var contentMgmt = '<div style="'+S.card+';margin-bottom:16px">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">'
      + '<div style="'+S.h+';margin:0">Content Management</div>'
      + '<div style="display:flex;gap:8px"><button style="'+S.btnGhost+'">Upload File</button><button style="'+S.btnGold+'">+ Manual Entry</button></div></div>'
      + '<div style="'+S.tblwrap+'"><table style="border-collapse:collapse;min-width:640px;width:100%"><thead><tr>'
      + '<th style="'+S.th+'">File Name</th><th style="'+S.th+'">Category</th><th style="'+S.th+'">Type</th><th style="'+S.th+'">Visibility</th>'
      + '<th style="'+S.th+'">Performers</th><th style="'+S.th+'">Price</th><th style="'+S.th+'">Publish Date</th>'
      + '</tr></thead><tbody>'+cmRows+'</tbody></table></div></div>';

    var tsRows = tsRow('2026-06-01','$0.00','$0.00','$0.00','0','0','0') + tsRow('2026-06-02','$0.00','$0.00','$0.00','0','0','0');
    var trackSales = '<div style="'+S.card+'">'
      + '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">'
      + '<div style="'+S.h+';margin:0">Track Sales</div>'
      + '<div style="display:flex;gap:8px"><button style="'+S.btnGhost+'">Upload File</button><button style="'+S.btnGold+'">+ Manual Entry</button></div></div>'
      + '<div style="'+S.tblwrap+'"><table style="border-collapse:collapse;min-width:640px;width:100%"><thead><tr>'
      + '<th style="'+S.th+'">Date</th><th style="'+S.th+'">Income</th><th style="'+S.th+'">ETP</th><th style="'+S.th+'">AMD</th>'
      + '<th style="'+S.th+'">Page Views</th><th style="'+S.th+'">Clip Sold</th><th style="'+S.th+'">Promo Sales</th>'
      + '</tr></thead><tbody>'+tsRows+'</tbody></table></div></div>';

    var header = '<h2 style="margin:0 0 16px;color:#e8eaf0;font-size:20px">Dashboard'+(scopeName?(' &mdash; '+esc(scopeName)):'')+'</h2>';
    root.innerHTML = header
      + '<div style="display:grid;grid-template-columns:'+gridCols+';gap:16px">'
      + '<div>'+ranking+revenue+stores+'</div>'
      + '<div>'+contentMgmt+trackSales+'</div></div>';

    // update clip count (non-blocking; ignore failure)
    try{
      fetch(API+'?action=clips_list').then(function(r){return r.json();}).then(function(d){
        var n=(d&&d.clips&&d.clips.length)||0; var el=document.getElementById('c4sClipCount'); if(el) el.textContent=n+' clips';
      }).catch(function(){ var el=document.getElementById('c4sClipCount'); if(el) el.textContent='0 clips'; });
    }catch(e){}
  }

  window.c4sLoadAdmin = function(){ render(document.getElementById('c4sAdminRoot'), ''); };
  window.c4sLoadPerformer = function(name){ render(document.getElementById('c4sPerfRoot'), name||''); };

  // stubs so any leftover onclick handlers from the old UI don't throw
  window.c4sOpenClip   = window.c4sOpenClip   || function(){ alert('Clip editor — pending rebuild (step 2)'); };
  window.c4sViewSales  = window.c4sViewSales  || function(){ alert('Sales view — pending rebuild (step 2)'); };
  window.c4sDeleteClip = window.c4sDeleteClip || function(){ alert('Delete — pending rebuild (step 2)'); };
  window.c4sInlineStatus = window.c4sInlineStatus || function(){};

  // responsive: re-render on resize (debounced)
  var _t; window.addEventListener('resize', function(){ clearTimeout(_t); _t=setTimeout(function(){
    var a=document.getElementById('c4sAdminRoot'); if(a && a.offsetParent!==null){ render(a,''); }
    var p=document.getElementById('c4sPerfRoot'); if(p && p.offsetParent!==null){ render(p,''); }
  },250); });
})();
