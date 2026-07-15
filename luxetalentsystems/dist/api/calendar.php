<?php
/* ═══════════════════════════════════════════════════════════════════════════
   Google Calendar API — /api/calendar.php
   Service-account auth (no per-user OAuth). Manages one central calendar.
     GET  ?action=list[&days=30]           → { ok, events:[...] }
     GET  ?action=performers               → { ok, performers:[{name,email,stage}] }
     POST ?action=create {title,start,end,description,attendees[]}  → { ok, id }
     POST ?action=update {id,title,start,end,description,attendees[]} → { ok }
     POST ?action=delete {id}              → { ok }
   Times are ISO 8601 (e.g. 2026-07-10T14:00:00-04:00) or date-time-local.
   ═══════════════════════════════════════════════════════════════════════════ */
require __DIR__ . '/../cors.php';
require __DIR__ . '/../config.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

$email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';
if ($email === '') { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }

function setting($k){ static $c=[]; if(isset($c[$k]))return $c[$k]; $v=db()->query("SELECT setting_value FROM settings WHERE setting_key=".db()->quote($k))->fetchColumn(); return $c[$k]=($v===false?null:$v); }

$CAL_ID  = setting('google_calendar_id');
$SA_PATH = setting('google_calendar_sa_path') ?: '/etc/luxe/calendar-sa.json';
if (!$CAL_ID || !is_file($SA_PATH)) { echo json_encode(['ok'=>false,'error'=>'Calendar not configured']); exit; }

/* ── Base64url helpers ── */
function b64url($d){ return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }

/* ── Get a Google access token via service-account JWT (cached in APCu/file for ~50 min) ── */
function google_access_token($saPath) {
    $cacheFile = sys_get_temp_dir().'/gcal_token.json';
    if (is_file($cacheFile)) {
        $c = json_decode(file_get_contents($cacheFile), true);
        if ($c && ($c['exp'] ?? 0) > time() + 60) return $c['token'];
    }
    $sa = json_decode(file_get_contents($saPath), true);
    if (!$sa || empty($sa['client_email']) || empty($sa['private_key'])) throw new Exception('bad service account file');

    $now = time();
    $header = ['alg'=>'RS256','typ'=>'JWT'];
    $claim = [
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/calendar',
        'aud'   => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];
    $signingInput = b64url(json_encode($header)).'.'.b64url(json_encode($claim));
    $sig = '';
    if (!openssl_sign($signingInput, $sig, $sa['private_key'], 'sha256WithRSAEncryption')) throw new Exception('jwt sign failed');
    $jwt = $signingInput.'.'.b64url($sig);

    $ch = curl_init($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20,
        CURLOPT_POSTFIELDS=>http_build_query([
            'grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'=>$jwt,
        ]),
    ]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $d = json_decode($res, true);
    if ($code !== 200 || empty($d['access_token'])) throw new Exception('token error: '.substr($res,0,200));

    @file_put_contents($cacheFile, json_encode(['token'=>$d['access_token'],'exp'=>$now + ($d['expires_in'] ?? 3600)]));
    @chmod($cacheFile, 0600);
    return $d['access_token'];
}

/* ── Call the Calendar API ── */
function gcal($method, $path, $token, $body=null) {
    $ch = curl_init('https://www.googleapis.com/calendar/v3'.$path);
    $headers = ['Authorization: Bearer '.$token, 'Content-Type: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>25,
        CURLOPT_HTTPHEADER=>$headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode($res, true), $res];
}

