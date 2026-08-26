<?php
/* ═══════════════════════════════════════════════════════════════════════════
   creatives.php — the images and videos affiliates use.

   Uploaded here, downloaded by affiliates who host their own sites. The ones
   we host do not see it: their site already carries whatever is current, and
   offering them files to install by hand would be offering them a job they
   are paying us to do.

   Files live under the affiliate site so they are served directly rather than
   through PHP — a 40 MB video read into memory and echoed out is a good way
   to exhaust a server, and these are marketing assets with nothing to hide.
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/authz.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/shell.php';

auth_start();
if (!auth_user()) { header('Location: login.php'); exit; }

$me = auth_user();
$my_email = is_array($me) ? ($me['email'] ?? '') : (string)$me;

const STORE = '/var/www/sites/affiliate/dist/creatives';
const PUBLIC_BASE = 'https://affiliate.modelsboutique.com/creatives';

function mb_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=models_boutique;charset=utf8mb4',
                   'mb_app', 'Mb7#kRq2vLx9Twn4', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function tables(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS creatives (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        kind       ENUM('image','video') NOT NULL DEFAULT 'image',
        category   VARCHAR(60) NOT NULL DEFAULT 'general',
        title      VARCHAR(160) NOT NULL DEFAULT '',
        filename   VARCHAR(255) NOT NULL,
        bytes      BIGINT NOT NULL DEFAULT 0,
        mime       VARCHAR(80) NOT NULL DEFAULT '',
        width      INT NOT NULL DEFAULT 0,
        height     INT NOT NULL DEFAULT 0,
        downloads  INT NOT NULL DEFAULT 0,
        active     TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        added_by   VARCHAR(160) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY k_active (active), KEY k_kind (kind)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function human(int $b): string {
    if ($b >= 1073741824) return number_format($b / 1073741824, 1) . ' GB';
    if ($b >= 1048576)    return number_format($b / 1048576, 1) . ' MB';
    if ($b >= 1024)       return number_format($b / 1024) . ' KB';
    return $b . ' B';
}

$msg = '';
$err = '';

/* ── changes ──────────────────────────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $db = mb_db();
        tables($db);
        $do = (string)($_POST['do'] ?? '');

        if ($do === 'upload') {
            if (!is_dir(STORE) && !@mkdir(STORE, 0755, true)) {
                throw new RuntimeException('The creatives folder could not be made.');
            }

            $files = $_FILES['files'] ?? null;
            if (!$files || !is_array($files['name'])) {
                throw new RuntimeException('No files were chosen.');
            }

            $ok = 0;
            $bad = [];

            for ($i = 0; $i < count($files['name']); $i++) {
                $name = (string)$files['name'][$i];
                if ($name === '') continue;

                /* PHP reports why it refused, and the reasons are worth
                   repeating plainly — "the upload failed" tells nobody what
                   to do differently. */
                $e = (int)$files['error'][$i];
                if ($e !== UPLOAD_ERR_OK) {
                    $bad[] = $name . ' — ' . match ($e) {
                        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE
                            => 'too large for the server to accept',
                        UPLOAD_ERR_PARTIAL   => 'only partly arrived',
                        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE
                            => 'the server could not write it',
                        default              => 'refused (code ' . $e . ')',
                    };
                    continue;
                }

                $tmp   = (string)$files['tmp_name'][$i];
                $bytes = (int)$files['size'][$i];
                $mime  = (string)(mime_content_type($tmp) ?: '');

                $isImage = strpos($mime, 'image/') === 0;
                $isVideo = strpos($mime, 'video/') === 0;

                if (!$isImage && !$isVideo) {
                    $bad[] = $name . ' — not an image or a video';
                    continue;
                }

                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!preg_match('/^[a-z0-9]{2,5}$/', $ext)) {
                    $ext = $isVideo ? 'mp4' : 'jpg';
                }

                /* The affiliate's filename is what she sees when it lands in
                   her downloads, so it keeps the name you gave it — made safe,
                   and with enough randomness that two uploads cannot collide. */
                $stem = preg_replace('/[^A-Za-z0-9]+/', '-', pathinfo($name, PATHINFO_FILENAME));
                $stem = trim(substr($stem, 0, 48), '-') ?: 'creative';
                $final = $stem . '-' . substr(bin2hex(random_bytes(3)), 0, 5) . '.' . $ext;

                if (!@move_uploaded_file($tmp, STORE . '/' . $final)) {
                    $bad[] = $name . ' — could not be saved';
                    continue;
                }
                @chmod(STORE . '/' . $final, 0644);

                $w = 0; $h = 0;
                if ($isImage) {
                    $d = @getimagesize(STORE . '/' . $final);
                    if ($d) { $w = (int)$d[0]; $h = (int)$d[1]; }
                }

                $max = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM creatives")
                               ->fetchColumn();

                $db->prepare("INSERT INTO creatives
                        (kind, category, title, filename, bytes, mime, width, height,
                         sort_order, added_by)
                      VALUES (?,?,?,?,?,?,?,?,?,?)")
                   ->execute([
                       $isVideo ? 'video' : 'image',
                       trim((string)($_POST['category'] ?? 'general')) ?: 'general',
                       pathinfo($name, PATHINFO_FILENAME),
                       $final, $bytes, $mime, $w, $h, $max + 10, $my_email,
                   ]);
                $ok++;
            }

            $msg = $ok ? ($ok . ' uploaded.') : '';
            if ($bad) $err = implode(' · ', array_slice($bad, 0, 4));
            if (!$ok && !$bad) $err = 'Nothing was uploaded.';
        }

        if ($do === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $db->prepare("UPDATE creatives SET title = ?, category = ?, active = ?
                               WHERE id = ?")
                   ->execute([
                       trim((string)($_POST['title'] ?? '')),
                       trim((string)($_POST['category'] ?? 'general')) ?: 'general',
                       empty($_POST['active']) ? 0 : 1,
                       $id,
                   ]);
                $msg = 'Saved.';
            }
        }

        if ($do === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $q = $db->prepare("SELECT filename FROM creatives WHERE id = ?");
            $q->execute([$id]);
            if ($f = $q->fetchColumn()) {
                $abs = realpath(STORE . '/' . basename((string)$f));
                $root = realpath(STORE);
                if ($abs && $root && strpos($abs, $root) === 0 && is_file($abs)) @unlink($abs);
            }
            $db->prepare("DELETE FROM creatives WHERE id = ?")->execute([$id]);
            $msg = 'Removed.';
        }

    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

