<?php require __DIR__.'/_auth.php'; require_login();
header('Content-Type: application/json; charset=utf-8');
$ch = curl_init('http://127.0.0.1:8443/rooms');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>4]);
$res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if ($code === 200 && $res) { echo $res; } else { echo json_encode(['ok'=>false,'rooms'=>[]]); }
