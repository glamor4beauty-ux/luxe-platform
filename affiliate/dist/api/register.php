<?php
/* ═══════════════════════════════════════════════════════════════════════════
   register.php — signing up an affiliate.

   Takes the form, creates the account, and either sends her to Stripe or
   builds her site as a zip with her details already in it.

   The zip is built at the moment of registration rather than offered as a
   generic download, because a template she has to edit herself is a template
   most people never get working. What she downloads is finished.

     POST ?action=register   the form
     GET  ?action=zip&code=&token=   her download
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('json_response')) {
    function json_response($d, $c = 200) { http_response_code($c); echo json_encode($d); exit; }
}

const SITE       = 'https://affiliate.modelsboutique.com';
const ADMIN      = 'https://admin.modelsboutique.com';
const TPL_DIR    = __DIR__ . '/../templates';
const BUILD_DIR  = __DIR__ . '/../builds';
const UPLOAD_DIR = __DIR__ . '/../uploads/affiliates';

/* Stripe. Test keys until the programme goes live — they take fake cards only,
   which is what you want while any of this is still being tried out. */
const STRIPE_SECRET  = 'sk_test_51U5nTr2Vym5Jr8QNaAWjsiRUIy8Q11XlWV9MzAOsGZuUJQTaSZ0Avx9dMJDNt1oGVa5eUPcq7TvEIBYCSlzccBYY00nL8UFACL';
const PRICE_MONTHLY  = 2995;    /* cents */
const PRICE_QUARTERLY = 8536;   /* three months less 5% */

function body(): array {
    $j = json_decode(file_get_contents('php://input'), true);
    return is_array($j) ? $j : [];
}

function tables(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS affiliates (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        code        VARCHAR(20) NOT NULL UNIQUE,
        first_name  VARCHAR(80)  NOT NULL DEFAULT '',
        last_name   VARCHAR(80)  NOT NULL DEFAULT '',
        store_name  VARCHAR(120) NOT NULL DEFAULT '',
        email       VARCHAR(160) NOT NULL DEFAULT '',
        phone       VARCHAR(40)  NOT NULL DEFAULT '',
        street      VARCHAR(160) NOT NULL DEFAULT '',
        city        VARCHAR(80)  NOT NULL DEFAULT '',
        state       VARCHAR(80)  NOT NULL DEFAULT '',
        zip         VARCHAR(20)  NOT NULL DEFAULT '',
        country     VARCHAR(80)  NOT NULL DEFAULT '',
        sms_opt_in  TINYINT(1) NOT NULL DEFAULT 0,
        hosting     ENUM('managed','diy') NOT NULL DEFAULT 'diy',
        template    VARCHAR(20)  NOT NULL DEFAULT 'template1',
        site_url    VARCHAR(255) NOT NULL DEFAULT '',
        status      ENUM('trial','active','inactive','banned') NOT NULL DEFAULT 'trial',
        profile_img VARCHAR(255) NOT NULL DEFAULT '',
        id_img      VARCHAR(255) NOT NULL DEFAULT '',
        zip_token   VARCHAR(40)  NOT NULL DEFAULT '',
        pass_hash   VARCHAR(255) NOT NULL DEFAULT '',
        agreed_at   TIMESTAMP NULL,
        agreed_ip   VARCHAR(45) NOT NULL DEFAULT '',
        opened      DATE NULL,
        last_seen   TIMESTAMP NULL,
        last_ping   TIMESTAMP NULL,
        ping_ok     TINYINT(1) NOT NULL DEFAULT 0,
        loads       INT NOT NULL DEFAULT 0,
        stripe_id   VARCHAR(80)  NOT NULL DEFAULT '',
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY k_status (status), KEY k_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    /* a table made before passwords existed needs the column adding */
    try {
        $q = $db->query("SHOW COLUMNS FROM affiliates LIKE 'pass_hash'");
        if (!$q->fetch()) {
            $db->exec("ALTER TABLE affiliates ADD COLUMN pass_hash VARCHAR(255) NOT NULL DEFAULT ''");
        }
    } catch (Throwable $e) { /* not fatal */ }
}

/* AFF + four letters of the surname + two digits. The digits are not
   decoration: Johnson, Johnston and Johns all give AFFJOHN, and two affiliates
   sharing a code means commission paid to the wrong person. */
function make_code(PDO $db, string $last): string {
    $stem = strtoupper(preg_replace('/[^A-Za-z]/', '', $last));
    $stem = substr($stem, 0, 4);
    if ($stem === '') $stem = 'AFFL';

    for ($i = 0; $i < 60; $i++) {
        $code = 'AFF' . $stem . str_pad((string)random_int(0, 99), 2, '0', STR_PAD_LEFT);
        $q = $db->prepare("SELECT 1 FROM affiliates WHERE code = ?");
        $q->execute([$code]);
        if (!$q->fetch()) return $code;
    }
    /* a hundred taken is implausible, but silently reusing one would be worse */
    return 'AFF' . $stem . substr(bin2hex(random_bytes(2)), 0, 3);
}

function save_image(string $dataUrl, string $code, string $kind): ?string {
    if (!preg_match('#^data:image/(jpeg|jpg|png|webp);base64,#', $dataUrl, $m)) return null;
    $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
    $raw = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
    if ($raw === false || strlen($raw) < 1000 || strlen($raw) > 8388608) return null;

    if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0750, true)) return null;
    $name = $code . '-' . $kind . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (@file_put_contents(UPLOAD_DIR . '/' . $name, $raw) === false) return null;
    @chmod(UPLOAD_DIR . '/' . $name, 0640);
    return $name;
}

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ── her page, built from the template she chose ──────────────────────────
   Placeholders are replaced rather than the file being generated from
   scratch, so the templates stay editable as ordinary HTML and a change to
   them reaches every future build. */
