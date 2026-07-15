<?php
/**
 * Luxe Talent System — registration handler
 * File location:  api/register.php
 *
 * Final version. Adds to the Task 1 file:
 *  - Flow branching: 'webcam' requires the physical/preferences fields;
 *    'creator' does not (content creators only complete Basic + Address +
 *    Teaser Videos + Consent).
 *  - Teaser video uploads for content creators -> uploads/teasers/,
 *    saved into the new teaser_videos column.
 *  - applying_for is set from the chosen flow.
 */
require __DIR__."/../cors.php";
require __DIR__.'/../config.php';
require __DIR__.'/antibot.php';

const MAX_FILE_BYTES  = 15*1024*1024;          // images
const MAX_VIDEO_BYTES = 100*1024*1024;         // teaser videos (see php.ini note in README)
const ALLOWED_MIME  = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/heic'=>'heic','image/heif'=>'heif','image/gif'=>'gif'];
const ALLOWED_VIDEO = ['video/mp4'=>'mp4','video/quicktime'=>'mov','video/webm'=>'webm','video/x-m4v'=>'m4v','video/x-msvideo'=>'avi'];

if(!defined('UPLOAD_TEASERS')) define('UPLOAD_TEASERS', dirname(__DIR__).'/uploads/teasers');

if($_SERVER['REQUEST_METHOD']!=='POST') json_response(['success'=>false,'error'=>'POST only'],405);
error_log("[REG DEBUG] POST keys: ".implode(",",array_keys($_POST)));
error_log("[REG DEBUG] email=[".($_POST["email"]??"")."]");
try{register();}catch(Throwable $e){error_log('[luxe register] '.$e->getMessage());json_response(['success'=>false,'error'=>$e->getMessage()],400);}

