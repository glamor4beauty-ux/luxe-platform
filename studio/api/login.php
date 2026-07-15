<?php require __DIR__.'/_common.php';
$in=json_decode(file_get_contents('php://input'),true)?:$_POST;
$u=trim($in['username']??''); $p=(string)($in['password']??'');
foreach(users() as $row){
  if(hash_equals((string)$row['username'],$u) && password_verify($p,(string)$row['hash'])){
    session_regenerate_id(true);
    $_SESSION['user']=$u; $_SESSION['role']=$row['role']??'performer';
    jout(['ok'=>true,'name'=>$u,'role'=>$_SESSION['role']]);
  }
}
usleep(300000); http_response_code(401); jout(['ok'=>false,'error'=>'invalid credentials']);
