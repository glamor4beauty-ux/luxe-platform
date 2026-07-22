<?php require __DIR__.'/_auth.php'; require_admin();
header('Content-Type: application/json; charset=utf-8');
$VOD = '/var/www/sites/dashboard/vod';
@mkdir($VOD, 0775, true);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_FILES['file'])) {
  echo json_encode(['ok'=>false,'error'=>'no file received']); exit;
}
$f = $_FILES['file'];
if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
  $map = [1=>'file exceeds server limit',2=>'file too large',3=>'partial upload',4=>'no file',6=>'no temp dir',7=>'write failed'];
  echo json_encode(['ok'=>false,'error'=>($map[$f['error']] ?? ('error '.$f['error']))]); exit;
}
$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['mp4','webm','mov','m4v'], true)) {
  echo json_encode(['ok'=>false,'error'=>'only mp4 / webm / mov / m4v']); exit;
}
$base = preg_replace('/[^A-Za-z0-9._-]/','_', pathinfo($f['name'], PATHINFO_FILENAME));
if ($base==='') $base = 'video_'.date('Ymd_His');
$safe = $base.'.'.$ext;
$dest = $VOD.'/'.$safe;
if (!move_uploaded_file($f['tmp_name'], $dest)) {
  echo json_encode(['ok'=>false,'error'=>'could not save to VOD dir']); exit;
}
@chmod($dest, 0644);
echo json_encode(['ok'=>true,'name'=>$safe]);