/* ── the list ─────────────────────────────────────────────────────────── */
$rows = [];
$totalBytes = 0;
try {
    $db = mb_db();
    tables($db);
    $rows = $db->query("SELECT * FROM creatives ORDER BY sort_order, id DESC")->fetchAll();
    $totalBytes = (int)$db->query("SELECT COALESCE(SUM(bytes),0) FROM creatives")->fetchColumn();
} catch (Throwable $e) {
    $err = $err ?: $e->getMessage();
}

$limit = min(
    (int)ini_get('upload_max_filesize') ?: 2,
    (int)ini_get('post_max_size') ?: 8
);

page_open('Creatives', 'affiliates');
?>

<style>
.cr-head{display:flex;justify-content:space-between;align-items:center;gap:14px;
  margin-bottom:14px;flex-wrap:wrap}
.cr-head h1{font-size:19px;font-weight:700;color:var(--txt)}
.cr-head .sub{font-size:12px;color:var(--mute);margin-top:2px}
.cr-tabs{display:flex;gap:7px;margin-bottom:16px;flex-wrap:wrap}
.cr-tab{border:1px solid var(--line2);background:transparent;color:var(--mute);
  border-radius:9px;padding:9px 17px;font-size:12.5px;font-weight:700;
  text-decoration:none;white-space:nowrap}
.cr-tab:hover{border-color:var(--gold);color:var(--gold)}
.cr-tab.on{background:rgba(216,169,75,.12);border-color:var(--gold);color:var(--gold)}