function build_page(array $a): string {
    $file = TPL_DIR . '/' . basename($a['template']) . '.html';
    if (!is_file($file)) $file = TPL_DIR . '/template1.html';
    $html = (string)file_get_contents($file);

    $store = e($a['store_name']);
    $addr  = e(trim($a['street'] . ', ' . $a['city'] . ' ' . $a['state'] . ' ' . $a['zip']));

    /* the brand: her store name, split so the second word takes the gold */
    $parts = explode(' ', $a['store_name'], 2);
    $brand = count($parts) > 1
        ? e($parts[0]) . ' <span>' . e($parts[1]) . '</span>'
        : '<span>' . $store . '</span>';

    $swap = [
        // the code that earns her the commission
        'AFFBELL42' => e($a['code']),

        // the name, everywhere it appears
        "Bella's <span>Boutique</span>" => $brand,
        "Bella's Boutique"              => $store,

        // her details
        '418 Charles Street, Baltimore, MD 21201' => $addr,
        '418 Charles Street<br>Baltimore, MD 21201'
            => e($a['street']) . '<br>' . e($a['city'] . ', ' . $a['state'] . ' ' . $a['zip']),
        'hello@bellasboutique.com' => e($a['email']),
        '+14105550142'             => preg_replace('/[^0-9+]/', '', $a['phone']),
        '(410) 555-0142'           => e($a['phone']),

        // the title and description
        "Bella's Boutique — Women's Fashion" => $store . " — Women's Fashion",

        // social links are dropped rather than left pointing at an example
        'https://facebook.com/bellasboutique'  => '#',
        'https://twitter.com/bellasboutique'   => '#',
        'https://instagram.com/bellasboutique' => '#',
    ];

    foreach ($swap as $from => $to) $html = str_replace($from, $to, $html);

    /* the shared script lives on the admin site, not here */
    $html = str_replace('https://luxetalentsystems.com/api/', ADMIN . '/api/', $html);

    return $html;
}

