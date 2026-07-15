<?php
require __DIR__."/../cors.php";
require __DIR__.'/../config.php';

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'inbox':
            $email = $_GET['email'] ?? '';
            if (!$email) json_response(['error'=>'email required'], 400);
            $s = db()->prepare("SELECT m.*, r.first_name, r.last_name, r.stage_name
                FROM messages m
                LEFT JOIN registration r ON m.from_email = r.email
                WHERE m.to_email = ?
                ORDER BY m.created_at DESC");
            $s->execute([$email]);
            json_response($s->fetchAll());
            break;

        case 'sent':
            $email = $_GET['email'] ?? '';
            if (!$email) json_response(['error'=>'email required'], 400);
            $s = db()->prepare("SELECT m.*, r.first_name, r.last_name, r.stage_name
                FROM messages m
                LEFT JOIN registration r ON m.to_email = r.email
                WHERE m.from_email = ?
                ORDER BY m.created_at DESC");
            $s->execute([$email]);
            json_response($s->fetchAll());
            break;

        case 'send':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'], 405);
            $data = json_decode(file_get_contents('php://input'), true);
            $from = trim($data['from_email'] ?? '');
            $to = trim($data['to_email'] ?? '');
            $subject = trim($data['subject'] ?? '');
            $body = trim($data['body'] ?? '');
            if (!$from || !$to) json_response(['error'=>'from_email and to_email required'], 400);
            if (!$body) json_response(['error'=>'Message body required'], 400);
            $ins = db()->prepare('INSERT INTO messages (from_email, to_email, subject, body) VALUES (?,?,?,?)');
            $ins->execute([$from, $to, $subject ?: null, $body]);
            json_response(['success'=>true, 'id'=>(int)db()->lastInsertId()]);
            break;

        case 'mark_read':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'], 405);
            $data = json_decode(file_get_contents('php://input'), true);
            $id = (int)($data['id'] ?? 0);
            if (!$id) json_response(['error'=>'id required'], 400);
            db()->prepare('UPDATE messages SET is_read=1 WHERE id=?')->execute([$id]);
            json_response(['success'=>true]);
            break;

        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'], 405);
            $data = json_decode(file_get_contents('php://input'), true);
            $id = (int)($data['id'] ?? 0);
            if (!$id) json_response(['error'=>'id required'], 400);
            $s = db()->prepare('DELETE FROM messages WHERE id=?');
            $s->execute([$id]);
            json_response(['success'=>true, 'deleted'=>$s->rowCount()]);
            break;

        case 'delete_read':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'], 405);
            $data = json_decode(file_get_contents('php://input'), true);
            $email = trim($data['email'] ?? '');
            if (!$email) json_response(['error'=>'email required'], 400);
            $s = db()->prepare('DELETE FROM messages WHERE to_email=? AND is_read=1');
            $s->execute([$email]);
            json_response(['success'=>true, 'deleted'=>$s->rowCount()]);
            break;

        case 'unread_count':
            $email = $_GET['email'] ?? '';
            if (!$email) json_response(['error'=>'email required'], 400);
            $s = db()->prepare('SELECT COUNT(*) as cnt FROM messages WHERE to_email=? AND is_read=0');
            $s->execute([$email]);
            json_response(['count'=>(int)$s->fetch()['cnt']]);
            break;

        case 'performers':
            json_response(db()->query("SELECT email, first_name, last_name, stage_name FROM registration WHERE role='performer' ORDER BY stage_name ASC, last_name ASC")->fetchAll());
            break;

        default:
            json_response(['error'=>'Unknown action. Use: inbox, sent, send, mark_read, delete, delete_read, unread_count, performers'], 400);
    }
} catch (Throwable $e) {
    error_log('[luxe messages] ' . $e->getMessage());
    json_response(['error'=>$e->getMessage()], 500);
}
