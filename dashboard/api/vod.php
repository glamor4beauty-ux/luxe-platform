<?php
require __DIR__.'/_auth.php'; require_login();
header('Content-Type: application/json; charset=utf-8');
$VOD = '/var/www/sites/dashboard/vod';
@mkdir($VOD,0775,true);
$files=[];
foreach(glob($VOD.'/*.{mp4,webm,mov,m4v}',GLOB_BRACE) ?: [] as $f){
  if(!is_file($f)) continue;
  $b=basename($f);
  $files[]=['name'=>$b,'url'=>'/vod/'.rawurlencode($b),'size'=>filesize($f),'mtime'=>filemtime($f)];
}
usort($files, fn($a,$b)=>$b['mtime']<=>$a['mtime']);
echo json_encode(['ok'=>true,'files'=>$files]);