function readme(array $a): string {
    $code = $a['code'];
    $store = $a['store_name'];

    return <<<TXT
{$store}
Your affiliate site — how to put it online

════════════════════════════════════════════════════════════════════

WHAT IS IN THIS FOLDER

  index.html    your site
  images/       put your logo and banner picture here
  readme.txt    this file

Your affiliate code is {$code}. It is already in index.html and it is what
earns you commission. Do not change it, or your sales will not be credited
to you.

════════════════════════════════════════════════════════════════════

PUTTING IT ONLINE WITH cPANEL

  1. Sign in to cPanel. Your hosting company gave you the address; it is
     usually yourdomain.com/cpanel

  2. Open File Manager, in the Files section.

  3. Go into public_html. That folder is your website — whatever is in it
     is what people see.

  4. Click Upload, then choose index.html from this folder.

  5. Back in public_html, click +Folder and name it images. Go into it and
     upload anything from the images folder here.

  6. Visit your domain. Your site is live.

  If you already have a site in public_html and want this alongside it,
  make a folder such as shop and upload into that instead. Your address
  becomes yourdomain.com/shop

════════════════════════════════════════════════════════════════════

PUTTING IT ONLINE WITH PLESK

  1. Sign in to Plesk.

  2. Choose Websites & Domains, then your domain.

  3. Click File Manager.

  4. Open httpdocs. This is the folder your site is served from.

  5. Use Upload to add index.html.

  6. Create a folder called images and upload your pictures into it.

  7. Visit your domain.

════════════════════════════════════════════════════════════════════

YOUR PICTURES

  logo.png     optional. If you add one, it appears instead of your store
               name in the top bar. About 200 x 60 pixels works well.

  banner.jpg   only used by the Banner template. 1200 x 468 pixels. If it
               is missing the page still works, it simply shows no picture.

  Both go in the images folder, spelled exactly as above.

════════════════════════════════════════════════════════════════════

CHANGING THE WORDS

  Open index.html in any plain text editor — Notepad on Windows, TextEdit
  on a Mac. Change the words between the tags, save, and upload it again.

  Leave anything that looks like {$code} exactly as it is.

════════════════════════════════════════════════════════════════════

WHAT HAPPENS WHEN SOMEBODY BUYS

  They browse and pay without leaving your site. We hold the stock, take
  the payment, ship the order and handle returns. Your commission appears
  in your dashboard at {SITE}/dashboard.

  A customer who arrives through your site is credited to you for 30 days,
  so a sale made days later still counts.

════════════════════════════════════════════════════════════════════

IF SOMETHING IS WRONG

  Email support@modelsboutique.com and quote {$code}.

TXT;
}

/* Written by hand rather than with a library, because ZipArchive is not
   always compiled in and a missing extension at the last step of registration
   is the worst possible moment to find out. */
function make_zip(array $entries): string {
    $out = '';
    $central = '';
    $offset = 0;
    $n = 0;

    foreach ($entries as $name => $content) {
        $crc  = crc32($content);
        $len  = strlen($content);

        $local = "\x50\x4b\x03\x04" . "\x14\x00" . "\x00\x00" . "\x00\x00"
               . "\x00\x00\x00\x00"
               . pack('V', $crc) . pack('V', $len) . pack('V', $len)
               . pack('v', strlen($name)) . pack('v', 0)
               . $name . $content;

        $central .= "\x50\x4b\x01\x02" . "\x14\x00" . "\x14\x00" . "\x00\x00"
                  . "\x00\x00" . "\x00\x00\x00\x00"
                  . pack('V', $crc) . pack('V', $len) . pack('V', $len)
                  . pack('v', strlen($name)) . pack('v', 0) . pack('v', 0)
                  . pack('v', 0) . pack('v', 0) . pack('V', 32)
                  . pack('V', $offset) . $name;

        $out .= $local;
        $offset += strlen($local);
        $n++;
    }

    $out .= $central
          . "\x50\x4b\x05\x06" . "\x00\x00" . "\x00\x00"
          . pack('v', $n) . pack('v', $n)
          . pack('V', strlen($central)) . pack('V', $offset) . pack('v', 0);

    return $out;
}

