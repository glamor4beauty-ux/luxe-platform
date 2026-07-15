<?php
require __DIR__."/../cors.php";
require __DIR__.'/../config.php';

define('KB_UPLOAD_DIR', __DIR__.'/../uploads/knowledgebase');
if (!is_dir(KB_UPLOAD_DIR)) mkdir(KB_UPLOAD_DIR, 0750, true);

const KB_ALLOWED = [
    'application/pdf' => 'pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/msword' => 'doc',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
    'text/plain' => 'txt',
];

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'list':
            $cat = trim($_GET['category'] ?? '');
            $subcat = trim($_GET['subcategory'] ?? '');
            if ($cat && $subcat) {
                $s = db()->prepare('SELECT id,category,subcategory,title,file_type,file_path,file_url,file_size,created_at FROM knowledgebase WHERE category=? AND subcategory=? ORDER BY title');
                $s->execute([$cat, $subcat]);
            } elseif ($cat) {
                $s = db()->prepare('SELECT id,category,subcategory,title,file_type,file_path,file_url,file_size,created_at FROM knowledgebase WHERE category=? ORDER BY subcategory, title');
                $s->execute([$cat]);
            } else {
                $s = db()->query('SELECT id,category,subcategory,title,file_type,file_path,file_url,file_size,created_at FROM knowledgebase ORDER BY category, subcategory, title');
            }
            json_response($s->fetchAll());
            break;

        case 'categories':
            $rows = db()->query('SELECT category, subcategory, COUNT(*) as cnt FROM knowledgebase GROUP BY category, subcategory ORDER BY category, subcategory')->fetchAll();
            $cats = [];
            foreach ($rows as $r) {
                $c = $r['category'];
                if (!isset($cats[$c])) $cats[$c] = ['name'=>$c, 'total'=>0, 'subcategories'=>[]];
                $cats[$c]['total'] += $r['cnt'];
                if ($r['subcategory']) {
                    $cats[$c]['subcategories'][] = ['name'=>$r['subcategory'], 'count'=>(int)$r['cnt']];
                }
            }
            json_response(array_values($cats));
            break;

        case 'search':
            $q = trim($_GET['q'] ?? '');
            if (!$q) json_response([]);
            $s = db()->prepare("SELECT id,category,subcategory,title,file_type,file_path,file_url,file_size,created_at,
                MATCH(title,content_text,category,subcategory) AGAINST(? IN NATURAL LANGUAGE MODE) AS relevance
                FROM knowledgebase WHERE MATCH(title,content_text,category,subcategory) AGAINST(? IN NATURAL LANGUAGE MODE)
                ORDER BY relevance DESC LIMIT 20");
            $s->execute([$q, $q]);
            $results = $s->fetchAll();
            // If fulltext returns nothing, fallback to LIKE
            if (!$results) {
                $like = '%'.$q.'%';
                $s = db()->prepare('SELECT id,category,subcategory,title,file_type,file_path,file_url,file_size,created_at FROM knowledgebase WHERE title LIKE ? OR content_text LIKE ? OR category LIKE ? ORDER BY title LIMIT 20');
                $s->execute([$like, $like, $like]);
                $results = $s->fetchAll();
            }
            json_response($results);
            break;

        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) json_response(['error'=>'id required'], 400);
            $s = db()->prepare('SELECT * FROM knowledgebase WHERE id=?');
            $s->execute([$id]);
            $row = $s->fetch();
            if (!$row) json_response(['error'=>'Not found'], 404);
            json_response($row);
            break;

        case 'get_content':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) json_response(['error'=>'id required'], 400);
            $s = db()->prepare('SELECT id,title,file_type,file_path,file_url,content_text FROM knowledgebase WHERE id=?');
            $s->execute([$id]);
            $row = $s->fetch();
            if (!$row) json_response(['error'=>'Not found'], 404);
            json_response($row);
            break;

        case 'upload':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'], 405);
            $title = trim($_POST['title'] ?? '');
            $category = trim($_POST['category'] ?? 'General');
            $subcategory = trim($_POST['subcategory'] ?? '') ?: null;

            if (!$title) json_response(['error'=>'Title required'], 400);

            if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                json_response(['error'=>'File required'], 400);
            }

            $file = $_FILES['file'];
            if ($file['size'] > 50 * 1024 * 1024) json_response(['error'=>'Max 50MB'], 400);

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);

            if (!isset(KB_ALLOWED[$mime])) {
                json_response(['error'=>'File type not allowed: '.$mime], 400);
            }

            $ext = KB_ALLOWED[$mime];
            $slug = substr(preg_replace('/[^a-z0-9]/', '', strtolower($title)), 0, 30) ?: 'file';
            $filename = $slug . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest = KB_UPLOAD_DIR . '/' . $filename;

            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                json_response(['error'=>'Upload failed'], 500);
            }
            chmod($dest, 0644);

            // Extract text content for search
            $contentText = '';
            if ($ext === 'pdf') {
                $contentText = extractPdfText($dest);
            } elseif ($ext === 'docx') {
                $contentText = extractDocxText($dest);
            } elseif ($ext === 'txt') {
                $contentText = file_get_contents($dest);
            } elseif ($ext === 'doc') {
                $contentText = '(DOC file — text extraction requires additional tools)';
            }

            $relPath = 'uploads/knowledgebase/' . $filename;
            $fileType = in_array($ext, ['jpg','png','webp','gif']) ? 'image' : $ext;

            $ins = db()->prepare('INSERT INTO knowledgebase (category,subcategory,title,file_type,file_path,file_size,content_text) VALUES (?,?,?,?,?,?,?)');
            $ins->execute([$category, $subcategory, $title, $fileType, $relPath, $file['size'], $contentText]);

            json_response(['success'=>true, 'id'=>(int)db()->lastInsertId(), 'title'=>$title]);
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

            // Try to fetch page title/content
            $contentText = '';
            $ctx = stream_context_create(['http'=>['timeout'=>5,'user_agent'=>'LuxeTalentBot/1.0']]);
            $html = @file_get_contents($url, false, $ctx);
            if ($html) {
                // Strip tags, keep text
                $contentText = substr(strip_tags($html), 0, 50000);
            }

            $ins = db()->prepare('INSERT INTO knowledgebase (category,subcategory,title,file_type,file_url,content_text) VALUES (?,?,?,?,?,?)');
            $ins->execute([$category, $subcategory, $title, 'link', $url, $contentText]);

            json_response(['success'=>true, 'id'=>(int)db()->lastInsertId()]);
            break;

        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'], 405);
            $data = json_decode(file_get_contents('php://input'), true);
            $id = (int)($data['id'] ?? 0);
            if (!$id) json_response(['error'=>'id required'], 400);
            // Get file path to delete from disk
            $s = db()->prepare('SELECT file_path FROM knowledgebase WHERE id=?');
            $s->execute([$id]);
            $row = $s->fetch();
            if ($row && $row['file_path']) {
                $full = __DIR__ . '/../' . $row['file_path'];
                if (file_exists($full)) unlink($full);
            }
            $d = db()->prepare('DELETE FROM knowledgebase WHERE id=?');
            $d->execute([$id]);
            json_response(['success'=>true, 'deleted'=>$d->rowCount()]);
            break;

        case 'update':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'], 405);
            $data = json_decode(file_get_contents('php://input'), true);
            $id = (int)($data['id'] ?? 0);
            if (!$id) json_response(['error'=>'id required'], 400);
            $sets = []; $vals = [];
            foreach (['title','category','subcategory'] as $f) {
                if (isset($data[$f])) { $sets[] = "$f=?"; $vals[] = $data[$f] ?: null; }
            }
            if (!$sets) json_response(['error'=>'Nothing to update'], 400);
            $vals[] = $id;
            db()->prepare('UPDATE knowledgebase SET '.implode(',',$sets).' WHERE id=?')->execute($vals);
            json_response(['success'=>true]);
            break;

        case 'serve':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { http_response_code(400); exit; }
            $s = db()->prepare('SELECT file_type,file_path,file_url,title FROM knowledgebase WHERE id=?');
            $s->execute([$id]);
            $row = $s->fetch();
            if (!$row) { http_response_code(404); exit; }
            if ($row['file_type'] === 'link') {
                header('Location: '.$row['file_url']); exit;
            }
            $full = __DIR__.'/../'.$row['file_path'];
            if (!file_exists($full)) { http_response_code(404); echo 'File not found'; exit; }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($full);
            header('Content-Type: '.$mime);
            header('Content-Disposition: inline; filename="'.basename($row['file_path']).'"');
            header('Content-Length: '.filesize($full));
            readfile($full);
            exit;

        default:
            json_response(['error'=>'Unknown action. Use: list, categories, search, get, get_content, upload, add_link, delete, update, serve'], 400);
    }
} catch (Throwable $e) {
    error_log('[luxe kb] ' . $e->getMessage());
    json_response(['error'=>$e->getMessage()], 500);
}

// ── Text extraction helpers ──

function extractPdfText(string $path): string {
    // Try pdftotext (poppler-utils)
    $out = '';
    $cmd = 'pdftotext ' . escapeshellarg($path) . ' - 2>/dev/null';
    $out = shell_exec($cmd);
    if ($out && strlen(trim($out)) > 10) return substr(trim($out), 0, 100000);

    // Fallback: basic PHP extraction
    $content = file_get_contents($path);
    // Extract text between stream/endstream
    $text = '';
    if (preg_match_all('/\((.*?)\)/', $content, $matches)) {
        $text = implode(' ', $matches[1]);
        $text = preg_replace('/[^\x20-\x7E\n]/', '', $text);
    }
    return substr($text, 0, 100000) ?: '(PDF — install poppler-utils for full text extraction)';
}

function extractDocxText(string $path): string {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '(Could not read DOCX)';
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if (!$xml) return '(No content found in DOCX)';
    // Strip XML tags
    $text = strip_tags($xml);
    $text = preg_replace('/\s+/', ' ', $text);
    return substr(trim($text), 0, 100000);
}
