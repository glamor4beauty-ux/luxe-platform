<?php
/* ═══════════════════════════════════════════════════════════════════════════
   payouts.php — where an affiliate sets up being paid.

   Four states, and each one says plainly what is true and what to do next:

     not started  a button
     part done    what Stripe still wants, and a button to carry on
     approved     confirmation, and a link to her own Stripe dashboard
     refused      that it will not go through Stripe, that her commission is
                  unaffected, and that somebody will be in touch

   The last one matters most. Being turned down by a verification system is
   not a failure on her part, and a page that reads like an error would tell
   her it was.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/config.php';
$me = mb_require_affiliate();
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<meta name="robots" content="noindex,nofollow"/>
<title>Getting paid · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"/>
<style>
:root{--bg:#0a0d14;--panel:#111622;--panel2:#0e1219;--line:#1e2330;--line2:#2a3040;
  --txt:#e8eaf0;--txt2:#b9bcc6;--mute:#8a8fa8;--faint:#5a6070;
  --gold:#d4a830;--gold2:#e8bf44;--ok:#3fb950;--warn:#e0a340;--bad:#f2685e}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--txt);
  font-family:'DM Sans',system-ui,-apple-system,'Segoe UI',sans-serif;
  font-size:15px;line-height:1.6;padding:22px 18px 70px}
a{color:var(--gold);text-decoration:none}
a:hover{color:var(--gold2)}
.wrap{max-width:620px;margin:0 auto}

.top{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;
  margin-bottom:20px;flex-wrap:wrap}
h1{font-size:21px;font-weight:700;letter-spacing:-.3px}
h1 span{color:var(--gold)}
.sub{font-size:12.5px;color:var(--mute);margin-top:2px}
.who{font-size:12px;color:var(--mute);text-align:right}
.who a{display:block;margin-top:3px}

.tabs{display:flex;gap:7px;margin-bottom:22px;flex-wrap:wrap}
.tab{border:1px solid var(--line2);background:transparent;color:var(--mute);
  border-radius:9px;padding:9px 17px;font-size:12.5px;font-weight:700;
  text-decoration:none;white-space:nowrap}
.tab:hover{border-color:var(--gold);color:var(--gold)}
.tab.on{background:rgba(212,168,48,.12);border-color:var(--gold);color:var(--gold)}

.card{background:var(--panel);border:1px solid var(--line2);border-radius:15px;
  padding:26px;text-align:center}
.card.ok{border-color:rgba(63,185,80,.45);background:rgba(63,185,80,.05)}
.card.no{border-color:rgba(242,104,94,.35);background:rgba(242,104,94,.04)}
.card.wait{border-color:rgba(224,163,64,.4);background:rgba(224,163,64,.04)}

.mark{width:62px;height:62px;border-radius:50%;font-size:28px;line-height:58px;
  margin:0 auto 18px;border:2px solid var(--line2);color:var(--mute)}
.mark.ok{border-color:var(--ok);color:var(--ok);background:rgba(63,185,80,.1)}
.mark.no{border-color:var(--bad);color:var(--bad);background:rgba(242,104,94,.1)}
.mark.wait{border-color:var(--warn);color:var(--warn);background:rgba(224,163,64,.1)}

.card h2{font-size:19px;font-weight:700;margin-bottom:9px}
.card p{color:var(--txt2);font-size:14.5px;line-height:1.75;margin-bottom:18px}
.card p:last-child{margin-bottom:0}

.btn{display:inline-flex;align-items:center;gap:9px;background:var(--gold);border:none;
  border-radius:11px;color:#0a0d14;padding:14px 30px;font-size:15px;font-weight:700;
  cursor:pointer;font-family:inherit;text-decoration:none}
.btn:hover{background:var(--gold2);color:#0a0d14}
.btn:disabled{opacity:.5;cursor:wait}
.btn.quiet{background:transparent;border:1px solid var(--line2);color:var(--txt2)}
.btn.quiet:hover{border-color:var(--gold);color:var(--gold)}

.needs{text-align:left;background:var(--panel2);border:1px solid var(--line);
  border-radius:11px;padding:16px 18px;margin-bottom:18px}
.needs h3{font-size:10px;color:var(--mute);text-transform:uppercase;letter-spacing:.08em;
  font-weight:700;margin-bottom:10px}
.needs ul{list-style:none}
.needs li{font-size:14px;color:var(--txt2);padding:5px 0 5px 24px;position:relative}
.needs li:before{content:'\F4F9';font-family:'bootstrap-icons';position:absolute;left:0;
  color:var(--warn);font-size:13px}

.note{font-size:12.5px;color:var(--mute);line-height:1.75;margin-top:20px;text-align:left;
  background:rgba(212,168,48,.05);border-left:2px solid rgba(212,168,48,.4);
  border-radius:0 9px 9px 0;padding:13px 15px}
.note b{color:var(--gold)}
.msg{font-size:13.5px;color:var(--mute);margin-top:14px;min-height:19px}
.msg.bad{color:var(--bad)}
.loading{text-align:center;padding:44px;color:var(--faint);font-size:13.5px}
</style>
</head>
<body>
<div class="wrap">

  <div class="top">
    <div>
      <h1>Models <span>Boutique</span></h1>
      <div class="sub">Getting paid</div>
    </div>
    <div class="who">
      <?= e($me['email']) ?>
      <a href="/logout.php">Sign out</a>
    </div>
  </div>

  <div class="tabs">
    <a class="tab" href="dashboard.php">Your account</a>
    <a class="tab" href="my-sales.php?view=sales">Sales</a>
    <a class="tab" href="my-sales.php?view=billing">Billing</a>
    <a class="tab on" href="payouts.php">Getting paid</a>
  </div>

  <div id="body"><div class="loading">Checking…</div></div>

</div>

<script>
var API = '/api/connect.php';

function el(id){ return document.getElementById(id); }
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
  return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

function api(action){
  return fetch(API + '?action=' + action, { credentials:'same-origin' })
    .then(function(r){ return r.json(); });
}

function show(d){
  var h = '';

  if (d.state === 'enabled') {
    h = '<div class="card ok">' +
      '<div class="mark ok"><i class="bi bi-check-lg"></i></div>' +
      '<h2>You are set up</h2>' +
      '<p>' + esc(d.message) + '</p>' +
      '<button class="btn quiet" onclick="openDash(this)">' +
        '<i class="bi bi-box-arrow-up-right"></i>View your payouts</button>' +
      '</div>' +
      '<div class="note"><b>How it works.</b> Commission is paid to the bank account ' +
      'you gave Stripe. You can see every payment in your own Stripe dashboard, and ' +
      'the Billing tab here shows the same figures from our side.</div>';

  } else if (d.state === 'blocked') {
    /* Not framed as an error, because it is not one, and not her doing. */
    h = '<div class="card no">' +
      '<div class="mark no"><i class="bi bi-exclamation-lg"></i></div>' +
      '<h2>Payouts cannot go through Stripe</h2>' +
      '<p>' + esc(d.message) + '</p>' +
      '</div>' +
      '<div class="note"><b>Nothing is lost.</b> Everything you have earned is still ' +
      'earned and still owed — you can see it on the Billing tab. This is only about ' +
      'how the money reaches you, and we will sort that out with you directly.</div>';

  } else if (d.state === 'disabled') {
    h = '<div class="card wait">' +
      '<div class="mark wait"><i class="bi bi-plug"></i></div>' +
      '<h2>Disconnected</h2>' +
      '<p>' + esc(d.message) + '</p>' +
      '<button class="btn" onclick="go(this)">' +
        '<i class="bi bi-bank"></i>Set up again</button>' +
      '</div>';

  } else if (d.state === 'pending' && d.needs && d.needs.length) {
    h = '<div class="card wait">' +
      '<div class="mark wait"><i class="bi bi-hourglass-split"></i></div>' +
      '<h2>Nearly there</h2>' +
      '<p>' + esc(d.message) + '</p>' +
      '<div class="needs"><h3>Still to give Stripe</h3><ul>' +
        d.needs.map(function(n){ return '<li>' + esc(n) + '</li>'; }).join('') +
      '</ul></div>' +
      '<button class="btn" onclick="go(this)">' +
        '<i class="bi bi-arrow-right"></i>Carry on</button>' +
      '</div>';

  } else if (d.state === 'pending') {
    h = '<div class="card wait">' +
      '<div class="mark wait"><i class="bi bi-hourglass-split"></i></div>' +
      '<h2>Stripe is checking</h2>' +
      '<p>' + esc(d.message) + '</p>' +
      '<button class="btn quiet" onclick="location.reload()">' +
        '<i class="bi bi-arrow-clockwise"></i>Check again</button>' +
      '</div>';

  } else {
    h = '<div class="card">' +
      '<div class="mark"><i class="bi bi-bank"></i></div>' +
      '<h2>Get paid to your bank</h2>' +
      '<p>Set up payouts once and your commission goes straight to your bank account. ' +
      'Stripe handles it — we never see your bank details.</p>' +
      '<button class="btn" onclick="go(this)">' +
        '<i class="bi bi-bank"></i>Set up payouts</button>' +
      '</div>' +
      '<div class="note"><b>What you will need.</b> A photo of your ID, your bank ' +
      'details, and a few minutes. Stripe asks for these to confirm who you are before ' +
      'money can be sent — the same checks any bank would make.</div>';
  }

  h += '<div class="msg" id="msg"></div>';
  el('body').innerHTML = h;
}

