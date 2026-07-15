<?php require __DIR__.'/_common.php';
if(!empty($_SESSION['user'])){ http_response_code(200); echo 'ok'; } else { http_response_code(401); }
