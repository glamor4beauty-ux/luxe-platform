<?php
/* api/pbx.php — safe PBX management for the PBX tab.
   Writes ONLY to pjsip_custom.conf (extensions include); core pjsip.conf is untouchable.
   Backs up before every write; validates all input; reload runs via restricted sudoers. */

session_start();
header('Content-Type: application/json');

/* --- auth: admin session required (same gate as chat) --- */
$email = $_SESSION['admin_email'] ?? $_SESSION['user_email'] ?? '';
if ($email === '') { http_response_code(401); echo json_encode(['error'=>'Not signed in']); exit; }

$CUSTOM = '/etc/asterisk/pjsip_custom.conf';
$BACKUP_DIR = '/etc/asterisk/pbx_backups';
$ASTERISK = '/usr/sbin/asterisk';

function respond($a){ echo json_encode($a); exit; }
function ast($cmd){ /* only whitelisted commands (match sudoers) */ return shell_exec('sudo '.escapeshellcmd($cmd).' 2>&1'); }

$action = $_GET['action'] ?? '';

/* ---------- READ: list extensions from the custom file ---------- */
if ($action === 'list') {
    $exts = [];
    if (is_readable($CUSTOM)) {
        $txt = file_get_contents($CUSTOM);
        // find endpoint sections like [1001](webrtc-endpoint)
        if (preg_match_all('/\[(\d{3,6})\]\(webrtc-endpoint\)/', $txt, $m)) {
            foreach ($m[1] as $ext) {
                // pull the password from its auth block
                $pw = '';
                if (preg_match('/\[auth'.$ext.'\].*?password=([^\r\n]+)/s', $txt, $pm)) $pw = trim($pm[1]);
                $exts[] = ['ext'=>$ext, 'password'=>$pw];
            }
        }
    }
    // live status from asterisk
    $status = ast("$ASTERISK -rx pjsip show endpoints");
    $reg = [];
    foreach ($exts as $e) {
        $reg[$e['ext']] = (strpos($status, 'Endpoint:  '.$e['ext']) !== false &&
                           preg_match('/Endpoint:\s+'.$e['ext'].'\s+(Not in use|Available|In use|Ringing|Busy)/', $status)) ? 'registered' : 'offline';
    }
    respond(['ok'=>true, 'extensions'=>$exts, 'status'=>$reg]);
}

/* ---------- helper: rebuild the whole custom file from an extension list ---------- */
function write_custom($exts, $CUSTOM, $BACKUP_DIR){
    // backup first
    if (is_file($CUSTOM)) @copy($CUSTOM, $BACKUP_DIR.'/pjsip_custom.'.date('Ymd-His').'.bak');
    $out = ";=== Browser-editable extensions (managed by PBX tab) ===\n";
    $out .= ";=== Core transport/WSS stays in pjsip.conf — NOT editable here ===\n\n";
    foreach ($exts as $e) {
        $x = $e['ext']; $pw = $e['password'];
        $out .= "[$x](webrtc-endpoint)\nauth=auth$x\naors=$x\n\n";
        $out .= "[auth$x]\ntype=auth\nauth_type=userpass\nusername=$x\npassword=$pw\n\n";
        $out .= "[$x]\ntype=aor\nmax_contacts=5\nremove_existing=yes\n\n";
    }
    return file_put_contents($CUSTOM, $out) !== false;
}

/* read current extensions helper */
function read_exts($CUSTOM){
    $exts=[]; if(is_readable($CUSTOM)){ $txt=file_get_contents($CUSTOM);
      if(preg_match_all('/\[(\d{3,6})\]\(webrtc-endpoint\)/',$txt,$m)){ foreach($m[1] as $x){ $pw='';
        if(preg_match('/\[auth'.$x.'\].*?password=([^\r\n]+)/s',$txt,$pm)) $pw=trim($pm[1]);
        $exts[]=['ext'=>$x,'password'=>$pw]; } } }
    return $exts;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];

/* ---------- ADD extension ---------- */
if ($action === 'add') {
    $ext = preg_replace('/\D/', '', $body['ext'] ?? '');
    $pw  = $body['password'] ?? '';
    // validate
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

/* ---------- DELETE extension ---------- */
if ($action === 'delete') {
    $ext = preg_replace('/\D/', '', $body['ext'] ?? '');
    if (!preg_match('/^\d{3,6}$/', $ext)) respond(['error'=>'Invalid extension']);
    if ($ext === '1001') respond(['error'=>'1001 is the primary admin extension — remove via PuTTY if you really mean to']);
    $exts = array_values(array_filter(read_exts($CUSTOM), function($e) use ($ext){ return $e['ext'] !== $ext; }));
    if (!write_custom($exts, $CUSTOM, $BACKUP_DIR)) respond(['error'=>'Write failed']);
    ast("$ASTERISK -rx pjsip reload");
    respond(['ok'=>true, 'deleted'=>$ext]);
}

respond(['error'=>'Unknown action']);
