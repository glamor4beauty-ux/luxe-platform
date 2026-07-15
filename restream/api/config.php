<?php
header('Content-Type: application/json; charset=utf-8');
$CONFIG_FILE = '/opt/restream/config.json';
$action = $_GET['action'] ?? 'get';
$DEFAULTS = ['source' => 'rtmp://127.0.0.1:1935/live/jP8NvaMBLbVM4OGqElFEDSRsE6FIXr','platforms' => []];
function loadCfg($f,$d){ if(is_file($f)){ $j=json_decode(file_get_contents($f),true); if(is_array($j)) return array_merge($d,$j); } return $d; }
if($action==='get'){ echo json_encode(['ok'=>true] + loadCfg($CONFIG_FILE,$DEFAULTS)); exit; }
if($action==='save'){
  $in = json_decode(file_get_contents('php://input'), true) ?: [];
  $cfg = ['source' => trim($in['source'] ?? $DEFAULTS['source']),'platforms' => []];
  foreach(($in['platforms'] ?? []) as $p){
    $cfg['platforms'][] = ['name'=>trim($p['name']??''),'url'=>trim($p['url']??''),'key'=>trim($p['key']??''),'enabled'=>(bool)($p['enabled']??false),'vbitrate'=>preg_replace('/[^0-9]/','',$p['vbitrate']??''),'size'=>preg_replace('/[^0-9x]/','',$p['size']??'')];
  }
  @mkdir(dirname($CONFIG_FILE),0775,true);
  file_put_contents($CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT));
  echo json_encode(['ok'=>true]); exit;
}
echo json_encode(['ok'=>false,'error'=>'unknown action']);
