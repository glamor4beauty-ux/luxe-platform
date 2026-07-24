<?php
/* api/pbx.php v2 — PBX management: Extensions + Trunks + Routes.
   Writes ONLY pjsip_custom.conf + extensions_custom.conf. Core configs untouched.
   Backs up before every write. Reloads via restricted sudoers (pjsip reload / dialplan reload). */

session_start();
header('Content-Type: application/json');

$email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';
if ($email === '') { http_response_code(401); echo json_encode(['error'=>'Not signed in']); exit; }

$CUSTOM     = '/etc/asterisk/pjsip_custom.conf';
$DIALCUSTOM = '/etc/asterisk/extensions_custom.conf';
$BACKUP_DIR = '/etc/asterisk/pbx_backups';
$ASTERISK   = '/usr/sbin/asterisk';

function respond($a){ echo json_encode($a); exit; }
function ast($cmd){ return shell_exec('sudo '.escapeshellcmd($cmd).' 2>&1'); }
function backup($file, $BACKUP_DIR){ if (is_file($file)) @copy($file, $BACKUP_DIR.'/'.basename($file).'.'.date('Ymd-His').'.bak'); }

$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?: [];

/* ═══════════════════ EXTENSIONS (unchanged contract) ═══════════════════ */

function read_exts($CUSTOM){
    $exts=[]; if(is_readable($CUSTOM)){ $txt=file_get_contents($CUSTOM);
      if(preg_match_all('/\[(\d{3,6})\]\(webrtc-endpoint\)/',$txt,$m)){ foreach($m[1] as $x){ $pw='';
        if(preg_match('/\[auth'.$x.'\].*?password=([^\r\n]+)/s',$txt,$pm)) $pw=trim($pm[1]);
        $exts[]=['ext'=>$x,'password'=>$pw]; } } }
    return $exts;
}

/* ── trunk blocks live between marker lines; preserved on every extension rewrite ── */
function read_trunk_blocks($CUSTOM){
    $blocks=[];
    if (is_readable($CUSTOM)){
        $txt=file_get_contents($CUSTOM);
        if (preg_match_all('/^;===TRUNK:([a-z0-9_\-]+):([a-z\-]+):(.*?)===\R(.*?)^;===ENDTRUNK:\1===\R?/ms', $txt, $m, PREG_SET_ORDER)){
            foreach ($m as $b) $blocks[] = ['name'=>$b[1], 'type'=>$b[2], 'meta'=>$b[3], 'raw'=>$b[0]];
        }
    }
    return $blocks;
}

function write_custom($exts, $CUSTOM, $BACKUP_DIR, $trunkBlocks=null){
    if ($trunkBlocks === null) $trunkBlocks = read_trunk_blocks($CUSTOM);
    backup($CUSTOM, $BACKUP_DIR);
    $out  = ";=== Browser-editable extensions + trunks (managed by PBX tab) ===\n";
    $out .= ";=== Core transport/WSS stays in pjsip.conf — NOT editable here ===\n\n";
    foreach ($exts as $e) {
        $x = $e['ext']; $pw = $e['password'];
        $out .= "[$x](webrtc-endpoint)\nauth=auth$x\naors=$x\n\n";
        $out .= "[auth$x]\ntype=auth\nauth_type=userpass\nusername=$x\npassword=$pw\n\n";
        $out .= "[$x]\ntype=aor\nmax_contacts=5\nremove_existing=yes\n\n";
    }
    foreach ($trunkBlocks as $b) $out .= $b['raw']."\n";
    return file_put_contents($CUSTOM, $out) !== false;
}

if ($action === 'list') {
    $exts = read_exts($CUSTOM);
    $status = ast("$ASTERISK -rx pjsip show endpoints");
    $reg = [];
    foreach ($exts as $e) {
        $reg[$e['ext']] = (strpos($status, 'Endpoint:  '.$e['ext']) !== false &&
                           preg_match('/Endpoint:\s+'.$e['ext'].'\s+(Not in use|Available|In use|Ringing|Busy)/', $status)) ? 'registered' : 'offline';
    }
    respond(['ok'=>true, 'extensions'=>$exts, 'status'=>$reg]);
}

