<?php
/* ═══════════════════════════════════════════════════════════════════════════
   Appointment Booking API — /api/booking.php
   Slot-based booking on the shared Google Calendar (service-account auth).
   Modeled on sar-joshi/booking-with-google-calendar, adapted to PHP.

     GET  ?action=slots&date=YYYY-MM-DD        → { ok, slots:[{start,end,label,taken}] }
     GET  ?action=month&year=YYYY&month=M      → { ok, days:[{day,hasSlots}] }
     POST ?action=book {date,start,name,email,notes} → { ok, id }

   CONFIG (edit these to change bookable hours / slot length):
   ═══════════════════════════════════════════════════════════════════════════ */
require __DIR__ . '/../cors.php';
require __DIR__ . '/../config.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

// ── Booking configuration (easy to change) ──
$CFG = [
    'tz'            => 'America/New_York',  // calendar timezone
    'day_start'     => '10:00',             // first slot start
    'day_end'       => '20:00',             // last slot must end by
    'slot_minutes'  => 120,                  // appointment length
    'gap_minutes'   => 0,                   // gap between slots
    'weekdays_only' => true,                // Mon–Fri only
    'title_prefix'  => 'Appointment',       // event title prefix
];
// Overlay saved config from DB (Days Off / Settings)
try {
  $__bc = db()->query("SELECT setting_value FROM settings WHERE setting_key='booking_config'")->fetchColumn();
  if ($__bc) { $saved = json_decode($__bc, true); if (is_array($saved)) {
    foreach (['day_start','day_end','slot_minutes','gap_minutes','weekdays_only'] as $k) {
      if (isset($saved[$k]) && $saved[$k] !== '') $CFG[$k] = $saved[$k];
    }
    $CFG['blocked_dates'] = (isset($saved['blocked_dates']) && is_array($saved['blocked_dates'])) ? $saved['blocked_dates'] : [];
  }}
} catch (Throwable $e) {}
if (!isset($CFG['blocked_dates'])) $CFG['blocked_dates'] = [];

$email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';
// NOTE: booking can be public (performers self-book) — set REQUIRE_LOGIN false to allow that.
$REQUIRE_LOGIN = false;
if ($REQUIRE_LOGIN && $email === '') { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }

function setting($k){ static $c=[]; if(isset($c[$k]))return $c[$k]; $v=db()->query("SELECT setting_value FROM settings WHERE setting_key=".db()->quote($k))->fetchColumn(); return $c[$k]=($v===false?null:$v); }
$CAL_ID  = setting('google_calendar_id');
$SA_PATH = setting('google_calendar_sa_path') ?: '/etc/luxe/calendar-sa.json';
if (!$CAL_ID || !is_file($SA_PATH)) { echo json_encode(['ok'=>false,'error'=>'Calendar not configured']); exit; }

function b64url($d){ return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function google_token($saPath) {
    $cacheFile = sys_get_temp_dir().'/gcal_token.json';
    if (is_file($cacheFile)) { $c=json_decode(file_get_contents($cacheFile),true); if($c && ($c['exp']??0)>time()+60) return $c['token']; }
    $sa = json_decode(file_get_contents($saPath), true);
    $now=time();
    $h=b64url(json_encode(['alg'=>'RS256','typ'=>'JWT']));
    $c=b64url(json_encode(['iss'=>$sa['client_email'],'scope'=>'https://www.googleapis.com/auth/calendar','aud'=>$sa['token_uri'],'iat'=>$now,'exp'=>$now+3600]));
    $sig=''; openssl_sign("$h.$c",$sig,$sa['private_key'],'sha256WithRSAEncryption');
    $jwt="$h.$c.".b64url($sig);
    $ch=curl_init($sa['token_uri']);
    curl_setopt_array($ch,[CURLOPT_POST=>1,CURLOPT_RETURNTRANSFER=>1,CURLOPT_POSTFIELDS=>http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt])]);
    $d=json_decode(curl_exec($ch),true); curl_close($ch);
    if(empty($d['access_token'])) throw new Exception('token failed');
    @file_put_contents($cacheFile,json_encode(['token'=>$d['access_token'],'exp'=>$now+($d['expires_in']??3600)])); @chmod($cacheFile,0600);
    return $d['access_token'];
}
function gcal($method,$path,$token,$body=null){
    $ch=curl_init('https://www.googleapis.com/calendar/v3'.$path);
    $hdr=['Authorization: Bearer '.$token,'Content-Type: application/json'];
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>1,CURLOPT_HTTPHEADER=>$hdr]);
    if($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));
    $res=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code,json_decode($res,true)];
}

