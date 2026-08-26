<?php
/* ═══════════════════════════════════════════════════════════════════════════
   shell.php — the frame every page sits in.

   Mobile first throughout: rows stack, dialogs slide up from the bottom, and
   nothing depends on hover. Wider screens get more columns, not a different
   design.
   ═══════════════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/authz.php';

/* Every page that includes this is behind the login. */
$ME = auth_require();

function page_gear(): string {
    /* Only the superadmin has anywhere to go, so only they get the gear. */
    if (!is_super()) return '';
    return '<a class="gear" href="settings.php" aria-label="Settings" title="Settings">'
         . '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/>'
         . '<path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06'
         . 'a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09'
         . 'A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06'
         . 'A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09'
         . 'A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06'
         . 'A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09'
         . 'a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06'
         . 'A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09'
         . 'a1.65 1.65 0 0 0-1.51 1z"/></svg></a>';
}


/* ── the three areas ──────────────────────────────────────────────────────
   Home, Store, Affiliates. The bottom nav moves between pages inside the
   store; this moves between areas. Which one is lit is worked out from the
   file being viewed, so a new page in an area does not need adding here. */
function area_tabs(): void {
    $self = basename($_SERVER['SCRIPT_NAME'] ?? '');

    $store_pages = ['index.php', 'payments.php', 'inventory.php', 'catalog.php',
                    'product.php', 'settings.php'];

    $area = 'home';
    if ($self === 'affiliates.php')          $area = 'affiliates';
    elseif (in_array($self, $store_pages, true)) $area = 'store';

    $tabs = [
        ['home',       'home.php',       'Home'],
        ['store',      'index.php',      'Store'],
        ['affiliates', 'affiliates.php', 'Affiliates'],
    ];

    echo '<div class="mb-tabs"><div class="mb-tabs-in">';
    foreach ($tabs as [$key, $href, $label]) {
        echo '<a href="' . e($href) . '"' . ($area === $key ? ' class="on"' : '') . '>'
           . e($label) . '</a>';
    }
    echo '</div></div>';
}

