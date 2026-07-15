<?php
define('DB_HOST','127.0.0.1');
define('DB_PORT',3306);
define('DB_NAME','luxe_talent');
define('DB_USER','Ezmator7700');
define('DB_PASS','Sonia@7700');

function db():PDO{
    static $pdo=null;
    if($pdo!==null)return $pdo;
    $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',DB_HOST,DB_PORT,DB_NAME);
    try{
        $pdo=new PDO($dsn,DB_USER,DB_PASS,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    }catch(PDOException $e){
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error'=>'Database unavailable: '.$e->getMessage()]);
        exit;
    }
    return $pdo;
}

function json_response($data,int $status=200):void{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

define('UPLOAD_ROOT',__DIR__.'/uploads');
define('UPLOAD_PHOTOS',UPLOAD_ROOT.'/photos');
define('UPLOAD_IDS',UPLOAD_ROOT.'/ids');
define('UPLOAD_ADDITIONAL',UPLOAD_ROOT.'/additional');
define('UPLOAD_SIGS',UPLOAD_ROOT.'/signatures');
