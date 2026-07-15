<?php
require __DIR__."/../cors.php";
require __DIR__.'/../config.php';

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'list':
            $filter = $_GET['filter'] ?? '';
            $search = trim($_GET['search'] ?? '');
            $sql = "SELECT * FROM leads";
            $where = []; $params = [];
            if ($filter && in_array($filter, ['open','no_response','closed'])) {
                $where[] = "results=?"; $params[] = $filter;
            }
            if ($search) {
                $where[] = "(full_name LIKE ? OR stage_name LIKE ? OR email LIKE ? OR instagram LIKE ?)";
                $like = "%$search%";
                $params = array_merge($params, [$like,$like,$like,$like]);
            }
            if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
            $sql .= ' ORDER BY created_at DESC';
            $s = db()->prepare($sql);
            $s->execute($params);
            json_response($s->fetchAll());
            break;

        case 'add':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'], 405);
            $data = json_decode(file_get_contents('php://input'), true);
            $name = trim($data['full_name'] ?? '');
            if (!$name) json_response(['error'=>'Full name required'], 400);
            $ins = db()->prepare('INSERT INTO leads (stage_name,full_name,email,phone,instagram,country,recruiter,notes,results) VALUES (?,?,?,?,?,?,?,?,?)');
            $ins->execute([
                trim($data['stage_name'] ?? '') ?: null,
                $name,
                trim($data['email'] ?? '') ?: null,
                trim($data['phone'] ?? '') ?: null,
                trim($data['instagram'] ?? '') ?: null,
                trim($data['country'] ?? '') ?: null,
                trim($data['recruiter'] ?? 'Clarence'),
                trim($data['notes'] ?? '') ?: null,
                'open',
            ]);
            json_response(['success'=>true, 'id'=>(int)db()->lastInsertId()]);
            break;

        case 'update':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'], 405);
            $data = json_decode(file_get_contents('php://input'), true);
            $id = (int)($data['id'] ?? 0);
            if (!$id) json_response(['error'=>'id required'], 400);
            $fields = ['stage_name','full_name','email','phone','instagram','country','recruiter','notes','results'];
            $sets = []; $vals = [];
            foreach ($fields as $f) {
                if (array_key_exists($f, $data)) { $sets[] = "$f=?"; $vals[] = ($data[$f] === '' ? null : $data[$f]); }
            }
            if (!$sets) json_response(['error'=>'Nothing to update'], 400);
            $vals[] = $id;
            db()->prepare('UPDATE leads SET '.implode(',',$sets).' WHERE id=?')->execute($vals);
            json_response(['success'=>true]);
            break;

        case 'delete_selected':
            $json = json_decode(file_get_contents('php://input'), true);
            $ids = $json['ids'] ?? [];
            if(empty($ids)) throw new RuntimeException('No IDs provided');
            $pdo = getPDO();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM leads WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            json_response(['success'=>true,'deleted'=>$stmt->rowCount()]);
            break;
        case 'delete_all':
            $pdo = getPDO();
            $pdo->exec("DELETE FROM leads");
            json_response(['success'=>true]);
            break;
        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'], 405);
            $data = json_decode(file_get_contents('php://input'), true);
            $id = (int)($data['id'] ?? 0);
            if (!$id) json_response(['error'=>'id required'], 400);
            $s = db()->prepare('DELETE FROM leads WHERE id=?');
            $s->execute([$id]);
            json_response(['success'=>true, 'deleted'=>$s->rowCount()]);
            break;

        case 'mass_delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'], 405);
            $data = json_decode(file_get_contents('php://input'), true);
            $ids = $data['ids'] ?? [];
            if (!$ids || !is_array($ids)) json_response(['error'=>'ids array required'], 400);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $s = db()->prepare("DELETE FROM leads WHERE id IN ($placeholders)");
            $s->execute($ids);
            json_response(['success'=>true, 'deleted'=>$s->rowCount()]);
            break;

        default:
            json_response(['error'=>'Unknown action. Use: list, add, update, delete, mass_delete'], 400);
    }
} catch (Throwable $e) {
    error_log('[luxe leads] ' . $e->getMessage());
    json_response(['error'=>$e->getMessage()], 500);
}
