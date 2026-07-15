/* Luxe Talent System — new-registration pop-up overlay (self-contained, removable)
 * Loaded by dashboard.html. Every 60s it asks api/newregs.php for sign-ups
 * created since the last one it saw, and pops a dismissible box for each.
 * Closed boxes are remembered (per browser) and never return.
 * Remove this file + api/newregs.php to fully remove the feature.
 */
(function () {
  'use strict';

  var ENDPOINT  = '/api/newregs.php';
  var POLL_MS   = 60000;            // 60 seconds
  var LS_LATEST = 'luxeNewRegLatest';   // newest created_at seen
  var LS_SEEN   = 'luxeNewRegSeen';     // emails already dismissed

  function getSeen() {
    try { return JSON.parse(localStorage.getItem(LS_SEEN) || '[]'); }
    catch (e) { return []; }
  }
  function addSeen(email) {
    var s = getSeen();
    if (s.indexOf(email) === -1) { s.push(email); }
    if (s.length > 400) s = s.slice(-400);
    try { localStorage.setItem(LS_SEEN, JSON.stringify(s)); } catch (e) {}
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  /* ---- styles ---- */
  function injectCSS() {
    if (document.getElementById('luxe-nr-css')) return;
    var s = document.createElement('style');
    s.id = 'luxe-nr-css';
    s.textContent =
      "#luxeNrStack{position:fixed;top:70px;right:22px;z-index:99999;display:flex;" +
        "flex-direction:column;gap:12px;width:300px;pointer-events:none}" +
      ".luxe-nr-box{pointer-events:auto;background:#1a1f2e;border:1px solid #2a2f3e;" +
        "border-left:4px solid #d4a830;border-radius:12px;padding:16px 38px 16px 16px;" +
        "position:relative;box-shadow:0 10px 30px rgba(0,0,0,.55);" +
        "font-family:'DM Sans',sans-serif;animation:luxeNrIn .25s ease}" +
      "@keyframes luxeNrIn{from{opacity:0;transform:translateX(40px)}to{opacity:1;transform:none}}" +
      ".luxe-nr-box .hd{font-size:10px;font-weight:700;text-transform:uppercase;" +
        "letter-spacing:.07em;color:#d4a830}" +
      ".luxe-nr-box .stage{font-size:16px;font-weight:700;color:#e8eaf0;margin-top:5px}" +
      ".luxe-nr-box .real{font-size:12px;color:#8a8fa8;margin-top:1px}" +
      ".luxe-nr-box .x{position:absolute;top:8px;right:10px;width:24px;height:24px;" +
        "background:transparent;border:none;color:#8a8fa8;font-size:18px;cursor:pointer;" +
        "line-height:1;border-radius:6px}" +
      ".luxe-nr-box .x:hover{background:#2a2f3e;color:#e8eaf0}";
    document.head.appendChild(s);
  }

  function stack() {
    var st = document.getElementById('luxeNrStack');
    if (!st) {
      st = document.createElement('div');
      st.id = 'luxeNrStack';
      document.body.appendChild(st);
    }
    return st;
  }

  function popBox(reg) {
    var stage = (reg.stage_name && reg.stage_name.trim()) || '(no stage name)';
    var real  = [reg.first_name, reg.last_name].filter(Boolean).join(' ').trim() || '—';
    var box = document.createElement('div');
    box.className = 'luxe-nr-box';
    box.innerHTML =
      '<button class="x" title="Dismiss">&#10005;</button>' +
      '<div class="hd">New Registration</div>' +
      '<div class="stage">' + esc(stage) + '</div>' +
      '<div class="real">' + esc(real) + '</div>';
    box.querySelector('.x').addEventListener('click', function () {
      addSeen(reg.email || (stage + '|' + real));
      box.remove();
    });
    stack().appendChild(box);
  }

  function check() {
    var since = null;
    try { since = localStorage.getItem(LS_LATEST); } catch (e) {}
    var url = ENDPOINT + (since ? '?since=' + encodeURIComponent(since) : '');

    fetch(url).then(function (r) { return r.json(); }).then(function (d) {
      if (!d || d.error) return;

      // first run (no marker yet): just set the marker, don't pop a backlog
      if (!since) {
        if (d.latest) { try { localStorage.setItem(LS_LATEST, d.latest); } catch (e) {} }
        return;
      }

      var seen = getSeen();
      (d.registrations || []).forEach(function (reg) {
        var key = reg.email || '';
        if (key && seen.indexOf(key) !== -1) return;   // already dismissed
        popBox(reg);
      });
      if (d.latest) { try { localStorage.setItem(LS_LATEST, d.latest); } catch (e) {} }
    }).catch(function () { /* silent — try again next cycle */ });
  }

  function start() {
    injectCSS();
    check();                       // first check shortly after load
    setInterval(check, POLL_MS);   // then every 60s
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
