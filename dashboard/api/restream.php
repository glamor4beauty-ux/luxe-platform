<?php require __DIR__.'/_auth.php'; require_admin();
header('Content-Type: application/json; charset=utf-8');

/* Embedded restream engine — reads the same targets the dashboard edits,
   fans out via ffmpeg. Source can be a live URL or a VOD file (File->Live). */

$DATA = '/var/www/sites/dashboard/data';
$TGT  = $DATA.'/targets.json';
$SRC  = $DATA.'/restream-source.json';
$VOD  = '/var/www/sites/dashboard/vod';
$RUN  = '/opt/restream/run';
@mkdir($RUN, 0775, true); @mkdir($DATA, 0775, true);

function jload($f){ return is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : []; }
function slug($s){ return preg_replace('/[^a-zA-Z0-9]/','_', (string)$s); }
function running_tags(){
  $out = shell_exec("pgrep -af ffmpeg 2>/dev/null | grep RESTREAM_TAG=") ?: '';
  $t = [];
  foreach(explode("\n", trim($out)) as $l){ if(preg_match('/RESTREAM_TAG=([A-Za-z0-9_]+)/',$l,$m)) $t[]=$m[1]; }
  return array_values(array_unique($t));
}
function resolve_source($SRC,$VOD){
  $s = jload($SRC); $mode = ($s['mode'] ?? 'live');
  if($mode==='file'){
    $file = basename($s['file'] ?? ''); $path = $VOD.'/'.$file;
    if($file==='' || !is_file($path)) return [null,false];
    return [$path, !empty($s['loop'])];
  }
  $url = trim($s['url'] ?? '');
  return [$url!=='' ? $url : null, false];
}

$action = $_GET['action'] ?? 'status';

if($action==='getsource'){ echo json_encode(['ok'=>true,'source'=>jload($SRC)]); exit; }

if($action==='setsource'){
  $in = json_decode(file_get_contents('php://input'), true) ?: [];
  $s = [
    'mode'=> (($in['mode'] ?? 'live')==='file') ? 'file' : 'live',
    'url' => trim($in['url'] ?? ''),
    'file'=> basename($in['file'] ?? ''),
    'loop'=> !empty($in['loop']),
  ];
  file_put_contents($SRC, json_encode($s, JSON_PRETTY_PRINT));
  echo json_encode(['ok'=>true,'source'=>$s]); exit;
}

if($action==='status'){
  $tags = running_tags(); $names = [];
  foreach(jload($TGT) as $t){ if(in_array(slug($t['platform'] ?? ''), $tags)) $names[] = $t['platform']; }
  echo json_encode(['ok'=>true,'running'=>$names,'live'=>count($names)>0]); exit;
}

if($action==='stop'){ shell_exec("pkill -f RESTREAM_TAG= 2>/dev/null"); echo json_encode(['ok'=>true]); exit; }

if($action==='start'){
  [$src,$loop] = resolve_source($SRC,$VOD);
  if($src===null){ echo json_encode(['ok'=>false,'error'=>'No valid source set (live URL empty or VOD file missing).']); exit; }
  shell_exec("pkill -f RESTREAM_TAG= 2>/dev/null"); usleep(300000);
  $count = 0; $skipped = [];
  foreach(jload($TGT) as $t){
    if(empty($t['enabled'])) continue;
    $server = trim($t['server'] ?? ''); $key = trim($t['key'] ?? '');
    if($server==='') { continue; }
    if(strtoupper($t['protocol'] ?? 'RTMP') !== 'RTMP'){ $skipped[] = ($t['platform']??'?').' (non-RTMP)'; continue; }
    $tag  = slug($t['platform'] ?? ('t'.$count));
    $dest = rtrim($server,'/') . ($key!=='' ? '/'.$key : '');
    $loopArg = $loop ? '-stream_loop -1 ' : '';
    $cmd = "RESTREAM_TAG={$tag} ffmpeg -re {$loopArg}-i ".escapeshellarg($src)
         . " -c copy -f flv -metadata comment=RESTREAM_TAG={$tag} ".escapeshellarg($dest)
         . " > {$RUN}/{$tag}.log 2>&1 &";
    shell_exec($cmd); $count++;
  }
  echo json_encode(['ok'=>true,'count'=>$count,'skipped'=>$skipped,'file_mode'=>($loop||is_file($src))]); exit;
}

echo json_encode(['ok'=>false,'error'=>'unknown action']);
