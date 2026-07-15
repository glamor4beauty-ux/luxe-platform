<?php
require __DIR__."/../cors.php";
require __DIR__.'/../config.php';

session_start();

$action = $_GET['action'] ?? 'login';

try {
    switch ($action) {
        case 'login':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['success'=>false,'error'=>'POST only'],405);
            $data = json_decode(file_get_contents('php://input'), true);
            $email = strtolower(trim($data['email'] ?? ''));
            $password = $data['password'] ?? '';
            if (!$email || !$password) json_response(['success'=>false,'error'=>'Email and password required'],400);

            $s = db()->prepare('SELECT email,password_hash,role,first_name,last_name,stage_name,status FROM registration WHERE email=?');
            $s->execute([$email]);
            $user = $s->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                json_response(['success'=>false,'error'=>'Invalid email or password'],401);
            }

            if ($user['status'] === 'banned') {
                json_response(['success'=>false,'error'=>'Account suspended'],403);
            }

            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = trim($user['first_name'].' '.$user['last_name']);

            json_response([
                'success'   => true,
                'email'     => $user['email'],
                'role'      => $user['role'],
                'name'      => trim($user['first_name'].' '.$user['last_name']),
                'stageName' => $user['stage_name'],
                'status'    => $user['status'],
            ]);
            break;

        case 'check':
            if (!empty($_SESSION['user_email'])) {
                json_response([
                    'loggedIn' => true,
                    'email'    => $_SESSION['user_email'],
                    'role'     => $_SESSION['user_role'],
                    'name'     => $_SESSION['user_name'],
                ]);
            } else {
                json_response(['loggedIn'=>false]);
            }
            break;

        case 'logout':
            session_destroy();
            json_response(['success'=>true]);
            break;

        default:
            json_response(['error'=>'Unknown action. Use: login, check, logout'],400);
    }
} catch (Throwable $e) {
    error_log('[luxe login] '.$e->getMessage());
    json_response(['success'=>false,'error'=>$e->getMessage()],500);
}
