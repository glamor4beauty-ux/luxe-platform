<?php require __DIR__.'/_auth.php';
$_SESSION = []; session_destroy();
jout(['ok'=>true]);
