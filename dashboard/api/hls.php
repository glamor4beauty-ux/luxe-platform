<?php
header('Content-Type: application/json; charset=utf-8');
$HLS_DIR = '/var/www/sites/dashboard/hls';
$RUN_DIR = '/opt/red5dash/run';
@mkdir($HLS_DIR,0775,true); @mkdir($RUN_DIR,0775,true);
$action = $_GET['action'] ?? 'status';
$stream = preg_replace('/[^a-zA-Z0-9_-]/','',$_GET['stream'] ?? 'stream1');
if($stream==='')$stream='stream1';
function bridgeRunning($s){ $o=shell_exec("pgrep -af ffmpeg 2>/dev/null | grep 'HLSBRIDGE=".$s."'")?:''; return trim($o)!==''; }
if($action==='status'){
  $running=bridgeRunning($stream); $m=$HLS_DIR.'/'.$stream.'.m3u8';
  $live=$running && is_file($m) && (time()-filemtime($m)<30);
  echo json_encode(['ok'=>true,'stream'=>$stream,'bridge'=>$running,'live'=>$live]); exit;
}
if($action==='start'){
  if(bridgeRunning($stream)){echo json_encode(['ok'=>true,'already'=>true]);exit;}
  $src="rtmp://127.0.0.1:1935/live/".$stream; $out=$HLS_DIR.'/'.$stream.'.m3u8';
  $se=escapeshellarg($src); $oe=escapeshellarg($out);
  $cmd="HLSBRIDGE={$stream} ffmpeg -re -i {$se} -c copy -f hls -hls_time 2 -hls_list_size 5 -hls_flags delete_segments+append_list -hls_segment_filename '{$HLS_DIR}/{$stream}_%03d.ts' {$oe} > {$RUN_DIR}/{$stream}.log 2>&1 &";
  shell_exec($cmd); echo json_encode(['ok'=>true,'started'=>true,'stream'=>$stream]); exit;
}
if($action==='stop'){
  shell_exec("pkill -f 'HLSBRIDGE=".$stream."' 2>/dev/null");
  array_map('unlink',glob("{$HLS_DIR}/{$stream}*.ts")?:[]); @unlink("{$HLS_DIR}/{$stream}.m3u8");
  echo json_encode(['ok'=>true,'stopped'=>true]); exit;
}
echo json_encode(['ok'=>false,'error'=>'unknown action']);
