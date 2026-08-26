<?php
/* ═══════════════════════════════════════════════════════════════════════════
   build.php — an affiliate's page, built from her template.

   Called two ways, and both produce the same page:

     provision.sh, when a subscription is paid, writing to her hosted folder
     register.php, when she chooses DIY, going into her zip

   One builder rather than two, because a hosted site and a downloaded one
   that differ is a support conversation nobody wants to have.

       php -r '$_GET=["code"=>"AFFJOHN47"]; require "build.php";' > index.html
   ═══════════════════════════════════════════════════════════════════════════ */

require __DIR__ . '/../config.php';

const TPL_DIR = __DIR__ . '/../templates';
const ADMIN   = 'https://admin.modelsboutique.com';

function mb_e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ── the page ─────────────────────────────────────────────────────────────
   Placeholders in a real HTML file are replaced, rather than the page being
   assembled from strings. The templates stay editable as ordinary pages that
   can be opened in a browser, and a change to one reaches every build after
   it. */
function mb_build_page(array $a): string {
    $file = TPL_DIR . '/' . basename((string)$a['template']) . '.html';
    if (!is_file($file)) $file = TPL_DIR . '/template1.html';

    $html = (string)file_get_contents($file);
    if ($html === '') return '';

    $store = mb_e($a['store_name'] ?: (trim($a['first_name'] . ' ' . $a['last_name']) . "'s Boutique"));

    /* the brand, split so the second word takes the gold — the same shape the
       template was designed around */
    $parts = explode(' ', (string)$a['store_name'], 2);
    $brand = count($parts) > 1 && $parts[1] !== ''
        ? mb_e($parts[0]) . ' <span>' . mb_e($parts[1]) . '</span>'
        : '<span>' . $store . '</span>';

    $addrOne = trim((string)$a['street']);
    $addrTwo = trim(($a['city'] ?? '') . ', ' . ($a['state'] ?? '') . ' ' . ($a['zip'] ?? ''));
    $addrTwo = trim($addrTwo, ' ,');
    $addrOneLine = trim($addrOne . ($addrTwo ? ', ' . $addrTwo : ''));

    $tel = preg_replace('/[^0-9+]/', '', (string)$a['phone']);

    $swap = [
        /* the code that earns her the commission — first, so nothing later
           can disturb it */
        'AFFBELL42' => mb_e($a['code']),

        /* the name, in each of the forms the template uses */
        "Bella's <span>Boutique</span>" => $brand,
        "Bella's Boutique — Women's Fashion" => $store . " — Women's Fashion",
        "Bella's Boutique"              => $store,

        /* her details */
        '418 Charles Street, Baltimore, MD 21201' => mb_e($addrOneLine),
        '418 Charles Street<br>Baltimore, MD 21201'
            => mb_e($addrOne) . ($addrTwo ? '<br>' . mb_e($addrTwo) : ''),
        'hello@bellasboutique.com' => mb_e($a['email']),
        '+14105550142'             => $tel,
        '(410) 555-0142'           => mb_e($a['phone']),
    ];

    foreach ($swap as $from => $to) $html = str_replace($from, $to, $html);

    /* Social links are dropped rather than left pointing at an example. A
       template shipped with somebody else's Instagram in the footer is worse
       than one with no icons at all. */
    $html = preg_replace(
        '#<li class="list-inline-item"><a href="https://(facebook|twitter|instagram)\.com/bellasboutique".*?</li>#s',
        '', $html);

    /* the shared scripts live on the studio's server, wherever the template
       was written */
    $html = str_replace('https://luxetalentsystems.com/api/', ADMIN . '/api/', $html);

    /* Her own images folder. A hosted site and a downloaded one both keep
       their pictures beside the page, so the path is the same either way. */
    $html = str_replace('images/creatives/', 'images/', $html);
    $html = str_replace('images/Creatives/', 'images/', $html);

    return $html;
}

/* ── the readme, for a download ──────────────────────────────────────────*/
function mb_readme(array $a, string $site): string {
    $code = $a['code'];
    $store = $a['store_name'];

    return <<<TXT
{$store}
Your affiliate site — how to put it online

════════════════════════════════════════════════════════════════════

WHAT IS IN THIS FOLDER

  index.html    your site
  images/       your logo and any pictures
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

  logo.png     optional. If you add one it appears instead of your store
               name in the top bar. About 200 x 60 pixels works well.

  Product pictures go in the images folder too, and the page shows
  whichever it finds.

════════════════════════════════════════════════════════════════════

CHANGING THE WORDS

  Open index.html in any plain text editor — Notepad on Windows, TextEdit
  on a Mac. Change the words between the tags, save, upload it again.

  Leave anything that looks like {$code} exactly as it is.

════════════════════════════════════════════════════════════════════

WHAT HAPPENS WHEN SOMEBODY BUYS

  They browse and pay without leaving your site. We hold the stock, take
  the payment, ship the order and handle returns. Your commission appears
  in your dashboard at {$site}/dashboard.php

  A customer who arrives through your site is credited to you for 30 days,
  so a sale made days later still counts.

════════════════════════════════════════════════════════════════════

IF SOMETHING IS WRONG

  Email support@modelsboutique.com and quote {$code}.

TXT;
}

/* ── run from the command line, or included ─────────────────────────────── */
if (!empty($_GET['code']) && (PHP_SAPI === 'cli' || !empty($_GET['action']))) {
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$_GET['code']));

    try {
        $q = db()->prepare("SELECT * FROM affiliates WHERE code = ?");
        $q->execute([$code]);
        $a = $q->fetch();

        if (!$a) {
            fwrite(STDERR, "No affiliate with code {$code}\n");
            exit(1);
        }

        $page = mb_build_page($a);
        if ($page === '') {
            fwrite(STDERR, "The template could not be read\n");
            exit(1);
        }

        echo $page;
        exit(0);

    } catch (Throwable $e) {
        fwrite(STDERR, 'build failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