// Build the theoretical slots for a given date (before checking availability)
function build_slots($date, $CFG){
    $tz = new DateTimeZone($CFG['tz']);
    $slots = [];
    $cur = new DateTime($date.' '.$CFG['day_start'], $tz);
    $end = new DateTime($date.' '.$CFG['day_end'], $tz);
    $len = $CFG['slot_minutes'];
    $gap = $CFG['gap_minutes'];
    while (true) {
        $slotEnd = (clone $cur)->modify("+{$len} minutes");
        if ($slotEnd > $end) break;
        $slots[] = ['start'=>clone $cur, 'end'=>$slotEnd];
        $cur = (clone $slotEnd)->modify("+{$gap} minutes");
    }
    return $slots;
}

$action = $_GET['action'] ?? 'slots';
$in = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    $token = google_token($SA_PATH);
    $calPath = '/calendars/'.rawurlencode($CAL_ID).'/events';
    $tz = new DateTimeZone($CFG['tz']);

    if ($action === 'slots') {
        $date = $_GET['date'] ?? gmdate('Y-m-d');
        $d = new DateTime($date, $tz);
        // Weekday check
        if ($CFG['weekdays_only'] && (int)$d->format('N') >= 6) { echo json_encode(['ok'=>true,'slots'=>[],'note'=>'weekend']); exit; }
        if (in_array($date, $CFG['blocked_dates'], true)) { echo json_encode(['ok'=>true,'slots'=>[],'note'=>'blocked']); exit; }

        $slots = build_slots($date, $CFG);
        // Fetch existing events that day to mark taken slots
        $dayStart = (new DateTime($date.' 00:00:00',$tz))->format('c');
        $dayEnd   = (new DateTime($date.' 23:59:59',$tz))->format('c');
        $qs='?timeMin='.rawurlencode($dayStart).'&timeMax='.rawurlencode($dayEnd).'&singleEvents=true&orderBy=startTime&maxResults=250';
        [$code,$ev] = gcal('GET',$calPath.$qs,$token);
        $busy = [];
        foreach (($ev['items']??[]) as $e) {
            if(!empty($e['start']['dateTime']) && !empty($e['end']['dateTime'])){
                $busy[] = [new DateTime($e['start']['dateTime']), new DateTime($e['end']['dateTime'])];
            }
        }
        $out=[];
        foreach ($slots as $s) {
            $taken=false;
            foreach ($busy as $b) { if ($s['start'] < $b[1] && $s['end'] > $b[0]) { $taken=true; break; } }
            $out[] = [
                'start' => $s['start']->format('c'),
                'end'   => $s['end']->format('c'),
                'label' => $s['start']->format('g:i A').' – '.$s['end']->format('g:i A'),
                'taken' => $taken,
            ];
        }
        echo json_encode(['ok'=>true,'date'=>$date,'slots'=>$out]); exit;
    }

    if ($action === 'month') {
        $year=(int)($_GET['year']??date('Y')); $month=(int)($_GET['month']??date('n'));
        $days=[]; $dim=(int)date('t',mktime(0,0,0,$month,1,$year));
        for($day=1;$day<=$dim;$day++){
            $d=new DateTime(sprintf('%04d-%02d-%02d',$year,$month,$day),$tz);
            $isWeekend = (int)$d->format('N')>=6;
            $past = $d < (new DateTime('today',$tz));
            $hasSlots = !$past && !($CFG['weekdays_only'] && $isWeekend);
            $days[]=['day'=>$day,'hasSlots'=>$hasSlots,'weekend'=>$isWeekend,'past'=>$past];
        }
        echo json_encode(['ok'=>true,'year'=>$year,'month'=>$month,'days'=>$days]); exit;
    }

    if ($action === 'book') {
        $date = $in['date'] ?? '';
        $start= $in['start'] ?? '';         // ISO start of the chosen slot
        $name = trim($in['name'] ?? '');
        $bemail=trim($in['email'] ?? '');
        $notes= trim($in['notes'] ?? '');
        if(!$start || !$name){ echo json_encode(['ok'=>false,'error'=>'name and slot required']); exit; }

        $s = new DateTime($start);
        $e = (clone $s)->modify('+'.$CFG['slot_minutes'].' minutes');

        // Re-check the slot isn't already taken (prevent double-booking)
        $qs='?timeMin='.rawurlencode($s->format('c')).'&timeMax='.rawurlencode($e->format('c')).'&singleEvents=true&maxResults=10';
        [$c1,$ev]=gcal('GET',$calPath.$qs,$token);
        foreach(($ev['items']??[]) as $x){
            if(!empty($x['start']['dateTime'])){
                $xs=new DateTime($x['start']['dateTime']); $xe=new DateTime($x['end']['dateTime']);
                if($s < $xe && $e > $xs){ echo json_encode(['ok'=>false,'error'=>'That slot was just taken. Pick another.']); exit; }
            }
        }

        $event = [
            'summary'     => $CFG['title_prefix'].' — '.$name,
            'description' => trim("Booked by: $name".($bemail?" ($bemail)":"")."\n".$notes),
            'start'       => ['dateTime'=>$s->format('c'),'timeZone'=>$CFG['tz']],
            'end'         => ['dateTime'=>$e->format('c'),'timeZone'=>$CFG['tz']],
            'reminders'   => ['useDefault'=>false, 'overrides'=>[
                ['method'=>'popup','minutes'=>15],
                ['method'=>'email','minutes'=>15],
            ]],
        ];
        [$code,$d]=gcal('POST',$calPath,$token,$event);
        if($code>=200 && $code<300){ echo json_encode(['ok'=>true,'id'=>$d['id']??'','label'=>$s->format('g:i A').' – '.$e->format('g:i A')]); }
        else { echo json_encode(['ok'=>false,'error'=>'booking failed','detail'=>$d]); }
        exit;
    }

    if ($action === 'get_config') {
        echo json_encode(['ok'=>true,'config'=>[
            'day_start'=>$CFG['day_start'],'day_end'=>$CFG['day_end'],
            'slot_minutes'=>$CFG['slot_minutes'],'gap_minutes'=>$CFG['gap_minutes'],
            'weekdays_only'=>$CFG['weekdays_only'],'blocked_dates'=>$CFG['blocked_dates'],
        ]]); exit;
    }
    if ($action === 'save_config') {
        if ($email === '') { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Admin login required']); exit; }
        $nd = []; foreach ((array)($in['blocked_dates'] ?? []) as $bd) { $bd=trim($bd); if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $bd)) $nd[]=$bd; }
        $cfg = [
            'day_start'     => preg_match('/^[0-9]{2}:[0-9]{2}$/', $in['day_start']??'') ? $in['day_start'] : $CFG['day_start'],
            'day_end'       => preg_match('/^[0-9]{2}:[0-9]{2}$/', $in['day_end']??'') ? $in['day_end'] : $CFG['day_end'],
            'slot_minutes'  => max(15, min(480, (int)($in['slot_minutes'] ?? $CFG['slot_minutes']))),
            'gap_minutes'   => max(0, min(120, (int)($in['gap_minutes'] ?? $CFG['gap_minutes']))),
            'weekdays_only' => !empty($in['weekdays_only']),
            'blocked_dates' => array_values(array_unique($nd)),
        ];
        db()->prepare("INSERT INTO settings (setting_key,setting_value,is_secret) VALUES ('booking_config',?,0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([json_encode($cfg)]);
        echo json_encode(['ok'=>true,'config'=>$cfg]); exit;
    }
    echo json_encode(['ok'=>false,'error'=>'unknown action']);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
