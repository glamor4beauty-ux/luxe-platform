<?php require __DIR__.'/_common.php'; require_admin();
$c=cfg(); $ch=curl_init($c['owncast_url'].'/api/admin/disconnect');
curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,
  CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_USERPWD=>'admin:'.$c['owncast_streamkey']]);
curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
jout(['ok'=>($code>=200&&$code<300),'code'=>$code]);