function register():void{
    // --- anti-bot guard (replaces reCAPTCHA) ---
    antibot_check();

    // --- applicant flow ---
    $flow      = (($_POST['flow'] ?? '') === 'creator') ? 'creator' : 'webcam';
    $isWebcam  = ($flow === 'webcam');     // physical + preferences required for webcam only
    $applyFor  = ($flow === 'creator') ? 'Content Creator' : 'Webcam Model';

    $f=function(string $key,bool $req=true):string{$v=trim($_POST[$key]??'');if($req&&$v==='')throw new RuntimeException("Missing: $key");return $v;};

    $email=strtolower($f('email'));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid email');
    $password=$f('password');
    if(strlen($password)<6) throw new RuntimeException('Password min 6 chars');
    $dob=$f('dateOfBirth');$dobTs=strtotime($dob);
    if(!$dobTs) throw new RuntimeException('Invalid DOB');
    if((int)((time()-$dobTs)/(365.25*86400))<18) throw new RuntimeException('Must be 18+');

    $chk=db()->prepare('SELECT email FROM registration WHERE email=?');$chk->execute([$email]);
    if($chk->fetch()) throw new RuntimeException('Email already registered');

    $sigData=$_POST['signature']??'';
    if(!preg_match('#^data:image/png;base64,#',$sigData)) throw new RuntimeException('Sign the consent document');

    $sigColor=strtolower(trim($_POST['signature_color']??'black'));
    if($sigColor!=='blue') $sigColor='black';

    foreach([UPLOAD_PHOTOS,UPLOAD_IDS,UPLOAD_ADDITIONAL,UPLOAD_SIGS,UPLOAD_TEASERS] as $d){if(!is_dir($d))mkdir($d,0750,true);}

    // Photos + ID are required for BOTH flows.
    $profilePaths=save_multi('profilePhotos',UPLOAD_PHOTOS,true);
    $idFrontPath=save_single('idFront',UPLOAD_IDS,true);
    $idBackPath=save_single('idBack',UPLOAD_IDS,false);
    $faceIdPath=save_single('faceAndId',UPLOAD_IDS,false);
    $nudePaths=save_multi('morePhotos',UPLOAD_ADDITIONAL,false);

    // Teaser videos: required for content creators, ignored for webcam models.
    $teaserPaths=save_videos('teaserVideos',UPLOAD_TEASERS,!$isWebcam);

    $sigBytes=base64_decode(substr($sigData,strpos($sigData,',')+1),true);
    if(!$sigBytes||strlen($sigBytes)<50) throw new RuntimeException('Sig decode fail');
    $sigName=uname($email,'png');
    file_put_contents(UPLOAD_SIGS.'/'.$sigName,$sigBytes);
    $relSig='uploads/signatures/'.$sigName;

    $platforms=trim($_POST['platforms']??'');
    if(!$platforms) throw new RuntimeException('Select a platform');

    $sql='INSERT INTO registration (
        email,applying_for,role,platform,recruiter_name,first_name,middle_name,last_name,stage_name,password_hash,plain_password,
        alternate_user_names,street_address,city,state,zip_code,country,phone,date_of_birth,
        sexual_preferences,ethnicity,interested_in,primary_language,other_language,display_age,weight,height,
        orientation,eyes_color,hair_color,hair_length,build,butt_size,breast_size,pubic_hair,dress_size,
        about_me,what_turns_you_on,what_turns_you_off,
        profile_photo,id_front,id_back,face_and_id,nude_photos,
        consent,signature_path,signed_at,signed_ip,signed_user_agent,status,
        printed_name,sms_consent,sms_consent_at
    ) VALUES (
        :email,:applying_for,:role,:platform,:recruiter,:fname,:mname,:lname,:stage,:pw,:plainpw,
        :alt,:street,:city,:state,:zip,:country,:phone,:dob,
        :sexpref,:eth,:interest,:plang,:olang,:dage,:weight,:height,
        :orient,:eyes,:hair,:hairlen,:build,:butt,:breast,:pubic,:dress,
        :about,:turnson,:turnsoff,
        :photo,:idfront,:idback,:faceid,:nude,
        1,:sigpath,NOW(),:ip,:ua,:status,
        :printed_name,:sms_consent,:sms_consent_at
    )';

    $stmt=db()->prepare($sql);
    $stmt->execute([
        ':email'=>$email,':applying_for'=>$applyFor,
        ':role'=>$f('role',false)?:'performer',':platform'=>$platforms,':recruiter'=>$f('recruiterName'),
        ':fname'=>$f('firstName'),':mname'=>$f('middleName',false)?:null,':lname'=>$f('lastName'),
        ':stage'=>$f('stageName'),':pw'=>password_hash($password,PASSWORD_BCRYPT),
        ':plainpw'=>$password,
        ':alt'=>$f('alternateUsernames',false)?:null,':street'=>$f('streetAddress'),
        ':city'=>$f('city'),':state'=>$f('state'),':zip'=>$f('zipCode'),':country'=>$f('country'),
        ':phone'=>$f('phone'),':dob'=>date('Y-m-d',$dobTs),
        ':sexpref'=>$f('sexualPreferences',false)?:null,':eth'=>$f('ethnicity',$isWebcam)?:null,
        ':interest'=>$f('interestedIn',$isWebcam)?:null,':plang'=>$f('primaryLanguage',$isWebcam)?:null,
        ':olang'=>$f('otherLanguage',false)?:null,':dage'=>(int)($_POST['displayAge']??0)?:null,
        ':weight'=>$f('weight',$isWebcam)?:null,':height'=>$f('height',$isWebcam)?:null,
        ':orient'=>$f('orientation',$isWebcam)?:null,':eyes'=>$f('eyeColor',$isWebcam)?:null,
        ':hair'=>$f('hairColor',$isWebcam)?:null,':hairlen'=>$f('hairLength',$isWebcam)?:null,
        ':build'=>$f('build',$isWebcam)?:null,':butt'=>$f('buttSize',$isWebcam)?:null,
        ':breast'=>$f('breastSize',$isWebcam)?:null,':pubic'=>$f('pubicHair',$isWebcam)?:null,
        ':dress'=>$f('dressSize',$isWebcam)?:null,
        ':about'=>$f('aboutMe',$isWebcam)?:null,':turnson'=>$f('turnsOn',$isWebcam)?:null,
        ':turnsoff'=>$f('turnsOff',$isWebcam)?:null,
        ':photo'=>json_encode($profilePaths),':idfront'=>$idFrontPath,':idback'=>$idBackPath,
        ':faceid'=>$faceIdPath,':nude'=>$nudePaths?json_encode($nudePaths):null,
        ':sigpath'=>$relSig,':ip'=>$_SERVER['REMOTE_ADDR']??null,
        ':ua'=>substr($_SERVER['HTTP_USER_AGENT']??'',0,500)?:null,':status'=>'pending',
        ':printed_name'=>trim($_POST['printed_name']??'')?:null,
        ':sms_consent'=>(in_array(($_POST['sms_consent']??''),['yes','no'],true)?$_POST['sms_consent']:null),
        ':sms_consent_at'=>(in_array(($_POST['sms_consent']??''),['yes','no'],true)?date('Y-m-d H:i:s'):null),
    ]);

    // Signature ink colour — separate statement, own try/catch so a missing
    // column can never roll back a completed registration.
    try{
        db()->prepare('UPDATE registration SET signature_color=? WHERE email=?')
            ->execute([$sigColor,$email]);
    }catch(Throwable $ce){
        error_log('[signature_color] '.$ce->getMessage());
    }

    // Teaser videos -> own statement + try/catch, so a missing column never
    // rolls back a completed registration.
    if($teaserPaths){
        try{
            db()->prepare('UPDATE registration SET teaser_videos=? WHERE email=?')
                ->execute([json_encode($teaserPaths),$email]);
        }catch(Throwable $te){
            error_log('[teaser_videos] '.$te->getMessage());
        }
    }

    // --- Welcome SMS (Twilio) ---
    try {
        $phone     = $f('phone');
        $firstName = $f('firstName');
        $smsPhone  = preg_replace('/[^+0-9]/', '', $phone);
        if($smsPhone !== '' && $smsPhone[0] !== '+') $smsPhone = '+1' . $smsPhone;
        if(strlen($smsPhone) >= 11) {
            $smsBody = "Welcome to Luxe Model Collective, {$firstName}! Your registration is received. Login: https://luxetalentsystems.com/login.html Email: {$email}. Reply HELP for assistance.";
            $smsCh = curl_init('https://api.twilio.com/2010-04-01/Accounts/AC906dbbb445fc44f915f813107748e499/Messages.json');
            curl_setopt_array($smsCh, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => 'AC906dbbb445fc44f915f813107748e499:1e44f3902ff20ae0ba527e4c84287e3b',
                CURLOPT_POSTFIELDS => http_build_query(['From'=>'+15715836064','To'=>$smsPhone,'Body'=>$smsBody]),
                CURLOPT_TIMEOUT => 10,
            ]);
            curl_exec($smsCh);
            curl_close($smsCh);
        }
    } catch(Throwable $smsErr) {
        error_log('[sms auto-confirm] ' . $smsErr->getMessage());
    }

    // --- Registration emails (admin notify + performer acknowledgement) ---
    try {
        $firstName = $f('firstName', false) ?: '';
        $lastName  = $f('lastName', false) ?: '';
        $applyFor2 = $f('flow', false) ?: ($applyFor ?? '');
        $phone2    = $f('phone', false) ?: '';

        // Self-contained SMTP sender over the talentmail server (STARTTLS, AUTH LOGIN).
        $luxe_reg_smtp_send = function(string $to, string $subject, string $htmlBody): bool {
            $host='talentmail.luxetalentsystems.com'; $port=587;
            $user='support@luxetalentsystems.com'; $pass='Sonia@7700';
            $fromEmail='support@luxetalentsystems.com'; $fromName='Luxe Talent';
            $fp=@stream_socket_client("tcp://$host:$port",$en,$es,15);
            if(!$fp) { error_log("[reg-mail] connect fail $es"); return false; }
            $read=function() use($fp){ $d=''; while($line=fgets($fp,515)){ $d.=$line; if(substr($line,3,1)==' ') break; } return $d; };
            $cmd=function($c) use($fp,$read){ if($c!==null) fputs($fp,$c."\r\n"); return $read(); };
            $cmd(null);
            $cmd("EHLO luxetalentsystems.com");
            $cmd("STARTTLS");
            if(!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)){ error_log("[reg-mail] tls fail"); fclose($fp); return false; }
            $cmd("EHLO luxetalentsystems.com");
            $cmd("AUTH LOGIN");
            $cmd(base64_encode($user));
            $r=$cmd(base64_encode($pass));
            if(strpos($r,'235')===false){ error_log("[reg-mail] auth fail: ".trim($r)); fclose($fp); return false; }
            $cmd("MAIL FROM:<$fromEmail>");
            $cmd("RCPT TO:<$to>");
            $cmd("DATA");
            $headers ="From: ".$fromName." <".$fromEmail.">\r\n";
            $headers.="To: ".$to."\r\n";
            $headers.="Subject: ".$subject."\r\n";
            $headers.="MIME-Version: 1.0\r\n";
            $headers.="Content-Type: text/html; charset=UTF-8\r\n";
            $headers.="Date: ".date('r')."\r\n";
            $body=str_replace("\r\n.\r\n","\r\n..\r\n",$htmlBody);
            $cmd($headers."\r\n".$body."\r\n.");
            $cmd("QUIT");
            fclose($fp);
            return true;
        };

        // 1) ADMIN NOTIFICATION
        $adminTo='support@luxemodelcollective.com';
        $adminSubj='New Registration: '.trim($firstName.' '.$lastName);
        $adminHtml='<div style="font-family:Arial,sans-serif;font-size:14px;color:#222">'
            .'<h2 style="color:#b8860b;margin:0 0 12px">New Registration</h2>'
            .'<p><b>Name:</b> '.htmlspecialchars(trim($firstName.' '.$lastName)).'<br>'
            .'<b>Email:</b> '.htmlspecialchars($email).'<br>'
            .'<b>Phone:</b> '.htmlspecialchars($phone2).'<br>'
            .'<b>Applying for:</b> '.htmlspecialchars($applyFor2).'</p>'
            .'<p><a href="https://luxetalentsystems.com/dashboard.html">Open Dashboard</a></p></div>';
        $luxe_reg_smtp_send($adminTo,$adminSubj,$adminHtml);

        // 2) PERFORMER ACKNOWLEDGEMENT
        $ackSubj='Welcome to Luxe Model Collective - Next Steps';
        $ackHtml='<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#222;max-width:600px">'
            .'<h2 style="color:#b8860b">Welcome'.($firstName?(', '.htmlspecialchars($firstName)):'').'!</h2>'
            .'<p>Thank you for registering with Luxe Model Collective. Your registration has been received.</p>'
            .'<p><b>Get started now:</b></p>'
            .'<ol>'
            .'<li>Log in to your performer dashboard: <a href="https://luxetalentsystems.com/login.html">luxetalentsystems.com/login.html</a></li>'
            .'<li>Confirm your schedule.</li>'
            .'<li>Build your profile page by uploading your photos (see below).</li>'
            .'</ol>'
            .'<p><b>Profile photos:</b></p>'
            .'<p>If you are <b>female</b>, your profile requires nude photos for review: breasts, hips/buttocks, and genitals. '
            .'If you are <b>male</b>, no additional photos are required at this time.</p>'
            .'<p><b>Videos:</b> If you have any solo videos, please upload those now, or message us through the app to schedule a photo/video shoot.</p>'
            .'<p style="color:#666;font-size:13px">Questions? Reply to this email or message us in the app.</p>'
            .'<p style="color:#b8860b;font-weight:bold">Luxe Model Collective</p></div>';
        $luxe_reg_smtp_send($email,$ackSubj,$ackHtml);

    } catch(Throwable $mailErr) {
        error_log('[reg-mail] '.$mailErr->getMessage());
    }

    $consentToken = hash_hmac('sha256', 'consent:' . $email, LUXE_HMAC_SECRET);

    json_response([
        'success'       => true,
        'email'         => $email,
        'lastName'      => $f('lastName'),
        'status'        => 'pending',
        'flow'          => $flow,
        'consent_token' => $consentToken,
    ]);
}

