<?php require __DIR__.'/_common.php'; require_admin();
$F='/opt/owncast/private/users.json';
function _load($F){ return file_exists($F)?(json_decode(file_get_contents($F),true)?:[]):[]; }
function _save($F,$a){ file_put_contents($F,json_encode($a,JSON_PRETTY_PRINT)); @chmod($F,0640); }
$m=$_SERVER['REQUEST_METHOD'];
if($m==='GET'){
  jout(['ok'=>true,'users'=>array_map(fn($u)=>['username'=>$u['username'],'role'=>$u['role']??'performer'],_load($F))]);
}
$in=json_decode(file_get_contents('php://input'),true)?:[];
if($m==='POST'){
  $u=trim($in['username']??''); $p=(string)($in['password']??''); $r=(($in['role']??'')==='admin')?'admin':'performer';
  if($u==='') jout(['ok'=>false,'error'=>'Username required']);
  if(strlen($p)<6) jout(['ok'=>false,'error'=>'Password must be at least 6 characters']);
  $a=_load($F); $a=array_values(array_filter($a,fn($x)=>$x['username']!==$u));
  $a[]=['username'=>$u,'hash'=>password_hash($p,PASSWORD_BCRYPT),'role'=>$r]; _save($F,$a);
  jout(['ok'=>true]);
}
if($m==='DELETE'){
  $u=trim($in['username']??'');
  if($u===($_SESSION['user']??'')) jout(['ok'=>false,'error'=>'You cannot delete your own account']);
  _save($F,array_values(array_filter(_load($F),fn($x)=>$x['username']!==$u)));
  jout(['ok'=>true]);
}
http_response_code(405); jout(['ok'=>false]);
