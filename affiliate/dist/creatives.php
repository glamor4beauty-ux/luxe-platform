<?php
/* ═══════════════════════════════════════════════════════════════════════════
   creatives.php — what an affiliate can use on her own site.

   Only for affiliates who host their own. The ones we host already have
   whatever is current on their sites, and offering them files to install by
   hand would be offering them a job they are paying us not to do.

   Downloads are counted, so it is possible to see what is worth making more
   of and what nobody wants.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/config.php';
$me = mb_require_affiliate();

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function human(int $b): string {
    if ($b >= 1073741824) return number_format($b / 1073741824, 1) . ' GB';
    if ($b >= 1048576)    return number_format($b / 1048576, 1) . ' MB';
    if ($b >= 1024)       return number_format($b / 1024) . ' KB';
    return $b . ' B';
}

$db = db();

/* who she is, and whether this page is for her */
$q = $db->prepare("SELECT hosting, store_name FROM affiliates WHERE code = ?");
$q->execute([$me['code']]);
$aff = $q->fetch() ?: ['hosting' => 'diy', 'store_name' => ''];
$hosted = ($aff['hosting'] ?? 'diy') === 'managed';

/* counting a download, then sending her to the file */
if (!empty($_GET['get'])) {
    $id = (int)$_GET['get'];
    try {
        $s = $db->prepare("SELECT filename FROM creatives WHERE id = ? AND active = 1");
        $s->execute([$id]);
        if ($f = $s->fetchColumn()) {
            $db->prepare("UPDATE creatives SET downloads = downloads + 1 WHERE id = ?")
               ->execute([$id]);
            header('Location: /creatives/' . rawurlencode((string)$f));
            exit;
        }
    } catch (Throwable $ex) { /* fall through to the page */ }
}

$rows = [];
if (!$hosted) {
    try {
        $rows = $db->query("SELECT * FROM creatives WHERE active = 1
                            ORDER BY sort_order, id DESC")->fetchAll();
    } catch (Throwable $ex) { $rows = []; }
}

/* grouped, because thirty files in one row is a pile rather than a library */
$groups = [];
foreach ($rows as $r) $groups[$r['category'] ?: 'general'][] = $r;
ksort($groups);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<meta name="robots" content="noindex,nofollow"/>
<title>Creatives · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"/>
<style>
:root{--bg:#0a0d14;--panel:#111622;--panel2:#0e1219;--line:#1e2330;--line2:#2a3040;
  --txt:#e8eaf0;--txt2:#b9bcc6;--mute:#8a8fa8;--faint:#5a6070;
  --gold:#d4a830;--gold2:#e8bf44;--ok:#3fb950}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--txt);
  font-family:'DM Sans',system-ui,-apple-system,'Segoe UI',sans-serif;
  font-size:14.5px;line-height:1.55;padding:22px 18px 70px}
a{color:var(--gold);text-decoration:none}
a:hover{color:var(--gold2)}
.wrap{max-width:1080px;margin:0 auto}

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

.intro{font-size:13px;color:var(--mute);line-height:1.75;margin-bottom:24px;
  background:rgba(212,168,48,.05);border-left:2px solid rgba(212,168,48,.4);
  border-radius:0 9px 9px 0;padding:13px 15px}
.intro b{color:var(--gold)}

.sec{font-size:10px;color:var(--gold);text-transform:uppercase;letter-spacing:.09em;
  font-weight:700;margin:26px 0 13px;padding-bottom:7px;border-bottom:1px solid var(--line)}
.sec:first-of-type{margin-top:0}

.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px}
.item{background:var(--panel);border:1px solid var(--line);border-radius:12px;
  overflow:hidden;transition:border-color .18s}
.item:hover{border-color:rgba(212,168,48,.5)}
.shot{aspect-ratio:4/3;background:var(--panel2);position:relative;overflow:hidden}
.shot img,.shot video{width:100%;height:100%;object-fit:cover;display:block}
.shot .play{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
  background:rgba(10,13,20,.35);color:#fff;font-size:30px;pointer-events:none}
.body{padding:12px 13px}
.body b{display:block;font-size:13.5px;color:var(--txt);margin-bottom:3px;
  word-break:break-word}
.body span{font-size:11.5px;color:var(--mute)}
.get{display:block;margin:0 13px 13px;background:var(--gold);border:none;border-radius:8px;
  color:#0a0d14;padding:9px;font-size:12.5px;font-weight:700;text-align:center;
  text-decoration:none}
.get:hover{background:var(--gold2);color:#0a0d14}

.hosted{background:var(--panel);border:1px solid var(--line2);border-radius:15px;
  padding:34px;text-align:center;max-width:520px;margin:0 auto}
.hosted i{font-size:38px;color:var(--ok);display:block;margin-bottom:16px}
.hosted h2{font-size:19px;font-weight:700;margin-bottom:10px}
.hosted p{color:var(--txt2);font-size:14.5px;line-height:1.75;margin-bottom:16px}
.hosted p:last-child{margin-bottom:0}
.empty{text-align:center;padding:50px;color:var(--faint);font-size:13.5px}
</style>
</head>
<body>
<div class="wrap">

  <div class="top">
    <div>
      <h1>Models <span>Boutique</span></h1>
      <div class="sub">Creatives</div>
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
    <a class="tab" href="payouts.php">Getting paid</a>
    <a class="tab on" href="creatives.php">Creatives</a>
  </div>

<?php if ($hosted): ?>

  <!-- Nothing to do, said as good news rather than a refusal. -->
  <div class="hosted">
    <i class="bi bi-check-circle"></i>
    <h2>Already done for you</h2>
    <p>Your store is hosted by us, so it already carries the latest photographs and
       videos. When we add new ones, your site gets them — you do not have to do
       anything.</p>
    <p>This page is for affiliates who put their store on their own hosting and need
       the files to work with.</p>
  </div>

<?php elseif (!$rows): ?>

  <div class="empty">Nothing here yet. Photographs and videos will appear as we add them.</div>

<?php else: ?>

  <div class="intro">
    Photographs and videos to use on your own site and in your posts. Free to use for
    promoting your store — <b>download whichever you like</b>.
  </div>

  <?php foreach ($groups as $name => $items): ?>
    <div class="sec"><?= e($name) ?></div>
    <div class="grid">
      <?php foreach ($items as $r):
        $url = '/creatives/' . rawurlencode($r['filename']);
      ?>
        <div class="item">
          <div class="shot">
            <?php if ($r['kind'] === 'video'): ?>
              <video src="<?= e($url) ?>" muted preload="metadata"></video>
              <div class="play"><i class="bi bi-play-circle-fill"></i></div>
            <?php else: ?>
              <img src="<?= e($url) ?>" alt="" loading="lazy">
            <?php endif; ?>
          </div>
          <div class="body">
            <b><?= e($r['title'] ?: $r['filename']) ?></b>
            <span><?= e(human((int)$r['bytes'])) ?><?php
              if ($r['width']): ?> · <?= (int)$r['width'] ?>×<?= (int)$r['height'] ?><?php
              endif; ?></span>
          </div>
          <a class="get" href="?get=<?= (int)$r['id'] ?>" download>
            <i class="bi bi-download"></i> Download</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

<?php endif; ?>

</div>
</body>
</html>