function save_single(string $key,string $dir,bool $req):?string{
    if(empty($_FILES[$key]['tmp_name'])||$_FILES[$key]['error']===UPLOAD_ERR_NO_FILE){if($req)throw new RuntimeException("$key required");return null;}
    return save_one($_FILES[$key],$dir,$key);
}
function save_multi(string $key,string $dir,bool $req):array{
    $out=[];
    if(empty($_FILES[$key])||!is_array($_FILES[$key]['name']??null)){if($req)throw new RuntimeException("$key required");return $out;}
    $n=count($_FILES[$key]['name']);
    for($i=0;$i<$n;$i++){
        if($_FILES[$key]['error'][$i]===UPLOAD_ERR_NO_FILE)continue;
        $f=['name'=>$_FILES[$key]['name'][$i],'type'=>$_FILES[$key]['type'][$i],'tmp_name'=>$_FILES[$key]['tmp_name'][$i],'error'=>$_FILES[$key]['error'][$i],'size'=>$_FILES[$key]['size'][$i]];
        $out[]=save_one($f,$dir,$key);
    }
    if($req&&!$out)throw new RuntimeException("$key: at least one file needed");
    return $out;
}
function save_one(array $f,string $dir,string $label):string{
    if($f['error']!==UPLOAD_ERR_OK) throw new RuntimeException("Upload error $label (code {$f['error']})");
    if($f['size']<=0||$f['size']>MAX_FILE_BYTES) throw new RuntimeException("$label: max 15 MB");
    $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file($f['tmp_name']);
    if(!isset(ALLOWED_MIME[$mime])) throw new RuntimeException("$label: must be image (got $mime)");
    $ext=ALLOWED_MIME[$mime];$name=uname(strtolower($_POST['email']??'anon'),$ext);$dest=$dir.'/'.$name;
    if(!move_uploaded_file($f['tmp_name'],$dest)) throw new RuntimeException("Could not save $label");
    chmod($dest,0644);
    return 'uploads/'.basename($dir).'/'.$name;
}
/* Teaser videos — separate handler: video MIME allowlist + larger size cap. */
function save_videos(string $key,string $dir,bool $req):array{
    $out=[];
    if(empty($_FILES[$key])||!is_array($_FILES[$key]['name']??null)){if($req)throw new RuntimeException('At least one teaser video is required');return $out;}
    $n=count($_FILES[$key]['name']);
    for($i=0;$i<$n;$i++){
        if($_FILES[$key]['error'][$i]===UPLOAD_ERR_NO_FILE)continue;
        $err=$_FILES[$key]['error'][$i];
        if($err!==UPLOAD_ERR_OK) throw new RuntimeException("Teaser video upload error (code $err)");
        $size=$_FILES[$key]['size'][$i];
        if($size<=0||$size>MAX_VIDEO_BYTES) throw new RuntimeException('Each teaser video must be 100 MB or less');
        $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file($_FILES[$key]['tmp_name'][$i]);
        if(!isset(ALLOWED_VIDEO[$mime])) throw new RuntimeException("Teaser videos must be MP4, MOV or WEBM (got $mime)");
        $name=uname(strtolower($_POST['email']??'anon'),ALLOWED_VIDEO[$mime]);
        $dest=$dir.'/'.$name;
        if(!move_uploaded_file($_FILES[$key]['tmp_name'][$i],$dest)) throw new RuntimeException('Could not save teaser video');
        chmod($dest,0644);
        $out[]='uploads/teasers/'.$name;
    }
    if($req&&!$out)throw new RuntimeException('At least one teaser video is required');
    return $out;
}
function uname(string $e,string $ext):string{
    $slug=substr(preg_replace('/[^a-z0-9]/','',strtolower($e)),0,20)?:'anon';
    return $slug.'_'.bin2hex(random_bytes(6)).'_'.date('Ymd_His').'.'.$ext;
}
