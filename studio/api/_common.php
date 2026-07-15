<?php
session_set_cookie_params(['path'=>'/',
  'httponly'=>true,'samesite'=>'Lax',
  'secure'=>(!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https'),
]);
session_start();
function cfg(){ static $c=null; if($c===null){ $c=require '/opt/owncast/private/config.php'; } return $c; }
function users(){ $f='/opt/owncast/private/users.json'; return file_exists($f)?(json_decode(file_get_contents($f),true)?:[]):[]; }
function jout($a){ header('Content-Type: application/json'); echo json_encode($a); exit; }
function require_login(){ if(empty($_SESSION['user'])){ http_response_code(401); jout(['ok'=>false]); } }
function require_admin(){ require_login(); if(($_SESSION['role']??'')!=='admin'){ http_response_code(403); jout(['ok'=>false,'error'=>'admin only']); } }
function bunny_conf(){ $c=[]; if(is_readable('/etc/bunny-offload.conf')){ foreach(file('/etc/bunny-offload.conf') as $l){ if(preg_match('/^(\w+)="?([^"\r\n]*)"?/',$l,$m)) $c[$m[1]]=$m[2]; } } return $c; }
