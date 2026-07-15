<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
$DB_HOST='127.0.0.1'; $DB_PORT=3306; $DB_NAME='luxe_talent'; $DB_USER='Ezmator7700'; $DB_PASS='Sonia@7700';
try{ $pdo=new PDO("mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4",$DB_USER,$DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}catch(Exception $e){ echo json_encode(['ok'=>false,'error'=>'db']); exit; }
$pdo->exec("CREATE TABLE IF NOT EXISTS browser_links(id INT AUTO_INCREMENT PRIMARY KEY,url VARCHAR(1024) NOT NULL,username VARCHAR(128) DEFAULT '',password VARCHAR(256) DEFAULT '',label VARCHAR(191) DEFAULT '',sort_order INT DEFAULT 0,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$action = $_GET['action'] ?? 'list';
$in = json_decode(file_get_contents('php://input'), true) ?: [];
if ($action==='list'){ $rows=$pdo->query("SELECT id,url,username,password,label,sort_order FROM browser_links ORDER BY sort_order ASC, id ASC")->fetchAll(); echo json_encode(['ok'=>true,'rows'=>$rows]); exit; }
if ($action==='save'){
  $id=intval($in['id']??0); $url=trim($in['url']??''); $user=mb_substr(trim($in['username']??''),0,128); $pass=mb_substr((string)($in['password']??''),0,256); $label=mb_substr(trim($in['label']??''),0,191); $sort=intval($in['sort_order']??0);
  if($url===''){ echo json_encode(['ok'=>false,'error'=>'url required']); exit; }
  if(!preg_match('~^https?://~i',$url)) $url='https://'.$url;
  if($id>0){ $s=$pdo->prepare("UPDATE browser_links SET url=?,username=?,password=?,label=?,sort_order=? WHERE id=?"); $s->execute([$url,$user,$pass,$label,$sort,$id]); echo json_encode(['ok'=>true,'id'=>$id]); exit; }
  else{ $s=$pdo->prepare("INSERT INTO browser_links(url,username,password,label,sort_order) VALUES(?,?,?,?,?)"); $s->execute([$url,$user,$pass,$label,$sort]); echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]); exit; }
}
if ($action==='delete'){ $id=intval($in['id']??0); if($id>0){ $pdo->prepare("DELETE FROM browser_links WHERE id=?")->execute([$id]); } echo json_encode(['ok'=>true]); exit; }
echo json_encode(['ok'=>false,'error'=>'unknown action']);
