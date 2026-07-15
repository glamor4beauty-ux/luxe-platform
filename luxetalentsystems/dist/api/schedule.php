<?php
require __DIR__."/../cors.php";
require __DIR__.'/../config.php';
header('Content-Type: application/json; charset=utf-8');
$action=$_GET['action']??'';
try{
    switch($action){
        case 'get_calendar':
            $w=(int)($_GET['week']??0);if($w<1)json_response(['error'=>'week required'],400);
            $s=db()->prepare('SELECT * FROM calendar WHERE week_number=?');$s->execute([$w]);$row=$s->fetch();
            if(!$row)json_response(['error'=>'Week not found'],404);
            $days=[];$d=new DateTime($row['start_date']);
            for($i=0;$i<7;$i++){$days[]=['date'=>$d->format('Y-m-d'),'display'=>$d->format('D n/j'),'day'=>$d->format('l')];$d->modify('+1 day');}
            json_response(['week_number'=>$row['week_number'],'start_date'=>$row['start_date'],'end_date'=>$row['end_date'],'days'=>$days]);
            break;

        case 'get_week':
            $email=$_GET['email']??'';$w=(int)($_GET['week']??0);
            if(!$email||$w<1)json_response(['error'=>'email+week required'],400);
            $s=db()->prepare('SELECT * FROM schedule WHERE email=? AND week_number=? ORDER BY start_date');$s->execute([$email,$w]);
            json_response($s->fetchAll());break;

        case 'get_day':
            $date=$_GET['date']??'';
            if(!$date)json_response(['error'=>'date required'],400);
            $s=db()->prepare('SELECT s.id,s.email,s.last_name,s.start_date,s.start_time,s.end_time,s.hours_worked,r.first_name,r.stage_name,r.phone FROM schedule s JOIN registration r ON s.email=r.email WHERE s.start_date=? ORDER BY s.start_time');
            $s->execute([$date]);
            json_response($s->fetchAll());
            break;

        case 'get_month':
            $year=(int)($_GET['year']??date('Y'));
            $month=(int)($_GET['month']??date('n'));
            $start=sprintf('%04d-%02d-01',$year,$month);
            $end=date('Y-m-t',strtotime($start));
            $s=db()->prepare('SELECT start_date,COUNT(*) as cnt FROM schedule WHERE start_date BETWEEN ? AND ? GROUP BY start_date');
            $s->execute([$start,$end]);
            $counts=[];
            foreach($s->fetchAll() as $r) $counts[$r['start_date']]=(int)$r['cnt'];
            json_response(['year'=>$year,'month'=>$month,'counts'=>$counts]);
            break;

        case 'add':
            if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'POST only'],405);
            $data=json_decode(file_get_contents('php://input'),true);
            $email=$data['email']??'';$date=$data['date']??'';$st=$data['start_time']??'';$et=$data['end_time']??'';
            if(!$email||!$date||!$st||!$et)json_response(['error'=>'email,date,start_time,end_time required'],400);
            // Get performer last_name
            $p=db()->prepare('SELECT last_name FROM registration WHERE email=?');$p->execute([$email]);$pr=$p->fetch();
            if(!$pr)json_response(['error'=>'Performer not found'],404);
            // Find week number
            $w=db()->prepare('SELECT week_number FROM calendar WHERE ? BETWEEN start_date AND end_date');$w->execute([$date]);$wr=$w->fetch();
            if(!$wr)json_response(['error'=>'Date not in calendar range'],400);
            // Calc hours
            $s1=strtotime("2000-01-01 $st");$s2=strtotime("2000-01-01 $et");$diff=$s2-$s1;if($diff<=0)$diff+=86400;
            $hrs=round($diff/3600,2);
            // Check duplicate
            $chk=db()->prepare('SELECT id FROM schedule WHERE email=? AND start_date=?');$chk->execute([$email,$date]);
            if($chk->fetch()){
                // Update existing
                $u=db()->prepare('UPDATE schedule SET start_time=?,end_time=?,hours_worked=? WHERE email=? AND start_date=?');
                $u->execute([$st,$et,$hrs,$email,$date]);
                json_response(['success'=>true,'action'=>'updated']);
            } else {
                $ins=db()->prepare('INSERT INTO schedule (email,last_name,week_number,start_date,start_time,end_time,hours_worked) VALUES (?,?,?,?,?,?,?)');
                $ins->execute([$email,$pr['last_name'],$wr['week_number'],$date,$st,$et,$hrs]);
                json_response(['success'=>true,'action'=>'added','id'=>db()->lastInsertId()]);
            }
            break;

        case 'save_week':
            if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'POST only'],405);
            $data=json_decode(file_get_contents('php://input'),true);if(!$data)json_response(['error'=>'Invalid JSON'],400);
            $email=$data['email']??'';$lastName=$data['lastName']??'';$week=(int)($data['week']??0);$entries=$data['entries']??[];
            if(!$email||!$lastName||$week<1)json_response(['error'=>'email,lastName,week required'],400);
            $wc=db()->prepare('SELECT * FROM calendar WHERE week_number=?');$wc->execute([$week]);$cal=$wc->fetch();
            if(!$cal)json_response(['error'=>'Invalid week'],400);
            $pdo=db();$pdo->beginTransaction();
            try{
                $del=$pdo->prepare('DELETE FROM schedule WHERE email=? AND week_number=?');$del->execute([$email,$week]);
                $ins=$pdo->prepare('INSERT INTO schedule (email,last_name,week_number,start_date,start_time,end_time,hours_worked) VALUES (?,?,?,?,?,?,?)');
                foreach($entries as $e){
                    $sd=$e['start_date']??'';$st=$e['start_time']??'';$et=$e['end_time']??'';if(!$sd||!$st||!$et)continue;
                    if($sd<$cal['start_date']||$sd>$cal['end_date'])throw new RuntimeException("Date $sd not in week $week");
                    $s1=strtotime("2000-01-01 $st");$s2=strtotime("2000-01-01 $et");$diff=$s2-$s1;if($diff<=0)$diff+=86400;
                    $ins->execute([$email,$lastName,$week,$sd,$st,$et,round($diff/3600,2)]);
                }
                $pdo->commit();json_response(['success'=>true,'week'=>$week]);
            }catch(Throwable $ex){$pdo->rollBack();throw $ex;}
            break;

        case 'delete_entry':
            if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'POST only'],405);
            $data=json_decode(file_get_contents('php://input'),true);$id=(int)($data['id']??0);
            if(!$id)json_response(['error'=>'id required'],400);
            $s=db()->prepare('DELETE FROM schedule WHERE id=?');$s->execute([$id]);
            json_response(['success'=>true,'deleted'=>$s->rowCount()]);break;

        case 'list_performers':
            json_response(db()->query("SELECT email,first_name,last_name,stage_name FROM registration WHERE role='performer' ORDER BY stage_name ASC, last_name ASC")->fetchAll());break;

        case 'list_all':
            json_response(db()->query('SELECT s.*,r.first_name,r.stage_name FROM schedule s JOIN registration r ON s.email=r.email ORDER BY s.start_date DESC, s.start_time')->fetchAll());break;

        case 'current_week':
            $today=date('Y-m-d');$s=db()->prepare('SELECT week_number FROM calendar WHERE ? BETWEEN start_date AND end_date');$s->execute([$today]);$row=$s->fetch();
            json_response(['week_number'=>$row?(int)$row['week_number']:1]);break;

        default:json_response(['error'=>'Unknown action'],400);
    }
}catch(Throwable $e){error_log('[luxe schedule] '.$e->getMessage());json_response(['error'=>$e->getMessage()],500);}
