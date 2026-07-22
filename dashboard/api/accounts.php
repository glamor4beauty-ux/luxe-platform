<?php require __DIR__.'/_auth.php'; require_admin();
header('Content-Type: application/json; charset=utf-8');
const UF = '/opt/owncast/private/users.json';
function load_u(){ return is_file(UF) ? (json_decode(file_get_contents(UF), true) ?: []) : []; }
function save_u($a){ file_put_contents(UF, json_encode(array_values($a), JSON_PRETTY_PRINT)); }

$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
  $out = array_map(fn($u)=>['username'=>$u['username']??'', 'role'=>$u['role']??'performer'], load_u());
  echo json_encode(['ok'=>true, 'users'=>array_values($out)]); exit;
}

$b = json_decode(file_get_contents('php://input'), true) ?: [];
$u = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($b['username'] ?? ''));

if ($action === 'save') {
  if ($u === '') { echo json_encode(['ok'=>false,'error'=>'username required']); exit; }
  $hasRole = array_key_exists('role', $b);
  $role = (($b['role'] ?? 'performer') === 'admin') ? 'admin' : 'performer';
  $pass = (string)($b['password'] ?? '');
  $users = load_u(); $found = false;
  foreach ($users as &$row) {
    if (($row['username'] ?? '') === $u) {
      if ($hasRole) $row['role'] = $role;
      if ($pass !== '') $row['hash'] = password_hash($pass, PASSWORD_BCRYPT);
      $found = true; break;
    }
  } unset($row);
  if (!$found) {
    if ($pass === '') { echo json_encode(['ok'=>false,'error'=>'password required for new user']); exit; }
    $users[] = ['username'=>$u, 'hash'=>password_hash($pass, PASSWORD_BCRYPT), 'role'=>($hasRole?$role:'performer')];
  }
  save_u($users); echo json_encode(['ok'=>true]); exit;
}

if ($action === 'delete') {
  if ($u === current_user()) { echo json_encode(['ok'=>false,'error'=>'cannot delete yourself']); exit; }
  $users = load_u();
  $admins = array_filter($users, fn($x)=>($x['role']??'')==='admin');
  $tgt = array_values(array_filter($users, fn($x)=>($x['username']??'')===$u));
  $tgtIsAdmin = $tgt && (($tgt[0]['role']??'')==='admin');
  if ($tgtIsAdmin && count($admins) <= 1) { echo json_encode(['ok'=>false,'error'=>'cannot delete the last admin']); exit; }
  $users = array_filter($users, fn($x)=>($x['username']??'')!==$u);
  save_u($users); echo json_encode(['ok'=>true]); exit;
}
echo json_encode(['ok'=>false,'error'=>'unknown action']);