if ($action === 'add') {
    $ext = preg_replace('/\D/', '', $body['ext'] ?? '');
    $pw  = $body['password'] ?? '';
    if (!preg_match('/^\d{3,6}$/', $ext)) respond(['error'=>'Extension must be 3-6 digits']);
    if (strlen($pw) < 6) respond(['error'=>'Password must be at least 6 characters']);
    if (!preg_match('/^[A-Za-z0-9!@#$%^&*_\-]+$/', $pw)) respond(['error'=>'Password has invalid characters']);
    $exts = read_exts($CUSTOM);
    foreach ($exts as $e) if ($e['ext'] === $ext) respond(['error'=>"Extension $ext already exists"]);
    $exts[] = ['ext'=>$ext, 'password'=>$pw];
    if (!write_custom($exts, $CUSTOM, $BACKUP_DIR)) respond(['error'=>'Write failed']);
    ast("$ASTERISK -rx pjsip reload");
    respond(['ok'=>true, 'ext'=>$ext]);
}

if ($action === 'delete') {
    $ext = preg_replace('/\D/', '', $body['ext'] ?? '');
    if (!preg_match('/^\d{3,6}$/', $ext)) respond(['error'=>'Invalid extension']);
    if ($ext === '1001') respond(['error'=>'1001 is the primary admin extension — remove via PuTTY if you really mean to']);
    $exts = array_values(array_filter(read_exts($CUSTOM), function($e) use ($ext){ return $e['ext'] !== $ext; }));
    if (!write_custom($exts, $CUSTOM, $BACKUP_DIR)) respond(['error'=>'Write failed']);
    ast("$ASTERISK -rx pjsip reload");
    respond(['ok'=>true, 'deleted'=>$ext]);
}

/* ═══════════════════ TRUNKS ═══════════════════ */
/* types: generic-reg (username/password registration), generic-ip (IP auth; Centrex uses this), twilio (trusted-peer) */

$TWILIO_IPS = ['54.172.60.0/30','54.244.51.0/30','54.171.127.192/30','35.156.191.128/30'];

function build_trunk_block($t){
    $n = $t['name']; $lines = [];
    $lines[] = ";===TRUNK:$n:{$t['type']}:{$t['meta']}===";
    $lines[] = "[$n]";
    $lines[] = "type=endpoint";
    $lines[] = "transport=transport-udp";
    $lines[] = "context=from-trunk";
    $lines[] = "disallow=all";
    $lines[] = "allow=ulaw";
    $lines[] = "allow=alaw";
    $lines[] = "direct_media=no";
    $lines[] = "rtp_symmetric=yes";
    $lines[] = "force_rport=yes";
    $lines[] = "aors=$n-aor";
    if ($t['type'] === 'generic-reg') {
        $lines[] = "outbound_auth=$n-auth";
        $lines[] = "from_user={$t['user']}";
        $lines[] = "";
        $lines[] = "[$n-auth]";
        $lines[] = "type=auth";
        $lines[] = "auth_type=userpass";
        $lines[] = "username={$t['user']}";
        $lines[] = "password={$t['pass']}";
    }
    $lines[] = "";
    $lines[] = "[$n-aor]";
    $lines[] = "type=aor";
    $lines[] = "contact=sip:{$t['host']}";
    $lines[] = "qualify_frequency=60";
    if ($t['type'] === 'generic-reg') {
        $lines[] = "";
        $lines[] = "[$n-reg]";
        $lines[] = "type=registration";
        $lines[] = "transport=transport-udp";
        $lines[] = "outbound_auth=$n-auth";
        $lines[] = "server_uri=sip:{$t['host']}";
        $lines[] = "client_uri=sip:{$t['user']}@{$t['host']}";
        $lines[] = "retry_interval=60";
    }
    if (!empty($t['match'])) {
        $lines[] = "";
        $lines[] = "[$n-identify]";
        $lines[] = "type=identify";
        $lines[] = "endpoint=$n";
        foreach ($t['match'] as $ip) $lines[] = "match=$ip";
    }
    $lines[] = ";===ENDTRUNK:$n===";
    return implode("\n", $lines)."\n";
}

