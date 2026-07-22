<?php require __DIR__.'/_auth.php'; require_login();
function cpu_snap(){ $l=file('/proc/stat')[0]; $p=preg_split('/\s+/',trim($l)); array_shift($p); $p=array_map('intval',$p);
  $idle=$p[3]+($p[4]??0); $total=array_sum($p); return [$idle,$total]; }
[$i1,$t1]=cpu_snap(); usleep(200000); [$i2,$t2]=cpu_snap();
$dt=$t2-$t1; $di=$i2-$i1; $cpu=$dt>0?round(100*($dt-$di)/$dt,1):0.0;
$load=sys_getloadavg(); $cores=(int)trim(@shell_exec('nproc')?:'1'); if($cores<1)$cores=1;
$mem=[]; foreach(file('/proc/meminfo') as $ml){ if(preg_match('/^(MemTotal|MemAvailable):\s+(\d+)/',$ml,$m)) $mem[$m[1]]=(int)$m[2]; }
$mempct = (!empty($mem['MemTotal'])) ? round(100*($mem['MemTotal']-($mem['MemAvailable']??0))/$mem['MemTotal'],1) : null;
jout(['ok'=>true,'cpu'=>$cpu,'cores'=>$cores,
      'load'=>['1m'=>$load[0],'5m'=>$load[1],'15m'=>$load[2]],
      'load_pct'=>round(100*$load[0]/$cores,1),'mem_pct'=>$mempct]);