try {
    $db = db();
    tables($db);
    $action = $_GET['action'] ?? '';

    /* ═══ the download ════════════════════════════════════════════════════
       A token in the address rather than a login, because she has not got one
       yet and turning her away at the last step to make a password is how a
       finished registration becomes an abandoned one. */
    if ($action === 'zip') {
        $code  = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['code'] ?? '')));
        $token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['token'] ?? ''));

        $q = $db->prepare("SELECT * FROM affiliates WHERE code = ? AND zip_token = ? AND zip_token <> ''");
        $q->execute([$code, $token]);
        $a = $q->fetch(PDO::FETCH_ASSOC);
        if (!$a) { http_response_code(404); exit('Not found'); }

        $files = [
            'index.html'      => build_page($a),
            'readme.txt'      => str_replace('{SITE}', SITE, readme($a)),
            'images/'         => '',
            'images/READ.txt' => "Put logo.png and banner.jpg in this folder.\n"
                               . "See readme.txt for the sizes.\n",
        ];

        $zip = make_zip($files);
        $safe = preg_replace('/[^A-Za-z0-9]+/', '-', $a['store_name']) ?: 'my-store';

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . strtolower(trim($safe, '-')) . '-site.zip"');
        header('Content-Length: ' . strlen($zip));
        header('Cache-Control: no-store');
        echo $zip;
        exit;
    }

    /* ═══ is her site ready ═══════════════════════════════════════════════
       Asked by the welcome page every few seconds while she waits. Returns
       only whether it is built and where — nothing about her account, since
       this is reachable without signing in. */
    if ($action === 'status') {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['code'] ?? '')));
        if ($code === '') json_response(['ok' => false], 400);

        $q = $db->prepare("SELECT site_url, status FROM affiliates WHERE code = ?");
        $q->execute([$code]);
        $a = $q->fetch(PDO::FETCH_ASSOC);

        if (!$a) json_response(['ok' => false]);

        json_response([
            'ok'       => true,
            'ready'    => $a['site_url'] !== '',
            'site_url' => $a['site_url'] ?: null,
        ]);
    }

    if ($action !== 'register') json_response(['ok' => false, 'error' => 'Unknown action'], 400);

    /* ═══ registering ═════════════════════════════════════════════════════ */
    $in = body();

    $email = strtolower(trim((string)($in['email'] ?? '')));
    $first = trim((string)($in['first'] ?? ''));
    $last  = trim((string)($in['last'] ?? ''));
    $store = trim((string)($in['store'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['ok' => false, 'error' => 'That email address does not look right.'], 400);
    }
    foreach ([['first', $first, 'your first name'], ['last', $last, 'your last name'],
              ['store', $store, 'your store name']] as [$k, $val, $label]) {
        if ($val === '') json_response(['ok' => false, 'error' => 'Please add ' . $label . '.'], 400);
    }
    if (empty($in['agreed'])) {
        json_response(['ok' => false, 'error' => 'The agreement has not been accepted.'], 400);
    }

    /* Eight characters and nothing more demanded. Rules about capitals and
       symbols mostly produce Password1! written on a note beside the screen. */
    $pass = (string)($in['password'] ?? '');
    if (strlen($pass) < 8) {
        json_response(['ok' => false, 'error' => 'Your password needs at least 8 characters.'], 400);
    }

    $dup = $db->prepare("SELECT code FROM affiliates WHERE email = ?");
    $dup->execute([$email]);
    if ($existing = $dup->fetchColumn()) {
        json_response(['ok' => false, 'error' => 'There is already an account for that email '
            . 'address, under code ' . $existing . '. Get in touch if you cannot sign in.'], 400);
    }

    /* the test plan is managed hosting at a nominal price, so it takes the
       same path through Stripe and provisioning as a real one */
    $choice   = (string)($in['hosting'] ?? 'diy');
    $hosting  = in_array($choice, ['managed', 'test'], true) ? 'managed' : 'diy';
    $testPlan = ($choice === 'test');
    $template = ($in['template'] ?? 'template1') === 'template2' ? 'template2' : 'template1';

    $code  = make_code($db, $last);
    $token = bin2hex(random_bytes(16));

    $profile = save_image((string)($in['profile'] ?? ''), $code, 'profile');
    $idimg   = save_image((string)($in['id_photo'] ?? ''), $code, 'id');

    if ($idimg === null) {
        json_response(['ok' => false, 'error' => 'The photo of your ID could not be read. '
            . 'Use a JPG or PNG under 8 MB.'], 400);
    }

    $db->prepare("INSERT INTO affiliates
        (code, first_name, last_name, store_name, email, phone, street, city, state, zip,
         country, sms_opt_in, hosting, template, status, profile_img, id_img, zip_token,
         pass_hash, agreed_at, agreed_ip, opened)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'trial',?,?,?,?,NOW(),?,CURDATE())")
       ->execute([
           $code, $first, $last, $store, $email,
           trim((string)($in['phone'] ?? '')),
           trim((string)($in['street'] ?? '')),
           trim((string)($in['city'] ?? '')),
           trim((string)($in['state'] ?? '')),
           trim((string)($in['zip'] ?? '')),
           trim((string)($in['country'] ?? '')),
           !empty($in['sms']) ? 1 : 0,
           $hosting, $template, $profile ?? '', $idimg, $token,
           /* hashed, never stored as typed */
           password_hash($pass, PASSWORD_DEFAULT),
           substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
       ]);

    $out = ['ok' => true, 'code' => $code, 'hosting' => $hosting];

    if ($hosting === 'managed') {
        /* Stripe is handed the checkout; card details never touch this server,
           which is the whole reason for using them. */
        $out['checkout_url'] = SITE . '/subscribe.html?code=' . urlencode($code)
                             . ($testPlan ? '&plan=test' : '');
    } else {
        $out['zip_url'] = SITE . '/api/register.php?action=zip&code=' . urlencode($code)
                        . '&token=' . $token;
    }

    json_response($out);

} catch (Throwable $e) {
    error_log('[affiliate-register] ' . $e->getMessage() . ' @ ' . $e->getLine());
    json_response(['ok' => false, 'error' => 'Something went wrong at our end. '
        . 'Try again in a moment.'], 500);
}
