<?php
header('Content-Type: application/json; charset=utf-8');
$CONFIG_FILE = '/opt/restream/config.json';
$RUN_DIR = '/opt/restream/run';
@mkdir($RUN_DIR, 0775, true);
$action = $_GET['action'] ?? 'status';
function loadCfg($f){ if(is_file($f)){ $j=json_decode(file_get_contents($f),true); if(is_array($j)) return $j; } return ['source'=>'','platforms'=>[]]; }
function slug($s){ return preg_replace('/[^a-zA-Z0-9]/','_', $s); }
function runningTags(){
  $out = shell_exec("pgrep -af 'ffmpeg' 2>/dev/null | grep 'RESTREAM_TAG=' ") ?: '';
  $tags = [];
  foreach(explode("\n", trim($out)) as $line){ if(preg_match('/RESTREAM_TAG=([A-Za-z0-9_]+)/', $line, $m)) $tags[] = $m[1]; }
  return array_unique($tags);
}
if($action==='status'){
  $tags = runningTags(); $cfg = loadCfg($CONFIG_FILE); $names = [];
  foreach($cfg['platforms'] as $p){ if(in_array(slug($p['name']), $tags)) $names[] = $p['name']; }
  echo json_encode(['ok'=>true,'running'=>$names]); exit;
}
if($action==='stop'){ shell_exec("pkill -f 'RESTREAM_TAG=' 2>/dev/null"); echo json_encode(['ok'=>true]); exit; }
if($action==='start'){
  $cfg = loadCfg($CONFIG_FILE); $source = $cfg['source'] ?? '';
  if($source===''){ echo json_encode(['ok'=>false,'error'=>'no source']); exit; }
  shell_exec("pkill -f 'RESTREAM_TAG=' 2>/dev/null"); usleep(300000);
  $count = 0;
  foreach($cfg['platforms'] as $p){
    if(empty($p['enabled'])) continue; if(empty($p['url'])) continue;
    $tag = slug($p['name']); $dest = rtrim($p['url'],'/') . '/' . $p['key'];
    if(!empty($p['vbitrate']) || !empty($p['size'])){
      $vb = !empty($p['vbitrate']) ? intval($p['vbitrate']) : 6000;
      $sz = !empty($p['size']) ? $p['size'] : '1920x1080';
      $enc = "-c:v libx264 -preset veryfast -b:v {$vb}k -maxrate {$vb}k -bufsize " . ($vb*2) . "k -s {$sz} -c:a aac -b:a 128k -ar 44100";
    } else { $enc = "-c copy"; }
    $srcEsc = escapeshellarg($source); $destEsc = escapeshellarg($dest);
    $cmd = "RESTREAM_TAG={$tag} ffmpeg -re -i {$srcEsc} {$enc} -f flv -metadata comment=RESTREAM_TAG={$tag} {$destEsc} > /opt/restream/run/{$tag}.log 2>&1 &";
    shell_exec($cmd); $count++;
  }
  echo json_encode(['ok'=>true,'count'=>$count]); exit;
}
echo json_encode(['ok'=>false,'error'=>'unknown action']);
