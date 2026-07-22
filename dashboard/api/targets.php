<?php
header('Content-Type: application/json; charset=utf-8');
$DATA = '/var/www/sites/dashboard/data';
$FILE = $DATA.'/targets.json';
@mkdir($DATA,0775,true);

function load_rows($f){ if(!is_file($f)) return null; $j=json_decode(@file_get_contents($f),true); return is_array($j)?$j:null; }
function save_rows($f,$a){ @file_put_contents($f,json_encode(array_values($a),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); }

$defaults = [
  ['id'=>'direct',  'platform'=>'Direct',  'protocol'=>'RTMP','server'=>'rtmp://162.35.180.58:1935/live','key'=>'stream1','region'=>''],
  ['id'=>'twitter', 'platform'=>'Twitter', 'protocol'=>'RTMP','server'=>'','key'=>'','region'=>''],
  ['id'=>'facebook','platform'=>'Facebook','protocol'=>'RTMP','server'=>'','key'=>'','region'=>''],
  ['id'=>'youtube', 'platform'=>'Youtube', 'protocol'=>'RTMP','server'=>'','key'=>'','region'=>''],
  ['id'=>'tiktok',  'platform'=>'TikTok',  'protocol'=>'RTMP','server'=>'','key'=>'','region'=>''],
];

$action = $_GET['action'] ?? 'list';
$rows = load_rows($FILE);
if($rows===null){ $rows=$defaults; save_rows($FILE,$rows); }

if($action==='list'){ echo json_encode(['ok'=>true,'targets'=>array_values($rows)]); exit; }

$body = json_decode(file_get_contents('php://input'),true) ?: [];

if($action==='save'){
  $t = [];
  $t['id'] = preg_replace('/[^a-z0-9_-]/i','', (string)($body['id'] ?? '')); 
  if($t['id']==='') $t['id'] = 't'.substr(md5(uniqid('',true)),0,8);
  foreach(['platform','protocol','server','key','region'] as $k){ $t[$k]=trim((string)($body[$k] ?? '')); }
  if(!in_array($t['protocol'],['RTMP','RTSP','SRT'],true)) $t['protocol']='RTMP';
  $found=false;
  foreach($rows as &$r){ if(($r['id']??'')===$t['id']){ $r=$t; $found=true; break; } } unset($r);
  if(!$found) $rows[]=$t;
  save_rows($FILE,$rows);
  echo json_encode(['ok'=>true,'id'=>$t['id']]); exit;
}

if($action==='delete'){
  $id = preg_replace('/[^a-z0-9_-]/i','', (string)($body['id'] ?? ''));
  $rows = array_filter($rows, fn($r)=>($r['id']??'')!==$id);
  save_rows($FILE,$rows);
  echo json_encode(['ok'=>true]); exit;
}

echo json_encode(['ok'=>false,'error'=>'unknown action']);
