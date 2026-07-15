<?php
require __DIR__."/../cors.php";
require __DIR__.'/../config.php';

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'list':
            $filter = $_GET['filter'] ?? '';
            $sql = "SELECT t.*, r.first_name, r.last_name, r.stage_name
                    FROM tasks t
                    JOIN registration r ON t.assigned_email = r.email";
            $params = [];
            if ($filter === 'daily' || $filter === 'ongoing' || $filter === 'urgent') {
                $sql .= " WHERE t.priority = ?";
                $params[] = $filter;
            }
            $sql .= " ORDER BY FIELD(t.priority,'urgent','ongoing','daily'), t.task_date DESC";
            $s = db()->prepare($sql);
            $s->execute($params);
            json_response($s->fetchAll());
            break;

        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) json_response(['error'=>'id required'], 400);
            $s = db()->prepare("SELECT t.*, r.first_name, r.last_name, r.stage_name, r.phone, r.email
                FROM tasks t JOIN registration r ON t.assigned_email = r.email WHERE t.id = ?");
            $s->execute([$id]);
            $row = $s->fetch();
            if (!$row) json_response(['error'=>'Not found'], 404);
            json_response($row);
            break;

        case 'add':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'], 405);
            $data = json_decode(file_get_contents('php://input'), true);
            $email = trim($data['assigned_email'] ?? '');
            $priority = $data['priority'] ?? 'daily';
            $taskDate = $data['task_date'] ?? date('Y-m-d');
            $completeDate = $data['complete_date'] ?? null;
            $result = $data['result'] ?? 'pending';
            $desc = trim($data['description'] ?? '');
            $createdBy = $data['created_by'] ?? null;

            if (!$email) json_response(['error'=>'Performer email required'], 400);
            if (!$desc) json_response(['error'=>'Description required'], 400);
            if (!in_array($priority, ['daily','ongoing','urgent'])) json_response(['error'=>'Priority must be daily, ongoing, or urgent'], 400);

            // Verify performer exists
            $chk = db()->prepare('SELECT email FROM registration WHERE email = ?');
            $chk->execute([$email]);
            if (!$chk->fetch()) json_response(['error'=>'Performer not found'], 404);

            $ins = db()->prepare('INSERT INTO tasks (assigned_email, priority, task_date, complete_date, result, description, created_by) VALUES (?,?,?,?,?,?,?)');
            $ins->execute([$email, $priority, $taskDate, $completeDate ?: null, $result, $desc, $createdBy]);
            json_response(['success'=>true, 'id'=>(int)db()->lastInsertId()]);
            break;

        case 'update':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'], 405);
            $data = json_decode(file_get_contents('php://input'), true);
            $id = (int)($data['id'] ?? 0);
            if (!$id) json_response(['error'=>'id required'], 400);

            $fields = ['priority','task_date','complete_date','result','description','assigned_email'];
            $sets = []; $vals = [];
            foreach ($fields as $f) {
                if (array_key_exists($f, $data)) {
                    $sets[] = "$f=?";
                    $vals[] = ($data[$f] === '' ? null : $data[$f]);
                }
            }
            if (!$sets) json_response(['error'=>'Nothing to update'], 400);
            $vals[] = $id;
            db()->prepare('UPDATE tasks SET ' . implode(',', $sets) . ' WHERE id=?')->execute($vals);
            json_response(['success'=>true]);
            break;

        case 'complete':
            if($_SERVER['REQUEST_METHOD']!=='POST') json_response(['error'=>'POST only'],405);
            $d=json_decode(file_get_contents('php://input'),true);
            $id=(int)($d['id']??0);
            if(!$id) json_response(['error'=>'ID required'],400);
            $s=db()->prepare('UPDATE tasks SET completed=?,complete_date=?,result=?,notes=?,updated_at=NOW() WHERE id=?');
            $s->execute([$d['completed']??0,$d['complete_date']??null,$d['result']??null,$d['notes']??null,$id]);
            json_response(['success'=>true]);
            break;
        case 'approve':
            if($_SERVER['REQUEST_METHOD']!=='POST') json_response(['error'=>'POST only'],405);
            $d=json_decode(file_get_contents('php://input'),true);
            $id=(int)($d['id']??0);
            $status=$d['approval_status']??'approved';
            if(!$id) json_response(['error'=>'ID required'],400);
            $s=db()->prepare('UPDATE tasks SET approval_status=?,completed=1,complete_date=CURDATE(),updated_at=NOW() WHERE id=?');
            $s->execute([$status,$id]);
            // If approved and it's a photo approval, apply the change
            $task=db()->prepare('SELECT * FROM tasks WHERE id=?');$task->execute([$id]);$row=$task->fetch(PDO::FETCH_ASSOC);
            if($row && $row['approval_type']==='photo_upload' && $status==='approved' && $row['approval_data']){
                $data=json_decode($row['approval_data'],true);
                if($data && isset($data['photo_path'])&&isset($data['email'])){
                    $up=db()->prepare('UPDATE registration SET additional_photos=CASE WHEN additional_photos IS NULL THEN ? ELSE CONCAT(additional_photos,",",?) END WHERE email=?');
                    $up->execute([$data['photo_path'],$data['photo_path'],$data['email']]);
                }
            }
            json_response(['success'=>true]);
            break;
        case 'pending_approvals':
            $rows=db()->query("SELECT t.*,r.stage_name,r.first_name,r.last_name FROM tasks t LEFT JOIN registration r ON t.assigned_email=r.email WHERE t.approval_status='pending' ORDER BY t.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
            json_response($rows);
            break;
        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'], 405);
            $data = json_decode(file_get_contents('php://input'), true);
            $id = (int)($data['id'] ?? 0);
            if (!$id) json_response(['error'=>'id required'], 400);
            $s = db()->prepare('DELETE FROM tasks WHERE id=?');
            $s->execute([$id]);
            json_response(['success'=>true, 'deleted'=>$s->rowCount()]);
            break;

        case 'performers':
            // Quick list for dropdown
            json_response(db()->query("SELECT email, first_name, last_name, stage_name FROM registration WHERE role='performer' ORDER BY stage_name ASC, last_name ASC")->fetchAll());
            break;

        default:
            json_response(['error'=>'Unknown action. Use: list, get, add, update, delete, performers'], 400);
    }
} catch (Throwable $e) {
    error_log('[luxe tasks] ' . $e->getMessage());
    json_response(['error'=>$e->getMessage()], 500);
}
