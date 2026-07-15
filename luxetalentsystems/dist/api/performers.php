<?php
require __DIR__."/../cors.php";
require __DIR__.'/../config.php';

// Consent-token secret — loaded only if antibot.php exists. If it doesn't,
// performers.php still works fine (the consent button just won't appear).
$CONSENT_SECRET = '';
if (is_file(__DIR__.'/antibot.php')) { require_once __DIR__.'/antibot.php'; }
if (defined('LUXE_HMAC_SECRET'))     { $CONSENT_SECRET = LUXE_HMAC_SECRET; }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            $search = trim($_GET['search'] ?? '');
            if ($search) {
                $s = db()->prepare("SELECT email,first_name,last_name,stage_name,status,role,phone,profile_photo,created_at FROM registration WHERE role='performer' AND (stage_name LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ?) ORDER BY created_at DESC");
                $like = "%$search%";
                $s->execute([$like,$like,$like,$like]);
            } else {
                $s = db()->query("SELECT email,first_name,last_name,stage_name,status,role,phone,profile_photo,created_at FROM registration WHERE role='performer' ORDER BY created_at DESC");
            }
            json_response($s->fetchAll());
            break;

        case 'update_status':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'],405);
            $data = json_decode(file_get_contents('php://input'), true);
            $email = $data['email'] ?? '';
            $status = $data['status'] ?? '';
            $allowed = ['registered','inactive','info_needed','banned','pending','active'];
            if (!$email || !in_array($status, $allowed)) json_response(['error'=>'Invalid email or status'],400);
            $s = db()->prepare('UPDATE registration SET status=? WHERE email=?');
            $s->execute([$status, $email]);
            json_response(['success'=>true,'email'=>$email,'status'=>$status]);
            break;

        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'],405);
            $data = json_decode(file_get_contents('php://input'), true);
            $email = $data['email'] ?? '';
            if (!$email) json_response(['error'=>'Email required'],400);
            $s = db()->prepare('DELETE FROM registration WHERE email=? AND role="performer"');
            $s->execute([$email]);
            json_response(['success'=>true,'deleted'=>$s->rowCount()]);
            break;

        case 'get':
            $email = $_GET['email'] ?? '';
            if (!$email) json_response(['error'=>'Email required'],400);
            $s = db()->prepare('SELECT * FROM registration WHERE email=?');
            $s->execute([$email]);
            $row = $s->fetch();
            if (!$row) json_response(['error'=>'Not found'],404);
            $row['plain_password'] = $row['plain_password'] ?? '';
            if ($CONSENT_SECRET !== '') {
                $row['consent_token'] = hash_hmac('sha256','consent:'.strtolower($row['email']), $CONSENT_SECRET);
            }
            unset($row['password_hash']);
            json_response($row);
            break;

        case 'update':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'],405);
            $data = json_decode(file_get_contents('php://input'), true);
            $email = $data['email'] ?? '';
            if (!$email) json_response(['error'=>'Email required'],400);
            $fields = ['first_name','last_name','middle_name','stage_name','phone','street_address','city','state','zip_code','country',
                       'ethnicity','orientation','primary_language','status','platform','recruiter_name',
                       'height','weight','build','eyes_color','hair_color','hair_length','breast_size','butt_size',
                       'pubic_hair','dress_size','display_age','about_me','what_turns_you_on','what_turns_you_off','notepad',
                       'sexual_preferences','interested_in','alternate_user_names','other_language',
                       'studio_fee','date_of_birth'];
            $sets = [];
            $vals = [];
            if (!empty($data['password'])) {
                $sets[] = 'password_hash=?';
                $vals[] = password_hash($data['password'], PASSWORD_BCRYPT);
                $sets[] = 'plain_password=?';
                $vals[] = $data['password'];
            }
            foreach ($fields as $f) {
                if (array_key_exists($f, $data)) {
                    $sets[] = "$f=?";
                    $vals[] = $data[$f] === '' ? null : $data[$f];
                }
            }
            if (!$sets) json_response(['error'=>'No fields to update'],400);
            $vals[] = $email;
            $sql = 'UPDATE registration SET ' . implode(',', $sets) . ' WHERE email=?';
            $s = db()->prepare($sql);
            $s->execute($vals);
            json_response(['success'=>true,'email'=>$email,'updated'=>count($sets)]);
            break;

        case 'csv_import':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'],405);
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data || !isset($data['email'])) json_response(['error'=>'Data required'],400);

            $email = strtolower(trim($data['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(['error'=>'Invalid email: '.$email],400);

            // Check duplicate
            $chk = db()->prepare('SELECT email FROM registration WHERE email=?');
            $chk->execute([$email]);
            if ($chk->fetch()) json_response(['error'=>'Email already exists: '.$email],409);

            // Download photos from URLs
            $photoDir = __DIR__.'/../uploads/photos';
            $idDir = __DIR__.'/../uploads/ids';
            if (!is_dir($photoDir)) mkdir($photoDir, 0750, true);
            if (!is_dir($idDir)) mkdir($idDir, 0750, true);

            $profilePath = null;
            $idFrontPath = null;
            $idBackPath = null;

            $slug = substr(preg_replace('/[^a-z0-9]/','',strtolower($email)),0,20)?:'anon';

            if (!empty($data['profile_photo_url'])) {
                $profilePath = downloadImage($data['profile_photo_url'], $photoDir, $slug);
                if ($profilePath) $profilePath = json_encode(['uploads/photos/'.basename($profilePath)]);
            }
            if (!empty($data['id_front_url'])) {
                $idFrontPath = downloadImage($data['id_front_url'], $idDir, $slug.'_idfront');
                if ($idFrontPath) $idFrontPath = 'uploads/ids/'.basename($idFrontPath);
            }
            if (!empty($data['id_back_url'])) {
                $idBackPath = downloadImage($data['id_back_url'], $idDir, $slug.'_idback');
                if ($idBackPath) $idBackPath = 'uploads/ids/'.basename($idBackPath);
            }

            // Create signature
            $sigDir = __DIR__.'/../uploads/signatures';
            if (!is_dir($sigDir)) mkdir($sigDir, 0750, true);
            $sigName = $slug.'_'.bin2hex(random_bytes(4)).'.png';
            // Create a tiny valid PNG
            $img = imagecreatetruecolor(200, 60);
            $bg = imagecolorallocate($img, 14, 17, 23);
            imagefill($img, 0, 0, $bg);
            $gold = imagecolorallocate($img, 212, 168, 48);
            imagestring($img, 5, 10, 20, 'CSV Import', $gold);
            imagepng($img, $sigDir.'/'.$sigName);
            imagedestroy($img);
            $sigPath = 'uploads/signatures/'.$sigName;

            $password = $data['password'] ?? 'Luxe2025!';

            $sql = 'INSERT INTO registration (
                email,role,platform,recruiter_name,first_name,middle_name,last_name,stage_name,password_hash,
                alternate_user_names,street_address,city,state,zip_code,country,phone,date_of_birth,
                sexual_preferences,ethnicity,interested_in,primary_language,other_language,display_age,weight,height,
                orientation,eyes_color,hair_color,hair_length,build,butt_size,breast_size,pubic_hair,dress_size,
                about_me,what_turns_you_on,what_turns_you_off,studio_fee,
                profile_photo,id_front,id_back,
                consent,signature_path,signed_at,status,applying_for
            ) VALUES (
                ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,NOW(),?,?)';

            $stmt = db()->prepare($sql);
            $stmt->execute([
                $email, 'performer',
                $data['platform'] ?? 'Stripchat',
                $data['recruiter'] ?? 'Clarence',
                $data['first_name'] ?? 'Unknown',
                $data['middle_name'] ?? null,
                $data['last_name'] ?? 'Unknown',
                $data['stage_name'] ?? null,
                password_hash($password, PASSWORD_BCRYPT),
                $data['alternate_usernames'] ?? null,
                $data['street_address'] ?? 'TBD',
                $data['city'] ?? 'TBD',
                $data['state'] ?? 'TBD',
                $data['zip_code'] ?? '00000',
                $data['country'] ?? 'US',
                $data['phone'] ?? null,
                $data['date_of_birth'] ?? '1990-01-01',
                $data['sexual_preferences'] ?? null,
                $data['ethnicity'] ?? 'TBD',
                $data['interested_in'] ?? 'Both',
                $data['primary_language'] ?? 'English',
                $data['other_language'] ?? null,
                $data['display_age'] ?? null,
                $data['weight'] ?? 'TBD',
                $data['height'] ?? 'TBD',
                $data['orientation'] ?? 'Straight',
                $data['eye_color'] ?? 'Brown',
                $data['hair_color'] ?? 'Black',
                $data['hair_length'] ?? 'Long',
                $data['build'] ?? 'Medium',
                $data['butt_size'] ?? 'Normal',
                $data['breast_size'] ?? 'Normal',
                $data['pubic_hair'] ?? 'Shaved',
                $data['dress_size'] ?? 'Medium',
                $data['about_me'] ?? 'Imported via CSV',
                $data['turns_on'] ?? 'TBD',
                $data['turns_off'] ?? 'TBD',
                $data['studio_fee'] ?? null,
                $profilePath, $idFrontPath, $idBackPath,
                $sigPath, 'pending', 'Webcam Model',
            ]);
            json_response(['success'=>true,'email'=>$email,'photos_downloaded'=>($profilePath?1:0)+($idFrontPath?1:0)+($idBackPath?1:0)]);
            break;

        case 'upload_photo':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'POST only'],405);
            $email = strtolower(trim($_POST['email'] ?? ''));
            if (!$email) json_response(['error'=>'Email required'],400);
            if (empty($_FILES['photos']['name'])) json_response(['error'=>'No photos uploaded'],400);
            $dir = dirname(__DIR__).'/uploads/photos';
            if (!is_dir($dir)) mkdir($dir,0755,true);
            $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/heic'=>'heic','image/heif'=>'heic'];
            $saved = [];
            $names = (array)$_FILES['photos']['name'];
            for ($i=0; $i<count($names); $i++) {
                if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $tmp  = $_FILES['photos']['tmp_name'][$i];
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
                if (!isset($allowed[$mime])) continue;
                $fn = preg_replace('/[^a-z0-9]/','',$email).'_'.bin2hex(random_bytes(5)).'.'.$allowed[$mime];
                if (move_uploaded_file($tmp, $dir.'/'.$fn)) $saved[] = 'uploads/photos/'.$fn;
            }
            if (!$saved) json_response(['error'=>'No valid image files'],400);
            $s = db()->prepare('SELECT nude_photos FROM registration WHERE email=?');
            $s->execute([$email]);
            $existing = $s->fetchColumn();
            $arr = [];
            if ($existing) { $d = json_decode($existing,true); if (is_array($d)) $arr = $d; }
            $arr = array_merge($arr, $saved);
            db()->prepare('UPDATE registration SET nude_photos=? WHERE email=?')->execute([json_encode($arr),$email]);
            json_response(['success'=>true,'paths'=>$saved,'path'=>$saved[0]]);
            break;

        default:
            json_response(['error'=>'Unknown action. Use: list, update_status, delete, get'],400);
    }
} catch (Throwable $e) {
    error_log('[luxe performers] ' . $e->getMessage());
    json_response(['error'=>$e->getMessage()], 500);
}

function downloadImage(string $url, string $dir, string $slug): ?string {
    $url = trim($url);
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) return null;
    try {
        $ctx = stream_context_create(['http'=>['timeout'=>15,'user_agent'=>'LuxeTalentBot/1.0']]);
        $data = @file_get_contents($url, false, $ctx);
        if (!$data || strlen($data) < 100) return null;
        // Detect extension from content
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($data);
        $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
        $ext = $extMap[$mime] ?? null;
        if (!$ext) return null;
        $filename = $slug.'_'.bin2hex(random_bytes(4)).'_'.date('Ymd_His').'.'.$ext;
        $dest = $dir.'/'.$filename;
        file_put_contents($dest, $data);
        chmod($dest, 0640);
        return $dest;
    } catch (Throwable $e) {
        error_log('[luxe download] '.$e->getMessage());
        return null;
    }
}