function page_open(string $title, string $active = ''): void {
    $nav = [
        'index.php'     => ['Customers', 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8'],
        'payments.php'  => ['Payments',  'M1 4h22v16H1zM1 10h22'],
        'inventory.php' => ['Inventory', 'M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z'],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"/>
<meta name="robots" content="noindex,nofollow"/>
<meta name="theme-color" content="#0f1115"/>
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<style>
:root{
  --bg:#0f1115; --panel:#171a21; --panel2:#12151b;
  --line:#242832; --line2:#333846;
  --txt:#e9eaee; --txt2:#b9bcc6; --mute:#7b8194; --faint:#4d5364;
  --gold:#d8a94b; --ok:#4ec97a; --warn:#e0a340; --bad:#f2685e; --info:#6aa9f0;
  --pad:clamp(13px,3.5vw,28px);
  --safe:env(safe-area-inset-bottom,0px);
}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;padding:0}
body{
  background:var(--bg);color:var(--txt);
  font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
  font-size:15px;line-height:1.5;-webkit-font-smoothing:antialiased;
  padding-bottom:calc(66px + var(--safe));
}
a{color:var(--gold)}
img{display:block}
:focus-visible{outline:2px solid var(--gold);outline-offset:2px}

/* ── masthead ─────────────────────────────────────────────────────── */
.top{
  position:sticky;top:0;z-index:30;background:var(--panel);
  border-bottom:1px solid var(--line);padding:12px var(--pad);
  display:flex;align-items:center;justify-content:space-between;gap:12px;
}
.top h1{margin:0;font-size:16px;font-weight:700}
.top .app{font-size:10.5px;color:var(--gold);text-transform:uppercase;letter-spacing:.08em;font-weight:600}
.top .right{font-size:12px;color:var(--mute);text-align:right}
.back{
  display:inline-flex;align-items:center;gap:6px;color:var(--mute);
  text-decoration:none;font-size:13px;
}
.back:hover{color:var(--gold)}

/* ── search ───────────────────────────────────────────────────────── */
.bar{padding:12px var(--pad);display:flex;gap:8px}
.bar input{
  flex:1;min-width:0;background:var(--panel);border:1px solid var(--line2);
  border-radius:10px;padding:11px 14px;color:var(--txt);font-size:16px;
  outline:none;font-family:inherit;
}
.bar input:focus{border-color:var(--gold)}
.bar button{
  background:var(--gold);border:none;border-radius:10px;color:#14161b;
  padding:0 18px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;
}
.bar a.clear{display:flex;align-items:center;padding:0 14px;border:1px solid var(--line2);
  border-radius:10px;color:var(--mute);text-decoration:none;font-size:13px}

/* ── the list: name over email, which is what reads well on a phone ── */
.wrap{padding:0 var(--pad)}
.list{border:1px solid var(--line);border-radius:12px;overflow:hidden}
.row{
  display:grid;grid-template-columns:1fr auto;gap:2px 12px;align-items:center;
  width:100%;padding:12px 14px;border:0;border-bottom:1px solid var(--line);
  background:none;color:inherit;font-family:inherit;text-align:left;cursor:pointer;
}
.row:last-child{border-bottom:none}
.row:hover,.row:focus-visible{background:#1b1f27}
.row .nm{font-size:15px;font-weight:600;grid-column:1}
.row .sub{font-size:13px;color:var(--txt2);grid-column:1;word-break:break-all}
.row .end{grid-column:2;grid-row:1/3;text-align:right;white-space:nowrap;font-size:12px;color:var(--mute)}
.row .end b{display:block;color:var(--gold);font-size:13.5px}

.more{display:block;margin:14px 0 0;padding:13px;text-align:center;border:1px solid var(--line2);
  border-radius:10px;color:var(--txt2);text-decoration:none;font-size:14px}
.more:hover{border-color:var(--gold);color:var(--gold)}

.empty{border:1px solid var(--line);border-radius:12px;padding:30px 20px;text-align:center;color:var(--mute)}
.problem{
  border:1px solid rgba(242,104,94,.45);background:rgba(242,104,94,.07);
  border-radius:12px;padding:18px;color:#ffb3ac;
}
.problem h2{margin:0 0 7px;font-size:16px}
.problem p{margin:0 0 8px;font-size:14px;line-height:1.6}
.problem code{background:#1a1d24;padding:2px 6px;border-radius:4px;font-size:12.5px;word-break:break-all}
.problem ol{margin:8px 0 0;padding-left:19px;font-size:13.5px;line-height:1.7}

/* ── sheet ────────────────────────────────────────────────────────── */
.sheet{position:fixed;inset:0;z-index:70;display:none;background:rgba(0,0,0,.64);
  align-items:flex-end;justify-content:center}
.sheet.on{display:flex}
.sheet-box{background:var(--panel);width:100%;max-width:640px;max-height:92vh;
  border-radius:16px 16px 0 0;display:flex;flex-direction:column;
  animation:up .2s cubic-bezier(.2,.8,.3,1)}
@keyframes up{from{transform:translateY(24px);opacity:.6}to{transform:none;opacity:1}}
@media (prefers-reduced-motion:reduce){.sheet-box{animation:none}}
.sheet-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;
  padding:16px 17px 12px;border-bottom:1px solid var(--line)}
.sheet-head h2{margin:0;font-size:18px;font-weight:700}
.sheet-head .sub{font-size:13.5px;color:var(--txt2);margin-top:3px}
.sheet-head .sub a{color:var(--gold);text-decoration:none}
.x{background:none;border:none;color:var(--mute);font-size:26px;line-height:1;cursor:pointer;padding:0 4px}
.x:hover{color:var(--txt)}
.sheet-body{padding:14px 17px 26px;overflow-y:auto}

/* ── order blocks ─────────────────────────────────────────────────── */
.grp{margin-bottom:16px}
.grp h3{font-size:10.5px;color:var(--mute);text-transform:uppercase;letter-spacing:.09em;
  margin:0 0 8px;font-weight:600}
.ord{border:1px solid var(--line);border-radius:11px;margin-bottom:11px;overflow:hidden}
.ord-top{display:flex;justify-content:space-between;gap:10px;padding:11px 13px;
  background:var(--panel2);border-bottom:1px solid var(--line);flex-wrap:wrap}
.ord-no{font-weight:700;font-size:14.5px}
.ord-date{font-size:12px;color:var(--mute)}
.ord-tot{font-family:ui-monospace,monospace;color:var(--gold);font-weight:600}

/* the location line sits first, as asked, and is tappable */
.loc-line{
  display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;
  padding:10px 13px;background:rgba(216,169,75,.08);border:0;border-bottom:1px solid var(--line);
  color:var(--gold);font-family:inherit;font-size:13.5px;font-weight:600;
  cursor:pointer;text-align:left;text-decoration:none;
}
.loc-line:hover{background:rgba(216,169,75,.16)}
.loc-line span.hint{color:var(--mute);font-weight:400;font-size:12px}

.li{display:flex;gap:11px;padding:10px 13px;border-bottom:1px solid #1e222a;align-items:center}
.li:last-child{border-bottom:none}
.li img,.li .noimg{width:44px;height:44px;border-radius:7px;object-fit:cover;
  background:var(--panel2);flex:0 0 44px}
.li .noimg{display:flex;align-items:center;justify-content:center;color:var(--faint);font-size:9px}
.li .t{flex:1;min-width:0}
.li .t b{display:block;font-size:13.5px;font-weight:600}
.li .t small{color:var(--mute);font-size:11.5px;font-family:ui-monospace,monospace}
.li .p{font-family:ui-monospace,monospace;font-size:13px;white-space:nowrap;color:var(--txt2)}

.f{display:flex;gap:12px;padding:8px 0;border-bottom:1px solid #1e222a;font-size:14px}
.f:last-child{border-bottom:none}
.f dt{flex:0 0 36%;color:var(--mute);font-size:13px}
.f dd{flex:1;margin:0;word-break:break-word}
.f dd.none{color:var(--faint)}
dl{margin:0}

.pill{display:inline-block;border-radius:5px;padding:2px 8px;font-size:11px;font-weight:600;
  background:#1e222b;color:var(--txt2)}
.pill.ok{background:rgba(78,201,122,.15);color:var(--ok)}
.pill.warn{background:rgba(224,163,64,.15);color:var(--warn)}
.pill.bad{background:rgba(242,104,94,.15);color:var(--bad)}

/* ── shipping inside an order ─────────────────────────────────────── */
.ship{padding:10px 13px;border-bottom:1px solid #1e222a;font-size:12.5px;line-height:1.55}
.ship-l{color:var(--txt2)}
.ship-l b{color:var(--txt)}
.ship-l a{color:var(--gold);text-decoration:none}
.track{display:inline-block;margin-top:6px;padding:5px 11px;border:1px solid var(--line2);
  border-radius:7px;font-family:ui-monospace,monospace;font-size:11.5px;
  color:var(--info);text-decoration:none}
a.track:hover{border-color:var(--info)}

/* ── inventory tables ─────────────────────────────────────────────── */
.inv{border:1px solid var(--line);border-radius:12px;overflow:hidden}
.inv-row{display:flex;gap:11px;align-items:center;padding:11px 13px;
  border-bottom:1px solid var(--line);text-decoration:none;color:inherit}
.inv-row:last-child{border-bottom:none}
.inv-row:hover{background:#1b1f27}
.inv-row{cursor:default}
.inv-row img,.inv-row .noimg{width:46px;height:46px;border-radius:7px;object-fit:cover;
  background:var(--panel2);flex:0 0 46px}
.inv-row .noimg{display:flex;align-items:center;justify-content:center;color:var(--faint);font-size:9px}
.inv-row .m{flex:1;min-width:0}
.inv-row .m b{display:block;font-size:14px;font-weight:600;line-height:1.3}
.inv-row .m .pname{display:block;font-size:14px;font-weight:600;line-height:1.3;
  color:var(--gold);text-decoration:none}
.inv-row .m .pname:active{opacity:.6}
.inv-row .m .sku{font-family:ui-monospace,monospace;font-size:11.5px;color:var(--txt2)}
.inv-row .m .sku.plain{color:var(--faint)}
.inv-row .r{text-align:right;white-space:nowrap}
.inv-row .r .pr{font-family:ui-monospace,monospace;font-size:13.5px}
.inv-row .r .qty{font-size:11px;color:var(--mute);display:block}
/* stock at a glance: green if there is something to sell, orange if not */
.inv-row .r .qty.in{color:var(--ok)}
.inv-row .r .qty.out{color:#f0883e;font-weight:600}
.in{color:var(--ok)}
.out{color:#f0883e;font-weight:600}

.loc-cards{display:grid;gap:11px;grid-template-columns:1fr}
.loc-card{display:flex;justify-content:space-between;align-items:center;gap:12px;
  border:1px solid var(--line);border-radius:12px;padding:16px;
  text-decoration:none;color:inherit;background:var(--panel)}
.loc-card:hover{border-color:var(--gold)}
.loc-card b{display:block;font-size:16px;margin-bottom:2px}
.loc-card small{color:var(--mute);font-size:12.5px}
.loc-card .arrow{color:var(--gold);font-size:19px}

.top .app{display:flex;align-items:center;gap:7px}
.gear{display:inline-flex;color:var(--mute);text-decoration:none}
.gear:hover{color:var(--gold)}
.gear svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;
  stroke-linecap:round;stroke-linejoin:round}
.readonly{font-size:9.5px;color:var(--mute);border:1px solid var(--line2);
  border-radius:4px;padding:1px 6px;letter-spacing:.05em;text-transform:uppercase}

.inv-row .m .cost{display:inline-block;margin-left:8px;color:var(--txt2);
  font-family:-apple-system,sans-serif;font-size:11px}
.inv-row .m .cost.none{color:var(--faint)}
.inv-row .m .cost.landed{color:var(--gold);font-weight:600}
.inv-row .m .profit{display:inline-block;margin-left:8px;color:var(--ok);
  font-family:-apple-system,sans-serif;font-size:11px;font-weight:600}
.inv-row .m .profit.loss{color:#f0883e}
.totals-row b.gain{color:var(--ok)}
.totals-row b.loss{color:#f0883e}
.totals{border:1px solid var(--gold);border-radius:12px;padding:14px 16px;margin-top:14px;
  background:rgba(216,169,75,.06)}
.totals-row{display:flex;justify-content:space-between;align-items:baseline;gap:12px;
  font-size:14px;color:var(--txt2)}
.totals-row b{font-family:ui-monospace,monospace;font-size:19px;color:var(--gold);font-weight:700}
.totals-note{font-size:11.5px;color:var(--mute);margin-top:7px;line-height:1.6}

/* ── write forms ──────────────────────────────────────────────────── */
.act{background:transparent;border:1px solid var(--line2);border-radius:7px;
  color:var(--txt2);padding:6px 12px;font-size:12px;font-weight:600;
  cursor:pointer;font-family:inherit}
.act:hover{border-color:var(--gold);color:var(--gold)}
.act.sm{padding:3px 9px;font-size:11px;margin-left:7px}
.act.go{background:var(--gold);border-color:var(--gold);color:#14161b}
.act.go:hover{background:#e6bb63;color:#14161b}
.act.go:disabled{opacity:.5;cursor:wait}
.act.ghost{color:var(--mute)}
.act.danger{color:var(--mute)}
.act.danger:hover{border-color:var(--bad);color:var(--bad)}
.li.acts{flex-wrap:wrap;gap:8px}
.li.acts .btns{display:flex;gap:6px;flex-wrap:wrap}
.li.acts .act{margin:0}
.form{padding:2px 0}
.form h3{margin:0 0 10px;font-size:16px;font-weight:700;color:var(--txt);
  text-transform:none;letter-spacing:0}
.fp{font-size:12.5px;color:var(--mute);line-height:1.6;margin:0 0 14px}
.fl{display:block;margin-bottom:11px;font-size:10.5px;color:var(--mute);
  text-transform:uppercase;letter-spacing:.06em;font-weight:600}
.fl input,.fl select{display:block;width:100%;box-sizing:border-box;margin-top:5px;
  background:var(--panel2);border:1px solid var(--line2);border-radius:9px;
  padding:11px 13px;color:var(--txt);font-size:16px;outline:none;
  font-family:inherit;font-weight:400;text-transform:none;letter-spacing:0}
.fl input:focus,.fl select:focus{border-color:var(--gold)}
.fc{display:flex;align-items:center;gap:9px;font-size:13.5px;color:var(--txt2);
  margin:4px 0 14px;cursor:pointer}
.fc input{width:18px;height:18px;accent-color:var(--gold)}
.form-b{display:flex;gap:9px;justify-content:flex-end;margin-top:16px}
.form-msg{font-size:12.5px;min-height:17px;margin-top:10px;line-height:1.5;color:var(--mute)}
.form-msg.ok{color:var(--ok)}
.form-msg.bad{color:var(--bad)}


/* ── area tabs ────────────────────────────────────────────────────────── */
.mb-tabs{background:var(--panel);border-bottom:1px solid var(--line);
  position:sticky;top:0;z-index:50}
.mb-tabs-in{display:flex;gap:2px;max-width:1280px;margin:0 auto;
  padding:0 var(--pad);overflow-x:auto;-webkit-overflow-scrolling:touch}
.mb-tabs a{flex:0 0 auto;padding:13px 20px;font-size:13px;font-weight:700;
  color:var(--mute);text-decoration:none;border-bottom:2px solid transparent;
  white-space:nowrap;letter-spacing:.02em}
.mb-tabs a:hover{color:var(--txt2)}
.mb-tabs a.on{color:var(--gold);border-bottom-color:var(--gold)}
@media(max-width:420px){.mb-tabs a{padding:12px 14px;font-size:12.5px}}

/* ── bottom nav ───────────────────────────────────────────────────── */
.nav{
  position:fixed;left:0;right:0;bottom:0;z-index:40;display:flex;
  background:var(--panel);border-top:1px solid var(--line);
  padding-bottom:var(--safe);
}
.nav a{
  flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;gap:3px;
  padding:9px 2px 7px;color:var(--mute);text-decoration:none;font-size:10px;
}
.nav a span{max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.nav a.on{color:var(--gold)}
.nav svg{width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2;
  stroke-linecap:round;stroke-linejoin:round;flex:0 0 20px}
/* the Trendsi mark is a filled shape, so it must not take the stroke */
.nav svg.ico-solid{stroke:none;stroke-width:0}
@media(max-width:340px){.nav a{font-size:9px}.nav svg{width:19px;height:19px}}

.load{text-align:center;padding:22px;color:var(--faint);font-size:13px}

@media(min-width:720px){
  .sheet{align-items:center}
  .sheet-box{border-radius:16px;max-height:88vh}
  .row{grid-template-columns:1.1fr 1.5fr auto}
  .row .nm{grid-column:1}
  .row .sub{grid-column:2;font-size:14px}
  .row .end{grid-column:3;grid-row:1}
  .loc-cards{grid-template-columns:repeat(auto-fit,minmax(240px,1fr))}
}
</style>
</head>
<body>
<?php area_tabs(); ?>
<?php
}

function page_nav(string $active = '', string $locId = ''): void {
    $tabs = [];
    foreach (shop_tabs() as $t) {
        $tabs[] = [
            'href'  => 'catalog.php?v=' . rawurlencode($t['kind']),
            'label' => $t['label'],
            'icon'  => $t['kind'],
            'on'    => ($active === 'catalog' && $locId === $t['kind']),
        ];
    }

    $tabs[] = ['href' => 'index.php',    'label' => 'Customers',
               'icon' => 'people', 'on' => $active === 'index'];
    $tabs[] = ['href' => 'payments.php', 'label' => 'Payments',
               'icon' => 'card',   'on' => $active === 'payments'];

    echo '<nav class="nav">';
    foreach ($tabs as $t) {
        echo '<a href="' . e($t['href']) . '"' . ($t['on'] ? ' class="on"' : '') . '>'
           . nav_icon($t['icon'])
           . '<span>' . e($t['label']) . '</span></a>';
    }
    echo '</nav>';
}

/* Trendsi's mark is a serif T knocked out of a rounded square. Drawn inline so
   it stays sharp at any size, takes the tab colour like the other icons, and
   needs no image file on the server. */
function nav_icon(string $kind): string {
    switch ($kind) {

    case 'trendsi':
        return '<svg viewBox="0 0 24 24" class="ico-solid" aria-hidden="true">'
             . '<rect x="1.5" y="1.5" width="21" height="21" rx="4.5" fill="currentColor" stroke="none"/>'
             . '<path d="M6.4 7.1h11.2v3.1h-1.05c-.15-1.2-.5-1.55-1.5-1.55h-1.9v6.9c0 .95.2 1.15 1.25 1.2v1.15H9.6v-1.15c1.05-.05 1.25-.25 1.25-1.2v-6.9h-1.9c-1 0-1.35.35-1.5 1.55H6.4z" '
             . 'fill="var(--panel)" stroke="none"/></svg>';

    case 'shop':
        return '<svg viewBox="0 0 24 24" aria-hidden="true">'
             . '<path d="M3 9l1.5-5h15L21 9"/><path d="M4 9v11h16V9"/>'
             . '<path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/>'
             . '<path d="M9 20v-6h6v6"/></svg>';

    case 'people':
        return '<svg viewBox="0 0 24 24" aria-hidden="true">'
             . '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>'
             . '<path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg>';

    case 'card':
    default:
        return '<svg viewBox="0 0 24 24" aria-hidden="true">'
             . '<rect x="1" y="4" width="22" height="16" rx="2"/>'
             . '<line x1="1" y1="10" x2="23" y2="10"/></svg>';
    }
}

function page_close(string $active = '', string $locId = ''): void {
    page_nav($active, $locId);
    echo '</body></html>';
}

/* Shown whenever a query fails, in place of the list. Says what Shopify said,
   because "something went wrong" is no help when the cause is a missing scope. */
function problem_block(array $res): void {
    $reason = $res['reason'] ?? '';
    $title = $reason === 'no_token' ? 'Not connected yet'
           : ($reason === 'unauthorised' ? 'Shopify refused the token' : 'Could not load');
    echo '<div class="problem"><h2>' . e($title) . '</h2>'
       . '<p>' . e($res['message'] ?? '') . '</p>';
    if ($reason === 'no_token') {
        echo '<ol><li>Put the app credentials in <code>config.php</code></li>'
           . '<li>Open <a href="auth.php" style="color:#d8a94b">auth.php</a> once to authorise</li></ol>';
    }
    echo '</div>';
}