$action = $_GET['action'] ?? 'list';
$in = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    $token = google_access_token($SA_PATH);
    $calPath = '/calendars/'.rawurlencode($CAL_ID).'/events';

    if ($action === 'list') {
        $days = max(1, min(365, (int)($_GET['days'] ?? 60)));
        $timeMin = gmdate('c', time() - 7*86400);           // include last week
        $timeMax = gmdate('c', time() + $days*86400);
        $qs = '?timeMin='.rawurlencode($timeMin).'&timeMax='.rawurlencode($timeMax).'&singleEvents=true&orderBy=startTime&maxResults=250';
        [$code,$d] = gcal('GET', $calPath.$qs, $token);
        if ($code !== 200) { echo json_encode(['ok'=>false,'error'=>'list failed','detail'=>$d]); exit; }
        $events = array_map(function($e){
            return [
                'id'          => $e['id'] ?? '',
                'title'       => $e['summary'] ?? '(no title)',
                'description' => $e['description'] ?? '',
                'start'       => $e['start']['dateTime'] ?? ($e['start']['date'] ?? ''),
                'end'         => $e['end']['dateTime'] ?? ($e['end']['date'] ?? ''),
                'attendees'   => array_map(function($a){ return $a['email'] ?? ''; }, $e['attendees'] ?? []),
                'htmlLink'    => $e['htmlLink'] ?? '',
            ];
        }, $d['items'] ?? []);
        echo json_encode(['ok'=>true,'events'=>$events]); exit;
    }

    if ($action === 'performers') {
        $rows = db()->query("SELECT stage_name, first_name, last_name, email FROM registration WHERE email IS NOT NULL AND email<>'' ORDER BY stage_name, last_name")->fetchAll(PDO::FETCH_ASSOC);
        $out = array_map(function($r){
            $nm = trim(($r['first_name']??'').' '.($r['last_name']??''));
            return ['stage'=>$r['stage_name']?:'', 'name'=>$nm?:($r['stage_name']?:$r['email']), 'email'=>$r['email']];
        }, $rows);
        echo json_encode(['ok'=>true,'performers'=>$out]); exit;
    }

    if ($action === 'create' || $action === 'update') {
        $title = trim($in['title'] ?? '');
        $start = trim($in['start'] ?? '');
        $end   = trim($in['end'] ?? '');
        $desc  = trim($in['description'] ?? '');
        $att   = array_values(array_filter(array_map('trim', $in['attendees'] ?? [])));
        if ($title==='' || $start==='' || $end==='') { echo json_encode(['ok'=>false,'error'=>'title, start and end required']); exit; }

        $event = [
            'summary'     => $title,
            'description' => $desc,
            'start'       => ['dateTime'=>$start, 'timeZone'=>'America/New_York'],
            'end'         => ['dateTime'=>$end,   'timeZone'=>'America/New_York'],
            'reminders'   => ['useDefault'=>false, 'overrides'=>[
                ['method'=>'popup','minutes'=>15],
                ['method'=>'email','minutes'=>15],
            ]],
        ];
        // Service accounts cannot invite attendees (403). Fold invitees into the description instead.
        if ($att) { $event['description'] = trim(($event['description']??'')."\n\nInvited: ".implode(", ", $att)); }

        if ($action === 'create') {
            [$code,$d] = gcal('POST', $calPath.'?sendUpdates=all', $token, $event);
            if ($code >= 200 && $code < 300) { echo json_encode(['ok'=>true,'id'=>$d['id'] ?? '']); }
            else { echo json_encode(['ok'=>false,'error'=>'create failed','detail'=>$d]); }
            exit;
        } else {
            $id = trim($in['id'] ?? '');
            if ($id==='') { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
            [$code,$d] = gcal('PUT', $calPath.'/'.rawurlencode($id).'?sendUpdates=all', $token, $event);
            if ($code >= 200 && $code < 300) { echo json_encode(['ok'=>true]); }
            else { echo json_encode(['ok'=>false,'error'=>'update failed','detail'=>$d]); }
            exit;
        }
    }

    if ($action === 'delete') {
        $id = trim($in['id'] ?? '');
        if ($id==='') { echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
        [$code,$d,$raw] = gcal('DELETE', $calPath.'/'.rawurlencode($id).'?sendUpdates=all', $token);
        if ($code >= 200 && $code < 300) { echo json_encode(['ok'=>true]); }
        else { echo json_encode(['ok'=>false,'error'=>'delete failed','detail'=>$raw]); }
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'unknown action']);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
