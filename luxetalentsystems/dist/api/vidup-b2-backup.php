<?php
/* vidup.php — LOCAL STORAGE video uploader.
 * Drop-in replacement for the old B2/S3 version. Keeps the same action names
 * the client already calls, but stores chunks locally and reassembles.
 *
 * Actions:
 *   init_multipart     -> {success, key, upload_id, parts:[{part_number,url}]}
 *   (client PUTs each part blob to url = vidup.php?action=put_part&upload_id=..&part_number=..)
 *   complete_multipart -> reassembles parts into final file {success, key, url}
 *   abort_multipart    -> deletes temp parts
 *   list               -> {success, items:[...]}
 *   download_url       -> {success, url}
 *   delete             -> {success}
 *
 * Storage: final files in $VID_DIR ; temp chunks in $TMP_DIR/<upload_id>/
 */
header('Content-Type: application/json; charset=utf-8');

$VID_DIR = '/var/www/sites/luxetalentsystems/dist/uploads/videos';
$TMP_DIR = '/var/www/sites/luxetalentsystems/dist/uploads/.videotmp';
$PUBLIC_BASE = '/uploads/videos'; // web path
@mkdir($VID_DIR, 0775, true);
@mkdir($TMP_DIR, 0775, true);

$action = $_GET['action'] ?? '';

function jout($a){ echo json_encode($a); exit; }
function safeName($s){ $s=preg_replace('/[^A-Za-z0-9._-]/','_', $s); return substr($s,0,180) ?: 'video.bin'; }

/* ---- init: create an upload id, return a ready-made parts array ---- */
if ($action === 'init_multipart') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $filename = safeName($in['filename'] ?? 'video.bin');
    $stage    = safeName($in['stage_name'] ?? $in['stage'] ?? '');
    $size     = intval($in['size'] ?? 0);
    $partSize = intval($in['part_size'] ?? (16*1024*1024));
    if ($partSize < 1) $partSize = 16*1024*1024;

    $uploadId = bin2hex(random_bytes(8));
    $prefix = $stage ? ($stage.'/') : '';
    $key = $prefix . date('Ymd_His') . '_' . $filename;

    @mkdir("$TMP_DIR/$uploadId", 0775, true);
    file_put_contents("$TMP_DIR/$uploadId/meta.json", json_encode(['key'=>$key,'filename'=>$filename]));

    // build the parts array the client expects: each has part_number + a local PUT url
    $numParts = $size > 0 ? (int)ceil($size / $partSize) : 1;
    if ($numParts < 1) $numParts = 1;
    $parts = [];
    for ($n = 1; $n <= $numParts; $n++) {
        $parts[] = [
            'part_number' => $n,
            'url' => 'api/vidup.php?action=put_part&upload_id='.$uploadId.'&part_number='.$n
        ];
    }

    jout([
        'success'   => true,
        'key'       => $key,
        'upload_id' => $uploadId,
        'part_size' => $partSize,
        'parts'     => $parts
    ]);
}

/* ---- put_part: receive one chunk (raw body) ---- */
if ($action === 'put_part') {
    $uploadId = preg_replace('/[^a-f0-9]/','', $_GET['upload_id'] ?? '');
    $partNum  = intval($_GET['part_number'] ?? 0);
    $dir = "$TMP_DIR/$uploadId";
    if ($uploadId==='' || $partNum<1 || !is_dir($dir)) { http_response_code(400); jout(['success'=>false,'error'=>'bad part']); }
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') { http_response_code(400); jout(['success'=>false,'error'=>'empty part']); }
    file_put_contents(sprintf("%s/part_%06d", $dir, $partNum), $raw);
    // mimic S3 ETag so the client's existing code (expects an ETag) is happy
    $etag = md5($raw);
    header('ETag: "'.$etag.'"');
    jout(['success'=>true,'etag'=>$etag,'part_number'=>$partNum]);
}

/* ---- complete: reassemble parts in order ---- */
if ($action === 'complete_multipart') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $uploadId = preg_replace('/[^a-f0-9]/','', $in['upload_id'] ?? '');
    $key = $in['key'] ?? '';
    $dir = "$TMP_DIR/$uploadId";
    if ($uploadId==='' || !is_dir($dir)) { http_response_code(400); jout(['success'=>false,'error'=>'no upload']); }

    $meta = json_decode(@file_get_contents("$dir/meta.json"), true) ?: [];
    if ($key==='') $key = $meta['key'] ?? ('video_'.$uploadId.'.bin');

    $final = $VID_DIR . '/' . $key;
    @mkdir(dirname($final), 0775, true);

    $parts = glob("$dir/part_*");
    natsort($parts);
    $out = fopen($final, 'wb');
    if (!$out) { jout(['success'=>false,'error'=>'cannot write final']); }
    foreach ($parts as $p) {
        $in2 = fopen($p, 'rb');
        stream_copy_to_stream($in2, $out);
        fclose($in2);
    }
    fclose($out);

    // cleanup temp
    array_map('unlink', glob("$dir/*"));
    @rmdir($dir);

    @chown($final, 'www-data'); @chgrp($final, 'www-data');
    jout(['success'=>true,'key'=>$key,'url'=>$PUBLIC_BASE.'/'.rawurlencode($key)]);
}

/* ---- abort: delete temp ---- */
if ($action === 'abort_multipart') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $uploadId = preg_replace('/[^a-f0-9]/','', $in['upload_id'] ?? '');
    $dir = "$TMP_DIR/$uploadId";
    if ($uploadId!=='' && is_dir($dir)) { array_map('unlink', glob("$dir/*")); @rmdir($dir); }
    jout(['success'=>true]);
}

/* ---- list ---- */
if ($action === 'list') {
    $items = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($VID_DIR, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        if ($file->isFile()) {
            $rel = ltrim(str_replace($VID_DIR,'',$file->getPathname()),'/');
            $items[] = [
                'key'      => $rel,
                'filename' => $file->getFilename(),
                'size'     => $file->getSize(),
                'modified' => date('c', $file->getMTime()),
                'url'      => $PUBLIC_BASE.'/'.rawurlencode($rel)
            ];
        }
    }
    usort($items, function($a,$b){ return strcmp($b['modified'],$a['modified']); });
    jout(['success'=>true,'items'=>$items]);
}

/* ---- download_url (local: just the public path) ---- */
if ($action === 'download_url') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $key = $in['key'] ?? ($_GET['key'] ?? '');
    if ($key==='') jout(['success'=>false,'error'=>'no key']);
    jout(['success'=>true,'url'=>$PUBLIC_BASE.'/'.rawurlencode($key)]);
}

/* ---- delete ---- */
if ($action === 'delete') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $key = $in['key'] ?? '';
    $key = str_replace(['..','\\'],'',$key);
    $path = $VID_DIR.'/'.$key;
    if ($key!=='' && is_file($path)) { unlink($path); jout(['success'=>true]); }
    jout(['success'=>false,'error'=>'not found']);
}

http_response_code(400);
jout(['success'=>false,'error'=>'Invalid action']);
