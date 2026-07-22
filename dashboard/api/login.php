<?php require __DIR__.'/_auth.php';
$b = json_decode(file_get_contents('php://input'), true) ?: [];
$u = trim($b['username'] ?? ''); $p = (string)($b['password'] ?? '');
foreach (luxe_users() as $row) {
  if (($row['username'] ?? '') === $u && $u !== '' && password_verify($p, $row['hash'] ?? '')) {
    session_regenerate_id(true);
    $_SESSION['luxe_user'] = $u;
    $_SESSION['luxe_role'] = $row['role'] ?? 'performer';
    jout(['ok'=>true, 'user'=>$u, 'role'=>$_SESSION['luxe_role']]);
  }
}
http_response_code(401); jout(['ok'=>false, 'error'=>'invalid credentials']);
