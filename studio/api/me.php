<?php require __DIR__.'/_common.php';
if(!empty($_SESSION['user'])) jout(['ok'=>true,'name'=>$_SESSION['user'],'role'=>$_SESSION['role']??'performer']);
jout(['ok'=>false]);
