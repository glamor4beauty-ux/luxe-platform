<?php
// token.php — issue a short-lived publish token (GET, needs a valid session)
//            or validate one (internal, for nginx auth_request: ?validate=TOKEN)
require __DIR__.'/_common.php';

$dir = '/opt/owncast/private/tokens';
if(!is_dir($dir)) @mkdir($dir, 0750, true);

// --- validation mode (called internally by nginx auth_request) ---
if(isset($_GET['validate'])){
  $tok = preg_replace('/[^a-f0-9]/','', $_GET['validate']);
  $f = "$dir/$tok";
  if($tok && is_file($f) && (time() - filemtime($f)) < 3600){
    http_response_code(200); echo 'ok'; exit;
  }
  http_response_code(401); exit;
}

// --- issue mode (called by the Go Live page; requires login) ---
require_login();   // 401 if no valid session
// clean up old tokens (older than 1h)
foreach(glob("$dir/*") as $old){ if(time()-filemtime($old) > 3600) @unlink($old); }
$tok = bin2hex(random_bytes(20));
file_put_contents("$dir/$tok", ($_SESSION['user'] ?? ''));
@chmod("$dir/$tok", 0640);
jout(['ok'=>true, 'token'=>$tok]);