if ($action === 'trunk_list') {
    $blocks = read_trunk_blocks($CUSTOM);
    $regs = ast("$ASTERISK -rx pjsip show registrations");
    $out = [];
    foreach ($blocks as $b) {
        if ($b['type'] === 'generic-reg') {
            $live = preg_match('/'.preg_quote($b['name'],'/').'-reg\b[^\r\n]*Registered/i', $regs) ? 'registered' : 'not-registered';
        } else {
            $live = 'not-required';
        }
        $out[] = ['name'=>$b['name'], 'type'=>$b['type'], 'meta'=>$b['meta'], 'live'=>$live];
    }
    respond(['ok'=>true, 'trunks'=>$out]);
}

if ($action === 'trunk_add') {
    $type = $body['type'] ?? '';
    $name = strtolower(trim($body['name'] ?? ''));
    if (!preg_match('/^[a-z0-9][a-z0-9_\-]{1,29}$/', $name)) respond(['error'=>'Trunk name: 2-30 chars, lowercase letters/digits/dash/underscore']);
    if (preg_match('/^\d+$/', $name)) respond(['error'=>'Trunk name cannot be all digits (conflicts with extensions)']);
    foreach (read_trunk_blocks($CUSTOM) as $b) if ($b['name'] === $name) respond(['error'=>"Trunk $name already exists"]);

    $host = trim($body['host'] ?? '');
    if (!preg_match('/^[A-Za-z0-9\.\-]+(:\d{2,5})?$/', $host)) respond(['error'=>'Host must be a hostname or IP (optional :port)']);

    $t = ['name'=>$name, 'host'=>$host, 'match'=>[], 'user'=>'', 'pass'=>'', 'meta'=>$host];

    if ($type === 'twilio') {
        $t['type'] = 'twilio';
        global $TWILIO_IPS;
        $t['match'] = $TWILIO_IPS;
        $extra = trim($body['match'] ?? '');
    } elseif ($type === 'generic-ip' || $type === 'centrex') {
        $t['type'] = 'generic-ip';
        $t['meta'] = ($type==='centrex' ? 'centrex:' : '').$host;
        $extra = trim($body['match'] ?? '');
        if ($extra === '') $extra = preg_replace('/:\d+$/', '', $host); // default: match the host itself
    } elseif ($type === 'generic-reg') {
        $t['type'] = 'generic-reg';
        $t['user'] = trim($body['user'] ?? '');
        $t['pass'] = trim($body['pass'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_\-\.\+@]{2,60}$/', $t['user'])) respond(['error'=>'Username required (2-60 chars)']);
        if (strlen($t['pass']) < 4 || !preg_match('/^[^\r\n\[\];]+$/', $t['pass'])) respond(['error'=>'Password required (no newlines, [, ], ;)']);
        $extra = trim($body['match'] ?? '');
        if ($extra === '') $extra = preg_replace('/:\d+$/', '', $host);
    } else respond(['error'=>'Unknown trunk type']);

    if ($extra !== '') {
        foreach (preg_split('/[,\s]+/', $extra) as $ip) {
            if ($ip === '') continue;
            if (!preg_match('/^[A-Za-z0-9\.\-]+(\/\d{1,2})?$/', $ip)) respond(['error'=>"Bad match entry: $ip"]);
            $t['match'][] = $ip;
        }
    }

    $blocks = read_trunk_blocks($CUSTOM);
    $blocks[] = ['name'=>$name, 'type'=>$t['type'], 'meta'=>$t['meta'], 'raw'=>build_trunk_block($t)];
    if (!write_custom(read_exts($CUSTOM), $CUSTOM, $BACKUP_DIR, $blocks)) respond(['error'=>'Write failed']);
    ast("$ASTERISK -rx pjsip reload");
    respond(['ok'=>true, 'trunk'=>$name]);
}

if ($action === 'trunk_delete') {
    $name = strtolower(trim($body['name'] ?? ''));
    if (!preg_match('/^[a-z0-9][a-z0-9_\-]{1,29}$/', $name)) respond(['error'=>'Invalid trunk name']);
    $blocks = array_values(array_filter(read_trunk_blocks($CUSTOM), function($b) use ($name){ return $b['name'] !== $name; }));
    if (!write_custom(read_exts($CUSTOM), $CUSTOM, $BACKUP_DIR, $blocks)) respond(['error'=>'Write failed']);
    ast("$ASTERISK -rx pjsip reload");
    respond(['ok'=>true, 'deleted'=>$name]);
}

/* ═══════════════════ ROUTES (extensions_custom.conf managed region) ═══════════════════ */
/* Inbound: DID (E.164, digits, or 'catchall') -> extension.  Outbound: pattern -> trunk (+strip/prepend). */

function read_routes($DIALCUSTOM){
    $in=[]; $out=[];
    if (is_readable($DIALCUSTOM)){
        $txt = file_get_contents($DIALCUSTOM);
        if (preg_match_all('/^;ROUTE:in:(\d+):([^:\r\n]+):(\d{3,6})$/m', $txt, $m, PREG_SET_ORDER))
            foreach ($m as $r) $in[] = ['id'=>(int)$r[1], 'did'=>$r[2], 'ext'=>$r[3]];
        if (preg_match_all('/^;ROUTE:out:(\d+):([^:\r\n]+):([a-z0-9_\-]+):(\d{0,2}):([0-9+]*)$/m', $txt, $m, PREG_SET_ORDER))
            foreach ($m as $r) $out[] = ['id'=>(int)$r[1], 'pattern'=>$r[2], 'trunk'=>$r[3], 'strip'=>(int)$r[4], 'prepend'=>$r[5]];
    }
    return ['in'=>$in, 'out'=>$out];
}

function write_routes($routes, $DIALCUSTOM, $BACKUP_DIR){
    backup($DIALCUSTOM, $BACKUP_DIR);
    $txt = is_readable($DIALCUSTOM) ? file_get_contents($DIALCUSTOM) : '';
    // strip old managed region
    $txt = preg_replace('/;===PBXTAB ROUTES BEGIN===.*?;===PBXTAB ROUTES END===\R?/s', '', $txt);
    $txt = rtrim($txt);

    $o  = "\n;===PBXTAB ROUTES BEGIN===\n";
    $o .= "; managed by the PBX tab — do not hand-edit inside this region\n\n";

    $o .= "[from-trunk]\n";
    $o .= "exten => _[+0-9].,1,NoOp(Inbound call to \${EXTEN})\n";
    $o .= " same => n,Goto(inbound-dids,\${EXTEN},1)\n";
    $o .= "exten => s,1,Goto(inbound-dids,catchall,1)\n\n";

    $o .= "[inbound-dids]\n";
    $hasCatch = false;
    foreach ($routes['in'] as $r) {
        $o .= ";ROUTE:in:{$r['id']}:{$r['did']}:{$r['ext']}\n";
        if ($r['did'] === 'catchall') { $hasCatch = true;
            $o .= "exten => catchall,1,Dial(PJSIP/{$r['ext']},30)\n same => n,Hangup()\n";
        } else {
            $o .= "exten => {$r['did']},1,Dial(PJSIP/{$r['ext']},30)\n same => n,Hangup()\n";
        }
    }
    if (!$hasCatch) $o .= "exten => catchall,1,Hangup()\n";
    $o .= "exten => i,1,Hangup()\n\n";

    $o .= "[internal]\n";
    foreach ($routes['out'] as $r) {
        $o .= ";ROUTE:out:{$r['id']}:{$r['pattern']}:{$r['trunk']}:{$r['strip']}:{$r['prepend']}\n";
        $num = '${EXTEN'.($r['strip'] > 0 ? ':'.$r['strip'] : '').'}';
        $o .= "exten => {$r['pattern']},1,Dial(PJSIP/{$r['prepend']}{$num}@{$r['trunk']},60)\n same => n,Hangup()\n";
    }
    $o .= ";===PBXTAB ROUTES END===\n";
    return file_put_contents($DIALCUSTOM, $txt."\n".$o) !== false;
}

if ($action === 'route_list') {
    respond(['ok'=>true] + read_routes($DIALCUSTOM));
}

if ($action === 'route_add') {
    $kind = $body['kind'] ?? '';
    $routes = read_routes($DIALCUSTOM);
    $nextId = 1;
    foreach (array_merge($routes['in'], $routes['out']) as $r) if ($r['id'] >= $nextId) $nextId = $r['id']+1;

    if ($kind === 'in') {
        $did = trim($body['did'] ?? '');
        $ext = preg_replace('/\D/', '', $body['ext'] ?? '');
        if ($did !== 'catchall' && !preg_match('/^\+?\d{3,16}$/', $did)) respond(['error'=>'DID must be digits (optional +) or "catchall"']);
        if (!preg_match('/^\d{3,6}$/', $ext)) respond(['error'=>'Destination extension must be 3-6 digits']);
        foreach ($routes['in'] as $r) if ($r['did'] === $did) respond(['error'=>"A route for $did already exists"]);
        $routes['in'][] = ['id'=>$nextId, 'did'=>$did, 'ext'=>$ext];
    } elseif ($kind === 'out') {
        $pat   = trim($body['pattern'] ?? '');
        $trunk = strtolower(trim($body['trunk'] ?? ''));
        $strip = (int)($body['strip'] ?? 0);
        $prep  = trim($body['prepend'] ?? '');
        if (!preg_match('/^_?[+0-9NXZ\.\!\[\]\-]{1,24}$/', $pat)) respond(['error'=>'Bad dial pattern (use Asterisk pattern syntax, e.g. _1NXXNXXXXXX)']);
        if (!preg_match('/^[a-z0-9][a-z0-9_\-]{1,29}$/', $trunk)) respond(['error'=>'Pick a trunk']);
        $exists = false; foreach (read_trunk_blocks($CUSTOM) as $b) if ($b['name'] === $trunk) $exists = true;
        if (!$exists) respond(['error'=>"Trunk $trunk does not exist"]);
        if ($strip < 0 || $strip > 15) respond(['error'=>'Strip must be 0-15']);
        if ($prep !== '' && !preg_match('/^[0-9+]{1,8}$/', $prep)) respond(['error'=>'Prepend must be digits (optional +)']);
        $routes['out'][] = ['id'=>$nextId, 'pattern'=>$pat, 'trunk'=>$trunk, 'strip'=>$strip, 'prepend'=>$prep];
    } else respond(['error'=>'kind must be in|out']);

    if (!write_routes($routes, $DIALCUSTOM, $BACKUP_DIR)) respond(['error'=>'Write failed']);
    ast("$ASTERISK -rx dialplan reload");
    respond(['ok'=>true, 'id'=>$nextId]);
}

if ($action === 'route_delete') {
    $kind = $body['kind'] ?? '';
    $id   = (int)($body['id'] ?? 0);
    if (!in_array($kind, ['in','out'], true) || !$id) respond(['error'=>'kind + id required']);
    $routes = read_routes($DIALCUSTOM);
    $routes[$kind] = array_values(array_filter($routes[$kind], function($r) use ($id){ return $r['id'] !== $id; }));
    if (!write_routes($routes, $DIALCUSTOM, $BACKUP_DIR)) respond(['error'=>'Write failed']);
    ast("$ASTERISK -rx dialplan reload");
    respond(['ok'=>true]);
}

respond(['error'=>'Unknown action']);