function go(btn){
  btn.disabled = true;
  el('msg').textContent = 'Opening Stripe…';
  el('msg').className = 'msg';

  api('start').then(function(d){
    if (d.ok && d.url) { location.href = d.url; return; }
    btn.disabled = false;
    el('msg').textContent = d.error || 'That did not work. Try again shortly.';
    el('msg').className = 'msg bad';
  }).catch(function(){
    btn.disabled = false;
    el('msg').textContent = 'Could not reach the server. Check your connection.';
    el('msg').className = 'msg bad';
  });
}

function openDash(btn){
  btn.disabled = true;
  api('dashboard').then(function(d){
    btn.disabled = false;
    if (d.ok && d.url) window.open(d.url, '_blank');
    else { el('msg').textContent = d.error || 'Could not open that.'; el('msg').className = 'msg bad'; }
  }).catch(function(){ btn.disabled = false; });
}

api('status').then(show).catch(function(){
  el('body').innerHTML = '<div class="card"><p>Could not check your payout setup ' +
    'just now. Try again shortly.</p></div>';
});

/* Coming back from Stripe, her account has usually only just changed, so the
   first answer can still be stale. One quiet re-check a few seconds later
   saves her wondering why nothing happened. */
if (/[?&]done=1/.test(location.search)) {
  setTimeout(function(){ api('status').then(show).catch(function(){}); }, 4000);
}
</script>

</body>
</html>