.cr-note{background:rgba(78,201,122,.09);border:1px solid rgba(78,201,122,.35);
  color:#a8e6bd;border-radius:9px;padding:11px 14px;font-size:13px;margin-bottom:14px}
.cr-err{background:rgba(242,104,94,.08);border:1px solid rgba(242,104,94,.35);
  color:#ffb3ac;border-radius:9px;padding:11px 14px;font-size:13px;margin-bottom:14px}

.cr-up{background:var(--panel);border:1.5px dashed var(--line2);border-radius:14px;
  padding:22px;margin-bottom:20px}
.cr-up.over{border-color:var(--gold);background:rgba(216,169,75,.05)}
.cr-up .drop{text-align:center;padding:16px;cursor:pointer}
.cr-up .drop i{font-size:30px;color:var(--faint);display:block;margin-bottom:10px}
.cr-up .drop b{display:block;font-size:15.5px;color:var(--txt);margin-bottom:4px}
.cr-up .drop span{font-size:12.5px;color:var(--mute);line-height:1.6}
.cr-up input[type=file]{display:none}
.cr-up .row{display:flex;gap:10px;align-items:flex-end;margin-top:14px;flex-wrap:wrap}
.cr-up label{display:block;font-size:9.5px;color:var(--mute);text-transform:uppercase;
  letter-spacing:.07em;font-weight:700;margin-bottom:5px}
.cr-up input[type=text]{background:var(--panel2);border:1px solid var(--line2);
  border-radius:8px;padding:9px 12px;color:var(--txt);font-size:14px;outline:none;
  font-family:inherit;min-width:170px}
.cr-up input:focus{border-color:var(--gold)}
.cr-btn{background:var(--gold);border:1px solid var(--gold);border-radius:9px;
  color:#0f1115;padding:10px 20px;font-size:13px;font-weight:700;cursor:pointer;
  font-family:inherit}
.cr-btn:hover{background:#e8bf44}
.cr-btn:disabled{opacity:.5;cursor:wait}
.cr-chosen{font-size:12.5px;color:var(--gold);margin-top:10px;text-align:center}

.cr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:16px}
.cr-item{background:var(--panel);border:1px solid var(--line);border-radius:12px;
  overflow:hidden}
.cr-item.off{opacity:.5}
.cr-shot{aspect-ratio:4/3;background:var(--panel2);position:relative;overflow:hidden;
  display:flex;align-items:center;justify-content:center}
.cr-shot img{width:100%;height:100%;object-fit:cover;display:block}
.cr-shot video{width:100%;height:100%;object-fit:cover;display:block}
.cr-shot .kind{position:absolute;top:8px;left:8px;background:rgba(15,17,21,.85);
  color:var(--txt2);font-size:10px;font-weight:700;text-transform:uppercase;
  letter-spacing:.06em;padding:3px 9px;border-radius:5px}
.cr-body{padding:12px 13px}
.cr-body b{display:block;font-size:13.5px;color:var(--txt);margin-bottom:3px;
  word-break:break-word}
.cr-body .meta{font-size:11.5px;color:var(--mute)}
.cr-acts{display:flex;gap:6px;padding:0 13px 13px}
.cr-acts button,.cr-acts a{flex:1;background:none;border:1px solid var(--line2);
  border-radius:7px;color:var(--mute);padding:6px 8px;font-size:11px;font-weight:700;
  cursor:pointer;font-family:inherit;text-align:center;text-decoration:none}
.cr-acts button:hover,.cr-acts a:hover{border-color:var(--gold);color:var(--gold)}
.cr-acts .del:hover{border-color:var(--bad);color:var(--bad)}

.cr-edit{display:none;padding:0 13px 13px}
.cr-edit.on{display:block}
.cr-edit input[type=text]{width:100%;background:var(--panel2);border:1px solid var(--line2);
  border-radius:7px;padding:7px 9px;color:var(--txt);font-size:12.5px;outline:none;
  font-family:inherit;margin-bottom:7px}
.cr-edit label{display:flex;gap:8px;align-items:center;font-size:12px;color:var(--txt2);
  margin-bottom:9px;cursor:pointer}
.cr-edit input[type=checkbox]{width:17px;height:17px;accent-color:var(--gold)}

.cr-empty{text-align:center;padding:44px;color:var(--faint);font-size:13.5px}
.cr-foot{font-size:11.5px;color:var(--faint);line-height:1.7;margin-top:16px}
.cr-foot b{color:var(--gold)}
</style>

<div class="cr-head">
  <div>
    <h1>Creatives</h1>
    <div class="sub"><?= count($rows) ?> file<?= count($rows) === 1 ? '' : 's' ?>
      · <?= e(human($totalBytes)) ?></div>
  </div>
</div>

<div class="cr-tabs">
  <a class="cr-tab" href="affiliates.php">Affiliates</a>
  <a class="cr-tab" href="sales.php?view=sales">Sales</a>
  <a class="cr-tab" href="sales.php?view=billing">Billing</a>
  <a class="cr-tab on" href="creatives.php">Creatives</a>
</div>

<?php if ($msg): ?><div class="cr-note"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="cr-err"><?= e($err) ?></div><?php endif; ?>

<form class="cr-up" id="upBox" method="post" enctype="multipart/form-data">
  <input type="hidden" name="do" value="upload">
  <input type="file" id="picker" name="files[]" multiple accept="image/*,video/*">

  <div class="drop" onclick="document.getElementById('picker').click()">
    <i class="bi bi-cloud-arrow-up"></i>
    <b>Drop images and videos here</b>
    <span>or click to choose · up to <?= (int)$limit ?> MB each</span>
  </div>

  <div class="cr-chosen" id="chosen"></div>

  <div class="row">
    <div>
      <label>Group</label>
      <input type="text" name="category" placeholder="general" list="cats">
      <datalist id="cats">
        <?php foreach (array_unique(array_column($rows, 'category')) as $c): ?>
          <option value="<?= e($c) ?>">
        <?php endforeach; ?>
      </datalist>
    </div>
    <button class="cr-btn" type="submit" id="upBtn">Upload</button>
  </div>
</form>

<?php if (!$rows): ?>
  <div class="cr-empty">Nothing uploaded yet. Affiliates will see whatever you put here.</div>
<?php else: ?>

<div class="cr-grid">
  <?php foreach ($rows as $r):
    $url = PUBLIC_BASE . '/' . rawurlencode($r['filename']);
  ?>
    <div class="cr-item<?= $r['active'] ? '' : ' off' ?>">
      <div class="cr-shot">
        <?php if ($r['kind'] === 'video'): ?>
          <video src="<?= e($url) ?>" muted preload="metadata"></video>
          <span class="kind">Video</span>
        <?php else: ?>
          <img src="<?= e($url) ?>" alt="" loading="lazy">
          <span class="kind"><?= e($r['category']) ?></span>
        <?php endif; ?>
      </div>

      <div class="cr-body">
        <b><?= e($r['title'] ?: $r['filename']) ?></b>
        <div class="meta">
          <?= e(human((int)$r['bytes'])) ?>
          <?php if ($r['width']): ?> · <?= (int)$r['width'] ?>×<?= (int)$r['height'] ?><?php endif; ?>
          <?php if ($r['downloads']): ?> · <?= (int)$r['downloads'] ?> downloads<?php endif; ?>
          <?= $r['active'] ? '' : ' · hidden' ?>
        </div>
      </div>

      <div class="cr-acts">
        <a href="<?= e($url) ?>" target="_blank" rel="noopener">View</a>
        <button type="button" onclick="toggleEdit(<?= (int)$r['id'] ?>)">Edit</button>
        <form method="post" style="flex:1"
              onsubmit="return confirm('Remove this file? It goes from the server too.')">
          <input type="hidden" name="do" value="delete">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="del" type="submit" style="width:100%">Delete</button>
        </form>
      </div>

      <form class="cr-edit" id="ed<?= (int)$r['id'] ?>" method="post">
        <input type="hidden" name="do" value="edit">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <input type="text" name="title" value="<?= e($r['title']) ?>" placeholder="Title">
        <input type="text" name="category" value="<?= e($r['category']) ?>" placeholder="Group">
        <label><input type="checkbox" name="active" value="1"<?= $r['active'] ? ' checked' : '' ?>>
          <span>Affiliates can see it</span></label>
        <button class="cr-btn" type="submit" style="width:100%">Save</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<div class="cr-foot">
  These are for affiliates who host their own sites. <b>Affiliates we host do not see
  this</b> — their sites already carry whatever is current, and handing them files to
  install by hand would be handing them a job they pay us to do.
</div>

<script>
/* Dropping files is the natural gesture for this, and clicking still works for
   anyone who would rather. */
var box = document.getElementById('upBox');
var picker = document.getElementById('picker');
var chosen = document.getElementById('chosen');

['dragenter','dragover'].forEach(function(ev){
  box.addEventListener(ev, function(e){ e.preventDefault(); box.classList.add('over'); });
});
['dragleave','drop'].forEach(function(ev){
  box.addEventListener(ev, function(e){ e.preventDefault(); box.classList.remove('over'); });
});
box.addEventListener('drop', function(e){
  picker.files = e.dataTransfer.files;
  say();
});
picker.addEventListener('change', say);

function say(){
  var n = picker.files ? picker.files.length : 0;
  if (!n) { chosen.textContent = ''; return; }
  var total = 0;
  for (var i = 0; i < n; i++) total += picker.files[i].size;
  chosen.textContent = n + (n === 1 ? ' file' : ' files') + ' · ' +
    (total > 1048576 ? (total/1048576).toFixed(1) + ' MB' : Math.round(total/1024) + ' KB');
}

/* A large upload takes a while, and a button that does nothing looks broken. */
box.addEventListener('submit', function(){
  var b = document.getElementById('upBtn');
  b.disabled = true;
  b.textContent = 'Uploading…';
});

function toggleEdit(id){
  var f = document.getElementById('ed' + id);
  if (f) f.classList.toggle('on');
}
</script>

<?php page_close(); ?>
