<?php
header('Content-Type: application/json; charset=utf-8');
$HLS_DIR = '/var/www/sites/dashboard/hls';
$action = $_GET['action'] ?? 'list';
if ($action === 'list') {
  $seen = []; $streams = [];
  foreach (glob($HLS_DIR.'/*.m3u8') ?: [] as $m) {
    $name = basename($m, '.m3u8');
    if (isset($seen[$name])) continue;      // de-dupe by stream name
    $seen[$name] = true;
    $streams[] = ['name'=>$name, 'live'=>(time() - filemtime($m) < 30)];
  }
  echo json_encode(['ok'=>true, 'streams'=>$streams]); exit;
}
echo json_encode(['ok'=>false,'error'=>'unknown action']);
