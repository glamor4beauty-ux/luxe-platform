<?php
require __DIR__."/../cors.php";
require __DIR__.'/../config.php';

define('FS_UPLOAD_DIR', __DIR__.'/../uploads/filestorage');
if (!is_dir(FS_UPLOAD_DIR)) mkdir(FS_UPLOAD_DIR, 0750, true);

const FS_ALLOWED = [
    'application/pdf'=>'pdf','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx',
    'application/msword'=>'doc','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif',
    'text/plain'=>'txt','text/csv'=>'csv',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation'=>'pptx',
    'application/zip'=>'zip','video/mp4'=>'mp4','audio/mpeg'=>'mp3',
];

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'list':
            $cat = trim($_GET['category'] ?? '');
            $search = trim($_GET['search'] ?? '');
            $sql = 'SELECT id,category,subcategory,title,file_type,file_path,file_url,file_size,uploaded_by,created_at FROM file_storage';
            $where = []; $params = [];
            if ($cat) { $where[] = 'category=?'; $params[] = $cat; }
            if ($search) { $where[] = '(title LIKE ? OR category LIKE ?)'; $like="%$search%"; $params[]=$like; $params[]=$like; }
            if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
            $sql .= ' ORDER BY category, title';
            $s = db()->prepare($sql); $s->execute($params);
            json_response($s->fetchAll());
            break;

        case 'categories':
            $rows = db()->query('SELECT category, COUNT(*) as cnt FROM file_storage GROUP BY category ORDER BY category')->fetchAll();
            json_response($rows);
            break;

        case 'upload':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'], 405);
            $title = trim($_POST['title'] ?? '');
            $category = trim($_POST['category'] ?? 'General');
            $subcategory = trim($_POST['subcategory'] ?? '') ?: null;
            $uploadedBy = trim($_POST['uploaded_by'] ?? '') ?: null;
            if (!$title) json_response(['error'=>'Title required'], 400);
            if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) json_response(['error'=>'File required'], 400);
            $file = $_FILES['file'];
            if ($file['size'] > 100 * 1024 * 1024) json_response(['error'=>'Max 100MB'], 400);
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            if (!isset(FS_ALLOWED[$mime])) json_response(['error'=>'File type not allowed: '.$mime], 400);
            $ext = FS_ALLOWED[$mime];
            $slug = substr(preg_replace('/[^a-z0-9]/','',strtolower($title)),0,30)?:'file';
            $filename = $slug.'_'.bin2hex(random_bytes(4)).'.'.$ext;
            $dest = FS_UPLOAD_DIR.'/'.$filename;
            if (!move_uploaded_file($file['tmp_name'], $dest)) json_response(['error'=>'Upload failed'], 500);
            chmod($dest, 0644);
            $relPath = 'uploads/filestorage/'.$filename;
            $fileType = in_array($ext,['jpg','png','webp','gif'])?'image':$ext;
            $ins = db()->prepare('INSERT INTO file_storage (category,subcategory,title,file_type,file_path,file_size,uploaded_by) VALUES (?,?,?,?,?,?,?)');
            $ins->execute([$category,$subcategory,$title,$fileType,$relPath,$file['size'],$uploadedBy]);
            json_response(['success'=>true,'id'=>(int)db()->lastInsertId(),'title'=>$title]);
            break;

        case 'add_link':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'], 405);
            $data = json_decode(file_get_contents('php://input'), true);
            $title = trim($data['title'] ?? '');
            $url = trim($data['url'] ?? '');
            $category = trim($data['category'] ?? 'General');
            $subcategory = trim($data['subcategory'] ?? '') ?: null;
            if (!$title || !$url) json_response(['error'=>'Title and URL required'], 400);
            if (!filter_var($url, FILTER_VALIDATE_URL)) json_response(['error'=>'Invalid URL'], 400);
            $ins = db()->prepare('INSERT INTO file_storage (category,subcategory,title,file_type,file_url) VALUES (?,?,?,?,?)');
            $ins->execute([$category,$subcategory,$title,'link',$url]);
            json_response(['success'=>true,'id'=>(int)db()->lastInsertId()]);
            break;

        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'], 405);
            $data = json_decode(file_get_contents('php://input'), true);
            $id = (int)($data['id'] ?? 0);
            if (!$id) json_response(['error'=>'id required'], 400);
            $s = db()->prepare('SELECT file_path FROM file_storage WHERE id=?'); $s->execute([$id]); $row=$s->fetch();
            if ($row && $row['file_path']) { $full=__DIR__.'/../'.$row['file_path']; if(file_exists($full)) unlink($full); }
            $d = db()->prepare('DELETE FROM file_storage WHERE id=?'); $d->execute([$id]);
            json_response(['success'=>true,'deleted'=>$d->rowCount()]);
            break;

        case 'serve':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { http_response_code(400); exit; }
            $s = db()->prepare('SELECT file_type,file_path,file_url,title FROM file_storage WHERE id=?'); $s->execute([$id]); $row=$s->fetch();
            if (!$row) { http_response_code(404); exit; }
            if ($row['file_type'] === 'link') { header('Location: '.$row['file_url']); exit; }
            $full = __DIR__.'/../'.$row['file_path'];
            if (!file_exists($full)) { http_response_code(404); echo 'File not found'; exit; }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($full);
            header('Content-Type: '.$mime);
            header('Content-Disposition: inline; filename="'.basename($row['file_path']).'"');
            header('Content-Length: '.filesize($full));
            readfile($full); exit;

        default:
            json_response(['error'=>'Unknown action. Use: list, categories, upload, add_link, delete, serve'], 400);
    }
} catch (Throwable $e) {
    error_log('[luxe filestorage] ' . $e->getMessage());
    json_response(['error'=>$e->getMessage()], 500);
}
