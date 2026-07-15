<?php require __DIR__.'/_common.php'; require_login();
$b=bunny_conf();
$host=$b['BUNNY_STORAGE_HOST']??'storage.bunnycdn.com';
$zone=$b['BUNNY_ZONE']??''; $key=$b['BUNNY_KEY']??''; $path=$b['REMOTE_PATH']??'recordings';
$pull=cfg()['bunny_pullzone_host'];
$ch=curl_init("https://$host/$zone/$path/");
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_HTTPHEADER=>["AccessKey: $key"]]);
$res=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
$out=[];
if($code==200){ foreach(json_decode($res,true)?:[] as $o){
  if(empty($o['IsDirectory'])){ $n=$o['ObjectName'];
    $out[]=['name'=>$n,'url'=>"https://$pull/$path/".rawurlencode($n),
            'size'=>(int)($o['Length']??0),'date'=>$o['LastChanged']??'']; } } }
usort($out, fn($a,$b)=>strcmp($b['date'],$a['date']));
jout(['ok'=>true,'items'=>$out,'code'=>$code]);
